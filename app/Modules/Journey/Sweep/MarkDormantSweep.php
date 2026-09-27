<?php

declare(strict_types=1);

namespace WBS\Journey\Sweep;

use WBS\Journey\Config\Services as JourneyServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, M5): age member inactivity into a `dormant`
 * re-engagement state. There was no dormancy/lapsed model and no runner — a
 * member who stopped participating stayed `active` forever and the involvement
 * `cold` band was a read-only signal nothing acted on. Wraps the config-gated
 * (DEFAULT OFF), resource-light InvolvementService::processDormancy(): it reads
 * the already-materialised involvement snapshots (no per-member recompute) and
 * flips `member_journeys.dormancy_state` for cold+stale members, re-engaging any
 * who became active again. Idempotent (state-guarded transitions). `swept` =
 * members marked dormant this pass.
 */
final class MarkDormantSweep implements SweepContract
{
    public function key(): string
    {
        return 'journey.mark-dormant';
    }

    public function description(): string
    {
        return 'Mark cold/inactive members dormant and re-engage returning ones (config-gated, default off).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 5000;

        $r = JourneyServices::involvement()->processDormancy($organizationId, null, $limit);

        return SweepResult::ok((int) ($r['marked'] ?? 0), [
            'scanned'       => (int) ($r['scanned'] ?? 0),
            'marked'        => (int) ($r['marked'] ?? 0),
            'reengaged'     => (int) ($r['reengaged'] ?? 0),
            'skipped_gated' => (int) ($r['skipped_gated'] ?? 0),
        ]);
    }
}
