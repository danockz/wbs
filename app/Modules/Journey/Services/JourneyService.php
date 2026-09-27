<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Membership Journey — the first-class discipleship spine (assessment Option B).
 *
 * Responsibilities:
 *   - manage the configurable stage ladder (journey_stages);
 *   - open and advance/regress/set a person's journey (member_journeys);
 *   - record every change as immutable history (member_journey_transitions),
 *     capturing BOTH the actor and the discipler credited with the move;
 *   - answer the pastoral pipeline question ("who is at which stage") for a
 *     scope.
 *
 * Granularity is HYBRID (per the redefinition decision):
 *   - group_id = NULL  -> the person's org-wide PRIMARY journey;
 *   - group_id != NULL -> an optional per-group-context journey.
 *
 * The journey does NOT duplicate account lifecycle (Identity) or group
 * belonging (Groups); it references them. Rule-driven auto-advance is Option C
 * and is intentionally NOT wired here — the schema carries a `source` so a rule
 * engine can later record transitions with source='rule'.
 */
final class JourneyService
{
    private const PHASES = ['win', 'build', 'send', 'general'];

    private const JOURNEY_STATUSES = ['active', 'paused', 'completed', 'archived'];

    /**
     * Allowed journey-status transitions (M9). A journey's LIFECYCLE moves are now
     * guarded the same way its STAGE moves are: only these edges are legal, so a
     * nonsensical jump (e.g. archived → completed, or completed → paused) is
     * rejected rather than blind-written. Setting a status to itself is an
     * idempotent no-op. Restore/reopen paths are deliberately narrow — an
     * archived journey may only be brought back to `active`.
     *
     * @var array<string,list<string>>
     */
    private const STATUS_TRANSITIONS = [
        'active'    => ['paused', 'completed', 'archived'],
        'paused'    => ['active', 'completed', 'archived'],
        'completed' => ['active', 'archived'],
        'archived'  => ['active'],
    ];

    private const DIRECTIONS = ['advance', 'regress', 'set', 'open', 'status'];

    private const SOURCES = ['manual', 'conversion', 'rule', 'import'];

    /**
     * Triage thresholds (days a member has sat in their CURRENT stage). Fresh
     * arrivals are "hot" (momentum — recently moved), the middle band is "warm",
     * and anyone stalled beyond the warm window is "cold" and most in need of a
     * pastoral touch. Defaults chosen to be pastorally sensible; they gate the
     * birds-eye triage board, never any awarding.
     */
    private const TRIAGE_HOT_DAYS = 30;

    private const TRIAGE_WARM_DAYS = 90;

    /** The valid triage temperatures, coolest-last (hottest = most recent). */
    private const TEMPERATURES = ['hot', 'warm', 'cold'];

    /** @var list<JourneyTransitionListener> */
    private array $listeners;

    /**
     * @param list<JourneyTransitionListener> $listeners observers notified after
     *        a transition is committed (e.g. disciple-making credit). Optional so
     *        the journey core stays usable standalone.
     * @param ?InvolvementTriagePort $involvement when supplied AND switched on for
     *        a context (hierarchical config, default off), the pipeline board and
     *        per-stage roster classify HOT/WARM/COLD by member INVOLVEMENT (read
     *        from the materialized snapshot) instead of time-in-stage. When null
     *        or disabled, the legacy time-in-stage triage is used unchanged.
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
        array $listeners = [],
        private readonly ?InvolvementTriagePort $involvement = null,
        private readonly ?IntegrationGatePort $integration = null,
    ) {
        $this->listeners = $listeners;
    }

    /**
     * Whether involvement-based triage should drive this context's board/roster:
     * an InvolvementService must be wired AND the feature switched on for the
     * context. Kept private so the two read methods agree exactly.
     */
    private function useInvolvement(string $organizationId, ?string $groupId): bool
    {
        return $this->involvement !== null && $this->involvement->isEnabled($organizationId, $groupId);
    }

    // ---- Stage ladder (catalog) --------------------------------------------

    /**
     * Define (or update) a journey stage. org + optional group scoped; NULL
     * group_id = org-wide default catalog.
     *
     * @param array<string,mixed> $data code, name, phase, sort_order,
     *          description, is_terminal, is_entry, icon, color, group_id, status
     */
    public function defineStage(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_STAGE', 'journey.bad_stage', 422);
        }
        $phase = (string) ($data['phase'] ?? 'build');
        if (! in_array($phase, self::PHASES, true)) {
            return Result::fail('BAD_PHASE', 'journey.bad_phase', 422, ['allowed' => self::PHASES]);
        }

        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;
        $now     = $this->clock->nowUtcString();

        $q = $this->db->table('journey_stages')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        $payload = [
            'name'        => mb_substr($name, 0, 100),
            'phase'       => $phase,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'description' => isset($data['description']) && $data['description'] !== '' ? mb_substr((string) $data['description'], 0, 255) : null,
            'is_terminal' => ! empty($data['is_terminal']) ? 1 : 0,
            'is_entry'    => ! empty($data['is_entry']) ? 1 : 0,
            'icon'        => isset($data['icon']) && $data['icon'] !== '' ? mb_substr((string) $data['icon'], 0, 120) : null,
            'color'       => isset($data['color']) && $data['color'] !== '' ? mb_substr((string) $data['color'], 0, 20) : null,
            'status'      => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($data['status'] ?? 'active') : 'active',
            'updated_at'  => $now,
        ];

