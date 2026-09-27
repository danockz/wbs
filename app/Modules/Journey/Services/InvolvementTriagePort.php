<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * The read-side contract JourneyService depends on to drive involvement-based
 * triage on the pipeline board and per-stage roster. Kept as a narrow interface
 * (rather than the concrete InvolvementService) so JourneyService stays decoupled
 * and the branch is trivially stub-able in tests.
 */
interface InvolvementTriagePort
{
    /**
     * Whether involvement-based triage is switched ON for a context (hierarchical
     * config, default off). When false, JourneyService keeps legacy time-in-stage.
     */
    public function isEnabled(string $organizationId, ?string $groupId): bool;

    /**
     * Per-stage HOT/WARM/COLD counts for a context, from the materialized
     * snapshot (one grouped read).
     *
     * @return array<string,array{hot:int,warm:int,cold:int}> keyed by stage_code
     */
    public function bandCountsByStage(string $organizationId, ?string $groupId): array;

    /**
     * Involvement snapshots for the members at a stage, keyed by user_id — the
     * band + quantum-of-work figures the roster surfaces per member.
     *
     * @return array<string,array<string,mixed>>
     */
    public function snapshotsAtStage(string $organizationId, string $stageCode, ?string $groupId): array;
}
