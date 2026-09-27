<?php

declare(strict_types=1);

namespace WBS\Contributions\Sweep;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, C4/C5): reconcile the contributions ledger against
 * recorded payments and process webhook dead-letters. `reconciliation_cases` was
 * defined but never written or read — nothing detected C1 (out-of-balance
 * ledger), C2 (dropped refunds/disputes) or C3 (over-refund) drift, and
 * quarantined/failed webhooks had no processor. Wraps the idempotent
 * ReconciliationService::reconcile(): opens/auto-resolves cases and re-queues
 * webhooks that became processable. `swept` = cases opened + dead-letters
 * re-queued this pass; details carry the full breakdown incl. the open-case
 * total for alerting.
 */
final class ReconcileLedgerSweep implements SweepContract
{
    public function key(): string
    {
        return 'contributions.reconcile-ledger';
    }

    public function description(): string
    {
        return 'Reconcile the contributions ledger against payments and process webhook dead-letters.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $res = ContributionServices::reconciliation()->reconcile($organizationId, $limit);
        if (! $res->ok) {
            return SweepResult::fail((string) ($res->message ?? 'reconcile_failed'));
        }

        $data     = is_array($res->data) ? $res->data : [];
        $opened   = (int) ($data['opened'] ?? 0);
        $requeued = (int) ($data['dead_letters_requeued'] ?? 0);

        return SweepResult::ok($opened + $requeued, [
            'opened'                => $opened,
            'resolved'              => (int) ($data['resolved'] ?? 0),
            'open_total'            => (int) ($data['open_total'] ?? 0),
            'dead_letters_requeued' => $requeued,
            'dead_letters_open'     => (int) ($data['dead_letters_open'] ?? 0),
        ]);
    }
}
