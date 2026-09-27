<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers\Concerns;

/**
 * Shared helper for the group-scoped gamification config surfaces (activity
 * categories, ranks, achievements, streaks, badges, follow-up types).
 *
 * Extracted verbatim from the former GamificationController god-class so the
 * split controllers resolve the request's target group identically and feed the
 * hierarchy-aware PDP scope check the same way.
 */
trait ResolvesTargetGroup
{
    /**
     * Normalised target group id from the request body/query: a trimmed
     * non-empty string, or null for an org-wide resource.
     */
    protected function targetGroupId(): ?string
    {
        $g = $this->field('group_id');

        return $g !== null && $g !== '' ? (string) $g : null;
    }
}
