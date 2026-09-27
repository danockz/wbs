<?php

declare(strict_types=1);

namespace WBS\Gamification\Sweep;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, G1): reclaim WEDGED season rollovers. A `failed`
 * rollover, or a `running` row abandoned by a crashed worker, used to pin the
 * UNIQUE transition_key for that year boundary forever — every later attempt hit
 * `ROLLOVER_IN_PROGRESS` and the season could never close. Wraps the bounded,
 * idempotent SeasonService::reclaimStuckRollovers(): it retries stuck rows past
 * the stale lease (the rollover transaction is atomic, so retry is safe) and
 * leaves genuinely in-flight rows alone.
 */
final class ReclaimRolloversSweep implements SweepContract
{
    public function key(): string
    {
        return 'gamification.reclaim-rollovers';
    }

    public function description(): string
    {
        return 'Retry failed/abandoned season rollovers so a wedged year boundary can still close.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit     = isset($options['limit']) ? (int) $options['limit'] : 100;
        $reclaimed = GamificationServices::seasons()->reclaimStuckRollovers($organizationId, $limit);

        return SweepResult::ok($reclaimed, ['reclaimed' => $reclaimed]);
    }
}
