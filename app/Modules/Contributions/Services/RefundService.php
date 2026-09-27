<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Refund maker-checker workflow (SRS FR-VBCS-007).
 *
 *  - request -> approve -> execute, with strict segregation of duties: the
 *    approver MUST differ from the requester (SOD_SELF_APPROVAL otherwise).
 *  - Amount limited to the contribution's succeeded amount.
 *  - Execution is idempotent, posts a compensating ledger entry, sets the
 *    contribution to refunded, and stages a point reversal event.
 */
final class RefundService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly LedgerService $ledger,
        private readonly OutboxService $outbox,
    ) {
    }

    public function request(string $organizationId, string $contributionId, int $amountMinor, string $requestedBy, string $reason = ''): Result
    {
        $con = $this->db->table('contributions')->where('id', $contributionId)->get()->getRowArray();
        if ($con === null) {
            return Result::notFound('contribution.not_found', 'CONTRIBUTION_NOT_FOUND');
        }
        if ($con['state'] !== 'succeeded') {
            return Result::fail('NOT_REFUNDABLE', 'refund.not_refundable', 409, ['state' => $con['state']]);
        }
        if ($amountMinor <= 0) {
            return Result::fail('BAD_AMOUNT', 'refund.bad_amount', 422);
        }

        // Cumulative-refund guard (gap C3): validate against the ORIGINAL amount
        // minus everything already committed to a refund (approved or executed),
        // not just the original — so successive partial refunds can never sum past
        // the gift. `requested` rows are excluded (not yet committed); approve()
        // re-checks headroom at commit time to close the request/approve race.
        $original    = (int) $con['amount_minor'];
        $committed   = $this->refundedToDate($contributionId);
        $remaining   = $original - $committed;
        if ($amountMinor > $remaining) {
            return Result::fail('REFUND_EXCEEDS_REMAINING', 'refund.exceeds_remaining', 422, [
                'original'  => $original,
                'committed' => $committed,
                'remaining' => $remaining,
                'requested' => $amountMinor,
            ]);
        }

        $id = Uuid::v7();
        $this->db->table('refund_requests')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'contribution_id' => $contributionId,
            'amount_minor'    => $amountMinor,
            'currency'        => $con['currency'],
            'reason'          => $reason !== '' ? $reason : null,
            'requested_by'    => $requestedBy,
            'status'          => 'requested',
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['refund_id' => $id, 'status' => 'requested']);
    }

    /** Approve — enforces maker-checker (approver != requester). */
    public function approve(string $refundId, string $approverId): Result
    {
        $r = $this->find($refundId);
        if ($r === null) {
            return Result::notFound('refund.not_found', 'REFUND_NOT_FOUND');
        }
        if ($r['status'] !== 'requested') {
            return Result::fail('BAD_STATE', 'refund.bad_state', 409, ['status' => $r['status']]);
        }
        if ((string) $r['requested_by'] === $approverId) {
            return Result::fail('SOD_SELF_APPROVAL', 'refund.self_approval', 403);
        }

        // Re-check cumulative headroom at commit time (gap C3): two requests can
        // each pass request() validation before either is approved; approval is
        // the commit point, so re-measure against everything else already
        // committed and refuse if this one would push the total past the gift.
        $con = $this->db->table('contributions')->where('id', $r['contribution_id'])->get()->getRowArray();
        if ($con !== null) {
            $remaining = (int) $con['amount_minor'] - $this->refundedToDate((string) $r['contribution_id'], $refundId);
            if ((int) $r['amount_minor'] > $remaining) {
                return Result::fail('REFUND_EXCEEDS_REMAINING', 'refund.exceeds_remaining', 422, [
                    'remaining' => $remaining,
                    'requested' => (int) $r['amount_minor'],
                ]);
            }
        }

        $this->db->table('refund_requests')->where('id', $refundId)->update([
            'status'      => 'approved',
            'approved_by' => $approverId,
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['refund_id' => $refundId, 'status' => 'approved']);
    }

    /**
     * Execute an approved refund. Idempotent: re-executing an executed refund
     * is a no-op. Posts compensating ledger entry + stages point reversal.
     *
     * @param array<string,mixed> $txn provider_refund_id
     */
    public function execute(string $refundId, array $txn = []): Result
    {
        $r = $this->find($refundId);
        if ($r === null) {
            return Result::notFound('refund.not_found', 'REFUND_NOT_FOUND');
        }
        if ($r['status'] === 'executed') {
            return Result::ok(['refund_id' => $refundId, 'status' => 'executed'], 200, ['deduplicated' => true]);
        }
        if ($r['status'] !== 'approved') {
            return Result::fail('NOT_APPROVED', 'refund.not_approved', 409, ['status' => $r['status']]);
        }

        $orgId = $r['organization_id'];
        $now   = $this->clock->nowUtcMicro();

        $this->db->transStart();

        $this->db->table('refund_requests')->where('id', $refundId)->update([
            'status'             => 'executed',
            'provider_refund_id' => $txn['provider_refund_id'] ?? null,
            'updated_at'         => $now,
        ]);

        // Distinguish partial from full refund (gap C3): only flip the
        // contribution to `refunded` once the executed refunds reach the original
        // amount; a partial refund leaves it `succeeded` so further partials (up
        // to the remaining headroom) stay possible and the state stays truthful.
        $con         = $this->db->table('contributions')->where('id', $r['contribution_id'])->get()->getRowArray();
        $original    = $con !== null ? (int) $con['amount_minor'] : 0;
        $executedSum = $this->executedRefunds((string) $r['contribution_id']);
        if ($con !== null && $executedSum >= $original) {
            $this->db->table('contributions')->where('id', $r['contribution_id'])->update([
                'state'      => 'refunded',
                'updated_at' => $now,
            ]);
        }

        $this->db->table('payment_transactions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'contribution_id' => $r['contribution_id'],
            'provider'        => $txn['provider'] ?? 'unknown',
            'provider_txn_id' => $txn['provider_refund_id'] ?? null,
            'type'            => 'refund',
            'amount_minor'    => -1 * (int) $r['amount_minor'],
            'currency'        => $r['currency'],
            'status'          => 'succeeded',
            'created_at'      => $now,
        ]);

        // Compensating ledger entry (reverse the original contribution entry).
        // Provider-agnostic lookup (gap C1): an ONLINE gift posts its journal
        // entry with source_ref `contribution:{id}`, but a MANUAL gift posts it
        // with `manual:{id}`. The old code only looked up `contribution:` so a
        // refund of a manual gift silently skipped the reversal and left
        // cause_funds overstated. Try both formats.
        $orig = $this->db->table('journal_entries')
            ->where('organization_id', $orgId)
            ->where('source_ref', 'contribution:' . $r['contribution_id'])
            ->get()->getRowArray();
        if ($orig === null) {
            $orig = $this->db->table('journal_entries')
                ->where('organization_id', $orgId)
                ->where('source_ref', 'manual:' . $r['contribution_id'])
                ->get()->getRowArray();
        }
        if ($orig !== null) {
            // PROPORTIONAL reversal (gap C3): reverse only THIS refund's share of
            // the original entry, not the whole entry — otherwise two partial
            // refunds would each reverse the full gift and drive the ledger
            // negative. A full refund reverses the whole original exactly.
            $this->postProportionalReversal($orgId, $orig, (int) $r['amount_minor'], 'refund:' . $refundId, 'Refund executed');
        }

        // Reverse associated non-final points (FR-VBCS-007) and refresh derived
        // VBCS metrics for the giver. Carry user_id so the consumer can recompute
        // PGV/GGV/partnership without a second lookup.
        $con = $this->db->table('contributions')->select('user_id')
            ->where('id', $r['contribution_id'])->get()->getRowArray();
        $this->outbox->stage('contribution', $r['contribution_id'], 'contribution.refunded', [
            'contribution_id' => $r['contribution_id'],
            'user_id'         => $con['user_id'] ?? null,
            'source_ref'      => 'contribution:' . $r['contribution_id'],
        ], $orgId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('EXECUTE_FAILED', 'refund.execute_failed', 500);
        }

        return Result::ok(['refund_id' => $refundId, 'status' => 'executed']);
    }

    /**
     * Actionable refund requests for the maker-checker console: everything still
     * open (requested → awaiting approval, approved → awaiting execution), newest
     * first. Joined to the contribution for amount/currency context. Read side of
     * the refund console.
     *
     * @return list<array<string,mixed>>
     */
    public function pending(string $organizationId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table('refund_requests rr')
            ->select('rr.id, rr.contribution_id, rr.amount_minor, rr.currency, rr.reason, rr.requested_by, rr.approved_by, rr.status, rr.created_at, rr.updated_at, c.amount_minor AS contribution_amount_minor, c.user_id AS donor_id')
            ->join('contributions c', 'c.id = rr.contribution_id', 'left')
            ->where('rr.organization_id', $organizationId)
            ->whereIn('rr.status', ['requested', 'approved'])
            ->orderBy('rr.created_at', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function find(string $refundId): ?array
    {
        return $this->db->table('refund_requests')->where('id', $refundId)->get()->getRowArray() ?: null;
    }

    /**
     * Sum of refund amounts already COMMITTED for a contribution (gap C3):
     * requests in `approved` or `executed`. `requested` rows are deliberately
     * excluded — they are not yet committed and approve() re-validates headroom
     * at commit time. Excludes an optional refund id so approve() can measure
     * "everything committed other than me".
     */
    private function refundedToDate(string $contributionId, ?string $excludeRefundId = null): int
    {
        $q = $this->db->table('refund_requests')
            ->where('contribution_id', $contributionId)
            ->whereIn('status', ['approved', 'executed']);
        if ($excludeRefundId !== null) {
            $q->where('id !=', $excludeRefundId);
        }
        $rows = $q->get()->getResultArray();
        $sum  = 0;
        foreach ($rows as $r) {
            $sum += (int) $r['amount_minor'];
        }

        return $sum;
    }

    /**
     * Post a compensating entry that reverses THIS refund's proportional share
     * of the original contribution entry (gap C3). The original entry's total
     * debit magnitude is the gift amount; each line is scaled by
     * refundAmount/originalTotal and its direction swapped. A full refund
     * (refundAmount == originalTotal) reverses the whole entry exactly; partials
     * reverse only their slice, so successive partials sum to — never exceed —
     * the original. Falls back to a plain full reversal if the amounts can't be
     * scaled cleanly (defensive; keeps the books balanced).
     */
    private function postProportionalReversal(string $orgId, array $orig, int $refundAmount, string $sourceRef, string $memo): void
    {
        $lines = $this->db->table('journal_lines')->where('entry_id', $orig['id'])->get()->getResultArray();
        if ($lines === []) {
            return;
        }

        $originalTotal = 0;
        foreach ($lines as $l) {
            if (($l['direction'] ?? '') === 'debit') {
                $originalTotal += (int) $l['amount_minor'];
            }
        }

        // Full (or over-approximate) reversal: reverse the whole original entry.
        if ($originalTotal <= 0 || $refundAmount >= $originalTotal) {
            $this->ledger->reverseEntry($orgId, $orig['id'], 'refunded', $sourceRef, $memo);

            return;
        }

        // Partial: scale each line, swapping direction. Guard balance by making
        // the LAST line absorb any rounding remainder per side.
        $scaled       = [];
        $debitSum     = 0;
        $creditSum    = 0;
        foreach ($lines as $l) {
            $amt = (int) round((int) $l['amount_minor'] * $refundAmount / $originalTotal);
            if ($amt <= 0) {
                continue;
            }
            $swapDir = ($l['direction'] === 'debit') ? 'credit' : 'debit';
            $scaled[] = ['account' => $l['account'], 'direction' => $swapDir, 'amount_minor' => $amt];
            if ($swapDir === 'debit') {
                $debitSum += $amt;
            } else {
                $creditSum += $amt;
            }
        }
        // Rounding safety: nudge the last debit/credit so the entry balances.
        if ($scaled !== [] && $debitSum !== $creditSum) {
            $diff = $debitSum - $creditSum;
            for ($i = count($scaled) - 1; $i >= 0; $i--) {
                if ($diff > 0 && $scaled[$i]['direction'] === 'debit') {
                    $scaled[$i]['amount_minor'] -= $diff;
                    break;
                }
                if ($diff < 0 && $scaled[$i]['direction'] === 'credit') {
                    $scaled[$i]['amount_minor'] += $diff;
                    break;
                }
            }
        }

        $this->ledger->post($orgId, 'refunded', (string) $orig['currency'], $scaled, $sourceRef, [
            'cause_id'        => $orig['cause_id'] ?? null,
            'contribution_id' => $orig['contribution_id'] ?? null,
            'memo'            => $memo,
        ]);
    }

    /** Sum of EXECUTED refund amounts for a contribution (partial/full test). */
    private function executedRefunds(string $contributionId): int
    {
        $rows = $this->db->table('refund_requests')
            ->where('contribution_id', $contributionId)
            ->where('status', 'executed')
            ->get()->getResultArray();
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int) $r['amount_minor'];
        }

        return $sum;
    }
}
