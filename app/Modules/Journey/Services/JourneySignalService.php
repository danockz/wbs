<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\AccessControl\Services\RuleEngine;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Rule-driven journey transitions (assessment Option C).
 *
 * This service is the bridge between real-world SIGNALS (a course completed, an
 * event attended, a follow-up outcome, a contribution, …) and the membership
 * journey. It does NOT contain any hard-coded progression logic — the rules live
 * as data in the general RuBAC engine (facet = 'membership'), authored and
 * scoped by leaders exactly like access rules. This service only:
 *
 *   1. builds a context (signal attributes + the member's CURRENT stage + phase);
 *   2. asks the shared {@see RuleEngine} which membership rules match, within the
 *      leader-authored scope of the signal's group;
 *   3. for each matched rule, reads effect_params.to_stage and applies the
 *      per-rule apply-mode:
 *        - effect 'adjust'         -> AUTO-APPLY via JourneyService (source=rule);
 *        - effect 'require_review' -> PROPOSE (queue a pending proposal);
 *        - effect 'flag'           -> PROPOSE too (a soft nudge for a leader);
 *      other effects (allow/deny) are ignored for the journey facet.
 *
 * The per-rule auto-vs-propose choice is therefore configurable per rule with no
 * new column — it reuses the effect vocabulary the rules engine already
 * validates. A rule that would move a member BACKWARDS is only honoured when its
 * effect_params carry allow_regress=true, so a stray signal cannot demote people.
 *
 * Signal action convention: 'journey.signal.<domain>.<event>', e.g.
 *   journey.signal.course.completed, journey.signal.event.attended,
 *   journey.signal.follow_up.recorded, journey.signal.contribution.verified.
 * Rules match these with exact or 'journey.signal.*' patterns.
 */
final class JourneySignalService
{
    private const APPLY_EFFECTS = ['adjust', 'require_review', 'flag'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly JourneyService $journey,
        private readonly ?RuleEngine $rules = null,
    ) {
    }

    /**
     * Ingest one signal for a member and let membership rules act on it.
     *
     * @param array<string,mixed> $data
     *   Required: user_id, action (e.g. journey.signal.course.completed)
     *   Optional: group_id (JOURNEY CONTEXT — which journey to move: NULL = the
     *             person's org-wide primary journey), scope_group_id (the group
     *             the signal ORIGINATED in, used ONLY to scope which leader rules
     *             may fire; defaults to group_id when omitted), project_code (the
     *             project that drove the signal — exposed to rules for gating and
     *             tagged onto the transition/proposal + Option-D credit),
     *             attributes (extra condition inputs), actor_id, discipler_id,
     *             evidence_type, evidence_ref
     *
     * The two group fields are deliberately distinct: an emitter (e.g. a course
     * completion in group G) usually wants to advance the member's ORG-WIDE
     * journey (group_id = null) while still letting a G-scoped leader's rule
     * match (scope_group_id = G).
     *
     * @return Result data: { applied: [...], proposed: [...], matched: int }
     */
    public function ingest(string $organizationId, array $data): Result
    {
        $userId = trim((string) ($data['user_id'] ?? ''));
        $action = trim((string) ($data['action'] ?? ''));
        if ($userId === '' || $action === '') {
            return Result::fail('BAD_SIGNAL', 'journey.bad_signal', 422, ['need' => ['user_id', 'action']]);
        }
        if ($this->rules === null) {
            return Result::fail('NO_RULE_ENGINE', 'journey.no_rule_engine', 500);
        }

        // Journey context — which journey record to move.
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;
        // Rule-scope target — which leaders' rules may fire. Defaults to the
        // journey context so existing callers keep their behaviour.
        $scopeGroupId = isset($data['scope_group_id']) && $data['scope_group_id'] !== ''
            ? (string) $data['scope_group_id']
            : $groupId;
        // Project that drove the signal (e.g. a giving cause / outreach project).
        // Exposed to rules so leaders can gate on it, and carried onto the
        // resulting transition/proposal so the move — and the Option-D credit —
        // is tagged to the project. NULL when the source is not project-attributed.
        $projectCode = isset($data['project_code']) && $data['project_code'] !== ''
            ? (string) $data['project_code']
            : null;

        // Build the condition context: caller-supplied attributes + the member's
        // current journey position, so rules can gate on "from" state.
        $current   = $this->journey->currentStage($organizationId, $userId, $groupId);
        $extraAttr = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $attributes = $extraAttr + [
            'action'         => $action,
            'user_id'        => $userId,
            'group_id'       => $groupId,
            'scope_group_id' => $scopeGroupId,
            'project_code'   => $projectCode,
            'current_stage'  => $current,
            'has_journey'    => $current !== null,
        ];

        // Evaluate membership-facet rules within the signal's ORIGINATING group
        // scope (so a group-scoped leader's rule fires for their branch), while
        // the transition itself targets the journey context ($groupId).
        $outcome = $this->rules->evaluate($organizationId, 'membership', $action, $attributes, $scopeGroupId);
        if ($outcome->matched === []) {
            return Result::ok(['matched' => 0, 'applied' => [], 'proposed' => []]);
        }

        $applied  = [];
        $proposed = [];

        foreach ($outcome->matched as $m) {
            $effect = (string) ($m['effect'] ?? '');
            if (! in_array($effect, self::APPLY_EFFECTS, true)) {
                continue; // allow/deny are not journey moves
            }
            $params  = is_array($m['effect_params'] ?? null) ? $m['effect_params'] : [];
            $toStage = trim((string) ($params['to_stage'] ?? ''));
            if ($toStage === '') {
                continue; // a journey rule must name a destination
            }

            // Direction guard: never regress unless the rule opts in.
            $direction    = $current !== null
                ? $this->journey->compareStages($organizationId, $groupId, $current, $toStage)
                : 'set';
            $allowRegress = ! empty($params['allow_regress']);
            if ($direction === 'same') {
                continue; // already there
            }
            if ($direction === 'regress' && ! $allowRegress) {
                continue;
            }

            $ruleCode = (string) ($m['code'] ?? '');
            $reason   = isset($params['reason']) && $params['reason'] !== ''
                ? (string) $params['reason']
                : ('Rule ' . $ruleCode . ' on ' . $action);

            if ($effect === 'adjust') {
                $res = $this->journey->transition($organizationId, $userId, $toStage, [
                    'group_id'      => $groupId,
                    'reason'        => $reason,
                    'actor_id'      => $data['actor_id'] ?? null,
                    'discipler_id'  => $data['discipler_id'] ?? null,
                    'evidence_type' => $data['evidence_type'] ?? 'rule',
                    'evidence_ref'  => $data['evidence_ref'] ?? $ruleCode,
                    'project_code'  => $projectCode,
                    'source'        => 'rule',
                ]);
                if (! $res->failed()) {
                    $applied[] = ['rule' => $ruleCode, 'to_stage' => $toStage, 'direction' => $direction];
                }

                continue;
            }

            // require_review / flag -> propose (deduped).
            $prop = $this->propose($organizationId, $userId, $groupId, $current, $toStage, $direction, $ruleCode, $action, ['project_code' => $projectCode] + $data);
            if ($prop !== null) {
                $proposed[] = $prop;
            }
        }

        return Result::ok([
            'matched'  => count($outcome->matched),
            'applied'  => $applied,
            'proposed' => $proposed,
        ]);
    }

    /**
     * Queue a pending proposal, unless an identical open one already exists.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null the created proposal summary, or null if deduped
     */
    private function propose(
        string $organizationId,
        string $userId,
        ?string $groupId,
        ?string $fromStage,
        string $toStage,
        string $direction,
        string $ruleCode,
        string $action,
        array $data,
    ): ?array {
        $dupQ = $this->db->table('journey_stage_proposals')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('to_stage', $toStage)
            ->where('status', 'pending');
        $groupId === null ? $dupQ->where('group_id', null) : $dupQ->where('group_id', $groupId);
        if ($dupQ->countAllResults() > 0) {
            return null;
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        try {
            $this->db->table('journey_stage_proposals')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'user_id'         => $userId,
                'group_id'        => $groupId,
                'from_stage'      => $fromStage,
                'to_stage'        => $toStage,
                'direction'       => $direction,
                'rule_code'       => $ruleCode !== '' ? mb_substr($ruleCode, 0, 120) : null,
                'signal_action'   => mb_substr($action, 0, 160),
                'evidence_type'   => isset($data['evidence_type']) && $data['evidence_type'] !== '' ? mb_substr((string) $data['evidence_type'], 0, 40) : null,
                'evidence_ref'    => isset($data['evidence_ref']) && $data['evidence_ref'] !== '' ? mb_substr((string) $data['evidence_ref'], 0, 120) : null,
                'project_code'    => isset($data['project_code']) && $data['project_code'] !== '' ? mb_substr((string) $data['project_code'], 0, 64) : null,
                'reason'          => 'Proposed by rule ' . $ruleCode . ' on ' . $action,
                'status'          => 'pending',
                'created_at'      => $now,
            ]);
        } catch (Throwable $e) {
            return null;
        }

        return ['id' => $id, 'rule' => $ruleCode, 'to_stage' => $toStage, 'direction' => $direction];
    }

    // ---- Proposal queue management -----------------------------------------

    /** Pending proposals for a context (a leader's review queue). */
    public function pendingProposals(string $organizationId, ?string $groupId = null, int $limit = 200, int $offset = 0): Result
    {
        $q = $this->db->table('journey_stage_proposals')
            ->where('organization_id', $organizationId)
            ->where('status', 'pending');
        if ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }
        $rows = $q->orderBy('created_at', 'ASC')
            ->get(max(1, min(500, $limit)), max(0, $offset))
            ->getResultArray();

        return Result::ok(['group_id' => $groupId, 'proposals' => $rows]);
    }

    /**
     * Approve a pending proposal — applies the transition (source=rule, with the
     * proposal id as evidence) and closes the proposal.
     *
     * @param array<string,mixed> $data actor_id, discipler_id, note
     */
    public function approveProposal(string $organizationId, string $proposalId, array $data = []): Result
    {
        $p = $this->db->table('journey_stage_proposals')
            ->where('organization_id', $organizationId)->where('id', $proposalId)
            ->get()->getRowArray();
        if ($p === null) {
            return Result::notFound('journey.proposal_not_found', 'PROPOSAL_NOT_FOUND');
        }
        if ((string) $p['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'journey.proposal_not_pending', 409, ['status' => $p['status']]);
        }

        $groupId = $p['group_id'] !== null && $p['group_id'] !== '' ? (string) $p['group_id'] : null;
        $toStage = (string) $p['to_stage'];

        // J6 — re-derive direction against the member's CURRENT stage at approval
        // time, not the (possibly stale) direction captured when the proposal was
        // raised. A member may have advanced since (manually, by another rule, or
        // by an earlier approval), which would make this proposal redundant or a
        // regress. Approving such a proposal must NOT silently demote or re-run a
        // move the reviewer no longer intends.
        $current = $this->journey->currentStage($organizationId, (string) $p['user_id'], $groupId);
        if ($current !== null) {
            $liveDir = $this->journey->compareStages($organizationId, $groupId, $current, $toStage);
            // Already at/after the target: nothing to do — close it as superseded
            // rather than record a no-op transition.
            if ($liveDir === 'same') {
                $this->db->table('journey_stage_proposals')->where('id', $proposalId)->where('status', 'pending')->update([
                    'status'        => 'superseded',
                    'decided_by'    => $data['actor_id'] ?? null,
                    'decided_at'    => $this->clock->nowUtcString(),
                    'decision_note' => 'Superseded on approval: member already at ' . $toStage,
                ]);

                return Result::fail('PROPOSAL_STALE', 'journey.proposal_stale', 409, [
                    'reason'        => 'already_at_target',
                    'current_stage' => $current,
                    'to_stage'      => $toStage,
                ]);
            }
            // The live move would REGRESS the member and this proposal was not a
            // deliberate regress (allow_regress): refuse the unintended demotion and
            // close the now-stale proposal so it can't be retried.
            if ($liveDir === 'regress' && (string) ($p['direction'] ?? '') !== 'regress') {
                $this->db->table('journey_stage_proposals')->where('id', $proposalId)->where('status', 'pending')->update([
                    'status'        => 'superseded',
                    'decided_by'    => $data['actor_id'] ?? null,
                    'decided_at'    => $this->clock->nowUtcString(),
                    'decision_note' => 'Superseded on approval: member advanced past ' . $toStage,
                ]);

                return Result::fail('PROPOSAL_STALE', 'journey.proposal_stale', 409, [
                    'reason'        => 'would_regress',
                    'current_stage' => $current,
                    'to_stage'      => $toStage,
                ]);
            }
        }

        $res = $this->journey->transition($organizationId, (string) $p['user_id'], $toStage, [
            'group_id'      => $groupId,
            'reason'        => $p['reason'] ?? null,
            'actor_id'      => $data['actor_id'] ?? null,
            'discipler_id'  => $data['discipler_id'] ?? ($data['actor_id'] ?? null),
            'evidence_type' => $p['evidence_type'] ?? 'proposal',
            'evidence_ref'  => $proposalId,
            // Preserve the project the proposal was raised for so the approved
            // move — and its disciple-making credit — stays tagged to it.
            'project_code'  => $p['project_code'] ?? null,
            'source'        => 'rule',
        ]);
        if ($res->failed()) {
            return $res;
        }

        $this->db->table('journey_stage_proposals')->where('id', $proposalId)->update([
            'status'        => 'approved',
            'decided_by'    => $data['actor_id'] ?? null,
            'decided_at'    => $this->clock->nowUtcString(),
            'decision_note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 255) : null,
        ]);

        return Result::ok(['id' => $proposalId, 'status' => 'approved', 'transition' => $res->data]);
    }

    /** Reject a pending proposal (no transition happens). */
    public function rejectProposal(string $organizationId, string $proposalId, array $data = []): Result
    {
        $p = $this->db->table('journey_stage_proposals')
            ->where('organization_id', $organizationId)->where('id', $proposalId)
            ->get()->getRowArray();
        if ($p === null) {
            return Result::notFound('journey.proposal_not_found', 'PROPOSAL_NOT_FOUND');
        }
        if ((string) $p['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'journey.proposal_not_pending', 409, ['status' => $p['status']]);
        }
        $this->db->table('journey_stage_proposals')->where('id', $proposalId)->update([
            'status'        => 'rejected',
            'decided_by'    => $data['actor_id'] ?? null,
            'decided_at'    => $this->clock->nowUtcString(),
            'decision_note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 255) : null,
        ]);

        return Result::ok(['id' => $proposalId, 'status' => 'rejected']);
    }
}
