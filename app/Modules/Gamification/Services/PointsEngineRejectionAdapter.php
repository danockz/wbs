<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use WBS\Shared\Support\Result;

/** Production ReviewRejectionPort: delegates to the real PointsEngine (G2). */
final class PointsEngineRejectionAdapter implements ReviewRejectionPort
{
    public function __construct(private readonly PointsEngine $points)
    {
    }

    public function rejectAward(string $ledgerId, string $approverId, string $reason): Result
    {
        return $this->points->rejectAward($ledgerId, $approverId, $reason);
    }
}
