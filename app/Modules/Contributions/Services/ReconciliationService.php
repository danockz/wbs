<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Ledger ↔ payment reconciliation + webhook dead-letter processing
 * (Contributions C4/C5).
 *
 * The `reconciliation_cases` table was defined but never written or read, so the
 * safety net the schema anticipated — catching C1 (out-of-balance ledger), C2
 * (dropped refunds/disputes), C3 (over-refund) drift — did not exist. This
 * service is that net. On each scheduled pass it:
 *
 *   - **missing_ledger** — finds `succeeded` contributions with NO balanced
 *     journal entry (`source_ref = contribution:<id>`), and opens a case;
 *   - **amount_mismatch** — finds contributions whose ledger entry's total debits
 *     disagree with the recorded contribution amount, and opens a case;
 *   - **dead-letters (C5)** — re-evaluates `quarantined` webhook_inbox rows that
 *     are now verified AND of a known type (e.g. a type added by a later deploy),
 *     re-queueing them for processing, and surfaces the count of webhooks still
 *     stuck in `quarantined`/`failed` so they are never lost silently.
 *
 * Case-opening is idempotent via the UNIQUE (organization_id, dedupe_ref): a
 * repeated pass never opens a duplicate case for the same discrepancy. A pass
 * also AUTO-RESOLVES open cases whose discrepancy has since cleared (e.g. the
 * missing ledger entry was later posted), so the open-case count stays truthful.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Run a full reconciliation pass for one org (or every org when null).
     * Bounded per detector by $limit. Returns headline counts.
     *
     * @return Result data: {orgs, opened, resolved, open_total, dead_letters_requeued, dead_letters_open}
     */
    public function reconcile(?string $organizationId, int $limit = 500): Result
    {
        $limit = max(1, min(5000, $limit));

        $orgIds = [];
        if ($organizationId !== null && $organizationId !== '') {
            $orgIds = [$organizationId];
        } else {
            $orgIds = array_column(
                $this->db->table('organizations')->select('id')->get()->getResultArray(),
                'id',
            );
        }

        $opened   = 0;
        $resolved = 0;
        foreach ($orgIds as $orgId) {
            $orgId = (string) $orgId;
            if ($orgId === '') {
                continue;
            }
            [$o1, $r1] = $this->reconcileMissingLedger($orgId, $limit);
            [$o2, $r2] = $this->reconcileAmountMismatch($orgId, $limit);
            $opened   += $o1 + $o2;
            $resolved += $r1 + $r2;
        }

        // C5 — webhook dead-letters are provider-scoped (webhook_inbox has no org),
        // so they are re-queued/counted globally, not per org.
        [$dlRequeued, $dlOpen] = $this->processDeadLetters($limit);

        $openTotal = $this->openCaseCount($organizationId);

        return Result::ok([
            'orgs'                  => count($orgIds),
            'opened'                => $opened,
            'resolved'             => $resolved,
            'open_total'            => $openTotal,
            'dead_letters_requeued' => $dlRequeued,
            'dead_letters_open'     => $dlOpen,
        ]);
    }

    /**
     * missing_ledger: `succeeded` contributions with no `contribution:<id>`
     * journal entry. Opens cases for current gaps; resolves prior cases now fixed.
     *
     * @return array{0:int,1:int} [opened, resolved]
     */
    private function reconcileMissingLedger(string $organizationId, int $limit): array
    {
        $missing = $this->db->query(
            'SELECT c.id, c.amount_minor, c.currency
             FROM contributions c
             LEFT JOIN journal_entries je
               ON je.organization_id = c.organization_id
              AND je.source_ref = CONCAT(?, c.id)
             WHERE c.organization_id = ?
               AND c.state = ?
               AND je.id IS NULL
             LIMIT ?',
            ['contribution:', $organizationId, 'succeeded', $limit],
        )->getResultArray();

        $opened = 0;
        foreach ($missing as $row) {
            $opened += $this->openCase(
                $organizationId,
                'missing_ledger',
                'missing_ledger:contribution:' . $row['id'],
                (string) $row['id'],
                ['amount_minor' => (int) $row['amount_minor'], 'currency' => (string) $row['currency']],
            );
        }

        // Auto-resolve: open missing_ledger cases whose ledger now exists.
        $resolved = $this->resolveClearedCases($organizationId, 'missing_ledger');

        return [$opened, $resolved];
    }

    /**
     * amount_mismatch: contributions whose ledger entry total debits disagree
     * with the recorded contribution amount.
     *
     * @return array{0:int,1:int} [opened, resolved]
     */
    private function reconcileAmountMismatch(string $organizationId, int $limit): array
    {
        $rows = $this->db->query(
            'SELECT c.id, c.amount_minor,
                    COALESCE(SUM(CASE WHEN jl.direction = "debit" THEN jl.amount_minor ELSE 0 END), 0) AS ledger_debits
             FROM contributions c
             JOIN journal_entries je
               ON je.organization_id = c.organization_id
              AND je.source_ref = CONCAT(?, c.id)
             JOIN journal_lines jl ON jl.entry_id = je.id
             WHERE c.organization_id = ?
               AND c.state = ?
             GROUP BY c.id, c.amount_minor
             HAVING ledger_debits <> c.amount_minor
             LIMIT ?',
            ['contribution:', $organizationId, 'succeeded', $limit],
        )->getResultArray();

        $opened = 0;
        foreach ($rows as $row) {
            $opened += $this->openCase(
                $organizationId,
                'amount_mismatch',
                'amount_mismatch:contribution:' . $row['id'],
                (string) $row['id'],
                [
                    'contribution_amount' => (int) $row['amount_minor'],
                    'ledger_debits'       => (int) $row['ledger_debits'],
                ],
            );
        }

        $resolved = $this->resolveClearedCases($organizationId, 'amount_mismatch');

        return [$opened, $resolved];
    }

    /**
     * Open a reconciliation case idempotently. Returns 1 if a NEW case was
     * opened, 0 if one already existed for this dedupe_ref (UNIQUE collision) or
     * a matching open case is present.
     *
     * @param array<string,mixed> $detail
     */
    private function openCase(string $organizationId, string $kind, string $dedupeRef, ?string $contributionId, array $detail): int
    {
        try {
            $this->db->table('reconciliation_cases')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'provider'        => (string) ($detail['provider'] ?? 'internal'),
                'contribution_id' => $contributionId,
                'kind'            => $kind,
                'dedupe_ref'      => $dedupeRef,
                'detail'          => json_encode($detail, JSON_UNESCAPED_UNICODE),
                'status'          => 'open',
                'created_at'      => $this->clock->nowUtcString(),
            ]);

            return 1;
        } catch (Throwable) {
            // UNIQUE(organization_id, dedupe_ref) — a case already exists. If it
            // was previously resolved but the discrepancy recurred, re-open it.
            $existing = $this->db->table('reconciliation_cases')
                ->where('organization_id', $organizationId)
                ->where('dedupe_ref', $dedupeRef)
                ->get()->getRowArray();
            if ($existing !== null && (string) $existing['status'] === 'resolved') {
                $this->db->table('reconciliation_cases')
                    ->where('id', $existing['id'])
                    ->update(['status' => 'open', 'resolved_at' => null]);
            }

            return 0;
        }
    }

    /**
     * Resolve open cases of $kind whose underlying discrepancy has cleared (e.g.
     * the missing ledger entry was later posted, or the amounts now agree). The
     * per-kind clearing check lives in {@see self::discrepancyStillExists()}.
     */
    private function resolveClearedCases(string $organizationId, string $kind): int
    {
        $open = $this->db->table('reconciliation_cases')
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->where('status', 'open')
            ->get()->getResultArray();

        $resolved = 0;
        foreach ($open as $case) {
            $contributionId = (string) ($case['contribution_id'] ?? '');
            if ($contributionId === '') {
                continue;
            }
            if (! $this->discrepancyStillExists($organizationId, $kind, $contributionId)) {
                $this->db->table('reconciliation_cases')->where('id', $case['id'])->update([
                    'status'      => 'resolved',
                    'resolved_at' => $this->clock->nowUtcString(),
                ]);
                $resolved++;
            }
        }

        return $resolved;
    }

    /** Whether the discrepancy behind an open case of $kind is still present. */
    private function discrepancyStillExists(string $organizationId, string $kind, string $contributionId): bool
    {
        if ($kind === 'missing_ledger') {
            $entry = $this->db->table('journal_entries')
                ->where('organization_id', $organizationId)
                ->where('source_ref', 'contribution:' . $contributionId)
                ->get()->getRowArray();

            return $entry === null; // still missing => still broken.
        }

        if ($kind === 'amount_mismatch') {
            $row = $this->db->query(
                'SELECT c.amount_minor,
                        COALESCE(SUM(CASE WHEN jl.direction = "debit" THEN jl.amount_minor ELSE 0 END), 0) AS ledger_debits
                 FROM contributions c
                 JOIN journal_entries je
                   ON je.organization_id = c.organization_id
                  AND je.source_ref = CONCAT(?, c.id)
                 JOIN journal_lines jl ON jl.entry_id = je.id
                 WHERE c.organization_id = ? AND c.id = ?
                 GROUP BY c.amount_minor',
                ['contribution:', $organizationId, $contributionId],
            )->getRowArray();
            if ($row === null) {
                return false; // no ledger to compare => this case no longer applies here.
            }

            return (int) $row['ledger_debits'] !== (int) $row['amount_minor'];
        }

        return true;
    }

    /**
     * C5 — webhook dead-letter processing. Re-queues `quarantined` rows that are
     * now verified AND of a known type (flips them back to `received` so the
     * normal handler can pick them up), and returns how many were re-queued plus
     * how many webhooks remain stuck in `quarantined`/`failed`.
     *
     * @return array{0:int,1:int} [requeued, stillOpen]
     */
    private function processDeadLetters(int $limit): array
    {
        $stuck = $this->db->table('webhook_inbox')
            ->whereIn('status', ['quarantined', 'failed'])
            ->orderBy('received_at', 'ASC')
            ->get($limit)->getResultArray();

        $requeued = 0;
        foreach ($stuck as $row) {
            $isRequeueable = (string) $row['status'] === 'quarantined'
                && (int) ($row['verified'] ?? 0) === 1
                && in_array((string) ($row['event_type'] ?? ''), WebhookInboxService::KNOWN_TYPES, true);
            if ($isRequeueable) {
                $this->db->table('webhook_inbox')->where('id', $row['id'])->update([
                    'status' => 'received',
                ]);
                $requeued++;
            }
        }

        $stillOpen = (int) $this->db->table('webhook_inbox')
            ->whereIn('status', ['quarantined', 'failed'])
            ->countAllResults();

        return [$requeued, $stillOpen];
    }

    /** Count of currently-open reconciliation cases (org-scoped or global). */
    public function openCaseCount(?string $organizationId): int
    {
        $q = $this->db->table('reconciliation_cases')->where('status', 'open');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }

        return (int) $q->countAllResults();
    }

    /**
     * Read surface: list open cases for an org (admin dashboard / C4).
     *
     * @return list<array<string,mixed>>
     */
    public function openCases(string $organizationId, int $limit = 100): array
    {
        return $this->db->table('reconciliation_cases')
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->orderBy('created_at', 'ASC')
            ->get(max(1, min(1000, $limit)))->getResultArray();
    }
}
