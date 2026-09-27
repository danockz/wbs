<?php

declare(strict_types=1);

namespace WBS\Contributions\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Contributions\Services\WebhookSignatureVerifier;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Signed payment webhook receiver (SRS FR-VBCS-006).
 *
 * Order of operations is security-critical:
 *   1. Verify signature + timestamp (replay tolerance) BEFORE any state change.
 *   2. Record in the idempotent inbox (UNIQUE provider+event_id); duplicates and
 *      unverified/unknown events are quarantined, never applied.
 *   3. Only a freshly-accepted, verified, known event advances contribution
 *      state — and even then via the transactional path in the service.
 *
 * The raw signature check uses the connection's webhook secret; here we accept a
 * pre-computed verification flag from the adapter boundary plus an HMAC guard on
 * the raw body so this controller is safe even if called directly.
 */
final class WebhookController extends BaseController
{
    public function receive(string $provider = '')
    {
        $rawBody   = (string) $this->request->getBody();
        $signature = $this->request->getHeaderLine('X-WBS-Signature');
        $eventId   = $this->request->getHeaderLine('X-WBS-Event-Id');

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->respondWith(Result::fail('BAD_PAYLOAD', 'webhook.bad_payload', 400));
        }

        $eventId   = $eventId !== '' ? $eventId : (string) ($payload['event_id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        if ($eventId === '') {
            return $this->respondWith(Result::fail('MISSING_EVENT_ID', 'webhook.missing_event_id', 400));
        }

        // (1) Verify signature over the raw body with the provider secret.
        $verified = $this->verifySignature($provider, $rawBody, $signature);

        // (2) Idempotent inbox. Quarantines unverified/unknown; dedups replays.
        $inbox = ContributionServices::webhookInbox()->receive($provider, $eventId, $eventType, $verified);

        // Duplicate or quarantined -> acknowledge without changing state.
        $action = $inbox->data['action'] ?? null;
        if ($action !== 'process') {
            // 202 Accepted with the inbox decision; verified header signals result.
            return $this->response
                ->setStatusCode($verified ? 202 : 400)
                ->setHeader('X-WBS-Verified', $verified ? '1' : '0')
                ->setJSON($inbox->toArray());
        }

        // (3) Apply the verified, known event. Mark the inbox row `processed`
        // ONLY when the handler succeeded; a failed/unhandled handler is marked
        // `failed` (gap C2) so a money event is never silently acked + dropped.
        $applied = $this->apply($eventType, $payload);
        $inboxId = (string) $inbox->data['inbox_id'];
        if ($applied->ok) {
            ContributionServices::webhookInbox()->markProcessed($inboxId);

            return $this->response
                ->setStatusCode(200)
                ->setHeader('X-WBS-Verified', '1')
                ->setJSON($applied->toArray());
        }

        ContributionServices::webhookInbox()->markFailed($inboxId, (string) $applied->code);

        // 422 tells the provider to retry later; the row stays visible as failed.
        return $this->response
            ->setStatusCode(422)
            ->setHeader('X-WBS-Verified', '1')
            ->setJSON($applied->toArray());
    }

    /** @param array<string,mixed> $payload */
    private function apply(string $eventType, array $payload): Result
    {
        return match ($eventType) {
            'payment.succeeded' => ContributionServices::contributions()->markSucceeded(
                (string) ($payload['intent_id'] ?? ''),
                [
                    'provider'        => $payload['provider'] ?? null,
                    'provider_txn_id' => $payload['transaction_id'] ?? null,
                    'fee_minor'       => (int) ($payload['fee_minor'] ?? 0),
                ],
            ),
            'payment.failed' => ContributionServices::contributions()->markFailed(
                (string) ($payload['intent_id'] ?? ''),
                [
                    'provider' => $payload['provider'] ?? null,
                    // Only a genuine provider/transport fault feeds the circuit
                    // breaker (FR-INT-012); an ordinary decline does not.
                    'provider_fault' => (bool) ($payload['provider_fault'] ?? false),
                    'error'          => $payload['failure_reason'] ?? null,
                ],
            ),
            // Provider-originated refund (gap C2): reconcile / reverse rather than
            // silently 200-ing it. Was previously dropped via `default`.
            'refund.succeeded' => ContributionServices::contributions()->markProviderRefund(
                (string) ($payload['organization_id'] ?? ''),
                [
                    'provider'           => $payload['provider'] ?? null,
                    'provider_refund_id' => $payload['refund_id'] ?? ($payload['transaction_id'] ?? null),
                    'provider_txn_id'    => $payload['charge_id'] ?? ($payload['transaction_id'] ?? null),
                    'contribution_id'    => $payload['contribution_id'] ?? null,
                    'amount_minor'       => isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
                ],
            ),
            // Chargeback / dispute (gap C2): freeze the gift + open a case.
            'charge.dispute.created' => ContributionServices::contributions()->markDisputed(
                (string) ($payload['organization_id'] ?? ''),
                [
                    'provider'        => $payload['provider'] ?? null,
                    'provider_txn_id' => $payload['charge_id'] ?? ($payload['transaction_id'] ?? null),
                    'contribution_id' => $payload['contribution_id'] ?? null,
                    'dispute_id'      => $payload['dispute_id'] ?? null,
                    'reason'          => $payload['reason'] ?? null,
                ],
            ),
            // A KNOWN type with no handler must NOT be silently marked processed
            // (gap C2). Signal it as unhandled so the caller quarantines rather
            // than acking + dropping a money event.
            default          => Result::fail('UNHANDLED_KNOWN_TYPE', 'webhook.unhandled_known_type', 422, ['type' => $eventType]),
        };
    }

    /**
     * HMAC-SHA256 verification over the raw body (constant-time, fail-closed).
     * Delegates to the unit-tested {@see WebhookSignatureVerifier} so the trust
     * decision lives in one auditable place.
     */
    private function verifySignature(string $provider, string $rawBody, string $signature): bool
    {
        return (new WebhookSignatureVerifier())->verifyForProvider($provider, $rawBody, $signature);
    }
}
