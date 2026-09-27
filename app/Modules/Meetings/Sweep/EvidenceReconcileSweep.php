<?php

declare(strict_types=1);

namespace WBS\Meetings\Sweep;

use WBS\Meetings\Config\Services as MeetingServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, MT2): turn unapplied meeting attendance EVIDENCE into
 * platform ATTENDANCE for streaming-policy events. Wraps the already-idempotent
 * MeetingService::reconcileEvidence() (bounded batch, `applied=0` watermark), so
 * a second pass in the same window re-processes nothing.
 */
final class EvidenceReconcileSweep implements SweepContract
{
    public function key(): string
    {
        return 'meetings.evidence-reconcile';
    }

    public function description(): string
    {
        return 'Reconcile unapplied meeting attendance evidence into platform attendance (streaming events).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $res = MeetingServices::meetings()->reconcileEvidence($organizationId, $limit);
        if (! $res->ok) {
            return SweepResult::fail((string) ($res->message ?? 'reconcile_failed'));
        }

        $data    = is_array($res->data) ? $res->data : [];
        $applied = (int) ($data['applied_attendance'] ?? 0);

        return SweepResult::ok($applied, [
            'applied_attendance' => $applied,
            'marked_advisory'    => (int) ($data['marked_advisory'] ?? 0),
            'skipped_unresolved' => (int) ($data['skipped_unresolved'] ?? 0),
        ]);
    }
}
