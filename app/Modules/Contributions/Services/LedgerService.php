<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Append-only double-entry ledger (SRS FR-VBCS-005).
 *
 *  - All amounts are INTEGER minor units. Every entry must balance
 *    (sum debits == sum credits) or it is rejected.
 *  - Posted entries are NEVER edited or deleted. A correction is a compensating
 *    entry ({@see reverseEntry()}).
 *  - Posting is idempotent on (organization_id, source_ref).
 */
final class LedgerService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Post a balanced journal entry.
     *
     * @param list<array{account:string,direction:string,amount_minor:int}> $lines
     * @param array<string,mixed>                                            $meta cause_id, contribution_id, memo
     */
    public function post(string $organizationId, string $state, string $currency, array $lines, string $sourceRef, array $meta = []): Result
    {
        if ($lines === []) {
            return Result::fail('NO_LINES', 'ledger.no_lines', 422);
        }

        $debits  = 0;
        $credits = 0;
        foreach ($lines as $l) {
            $amt = (int) $l['amount_minor'];
            if ($amt <= 0) {
                return Result::fail('BAD_AMOUNT', 'ledger.bad_amount', 422);
            }
            if ($l['direction'] === 'debit') {
                $debits += $amt;
            } elseif ($l['direction'] === 'credit') {
                $credits += $amt;
            } else {
                return Result::fail('BAD_DIRECTION', 'ledger.bad_direction', 422);
            }
        }
        if ($debits !== $credits) {
            return Result::fail('UNBALANCED', 'ledger.unbalanced', 422, ['debits' => $debits, 'credits' => $credits]);
        }

        // Idempotency: entry for this source_ref already posted?
        $existing = $this->db->table('journal_entries')
            ->where('organization_id', $organizationId)->where('source_ref', $sourceRef)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['entry_id' => $existing['id']], 200, ['deduplicated' => true]);
        }

        $entryId = Uuid::v7();
        $now     = $this->clock->nowUtcMicro();

        $this->db->transStart();
        try {
            $this->db->table('journal_entries')->insert([
                'id'              => $entryId,
                'organization_id' => $organizationId,
                'cause_id'        => $meta['cause_id'] ?? null,
                'contribution_id' => $meta['contribution_id'] ?? null,
                'state'           => $state,
                'currency'        => $currency,
                'memo'            => $meta['memo'] ?? null,
                'source_ref'      => $sourceRef,
                'created_at'      => $now,
            ]);
            foreach ($lines as $l) {
                $this->db->table('journal_lines')->insert([
                    'id'           => Uuid::v7(),
                    'entry_id'     => $entryId,
                    'account'      => $l['account'],
                    'direction'    => $l['direction'],
                    'amount_minor' => (int) $l['amount_minor'],
                    'currency'     => $currency,
                    'created_at'   => $now,
                ]);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('POST_FAILED', 'ledger.post_failed', 409);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('POST_FAILED', 'ledger.post_failed', 500);
        }

        return Result::created(['entry_id' => $entryId, 'state' => $state]);
    }

    /**
     * Post a compensating entry that reverses an existing one (debits/credits
     * swapped). The original entry is untouched.
     */
    public function reverseEntry(string $organizationId, string $originalEntryId, string $state, string $sourceRef, string $memo = 'reversal'): Result
    {
        $entry = $this->db->table('journal_entries')
            ->where('id', $originalEntryId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($entry === null) {
            return Result::notFound('ledger.entry_not_found', 'ENTRY_NOT_FOUND');
        }

        $lines = $this->db->table('journal_lines')->where('entry_id', $originalEntryId)->get()->getResultArray();
        $swapped = [];
        foreach ($lines as $l) {
            $swapped[] = [
                'account'      => $l['account'],
                'direction'    => $l['direction'] === 'debit' ? 'credit' : 'debit',
                'amount_minor' => (int) $l['amount_minor'],
            ];
        }

        return $this->post($organizationId, $state, $entry['currency'], $swapped, $sourceRef, [
            'cause_id'        => $entry['cause_id'],
            'contribution_id' => $entry['contribution_id'],
            'memo'            => $memo,
        ]);
    }

    /** Net balance (credits - debits) for an account in minor units. */
    public function accountBalance(string $organizationId, string $account): int
    {
        $row = $this->db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN jl.direction = "credit" THEN jl.amount_minor ELSE 0 END),0) AS credits,
                COALESCE(SUM(CASE WHEN jl.direction = "debit"  THEN jl.amount_minor ELSE 0 END),0) AS debits
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.entry_id
             WHERE je.organization_id = ? AND jl.account = ?',
            [$organizationId, $account],
        )->getRowArray();

        return (int) ($row['credits'] ?? 0) - (int) ($row['debits'] ?? 0);
    }
}
