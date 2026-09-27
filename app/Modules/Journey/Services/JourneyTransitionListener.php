<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * Observer notified after a journey transition is durably recorded.
 *
 * Keeps JourneyService decoupled from downstream concerns (gamification credit,
 * notifications, …): the core journey logic depends only on this narrow
 * interface, and implementations live in their own service. A listener MUST be
 * side-effect-safe and MUST NOT throw — a failure to, say, award points can
 * never roll back the member's stage change.
 */
interface JourneyTransitionListener
{
    /**
     * @param array<string,mixed> $event {
     *   organization_id, journey_id, user_id, group_id, from_stage, to_stage,
     *   to_phase, direction (open|advance|regress|set), actor_id, discipler_id,
     *   evidence_type, evidence_ref, source
     * }
     */
    public function onTransition(array $event): void;
}
