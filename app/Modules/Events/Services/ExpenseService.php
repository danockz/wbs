<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event budgets and expenses (SRS FR-EVT-019).
 *
 * Money is INTEGER minor units + currency. Expense approval is SEPARATE from
 * the VBCS contribution receipt/ledger — nothing here touches journal_entries.
 *
 * Segregation of duties (maker-checker) is enforced: a user cannot approve,
 * reject or reimburse their OWN submitted expense (actor_id must differ from
 * the submitter). Every state change appends an immutable approval trail row.
 */
final class ExpenseService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** Set (or update) the event budget. @param array<string,mixed> $data */
    public function setBudget(string $organizationId, string $eventId, array $data, ?string $actorId): Result
    {
        $amount = (int) ($data['amount_minor'] ?? 0);
        if ($amount < 0) {
            return Result::fail('BAD_AMOUNT', 'expense.bad_budget', 422);
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'expense.bad_currency', 422);
        }

        $now      = $this->clock->nowUtcString();
        $existing = $this->db->table('event_budgets')->where('event_id', $eventId)->get()->getRowArray();
        if ($existing === null) {
            $id = Uuid::v7();
            $this->db->table('event_budgets')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'currency'        => $currency,
                'amount_minor'    => $amount,
                'status'          => 'draft',
                'created_by'      => $actorId,
                'created_at'      => $now,
            ]);

            return Result::created(['budget_id' => $id, 'amount_minor' => $amount, 'currency' => $currency]);
        }

        $this->db->table('event_budgets')->where('id', $existing['id'])->update([
            'amount_minor' => $amount,
            'currency'     => $currency,
            'updated_at'   => $now,
        ]);

        return Result::ok(['budget_id' => $existing['id'], 'amount_minor' => $amount, 'currency' => $currency]);
    }

    /**
     * Submit an expense request with an optional receipt reference. The receipt
     * itself is stored as an access-controlled object elsewhere; only an opaque
     * reference is kept here.
     *
     * @param array<string,mixed> $data description, amount_minor, currency, category, allocation, receipt_ref
     */
    public function submit(string $organizationId, string $eventId, string $submittedBy, array $data): Result
    {
        if ($submittedBy === '') {
            return Result::fail('SUBMITTER_REQUIRED', 'expense.submitter_required', 422);
        }
        if (trim((string) ($data['description'] ?? '')) === '') {
            return Result::fail('DESC_REQUIRED', 'expense.description_required', 422);
        }
        $amount = (int) ($data['amount_minor'] ?? 0);
        if ($amount <= 0) {
            return Result::fail('BAD_AMOUNT', 'expense.bad_amount', 422);
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'expense.bad_currency', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_expenses')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'submitted_by'    => $submittedBy,
            'category'        => $data['category'] ?? null,
            'description'     => $data['description'],
            'currency'        => $currency,
            'amount_minor'    => $amount,
            'allocation'      => $data['allocation'] ?? null,
            'receipt_ref'     => $data['receipt_ref'] ?? null,
            'status'          => 'submitted',
            'created_at'      => $now,
        ]);

        return Result::created(['expense_id' => $id, 'status' => 'submitted', 'amount_minor' => $amount, 'currency' => $currency]);
    }

    /**
     * Approve an expense. Enforces segregation of duties (approver != submitter)
     * and records the approved amount + variance (approved - requested).
     */
    public function approve(string $expenseId, string $actorId, ?int $approvedAmountMinor = null, ?string $note = null): Result
    {
        return $this->decide($expenseId, $actorId, 'approve', $approvedAmountMinor, $note);
    }

    /** Reject an expense. Enforces segregation of duties (rejecter != submitter). */
    public function reject(string $expenseId, string $actorId, ?string $note = null): Result
    {
        return $this->decide($expenseId, $actorId, 'reject', null, $note);
    }

    /**
     * Mark an approved expense reimbursed with a payment reference. Must be a
     * different actor than the submitter; the expense must be in `approved`.
     */
    public function reimburse(string $expenseId, string $actorId, string $paymentReference, ?string $note = null): Result
    {
        if (trim($paymentReference) === '') {
            return Result::fail('PAYMENT_REF_REQUIRED', 'expense.payment_ref_required', 422);
        }

        $this->db->transStart();
        $expense = $this->db->query('SELECT * FROM event_expenses WHERE id = ? FOR UPDATE', [$expenseId])->getRowArray();
        if ($expense === null) {
            $this->db->transComplete();

            return Result::notFound('expense.not_found', 'EXPENSE_NOT_FOUND');
        }
        if ($expense['submitted_by'] === $actorId) {
            $this->db->transComplete();

            return Result::fail('SOD_VIOLATION', 'expense.sod_violation', 403);
        }
        if ($expense['status'] !== 'approved') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'expense.bad_state', 409, ['status' => $expense['status']]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_expenses')->where('id', $expenseId)->update([
            'status'            => 'reimbursed',
            'payment_reference' => $paymentReference,
            'updated_at'        => $now,
        ]);
        $this->appendApproval((string) $expense['organization_id'], $expenseId, 'reimburse', $actorId, (int) ($expense['approved_amount_minor'] ?? $expense['amount_minor']), $note, $now);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REIMBURSE_FAILED', 'expense.reimburse_failed', 500);
        }

        return Result::ok(['expense_id' => $expenseId, 'status' => 'reimbursed', 'payment_reference' => $paymentReference]);
    }

    /**
     * Final reconciliation summary: budget vs approved vs reimbursed, with
     * per-status totals and variance. Read model for organizers/finance.
     */
    public function reconcile(string $eventId): Result
    {
        $budget = $this->db->table('event_budgets')->where('event_id', $eventId)->get()->getRowArray();

        $rows = $this->db->query(
            'SELECT status,
                    COUNT(*) AS n,
                    COALESCE(SUM(amount_minor),0) AS requested,
                    COALESCE(SUM(COALESCE(approved_amount_minor, amount_minor)),0) AS decided
             FROM event_expenses WHERE event_id = ? GROUP BY status',
            [$eventId],
        )->getResultArray();

        $byStatus = [];
        $approvedTotal = 0;
        $reimbursedTotal = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']] = [
                'count'          => (int) $r['n'],
                'requested_minor' => (int) $r['requested'],
                'decided_minor'  => (int) $r['decided'],
            ];
            if ($r['status'] === 'approved') {
                $approvedTotal += (int) $r['decided'];
            }
            if ($r['status'] === 'reimbursed') {
                $reimbursedTotal += (int) $r['decided'];
            }
        }

        $budgetMinor = $budget !== null ? (int) $budget['amount_minor'] : null;
        $committed   = $approvedTotal + $reimbursedTotal;

        return Result::ok([
            'event_id'          => $eventId,
            'currency'          => $budget['currency'] ?? 'GHS',
            'budget_minor'      => $budgetMinor,
            'approved_minor'    => $approvedTotal,
            'reimbursed_minor'  => $reimbursedTotal,
            'committed_minor'   => $committed,
            'variance_minor'    => $budgetMinor !== null ? $budgetMinor - $committed : null,
            'by_status'         => $byStatus,
        ]);
    }

    /**
     * Org-wide expense queue for the APPROVALS landing page — every expense in the
     * org (optionally filtered to a status), newest first, joined to its event so
     * the approver sees which event each line belongs to without an extra lookup.
     * Read-only; the maker-checker rules still run in decide() at approve time.
     *
     * @param list<string>|null $statuses restrict to these statuses (default: submitted only)
     * @return list<array<string,mixed>>
     */
    public function pendingForOrg(string $organizationId, ?array $statuses = ['submitted'], int $limit = 200): array
    {
        $q = $this->db->table('event_expenses ee')
            ->select('ee.id, ee.event_id, ee.submitted_by, ee.category, ee.description, ee.currency, ee.amount_minor, ee.approved_amount_minor, ee.status, ee.created_at, e.title AS event_title')
            ->join('events e', 'e.id = ee.event_id', 'left')
            ->where('ee.organization_id', $organizationId);
        if ($statuses !== null && $statuses !== []) {
            $q->whereIn('ee.status', $statuses);
        }

        return $q->orderBy('ee.created_at', 'DESC')
            ->limit(max(1, min($limit, 1000)))
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function list(string $eventId, ?string $status = null): array
    {
        $q = $this->db->table('event_expenses')->where('event_id', $eventId);
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')->get()->getResultArray();
    }

    /** Shared approve/reject path with maker-checker enforcement. */
    private function decide(string $expenseId, string $actorId, string $action, ?int $approvedAmountMinor, ?string $note): Result
    {
        if ($actorId === '') {
            return Result::fail('ACTOR_REQUIRED', 'expense.actor_required', 422);
        }

        $this->db->transStart();
        $expense = $this->db->query('SELECT * FROM event_expenses WHERE id = ? FOR UPDATE', [$expenseId])->getRowArray();
        if ($expense === null) {
            $this->db->transComplete();

            return Result::notFound('expense.not_found', 'EXPENSE_NOT_FOUND');
        }
        // Segregation of duties: cannot decide your own submission.
        if ($expense['submitted_by'] === $actorId) {
            $this->db->transComplete();

            return Result::fail('SOD_VIOLATION', 'expense.sod_violation', 403);
        }
        if ($expense['status'] !== 'submitted') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'expense.bad_state', 409, ['status' => $expense['status']]);
        }

        $now = $this->clock->nowUtcMicro();
        if ($action === 'approve') {
            $approved = $approvedAmountMinor ?? (int) $expense['amount_minor'];
            if ($approved < 0) {
                $this->db->transComplete();

                return Result::fail('BAD_AMOUNT', 'expense.bad_amount', 422);
            }
            $variance = $approved - (int) $expense['amount_minor'];
            $this->db->table('event_expenses')->where('id', $expenseId)->update([
                'status'                => 'approved',
                'approved_amount_minor' => $approved,
                'variance_minor'        => $variance,
                'updated_at'            => $now,
            ]);
            $this->appendApproval((string) $expense['organization_id'], $expenseId, 'approve', $actorId, $approved, $note, $now);
            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                return Result::fail('APPROVE_FAILED', 'expense.approve_failed', 500);
            }

            return Result::ok(['expense_id' => $expenseId, 'status' => 'approved', 'approved_amount_minor' => $approved, 'variance_minor' => $variance]);
        }

        // reject
        $this->db->table('event_expenses')->where('id', $expenseId)->update([
            'status'     => 'rejected',
            'updated_at' => $now,
        ]);
        $this->appendApproval((string) $expense['organization_id'], $expenseId, 'reject', $actorId, null, $note, $now);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REJECT_FAILED', 'expense.reject_failed', 500);
        }

        return Result::ok(['expense_id' => $expenseId, 'status' => 'rejected']);
    }

    private function appendApproval(string $organizationId, string $expenseId, string $action, string $actorId, ?int $amountMinor, ?string $note, string $now): void
    {
        $this->db->table('event_expense_approvals')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'expense_id'      => $expenseId,
            'action'          => $action,
            'actor_id'        => $actorId,
            'amount_minor'    => $amountMinor,
            'note'            => $note,
            'created_at'      => $now,
        ]);
    }
}
