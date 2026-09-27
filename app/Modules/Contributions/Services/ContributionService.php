<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Integrations\Services\ProviderReliabilityService;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Contribution lifecycle (SRS FR-VBCS-002/005/007).
 *
 *  - Amounts are integer minor units; no PAN/CVV ever touches the app.
 *  - An intent is created first (idempotent on idempotency_key), then a
 *    provider checkout is attached. Credit/points are granted ONLY when the
 *    provider-verified success is confirmed (markSucceeded), at which point the
 *    ledger is posted and a reward event is staged on the outbox.
 */
final class ContributionService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly LedgerService $ledger,
        private readonly OutboxService $outbox,
        private readonly ?ProviderReliabilityService $reliability = null,
    ) {
    }

    /** Circuit-breaker scope for a payment provider (per org). */
    private function paymentScope(?string $provider): string
    {
        $provider = trim((string) $provider);

        return $provider !== '' ? 'payment:' . strtolower($provider) : '';
    }

    /**
     * Create (or return existing) a contribution intent.
     *
     * @param array<string,mixed> $data amount_minor, currency, provider, recurrence, recognition, idempotency_key, user_id
     */
    public function createIntent(string $organizationId, string $causeId, array $data): Result
    {
        $amount = (int) ($data['amount_minor'] ?? 0);
        if ($amount <= 0) {
            return Result::fail('BAD_AMOUNT', 'contribution.bad_amount', 422);
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'contribution.bad_currency', 422);
        }

        $idem = $data['idempotency_key'] ?? Uuid::v7();

        $existing = $this->db->table('contribution_intents')->where('idempotency_key', $idem)->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['intent_id' => $existing['id'], 'status' => $existing['status']], 200, ['deduplicated' => true]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        try {
            $this->db->table('contribution_intents')->insert([
                'id'               => $id,
                'organization_id'  => $organizationId,
                'cause_id'         => $causeId,
                'user_id'          => $data['user_id'] ?? null,
                'provider_config_id' => $data['provider_config_id'] ?? null,
                'provider'         => $data['provider'] ?? null,
                'amount_minor'     => $amount,
                'currency'         => $currency,
                'recurrence'       => $data['recurrence'] ?? 'once',
                'recognition'      => $data['recognition'] ?? 'public',
                'idempotency_key'  => $idem,
                'status'           => 'intended',
                'created_at'       => $now,
            ]);
        } catch (Throwable) {
            $existing = $this->db->table('contribution_intents')->where('idempotency_key', $idem)->get()->getRowArray();

            return Result::ok(['intent_id' => $existing['id'] ?? $id], 200, ['deduplicated' => true]);
        }

        return Result::created(['intent_id' => $id, 'status' => 'intended']);
    }

    /** Attach the provider checkout reference and move intent to pending. */
    public function attachCheckout(string $intentId, string $checkoutRef): Result
    {
        $this->db->table('contribution_intents')->where('id', $intentId)->update([
            'checkout_ref' => $checkoutRef,
            'status'       => 'pending',
            'updated_at'   => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['intent_id' => $intentId, 'status' => 'pending']);
    }

    /**
     * Confirm provider-verified success. Idempotent per intent. Creates the
     * contribution, posts the succeeded ledger entry, and stages a reward event.
     *
     * @param array<string,mixed> $txn provider, provider_txn_id, fee_minor
     */
    public function markSucceeded(string $intentId, array $txn = []): Result
    {
        $intent = $this->db->table('contribution_intents')->where('id', $intentId)->get()->getRowArray();
        if ($intent === null) {
            return Result::notFound('contribution.intent_not_found', 'INTENT_NOT_FOUND');
        }

        // Idempotency: contribution already exists for this intent?
        $existing = $this->db->table('contributions')->where('intent_id', $intentId)->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['contribution_id' => $existing['id'], 'state' => $existing['state']], 200, ['deduplicated' => true]);
        }

        $orgId    = $intent['organization_id'];
        $amount   = (int) $intent['amount_minor'];
        $fee      = (int) ($txn['fee_minor'] ?? 0);
        $currency = $intent['currency'];
        $net      = $amount - $fee;
        $now      = $this->clock->nowUtcMicro();
        $conId    = Uuid::v7();

        $this->db->transStart();
        try {
            $this->db->table('contributions')->insert([
                'id'              => $conId,
                'organization_id' => $orgId,
                'cause_id'        => $intent['cause_id'],
                'intent_id'       => $intentId,
                'user_id'         => $intent['user_id'],
                'amount_minor'    => $amount,
                'currency'        => $currency,
                'fee_minor'       => $fee,
                'net_minor'       => $net,
                'source'          => 'online',
                'recognition'     => $intent['recognition'],
                'state'           => 'succeeded',
                'verified_at'     => $now,
                'created_at'      => $now,
            ]);

            if (! empty($txn['provider']) && ! empty($txn['provider_txn_id'])) {
                $this->db->table('payment_transactions')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $orgId,
                    'contribution_id' => $conId,
                    'intent_id'       => $intentId,
                    'provider'        => $txn['provider'],
                    'provider_txn_id' => $txn['provider_txn_id'],
                    'type'            => 'charge',
                    'amount_minor'    => $amount,
                    'currency'        => $currency,
                    'status'          => 'succeeded',
                    'created_at'      => $now,
                ]);
            }

            $this->db->table('contribution_intents')->where('id', $intentId)->update([
                'status'     => 'succeeded',
                'updated_at' => $now,
            ]);

            // Post succeeded ledger entry (double-entry, balanced).
            $lines = [
                ['account' => 'provider_clearing', 'direction' => 'debit', 'amount_minor' => $amount],
                ['account' => 'cause_funds', 'direction' => 'credit', 'amount_minor' => $net],
            ];
            if ($fee > 0) {
                $lines[] = ['account' => 'fees', 'direction' => 'credit', 'amount_minor' => $fee];
            }
            $this->ledger->post($orgId, 'succeeded', $currency, $lines, 'contribution:' . $conId, [
                'cause_id'        => $intent['cause_id'],
                'contribution_id' => $conId,
                'memo'            => 'Contribution succeeded',
            ]);

            // Stage reward (points) for async, idempotent processing.
            $this->outbox->stage('contribution', $conId, 'contribution.succeeded', [
                'contribution_id' => $conId,
                'cause_id'        => $intent['cause_id'],
                'user_id'         => $intent['user_id'],
                'amount_minor'    => $amount,
                'source_ref'      => 'contribution:' . $conId,
            ], $orgId);
        } catch (Throwable $e) {
            $this->db->transRollback();

            return Result::fail('SUCCEED_FAILED', 'contribution.succeed_failed', 500, ['detail' => $e->getMessage()]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('SUCCEED_FAILED', 'contribution.succeed_failed', 500);
        }

        // FR-INT-012: a provider-verified success is a healthy signal for the
        // payment provider's circuit breaker (closes it / resets the failure run).
        if ($this->reliability !== null) {
            $scope = $this->paymentScope($txn['provider'] ?? $intent['provider'] ?? null);
            if ($scope !== '') {
                $this->reliability->recordSuccess((string) $orgId, $scope);
            }
        }

        return Result::created(['contribution_id' => $conId, 'state' => 'succeeded']);
    }

    public function markFailed(string $intentId, array $txn = []): Result
    {
        $intent = $this->db->table('contribution_intents')->where('id', $intentId)->get()->getRowArray();

        $this->db->table('contribution_intents')->where('id', $intentId)->update([
            'status'     => 'failed',
            'updated_at' => $this->clock->nowUtcMicro(),
        ]);

        // FR-INT-012: only a PROVIDER/transport fault (timeout, 5xx, gateway
        // outage) counts toward tripping the payment breaker — an ordinary
        // giver-side decline (insufficient funds, card declined) must NOT. The
        // caller/webhook flags provider_fault=true only for genuine provider
        // failures, so the breaker reflects provider health, not decline rates.
        if ($this->reliability !== null && $intent !== null && ($txn['provider_fault'] ?? false) === true) {
            $scope = $this->paymentScope($txn['provider'] ?? $intent['provider'] ?? null);
            if ($scope !== '') {
                $reason = is_string($txn['error'] ?? null) ? $txn['error'] : 'payment provider fault';
                $this->reliability->recordFailure((string) $intent['organization_id'], $scope, $reason);
            }
        }

        return Result::ok(['intent_id' => $intentId, 'status' => 'failed']);
    }

    /**
     * Provider-originated refund (gap C2): a refund issued OUTSIDE our
     * maker-checker flow (e.g. in the provider dashboard) that arrives as a
     * `refund.succeeded` webhook. Without this, such refunds were acked and
     * dropped — the contribution stayed `succeeded`, the ledger kept the funds,
     * and points were never reversed.
     *
     * Resolves the contribution by provider charge txn id (or explicit
     * contribution_id), and — if it isn't already reconciled to an internal
     * refund request — posts a compensating ledger reversal, records the negative
     * payment transaction, moves the contribution to `refunded`/`reversed`, and
     * stages the point reversal. Idempotent on the provider refund id.
     *
     * @param array<string,mixed> $txn provider, provider_refund_id, provider_txn_id,
     *   contribution_id?, amount_minor?
     */
    public function markProviderRefund(string $organizationId, array $txn): Result
    {
        $providerRefundId = (string) ($txn['provider_refund_id'] ?? '');
        $con              = $this->resolveContribution($organizationId, $txn);
        if ($con === null) {
            return Result::notFound('contribution.not_found', 'CONTRIBUTION_NOT_FOUND');
        }
        $conId = (string) $con['id'];

        // Idempotency: this provider refund already recorded?
        if ($providerRefundId !== '') {
            $dupe = $this->db->table('payment_transactions')
                ->where('organization_id', $organizationId)
                ->where('type', 'refund')
                ->where('provider_txn_id', $providerRefundId)
                ->get()->getRowArray();
            if ($dupe !== null) {
                return Result::ok(['contribution_id' => $conId, 'state' => $con['state']], 200, ['deduplicated' => true]);
            }
        }

        // If already fully refunded internally, nothing to reverse again.
        if (in_array((string) $con['state'], ['refunded', 'reversed'], true)) {
            return Result::ok(['contribution_id' => $conId, 'state' => $con['state']], 200, ['deduplicated' => true]);
        }

        $amount = (int) ($txn['amount_minor'] ?? $con['amount_minor']);
        $now    = $this->clock->nowUtcMicro();

        $this->db->transStart();
        try {
            $this->db->table('payment_transactions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'contribution_id' => $conId,
                'provider'        => $txn['provider'] ?? 'unknown',
                'provider_txn_id' => $providerRefundId !== '' ? $providerRefundId : null,
                'type'            => 'refund',
                'amount_minor'    => -1 * $amount,
                'currency'        => $con['currency'],
                'status'          => 'succeeded',
                'created_at'      => $now,
            ]);

            $this->db->table('contributions')->where('id', $conId)->update([
                'state'      => 'reversed',
                'updated_at' => $now,
            ]);

            // Compensating ledger entry — provider-agnostic source_ref (as C1).
            $orig = $this->findOriginalEntry($organizationId, $conId);
            if ($orig !== null) {
                $this->ledger->reverseEntry($organizationId, $orig['id'], 'reversed', 'provider_refund:' . ($providerRefundId !== '' ? $providerRefundId : $conId), 'Provider-originated refund');
            }

            $this->outbox->stage('contribution', $conId, 'contribution.refunded', [
                'contribution_id' => $conId,
                'user_id'         => $con['user_id'] ?? null,
                'source_ref'      => 'contribution:' . $conId,
                'origin'          => 'provider',
            ], $organizationId);
        } catch (Throwable $e) {
            $this->db->transRollback();

            return Result::fail('PROVIDER_REFUND_FAILED', 'contribution.provider_refund_failed', 500, ['detail' => $e->getMessage()]);
        }
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('PROVIDER_REFUND_FAILED', 'contribution.provider_refund_failed', 500);
        }

        return Result::ok(['contribution_id' => $conId, 'state' => 'reversed']);
    }

    /**
     * Chargeback / dispute opened (gap C2): a `charge.dispute.created` webhook.
     * Moves the contribution to `disputed` (freezing further internal refunds —
     * RefundService.request only allows `succeeded`) and stages a signal so a
     * reconciliation case can be opened and finance alerted. Idempotent.
     *
     * @param array<string,mixed> $txn provider, provider_txn_id, contribution_id?,
     *   dispute_id?, reason?
     */
    public function markDisputed(string $organizationId, array $txn): Result
    {
        $con = $this->resolveContribution($organizationId, $txn);
        if ($con === null) {
            return Result::notFound('contribution.not_found', 'CONTRIBUTION_NOT_FOUND');
        }
        $conId = (string) $con['id'];

        if ((string) $con['state'] === 'disputed') {
            return Result::ok(['contribution_id' => $conId, 'state' => 'disputed'], 200, ['deduplicated' => true]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('contributions')->where('id', $conId)->update([
            'state'      => 'disputed',
            'updated_at' => $now,
        ]);

        $this->outbox->stage('contribution', $conId, 'contribution.disputed', [
            'contribution_id' => $conId,
            'user_id'         => $con['user_id'] ?? null,
            'dispute_id'      => $txn['dispute_id'] ?? null,
            'reason'          => $txn['reason'] ?? null,
            'source_ref'      => 'contribution:' . $conId,
        ], $organizationId);

        return Result::ok(['contribution_id' => $conId, 'state' => 'disputed']);
    }

    /**
     * Resolve a contribution from a provider webhook payload: prefer an explicit
     * contribution_id, else match the original charge's provider_txn_id.
     *
     * @param array<string,mixed> $txn
     * @return array<string,mixed>|null
     */
    private function resolveContribution(string $organizationId, array $txn): ?array
    {
        if (! empty($txn['contribution_id'])) {
            return $this->db->table('contributions')
                ->where('id', (string) $txn['contribution_id'])
                ->where('organization_id', $organizationId)
                ->get()->getRowArray() ?: null;
        }
        $chargeTxnId = (string) ($txn['provider_txn_id'] ?? '');
        if ($chargeTxnId === '') {
            return null;
        }
        $pt = $this->db->table('payment_transactions')
            ->where('organization_id', $organizationId)
            ->where('type', 'charge')
            ->where('provider_txn_id', $chargeTxnId)
            ->get()->getRowArray();
        if ($pt === null) {
            return null;
        }

        return $this->db->table('contributions')
            ->where('id', (string) $pt['contribution_id'])
            ->get()->getRowArray() ?: null;
    }

    /**
     * Provider-agnostic original journal-entry lookup (mirrors RefundService C1):
     * online gifts post `contribution:{id}`, manual gifts post `manual:{id}`.
     *
     * @return array<string,mixed>|null
     */
    private function findOriginalEntry(string $organizationId, string $contributionId): ?array
    {
        $orig = $this->db->table('journal_entries')
            ->where('organization_id', $organizationId)
            ->where('source_ref', 'contribution:' . $contributionId)
            ->get()->getRowArray();
        if ($orig === null) {
            $orig = $this->db->table('journal_entries')
                ->where('organization_id', $organizationId)
                ->where('source_ref', 'manual:' . $contributionId)
                ->get()->getRowArray();
        }

        return $orig ?: null;
    }
}
