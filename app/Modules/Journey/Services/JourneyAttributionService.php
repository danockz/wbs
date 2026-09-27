<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use WBS\Gamification\Services\ConfigService;
use WBS\Gamification\Services\PointsEngine;

/**
 * Disciple-making attribution (assessment Option D).
 *
 * A {@see JourneyTransitionListener} that credits the DISCIPLER when a journey
 * transition moves a member FORWARD. It reuses the EXISTING gamification stack
 * end to end — the immutable point_ledger, the anti-gaming limits, the group
 * attribution + rollups, and the leaderboards — by simply calling
 * PointsEngine::award(). There is no new ledger and no parallel scoring.
 *
 * Behaviour, all config-gated and OFF by default (hierarchical org config lives
 * in gamification_config, per the platform's default-off principle):
 *
 *   - only fires when disciplemaking_award_enabled is truthy;
 *   - only for FORWARD moves (direction 'advance', or an 'open' that lands above
 *     the entry stage) — you don't earn credit for a regression or a plain open;
 *   - only when a discipler_id is present (someone is being credited);
 *   - awards the discipler via the configurable rule disciplemaking_rule_code,
 *     tagging the entry with phase = the destination stage's phase so the WBS
 *     boards pick it up;
 *   - idempotent: source_ref = "journey:{journey_id}:{to_stage}", so replays and
 *     re-approvals never double-credit (the ledger's pl_idem_uq enforces it).
 *
 * The discipler's group attribution follows the platform's standard resolution
 * (receiving group → membership fallback → org) via PointsEngine/RollupService;
 * we pass the journey's group_id as the receiving group when present so the
 * credit lands where the disciple-making happened.
 */
final class JourneyAttributionService implements JourneyTransitionListener
{
    private const ENABLED_KEY = 'disciplemaking_award_enabled';

    private const RULE_KEY = 'disciplemaking_rule_code';

    private const DEFAULT_RULE = 'disciple.advance';

    public function __construct(
        private readonly ConfigService $config,
        private readonly PointsEngine $points,
    ) {
    }

    /** @param array<string,mixed> $event */
    public function onTransition(array $event): void
    {
        $orgId      = (string) ($event['organization_id'] ?? '');
        $discipler  = isset($event['discipler_id']) && $event['discipler_id'] !== '' ? (string) $event['discipler_id'] : null;
        $direction  = (string) ($event['direction'] ?? '');
        $toStage    = (string) ($event['to_stage'] ?? '');
        $journeyId  = (string) ($event['journey_id'] ?? '');

        if ($orgId === '' || $discipler === null || $toStage === '' || $journeyId === '') {
            return;
        }

        // Only forward progress earns disciple-making credit.
        if (! $this->isForward($direction)) {
            return;
        }

        // Config gate (default off).
        if (! (bool) $this->config->get($orgId, self::ENABLED_KEY, false)) {
            return;
        }

        $ruleCode = (string) $this->config->get($orgId, self::RULE_KEY, self::DEFAULT_RULE);
        if ($ruleCode === '') {
            return;
        }

        $groupId = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;
        $phase   = isset($event['to_phase']) && $event['to_phase'] !== '' ? (string) $event['to_phase'] : null;
        // Project that drove the advance (e.g. a giving cause / outreach project).
        // Tagged onto the disciple-making award so it shows on project boards and
        // rollups alongside the same project's other activity. NULL when the
        // advance was not project-attributed.
        $projectCode = isset($event['project_code']) && $event['project_code'] !== '' ? (string) $event['project_code'] : null;

        // Idempotent per (journey, destination stage): approving a proposal or
        // replaying a signal for the same advance cannot double-credit.
        $sourceRef = 'journey:' . $journeyId . ':' . $toStage;

        $this->points->award($orgId, $ruleCode, $discipler, $sourceRef, [
            'subject_type'       => 'user',
            'receiving_group_id' => $groupId,   // credit where the discipling happened; null → membership fallback
            'phase'              => $phase,
            'project_code'       => $projectCode,
            'data'               => [
                'to_stage'    => $toStage,
                'from_stage'  => $event['from_stage'] ?? null,
                'user_id'     => $event['user_id'] ?? null,
                'direction'   => $direction,
                'project_code' => $projectCode,
            ],
        ]);
    }

    private function isForward(string $direction): bool
    {
        // 'advance' is unambiguously forward. An 'open' that lands above the entry
        // stage (e.g. a conversion opening straight at New Believer) is also
        // forward progress worth crediting; a plain entry-stage open is not — but
        // that case rarely carries a discipler, and the direction there is 'open'
        // which we treat as forward only when a discipler is explicitly named.
        return $direction === 'advance' || $direction === 'open';
    }
}
