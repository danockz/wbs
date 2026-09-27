<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Event budgets and expenses with segregation of duties (SRS FR-EVT-019).
 *
 * Approve/reject/reimburse enforce actor != submitter in the service. Expense
 * approval is separate from any contribution receipt/ledger behavior.
 */
final class ExpenseController extends BaseController
{
    public function setBudget(string $eventId = '')
    {
        return $this->respondWith(EventServices::expenses()->setBudget(
            $this->orgId(),
            $eventId,
            $this->input(),
            $this->actorId(),
        ));
    }

    public function submit(string $eventId = '')
    {
        return $this->respondWith(EventServices::expenses()->submit(
            $this->orgId(),
            $eventId,
            $this->currentUserId('submitted_by'),
            $this->input(),
        ));
    }

    public function approve(string $expenseId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::expenses()->approve(
            $expenseId,
            $this->currentUserId('actor_id'),
            isset($in['approved_amount_minor']) ? (int) $in['approved_amount_minor'] : null,
            $in['note'] ?? null,
        ));
    }

    public function reject(string $expenseId = '')
    {
        return $this->respondWith(EventServices::expenses()->reject(
            $expenseId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        ));
    }

    public function reimburse(string $expenseId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::expenses()->reimburse(
            $expenseId,
            $this->currentUserId('actor_id'),
            (string) ($in['payment_reference'] ?? ''),
            $in['note'] ?? null,
        ));
    }

    /**
     * GET events/expenses — bespoke expense-APPROVALS launcher (menu landing page).
     * Shows the org-wide queue of expenses still needing an action — both
     * `submitted` (awaiting approve/reject) and `approved` (awaiting reimburse) —
     * and per line the controls for its stage. `renderForm()` mints the CSRF token
     * the webcsrf-guarded POST needs.
     */
    public function approvalsForm()
    {
        return $this->renderForm('WBS\Events\Views\expenses', [
            'expenses' => EventServices::expenses()->pendingForOrg($this->orgId(), ['submitted', 'approved']),
        ]);
    }

    /**
     * POST events/expenses — browser dispatcher for an approve / reject / reimburse
     * decision. Reads the target expense + action from the body and delegates to
     * the same ExpenseService path the id-scoped routes use (maker-checker and
     * segregation-of-duties still enforced there). Post/Redirect/Get: success or
     * failure both re-render the queue, failure carrying $error so the approver
     * sees why.
     */
    public function approvalsDispatch()
    {
        $in        = $this->input();
        $expenseId = (string) ($in['expense_id'] ?? '');
        $action    = (string) ($in['action'] ?? '');
        $actorId   = $this->currentUserId('actor_id');

        $svc = EventServices::expenses();
        if ($action === 'approve') {
            $amount = isset($in['approved_amount_minor']) && $in['approved_amount_minor'] !== ''
                ? (int) $in['approved_amount_minor'] : null;
            $result = $svc->approve($expenseId, $actorId, $amount, $in['note'] ?? null);
        } elseif ($action === 'reject') {
            $result = $svc->reject($expenseId, $actorId, $in['note'] ?? null);
        } elseif ($action === 'reimburse') {
            $result = $svc->reimburse($expenseId, $actorId, (string) ($in['payment_reference'] ?? ''), $in['note'] ?? null);
        } else {
            $result = \WBS\Shared\Support\Result::fail('BAD_ACTION', 'expense.bad_action', 422);
        }

        if (! $this->wantsJson()) {
            $view = ['expenses' => $svc->pendingForOrg($this->orgId(), ['submitted', 'approved'])];
            if (! $result->ok) {
                $view['error'] = (string) $result->message;
                $view['old']   = $in;
            } else {
                $view['message'] = 'expenses.decision_recorded';
            }

            return $this->renderForm('WBS\Events\Views\expenses', $view);
        }

        return $this->respondWith($result);
    }

    public function reconcile(string $eventId = '')
    {
        $result = EventServices::expenses()->reconcile($eventId);

        // API clients get JSON; browsers get the bespoke reconciliation view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Expense reconciliation', 'event ' . $eventId);
        }

        return $this->respondWith(
            $result,
            'WBS\Events\Views\expense_reconcile',
            null,
            ['recon' => is_array($result->data) ? $result->data : []],
        );
    }

    public function list(string $eventId = '')
    {
        $status = $this->field('status');
        $rows   = EventServices::expenses()->list($eventId, $status !== null ? (string) $status : null);

        // API clients get JSON; browsers get the bespoke expense-list view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok(['expenses' => $rows, 'count' => count($rows)]), 'Event expenses', 'event ' . $eventId);
        }

        return $this->respondWith(
            Result::ok(['expenses' => $rows, 'count' => count($rows)]),
            'WBS\Events\Views\expense_list',
            null,
            ['expenses' => $rows],
        );
    }
}