        try {
            if ($existing === null) {
                $id = Uuid::v7();
                $this->db->table('journey_stages')->insert($payload + [
                    'id'              => $id,
                    'organization_id' => $organizationId,
                    'group_id'        => $groupId,
                    'code'            => mb_substr($code, 0, 60),
                    'created_at'      => $now,
                ]);

                return Result::created(['id' => $id, 'code' => $code]);
            }

            $this->db->table('journey_stages')->where('id', $existing['id'])->update($payload);

            return Result::ok(['id' => $existing['id'], 'code' => $code]);
        } catch (Throwable $e) {
            return Result::fail('STAGE_WRITE_FAILED', 'journey.stage_write_failed', 500, ['detail' => $e->getMessage()]);
        }
    }

    /**
     * The EFFECTIVE, ordered stage ladder for a group context: the group's own
     * stages override org-wide stages of the same code; everything else falls
     * back to org-wide. Returns active stages ordered by sort_order.
     *
     * @return list<array<string,mixed>>
     */
    public function ladder(string $organizationId, ?string $groupId = null): array
    {
        $orgWide = $this->db->table('journey_stages')
            ->where('organization_id', $organizationId)
            ->where('group_id', null)
            ->where('status', 'active')
            ->get()->getResultArray();

        $byCode = [];
        foreach ($orgWide as $row) {
            $byCode[$row['code']] = $row;
        }

        if ($groupId !== null && $groupId !== '') {
            $own = $this->db->table('journey_stages')
                ->where('organization_id', $organizationId)
                ->where('group_id', $groupId)
                ->where('status', 'active')
                ->get()->getResultArray();
            foreach ($own as $row) {
                $byCode[$row['code']] = $row; // group override wins
            }
        }

        $ladder = array_values($byCode);
        usort($ladder, static fn ($a, $b) => (int) $a['sort_order'] <=> (int) $b['sort_order']);

        return $ladder;
    }

    /** Resolve one stage (group override then org-wide). @return array<string,mixed>|null */
    private function resolveStage(string $organizationId, string $code, ?string $groupId): ?array
    {
        if ($groupId !== null && $groupId !== '') {
            $own = $this->db->table('journey_stages')
                ->where('organization_id', $organizationId)->where('code', $code)
                ->where('group_id', $groupId)->where('status', 'active')
                ->get()->getRowArray();
            if ($own !== null) {
                return $own;
            }
        }

        return $this->db->table('journey_stages')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->where('group_id', null)->where('status', 'active')
            ->get()->getRowArray();
    }

    /** The configured entry stage for a context (is_entry=1), else lowest sort_order. */
    private function entryStage(string $organizationId, ?string $groupId): ?array
    {
        $ladder = $this->ladder($organizationId, $groupId);
        if ($ladder === []) {
            return null;
        }
        foreach ($ladder as $row) {
            if ((int) $row['is_entry'] === 1) {
                return $row;
            }
        }

        return $ladder[0];
    }

    // ---- Journeys -----------------------------------------------------------

    /**
     * Open a journey for a person in a context (idempotent — returns the
     * existing one if present). Records an 'open' transition.
     *
     * @param array<string,mixed> $data group_id, stage_code (optional, defaults
     *          to entry stage), source, source_ref, actor_id, discipler_id,
     *          project_code, note
     */
    public function openJourney(string $organizationId, string $userId, array $data = []): Result
    {
        $userId = trim($userId);
        if ($userId === '') {
            return Result::fail('BAD_USER', 'journey.bad_user', 422);
        }
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $existing = $this->findJourney($organizationId, $userId, $groupId);
        if ($existing !== null) {
            return Result::ok(['id' => $existing['id'], 'stage_code' => $existing['stage_code'], 'existing' => true]);
        }

        // Determine opening stage.
        $stageCode = isset($data['stage_code']) && $data['stage_code'] !== '' ? (string) $data['stage_code'] : null;
        $stage     = $stageCode !== null
            ? $this->resolveStage($organizationId, $stageCode, $groupId)
            : $this->entryStage($organizationId, $groupId);
        if ($stage === null) {
            return Result::fail('NO_STAGE', 'journey.no_stage', 422, ['detail' => 'No matching/entry stage; seed or define the ladder first.']);
        }

        $source = in_array($data['source'] ?? 'manual', self::SOURCES, true) ? (string) ($data['source'] ?? 'manual') : 'manual';
        $now    = $this->clock->nowUtcString();
        $id     = Uuid::v7();

        try {
            $this->db->transStart();
            $this->db->table('member_journeys')->insert([
                'id'               => $id,
                'organization_id'  => $organizationId,
                'user_id'          => $userId,
                'group_id'         => $groupId,
                'stage_code'       => $stage['code'],
                'stage_phase'      => $stage['phase'],
                'stage_entered_at' => $now,
                'previous_stage'   => null,
                'status'           => 'active',
                'source'           => $source,
                'source_ref'       => isset($data['source_ref']) && $data['source_ref'] !== '' ? mb_substr((string) $data['source_ref'], 0, 120) : null,
                'note'             => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 255) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $this->recordTransition($organizationId, $id, $userId, $groupId, null, (string) $stage['code'], 'open', $source, $data);
            $this->db->transComplete();
        } catch (Throwable $e) {
            return Result::fail('JOURNEY_OPEN_FAILED', 'journey.open_failed', 500, ['detail' => $e->getMessage()]);
        }

        if ($this->db->transStatus() === false) {
            return Result::fail('JOURNEY_OPEN_FAILED', 'journey.open_failed', 500);
        }

        $this->notify([
            'organization_id' => $organizationId,
            'journey_id'      => $id,
            'user_id'         => $userId,
            'group_id'        => $groupId,
            'from_stage'      => null,
            'to_stage'        => (string) $stage['code'],
            'to_phase'        => (string) $stage['phase'],
            'direction'       => 'open',
            'actor_id'        => $data['actor_id'] ?? null,
            'discipler_id'    => $data['discipler_id'] ?? null,
            'evidence_type'   => $data['evidence_type'] ?? null,
            'evidence_ref'    => $data['evidence_ref'] ?? null,
            'project_code'    => $data['project_code'] ?? null,
            'source'          => $source,
        ]);

        return Result::created(['id' => $id, 'stage_code' => $stage['code'], 'stage_phase' => $stage['phase']]);
    }

    /**
     * Move a person to a target stage (opening the journey first if needed).
     * Direction is derived from the ladder order (advance/regress/set).
     *
     * @param array<string,mixed> $data group_id, reason, actor_id, discipler_id,
     *          evidence_type, evidence_ref, project_code, source
     */
    public function transition(string $organizationId, string $userId, string $toStageCode, array $data = []): Result
    {
        $userId      = trim($userId);
        $toStageCode = trim($toStageCode);
        if ($userId === '' || $toStageCode === '') {
            return Result::fail('BAD_TRANSITION', 'journey.bad_transition', 422);
        }
        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $target = $this->resolveStage($organizationId, $toStageCode, $groupId);
        if ($target === null) {
            return Result::fail('NO_STAGE', 'journey.unknown_stage', 422, ['stage' => $toStageCode]);
        }

        // Integration gate (FR-REF-3b): entering a gated stage (default
        // In Foundation / Established) requires the member to be integrated —
        // the standalone dated decisions all present (salvation, water
        // baptism, Holy Spirit baptism, foundation course). Config-gated and
        // default OFF; a
        // missing gate (null) or an OFF policy means the move proceeds exactly
        // as before. This is the SINGLE choke point every advance funnels
        // through (auto-apply rules, proposal approval, manual moves).
        if ($this->integration !== null) {
            $blocked = $this->integration->gate($organizationId, $userId, (string) $target['code'], $groupId);
            if ($blocked !== null) {
                return Result::fail('INTEGRATION_REQUIRED', 'journey.integration_required', 409, $blocked);
            }
        }

        $journey = $this->findJourney($organizationId, $userId, $groupId);
        if ($journey === null) {
            // Open at the target directly.
            return $this->openJourney($organizationId, $userId, ['group_id' => $groupId, 'stage_code' => $toStageCode] + $data);
        }

        $fromCode = (string) $journey['stage_code'];
        if ($fromCode === $target['code']) {
            return Result::ok(['id' => $journey['id'], 'stage_code' => $fromCode, 'unchanged' => true]);
        }

        $direction = $this->direction($organizationId, $groupId, $fromCode, (string) $target['code']);
        $source    = in_array($data['source'] ?? 'manual', self::SOURCES, true) ? (string) ($data['source'] ?? 'manual') : 'manual';
        $now       = $this->clock->nowUtcString();

        // Reaching the terminal stage does NOT complete/close the journey — a
        // Sender/Multiplier is still very much an ACTIVE member and must remain
        // visible in the pipeline. `completed`/`archived` are explicit closes
        // done via setStatus(). Re-activate if a paused journey is moved.
        $status = in_array((string) $journey['status'], ['completed', 'archived'], true)
            ? (string) $journey['status']
            : 'active';

        try {
            $this->db->transStart();
            $this->db->table('member_journeys')->where('id', $journey['id'])->update([
                'stage_code'       => $target['code'],
                'stage_phase'      => $target['phase'],
                'previous_stage'   => $fromCode,
                'stage_entered_at' => $now,
                'status'           => $status,
                'source'           => $source,
                'source_ref'       => isset($data['evidence_ref']) && $data['evidence_ref'] !== '' ? mb_substr((string) $data['evidence_ref'], 0, 120) : $journey['source_ref'],
                'updated_at'       => $now,
            ]);
            $this->recordTransition($organizationId, (string) $journey['id'], $userId, $groupId, $fromCode, (string) $target['code'], $direction, $source, $data);
            $this->db->transComplete();
        } catch (Throwable $e) {
            return Result::fail('TRANSITION_FAILED', 'journey.transition_failed', 500, ['detail' => $e->getMessage()]);
        }

        if ($this->db->transStatus() === false) {
            return Result::fail('TRANSITION_FAILED', 'journey.transition_failed', 500);
        }

        $this->notify([
            'organization_id' => $organizationId,
            'journey_id'      => (string) $journey['id'],
            'user_id'         => $userId,
            'group_id'        => $groupId,
            'from_stage'      => $fromCode,
            'to_stage'        => (string) $target['code'],
            'to_phase'        => (string) $target['phase'],
            'direction'       => $direction,
            'actor_id'        => $data['actor_id'] ?? null,
            'discipler_id'    => $data['discipler_id'] ?? null,
            'evidence_type'   => $data['evidence_type'] ?? null,
            'evidence_ref'    => $data['evidence_ref'] ?? null,
            'project_code'    => $data['project_code'] ?? null,
            'source'          => $source,
        ]);

        return Result::ok([
            'id'         => $journey['id'],
            'from_stage' => $fromCode,
            'to_stage'   => $target['code'],
            'direction'  => $direction,
            'phase'      => $target['phase'],
            'status'     => $status,
        ]);
    }

    /**
     * Pause / resume / complete / archive a journey without changing stage.
     *
     * M9: the LIFECYCLE move is now guarded (only edges in STATUS_TRANSITIONS are
     * legal — the same discipline stage moves already have) AND recorded as an
     * immutable history row (`member_journey_transitions` with direction=`status`,
     * from_stage=to_stage=the current stage since the stage does not move), so a
     * journey's status changes are as auditable as its stage changes. A no-op
     * (status already == target) short-circuits without a history row.
     *
     * @param array<string,mixed> $data optional reason, actor_id
     */
    public function setStatus(string $organizationId, string $userId, string $status, ?string $groupId = null, array $data = []): Result
    {
        if (! in_array($status, self::JOURNEY_STATUSES, true)) {
            return Result::fail('BAD_STATUS', 'journey.bad_status', 422, ['allowed' => self::JOURNEY_STATUSES]);
        }
        $journey = $this->findJourney($organizationId, $userId, $groupId);
        if ($journey === null) {
            return Result::notFound('journey.not_found', 'JOURNEY_NOT_FOUND');
        }

        $current = (string) ($journey['status'] ?? 'active');
        // Idempotent no-op — the journey is already in the requested status.
        if ($current === $status) {
            return Result::ok(['id' => $journey['id'], 'status' => $status], 200, ['unchanged' => true]);
        }
        // Guard: only allow legal lifecycle edges (mirrors stage-move discipline).
        if (! in_array($status, self::STATUS_TRANSITIONS[$current] ?? [], true)) {
            return Result::fail('BAD_STATUS_TRANSITION', 'journey.bad_status_transition', 409, [
                'from'    => $current,
                'to'      => $status,
                'allowed' => self::STATUS_TRANSITIONS[$current] ?? [],
            ]);
        }

        $this->db->table('member_journeys')->where('id', $journey['id'])->update([
            'status'     => $status,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        // Immutable evidence row. The stage does not move, so from == to == the
        // current stage; direction='status' keeps these OUT of the stage-movement
        // analytics (funnel momentum / discipler leaderboard only count
        // advance/open/set).
        $stageCode = (string) ($journey['stage_code'] ?? '');
        $this->recordTransition(
            $organizationId,
            (string) $journey['id'],
            $userId,
            $groupId,
            $stageCode !== '' ? $stageCode : null,
            $stageCode !== '' ? $stageCode : $status,
            'status',
            'manual',
            [
                'reason'   => $data['reason'] ?? ('status: ' . $current . ' -> ' . $status),
                'actor_id' => $data['actor_id'] ?? null,
            ],
        );

        return Result::ok(['id' => $journey['id'], 'status' => $status, 'from' => $current]);
    }

    // ---- Lifecycle-signal consumers (Theme B — J4) --------------------------

    /**
     * Pause every ACTIVE journey a person holds across all contexts (Theme B, J4).
     *
     * Invoked by the JobRouter on account deactivate/suspend: a gone/frozen
     * person's journeys must stop appearing in every pipeline/funnel/leaderboard.
     * SYSTEM authority (no scope check — the account is gone) and idempotent (only
     * `active` rows flip), so a redelivered teardown event is a no-op. Completed /
     * archived journeys are left as-is.
     *
     * @return int number of journeys paused
     */
    public function pauseAllForSubject(string $organizationId, string $userId, string $reasonCode): int
    {
        if ($organizationId === '' || $userId === '') {
            return 0;
        }
        $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->update([
                'status'     => 'paused',
                'note'       => substr('teardown: ' . $reasonCode, 0, 255),
                'updated_at' => $this->clock->nowUtcString(),
            ]);

        return max(0, (int) $this->db->affectedRows());
    }

    /**
     * RESUME every journey a person had PAUSED BY a teardown (Theme B — M10, the
     * inverse of {@see pauseAllForSubject}). Invoked by the JobRouter on
     * `account.reactivated`: when a suspended/deactivated account comes back, the
     * journeys the teardown froze should un-freeze so the person re-appears in
     * pipelines/funnels/leaderboards where they left off.
     *
     * Only rows the teardown itself paused are resumed — identified by the
     * `teardown:` note marker `pauseAllForSubject` stamped. A journey the member
     * paused/archived/completed for other reasons is left exactly as-is (a
     * reactivation must not silently resurrect an unrelated state). The note is
     * cleared on resume. SYSTEM authority + idempotent (a re-run finds no
     * teardown-paused rows and resumes 0).
     *
     * @return int number of journeys resumed
     */
    public function resumeAllForSubject(string $organizationId, string $userId): int
    {
        if ($organizationId === '' || $userId === '') {
            return 0;
        }
        $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'paused')
            ->like('note', 'teardown:', 'after')
            ->update([
                'status'     => 'active',
                'note'       => null,
                'updated_at' => $this->clock->nowUtcString(),
            ]);

        return max(0, (int) $this->db->affectedRows());
    }

    /**
     * Re-point a merged person's journeys to the survivor (Theme B, J4 merge half).
     *
     * On `account.merged` the loser's journeys must not be abandoned. For each
     * loser journey we move it to the survivor UNLESS the survivor already has a
     * journey in that same context (org-wide or a given group) — the
     * (org, user, group) uniqueness constraint would collide — in which case the
     * loser journey is ARCHIVED instead (the survivor's own progress wins; no
     * silent overwrite of their stage). Never a blind cross-identity rewrite of
     * unrelated belongings — only the journey rows keyed to the loser. Idempotent.
     *
     * @return array{repointed:int, archived_dupes:int}
     */
    public function reassignForMerge(string $organizationId, string $loserUserId, string $survivorUserId): array
    {
        if ($organizationId === '' || $loserUserId === '' || $survivorUserId === '' || $loserUserId === $survivorUserId) {
            return ['repointed' => 0, 'archived_dupes' => 0];
        }

        $loserJourneys = $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $loserUserId)
            ->get()->getResultArray();

        $now = $this->clock->nowUtcString();
        $repointed = 0;
        $archivedDupes = 0;
        foreach ($loserJourneys as $j) {
            $groupId = $j['group_id'] ?? null;
            $survivorHas = $this->findJourney($organizationId, $survivorUserId, $groupId !== null ? (string) $groupId : null);
            if ($survivorHas !== null) {
                // Context collision: keep the survivor's own journey, archive the
                // loser's so history is retained but it stops resolving.
                $this->db->table('member_journeys')->where('id', $j['id'])->update([
                    'status'     => 'archived',
                    'note'       => substr('merged into ' . $survivorUserId . ' (survivor had a journey here)', 0, 255),
                    'updated_at' => $now,
                ]);
                $archivedDupes++;
                continue;
            }
            $this->db->table('member_journeys')->where('id', $j['id'])->update([
                'user_id'    => $survivorUserId,
                'note'       => substr('re-pointed from merged ' . $loserUserId, 0, 255),
                'updated_at' => $now,
            ]);
            $repointed++;
        }

        return ['repointed' => $repointed, 'archived_dupes' => $archivedDupes];
    }

    /**
     * Archive every ACTIVE or PAUSED journey scoped to a torn-down group
     * (Theme B group-half, J4-group).
     *
     * Invoked by the JobRouter on `group.dissolved` / `group.merged`: a group's
     * OWN journey context is gone, so those group-scoped journeys must stop
     * appearing in that branch's pipelines/funnels/leaderboards. Only journeys
     * whose `group_id` IS the dead group are touched — org-wide (NULL group)
     * journeys and other groups' journeys are left alone. `completed`/`archived`
     * rows are left as-is. SYSTEM authority (the group is gone — no scope check),
     * idempotent (a re-run archives 0). Empty inputs -> 0.
     *
     * NOTE: this is deliberately ARCHIVE, not re-point-to-survivor on merge — a
     * member's discipleship stage is context-specific and personal, so it is not
     * silently transplanted into the survivor group's ladder; history is retained
     * (archived, not deleted) and the member re-enters the survivor context
     * through the ordinary open/advance path.
     *
     * @return int number of journeys archived
     */
    public function archiveGroupContextJourneys(string $organizationId, string $groupId, string $reasonCode): int
    {
        if ($organizationId === '' || $groupId === '') {
            return 0;
        }
        $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->whereIn('status', ['active', 'paused'])
            ->update([
                'status'     => 'archived',
                'note'       => substr('group teardown: ' . $reasonCode, 0, 255),
                'updated_at' => $this->clock->nowUtcString(),
            ]);

        return max(0, (int) $this->db->affectedRows());
    }

    // ---- Reads --------------------------------------------------------------

    /** A person's journey in a context, with its transition history. */
    public function getJourney(string $organizationId, string $userId, ?string $groupId = null): Result
    {
        $journey = $this->findJourney($organizationId, $userId, $groupId);
        if ($journey === null) {
            return Result::notFound('journey.not_found', 'JOURNEY_NOT_FOUND');
        }
        $journey['history'] = $this->historyFor($organizationId, (string) $journey['id']);

        return Result::ok($journey);
    }

    /** All journeys for a person across every context. @return list<array> via Result */
    public function journeysForUser(string $organizationId, string $userId): Result
    {
        $rows = $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->orderBy('group_id', 'ASC')
            ->get()->getResultArray();

        return Result::ok(['journeys' => $rows]);
    }

    /**
     * Pipeline view: a birds-eye triage board of active members by stage for a
     * context. When $groupId is null this is org-wide primary journeys;
     * otherwise the given group context. Ordered by the ladder.
     *
     * Beyond the raw per-stage headcount, each stage is split into a HOT / WARM
     * / COLD triage by how long the member has sat in their CURRENT stage
     * (`stage_entered_at`): recent arrivals still have momentum (hot), the
     * middle band is warm, and anyone stalled past the warm window is cold and
     * most in need of a pastoral follow-up. This turns the pipeline from a plain
     * headcount into the downline "who needs attention" view.
     *
     * RESOURCE-LIGHT: the temperature split is computed with two additional
     * SQL-side grouped-COUNT queries (hot, warm) bounded by date cutoffs — cold
     * is derived by subtraction — so the whole board is THREE aggregate reads
     * regardless of how many members exist (never a per-member transfer).
     */
    public function pipeline(string $organizationId, ?string $groupId = null): Result
    {
        $ladder = $this->ladder($organizationId, $groupId);

        // Base grouped query builder for the active journeys in this context.
        $base = function () use ($organizationId, $groupId) {
            $q = $this->db->table('member_journeys')
                ->select('stage_code, stage_phase, COUNT(*) AS total')
                ->where('organization_id', $organizationId)
                ->where('status', 'active');
            $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

            return $q;
        };

        $counts = [];
        foreach ($base()->groupBy('stage_code, stage_phase')->get()->getResultArray() as $row) {
            $counts[$row['stage_code']] = (int) $row['total'];
        }

        // The HOT/WARM/COLD split per stage. When involvement-based triage is
        // switched on for this context, read the pre-classified bands from the
        // materialized snapshot (ONE grouped query, no per-member fan-out);
        // otherwise fall back to the legacy time-in-stage split (two more
        // date-bounded grouped COUNTs, cold by subtraction).
        $hot           = [];
        $warm          = [];
        $byInvolvement = $this->useInvolvement($organizationId, $groupId);

        if ($byInvolvement) {
            foreach ($this->involvement->bandCountsByStage($organizationId, $groupId) as $code => $bands) {
                $hot[$code]  = (int) ($bands['hot'] ?? 0);
                $warm[$code] = (int) ($bands['warm'] ?? 0);
            }
        } else {
            // Temperature cutoffs: entered on/after $hotCutoff = hot; on/after
            // $warmCutoff (but before $hotCutoff) = warm; earlier = cold.
            ['hot' => $hotCutoff, 'warm' => $warmCutoff] = $this->triageCutoffs();

            foreach (
                $base()->where('stage_entered_at >=', $hotCutoff)
                    ->groupBy('stage_code, stage_phase')->get()->getResultArray() as $row
            ) {
                $hot[$row['stage_code']] = (int) $row['total'];
            }

            foreach (
                $base()->where('stage_entered_at >=', $warmCutoff)
                    ->where('stage_entered_at <', $hotCutoff)
                    ->groupBy('stage_code, stage_phase')->get()->getResultArray() as $row
            ) {
                $warm[$row['stage_code']] = (int) $row['total'];
            }
        }

        $out    = [];
        $total  = 0;
        $totals = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        foreach ($ladder as $stage) {
            $code = (string) $stage['code'];
            $n    = $counts[$code] ?? 0;
            $h    = min($hot[$code] ?? 0, $n);
            $w    = min($warm[$code] ?? 0, max(0, $n - $h));
            $c    = max(0, $n - $h - $w);
            $total += $n;
            $totals['hot']  += $h;
            $totals['warm'] += $w;
            $totals['cold'] += $c;
            $out[] = [
                'code'  => $code,
                'name'  => $stage['name'],
                'phase' => $stage['phase'],
                'order' => (int) $stage['sort_order'],
                'count' => $n,
                'hot'   => $h,
                'warm'  => $w,
                'cold'  => $c,
            ];
        }

        return Result::ok([
            'group_id'    => $groupId,
            'total'       => $total,
            'triage'      => $totals,
            'triage_mode' => $byInvolvement ? 'involvement' : 'time_in_stage',
            'stages'      => $out,
        ]);
    }

    /**
     * The triage cutoff timestamps for "now": a journey whose stage_entered_at
     * is >= hot is HOT, >= warm (but < hot) is WARM, earlier is COLD. Shared by
     * the pipeline board and the per-stage roster so both agree exactly.
     *
     * @return array{hot:string,warm:string}
     */
    private function triageCutoffs(): array
    {
        $now = $this->clock->now();

        return [
            'hot'  => $now->modify('-' . self::TRIAGE_HOT_DAYS . ' days')->format('Y-m-d H:i:s'),
            'warm' => $now->modify('-' . self::TRIAGE_WARM_DAYS . ' days')->format('Y-m-d H:i:s'),
        ];
    }

    /** Classify one stage-entry timestamp into a triage temperature. */
    private function temperatureOf(?string $enteredAt, string $hotCutoff, string $warmCutoff): string
    {
        $enteredAt = (string) $enteredAt;
        if ($enteredAt >= $hotCutoff) {
            return 'hot';
        }
        if ($enteredAt >= $warmCutoff) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * The roster of members currently at a given stage in a context — the
     * drill-down behind a pipeline cell. Each row is enriched with the member's
     * display name (ONE batched users read) and a per-member triage
     * `temperature` (hot|warm|cold) computed from how long they have sat in the
     * stage, so a leader can work the coldest first. Ordered coldest-first
     * (longest in stage) by default so the people most needing a follow-up rise
     * to the top.
     *
     * An optional $temperature filter narrows to a single band; it is applied to
     * the FULL matching set before the limit so paging stays honest.
     */
    public function membersAtStage(string $organizationId, string $stageCode, ?string $groupId = null, int $limit = 200, int $offset = 0, ?string $temperature = null): Result
    {
        $q = $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('stage_code', $stageCode)
            ->where('status', 'active');
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        // Coldest first: oldest stage_entered_at at the top (most in need).
        $rows = $q->orderBy('stage_entered_at', 'ASC')->get()->getResultArray();

        ['hot' => $hotCutoff, 'warm' => $warmCutoff] = $this->triageCutoffs();
        $temperature   = $temperature !== null && in_array($temperature, self::TEMPERATURES, true) ? $temperature : null;
        $byInvolvement = $this->useInvolvement($organizationId, $groupId);

        // When involvement-based triage is on, pull the pre-computed snapshots for
        // this stage once (band + quantum-of-work figures the roster surfaces).
        $snaps = $byInvolvement ? $this->involvement->snapshotsAtStage($organizationId, $stageCode, $groupId) : [];

        // Tag band/temperature + (optionally) filter to one band, BEFORE paging.
        $tagged = [];
        foreach ($rows as $r) {
            if ($byInvolvement) {
                $snap = $snaps[(string) ($r['user_id'] ?? '')] ?? null;
                $temp = $snap !== null ? (string) $snap['band'] : 'cold';
                if ($temperature !== null && $temp !== $temperature) {
                    continue;
                }
                $r['temperature'] = $temp;
                // Surface the quantum-of-work + participation figures per member.
                $r['involvement'] = $snap !== null ? [
                    'band'              => (string) $snap['band'],
                    'participation_bps' => (int) $snap['participation_bps'],
                    'activity_count'    => (int) $snap['activity_count'],
                    'activity_target'   => (int) $snap['activity_target'],
                    'last_activity_at'  => $snap['last_activity_at'] ?? null,
                    'sponsorship_count' => (int) $snap['sponsorship_count'],
                    'giving_minor'      => (int) $snap['giving_minor'],
                    'points'            => (int) $snap['points'],
                    'quantum'           => (int) $snap['quantum'],
                    'downline_quantum'  => (int) $snap['downline_quantum'],
                ] : null;
            } else {
                $temp = $this->temperatureOf($r['stage_entered_at'] ?? null, $hotCutoff, $warmCutoff);
                if ($temperature !== null && $temp !== $temperature) {
                    continue;
                }
                $r['temperature'] = $temp;
            }
            $r['entered_at'] = $r['stage_entered_at'] ?? null; // presenter key
            $tagged[]        = $r;
        }

        // Involvement mode ranks the LEAST-involved (lowest quantum) first — the
        // people most needing attention rise to the top, mirroring coldest-first.
        if ($byInvolvement) {
            usort($tagged, static function (array $a, array $b): int {
                return ((int) ($a['involvement']['quantum'] ?? 0)) <=> ((int) ($b['involvement']['quantum'] ?? 0));
            });
        }

        $matched = count($tagged);
        $page    = array_slice($tagged, max(0, $offset), max(1, min(500, $limit)));

        // Names — ONE batched read over the paged user ids.
        $userIds = [];
        foreach ($page as $r) {
            $uid = (string) ($r['user_id'] ?? '');
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
        }
        $names = [];
        if ($userIds !== []) {
            foreach (
                $this->db->table('users')->select('id, display_name')
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', array_keys($userIds))
                    ->get()->getResultArray() as $u
            ) {
                $names[(string) $u['id']] = (string) ($u['display_name'] ?? '');
            }
        }
        foreach ($page as $i => $r) {
            $page[$i]['display_name'] = $names[(string) ($r['user_id'] ?? '')] ?? '';
        }

        return Result::ok([
            'stage_code'  => $stageCode,
            'group_id'    => $groupId,
            'temperature' => $temperature,
            'triage_mode' => $byInvolvement ? 'involvement' : 'time_in_stage',
            'matched'     => $matched,
            'members'     => $page,
        ]);
    }

    /**
     * Discipleship FUNNEL & progression report — the reporting gap named in the
     * activities/membership-journey assessment (§3: "no journey/stage
     * progression to report on or rank by"). Where the pipeline board answers
     * "who is at each stage right now and who is stalled", this answers the
     * cohort question: "how far along the discipleship ladder does the body
     * progress, and where does it thin out?".
     *
     * Two complementary, honest lenses — both computed from AGGREGATES only:
     *
     *  1. CURRENT-STATE FUNNEL (monotonic by construction). For each stage, in
     *     ladder order, `at_or_beyond` = the number of active members currently
     *     at that stage OR any later stage (Σ current[j] for j ≥ i). A member
     *     who is a Leader has, by definition, progressed past New-Believer, so
     *     this is a truthful non-increasing funnel that needs only the current
     *     distribution. `conversion_pct` = the share of a stage's at-or-beyond
     *     cohort that progressed PAST it (at_or_beyond[next] ÷ at_or_beyond[i]);
     *     `current` (members sitting at exactly this stage) is the stall count,
     *     and `stall_pct` its share of the cohort — this is where people thin out.
     *
     *  2. RECENT MOMENTUM (flow, from the trail). `moves_in` / `movers_in` =
     *     arrivals INTO each stage within the window (advance/open/set
     *     transitions), so a leader sees not just the static shape but where
     *     movement is happening lately.
     *
     * RESOURCE-LIGHT: exactly TWO grouped-COUNT aggregate reads regardless of
     * how many members or transitions exist (one over member_journeys for the
     * distribution, one over the transition trail for the window's arrivals) —
     * never a per-member transfer. Scope: org-wide primary journeys when
     * $groupId is null, else the given group context (matches pipeline()).
     *
     * @return Result data: {group_id, window_days, total_active, moves_in_window,
     *   stages: list<{code,name,phase,order,current,at_or_beyond,reach_pct,
     *   conversion_pct,stall_pct,is_last,moves_in,movers_in}>}
     */
    public function funnel(string $organizationId, ?string $groupId = null, int $windowDays = 90): Result
    {
        $windowDays = max(1, min(3650, $windowDays));
        $ladder     = $this->ladder($organizationId, $groupId);

        // (1) Current distribution — ONE grouped aggregate over active journeys.
        $curQ = $this->db->table('member_journeys')
            ->select('stage_code, COUNT(*) AS total')
            ->where('organization_id', $organizationId)
            ->where('status', 'active');
        $groupId === null ? $curQ->where('group_id', null) : $curQ->where('group_id', $groupId);
        $current = [];
        foreach ($curQ->groupBy('stage_code')->get()->getResultArray() as $row) {
            $current[(string) $row['stage_code']] = (int) $row['total'];
        }

        // (2) Recent momentum — ONE grouped aggregate over the transition trail:
        // arrivals INTO each stage within the window (advance/open/set land here).
        $cutoff = $this->clock->now()->modify('-' . $windowDays . ' days')->format('Y-m-d H:i:s');
        $movQ   = $this->db->table('member_journey_transitions')
            ->select('to_stage, COUNT(*) AS moves, COUNT(DISTINCT user_id) AS movers')
            ->where('organization_id', $organizationId)
            ->whereIn('direction', ['advance', 'open', 'set'])
            ->where('created_at >=', $cutoff);
        $groupId === null ? $movQ->where('group_id', null) : $movQ->where('group_id', $groupId);
        $movesIn  = [];
        $moversIn = [];
        foreach ($movQ->groupBy('to_stage')->get()->getResultArray() as $row) {
            $movesIn[(string) $row['to_stage']]  = (int) $row['moves'];
            $moversIn[(string) $row['to_stage']] = (int) ($row['movers'] ?? 0);
        }

        // Cumulative "at or beyond": accumulate from the LAST ladder stage back
        // to the first, so each stage's figure includes every later stage.
        $n          = count($ladder);
        $atOrBeyond = array_fill(0, max(1, $n), 0);
        $running    = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            $running       += $current[(string) $ladder[$i]['code']] ?? 0;
            $atOrBeyond[$i] = $running;
        }
        $totalActive = $running; // == at_or_beyond[0] (everyone active in context)

        $stages        = [];
        $movesInWindow = 0;
        foreach ($ladder as $i => $s) {
            $code      = (string) $s['code'];
            $cur       = $current[$code] ?? 0;
            $reach     = $atOrBeyond[$i];
            $isLast    = $i + 1 >= $n;
            $nextReach = $isLast ? 0 : $atOrBeyond[$i + 1];
            $mv        = $movesIn[$code] ?? 0;
            $movesInWindow += $mv;
            $stages[] = [
                'code'           => $code,
                'name'           => (string) ($s['name'] ?? $code),
                'phase'          => (string) ($s['phase'] ?? ''),
                'order'          => (int) ($s['sort_order'] ?? 0),
                'current'        => $cur,
                'at_or_beyond'   => $reach,
                'reach_pct'      => $totalActive > 0 ? round($reach * 100 / $totalActive, 1) : 0.0,
                // Share of this stage's cohort that progressed PAST it.
                'conversion_pct' => ($isLast || $reach <= 0) ? 0.0 : round($nextReach * 100 / $reach, 1),
                // Share of this stage's cohort that has stalled at exactly here.
                'stall_pct'      => $reach > 0 ? round($cur * 100 / $reach, 1) : 0.0,
                'is_last'        => $isLast,
                'moves_in'       => $mv,
                'movers_in'      => $moversIn[$code] ?? 0,
            ];
        }

        return Result::ok([
            'group_id'        => $groupId,
            'window_days'     => $windowDays,
            'total_active'    => $totalActive,
            'moves_in_window' => $movesInWindow,
            'stages'          => $stages,
        ]);
    }

    /**
     * Disciple-making leaderboard (Option D): rank disciplers by how many people
     * they have MOVED FORWARD. This reads the immutable transition trail directly
     * — a metric the point ledger cannot express (points reward activities;
     * this rewards moving distinct people up the ladder).
     *
     * Counts only forward transitions (advance / open) that name a discipler.
     * Scope: org-wide when $groupId is null, else that journey context.
     *
     * @param array{phase?:string,since?:string} $filters optional: restrict to a
     *        destination phase (win|build|send) or to transitions since a date.
     */
    public function disciplerLeaderboard(string $organizationId, ?string $groupId = null, int $limit = 20, array $filters = []): Result
    {
        $q = $this->db->table('member_journey_transitions')
            ->select('discipler_id,
                      COUNT(*) AS advances,
                      COUNT(DISTINCT user_id) AS people_moved')
            ->where('organization_id', $organizationId)
            ->where('discipler_id IS NOT NULL')
            ->whereIn('direction', ['advance', 'open']);

        if ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }
        if (isset($filters['phase']) && $filters['phase'] !== '') {
            // The destination phase is carried on the journey, not the transition
            // row; join to resolve it against the stage catalog would be heavier.
            // We approximate with a sub-filter on to_stage via journey_stages.
            $stageCodes = $this->stageCodesForPhase($organizationId, (string) $filters['phase']);
            if ($stageCodes === []) {
                return Result::ok(['group_id' => $groupId, 'entries' => []]);
            }
            $q->whereIn('to_stage', $stageCodes);
        }
        if (isset($filters['since']) && $filters['since'] !== '') {
            $q->where('created_at >=', (string) $filters['since']);
        }

        $rows = $q->groupBy('discipler_id')
            ->orderBy('people_moved', 'DESC')
            ->orderBy('advances', 'DESC')
            ->get(max(1, min(100, $limit)))
            ->getResultArray();

        $entries = [];
        $rank    = 0;
        foreach ($rows as $r) {
            $entries[] = [
                'rank'         => ++$rank,
                'discipler_id' => (string) $r['discipler_id'],
                'people_moved' => (int) $r['people_moved'],
                'advances'     => (int) $r['advances'],
            ];
        }

        return Result::ok(['group_id' => $groupId, 'entries' => $entries]);
    }

    /** @return list<string> */
    private function stageCodesForPhase(string $organizationId, string $phase): array
    {
        $rows = $this->db->table('journey_stages')
            ->select('code')
            ->where('organization_id', $organizationId)
            ->where('phase', $phase)
            ->get()->getResultArray();

        return array_values(array_unique(array_map(static fn ($r) => (string) $r['code'], $rows)));
    }

    /** @return list<array<string,mixed>> */
    public function historyFor(string $organizationId, string $journeyId): array
    {
        return $this->db->table('member_journey_transitions')
            ->where('organization_id', $organizationId)
            ->where('journey_id', $journeyId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Public stage comparison against the effective ladder for a context.
     * Returns 'advance' | 'regress' | 'same' | 'set' (the last when either code
     * is not on the ladder). Used by the signal engine to guard auto-transitions.
     */
    public function compareStages(string $organizationId, ?string $groupId, string $fromCode, string $toCode): string
    {
        if ($fromCode === $toCode) {
            return 'same';
        }

        return $this->direction($organizationId, $groupId, $fromCode, $toCode);
    }

    /** The current stage code for a person in a context, or null if no journey. */
    public function currentStage(string $organizationId, string $userId, ?string $groupId = null): ?string
    {
        $journey = $this->findJourney($organizationId, $userId, $groupId);

        return $journey !== null ? (string) $journey['stage_code'] : null;
    }

    // ---- Internals ----------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function findJourney(string $organizationId, string $userId, ?string $groupId): ?array
    {
        $q = $this->db->table('member_journeys')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

        return $q->get()->getRowArray();
    }

    /**
     * Ladder-order comparison to classify a move. Codes not in the ladder are
     * treated as 'set'.
     */
    private function direction(string $organizationId, ?string $groupId, string $fromCode, string $toCode): string
    {
        $order = [];
        foreach ($this->ladder($organizationId, $groupId) as $i => $stage) {
            $order[$stage['code']] = (int) $stage['sort_order'];
        }
        if (! isset($order[$fromCode], $order[$toCode])) {
            return 'set';
        }

        return $order[$toCode] > $order[$fromCode] ? 'advance' : 'regress';
    }

    /** @param array<string,mixed> $data */
    private function recordTransition(
        string $organizationId,
        string $journeyId,
        string $userId,
        ?string $groupId,
        ?string $fromStage,
        string $toStage,
        string $direction,
        string $source,
        array $data,
    ): void {
        $direction = in_array($direction, self::DIRECTIONS, true) ? $direction : 'set';
        $this->db->table('member_journey_transitions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'journey_id'      => $journeyId,
            'user_id'         => $userId,
            'group_id'        => $groupId,
            'from_stage'      => $fromStage,
            'to_stage'        => $toStage,
            'direction'       => $direction,
            'reason'          => isset($data['reason']) && $data['reason'] !== '' ? mb_substr((string) $data['reason'], 0, 255) : null,
            'actor_id'        => isset($data['actor_id']) && $data['actor_id'] !== '' ? (string) $data['actor_id'] : null,
            'discipler_id'    => isset($data['discipler_id']) && $data['discipler_id'] !== '' ? (string) $data['discipler_id'] : null,
            'evidence_type'   => isset($data['evidence_type']) && $data['evidence_type'] !== '' ? mb_substr((string) $data['evidence_type'], 0, 40) : null,
            'evidence_ref'    => isset($data['evidence_ref']) && $data['evidence_ref'] !== '' ? mb_substr((string) $data['evidence_ref'], 0, 120) : null,
            'project_code'    => isset($data['project_code']) && $data['project_code'] !== '' ? mb_substr((string) $data['project_code'], 0, 64) : null,
            'source'          => $source,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);
    }

    /**
     * Fan out a committed transition to listeners. Never lets a listener failure
     * affect the (already durable) journey change.
     *
     * @param array<string,mixed> $event
     */
    private function notify(array $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener->onTransition($event);
            } catch (Throwable) {
                // Swallow — a downstream credit/notification failure must never
                // undo the member's stage change.
            }
        }
    }
}
