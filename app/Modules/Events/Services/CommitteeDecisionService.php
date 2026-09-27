<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Events\Support\CommitteeConfig;
use WBS\Events\Support\CommitteeOversight;
use WBS\Events\Support\WorkPlan;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * The committee's OVERSIGHT QUEUE — what the committee wants to do, and whether
 * the group leader has to say yes first.
 *
 * How much oversight there is, is HIERARCHICAL GROUP CONFIG (capability
 * `event_committee`, key `oversight`), so each body chooses its own rung:
 *
 *  - `formation_and_major` (default once committees are on): money at or above the
 *    configured threshold, schedule changes, cancellation, publication and the
 *    committee's own constitution (chair, responsibilities) go to the leader;
 *  - `maker_checker_all`: everything does;
 *  - `observe_only`: nothing is blocked, but every decision is still recorded,
 *    still visible to the leader and still audited — oversight by visibility.
 *
 * A decision that needs no approval is recorded as `noted` and its effect applies
 * immediately: the committee is acting inside authority it was already delegated.
 *
 * THREE properties are copied deliberately from the platform's other review queues
 * (sponsor reassignment, prospect transfers) so a leader meets the same behaviour
 * everywhere:
 *
 *  - SEGREGATION OF DUTIES: the decider may not be the requester, re-asserted here
 *    even though the route gate already blocks it;
 *  - APPROVAL DEMANDS THE SAME AUTHORITY AS THE ACT: `required_permission` is the
 *    EXISTING capability that governs the underlying action
 *    (`event.expense.approve` for money, `event.schedule.approve` for dates,
 *    `event.create` for the committee's constitution), and the decider's own scope
 *    must cover the oversight group. The queue is therefore never a way round a
 *    gate, and no new permission bit was needed to build it;
 *  - NO EXPIRY: a request stays pending until a human decides it. What protects
 *    against stale facts is the RE-CHECK at approve — the committee may have been
 *    dissolved, the event closed or the config changed while it sat in the queue,
 *    and then the request is refused rather than silently actioned.
 *
 * The queue also never performs an action that has its own gate elsewhere: an
 * approved budget decision is the leader's authorization to spend, and the expense
 * itself still runs through `event_expenses` with `event.expense.submit` /
 * `.approve`. Effects applied here are the committee's own — chair and membership
 * changes, and plan moves (task, milestone, workstream).
 */
final class CommitteeDecisionService
{
    /** @var list<string> */
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'noted'];

    /** Effect actions this queue knows how to apply. @var list<string> */
    public const EFFECTS = [
        'chair.appoint',
        'member.add',
        'member.remove',
        'task.status',
        'task.assign',
        'milestone.meet',
        'milestone.miss',
        'workstream.status',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly EventConfigPort $config,
        private readonly ?CommitteeService $committees = null,
        private readonly ?EventWorkService $work = null,
        private readonly ?CommitteeAuthorityPort $authority = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /** @see CommitteeService::boot() */
    public static function boot(): self
    {
        $committees = CommitteeService::boot();

        return new self(
            \Config\Database::connect(),
            \WBS\Shared\Config\Services::clock(),
            new EffectiveConfigAdapter(\WBS\Admin\Config\Services::effectiveConfig()),
            $committees,
            EventWorkService::boot(),
            new DelegationAuthorityAdapter(
                \WBS\AccessControl\Config\Services::delegations(),
                \WBS\AccessControl\Config\Services::authorization(),
            ),
            \WBS\Audit\Config\Services::auditLogger(),
        );
    }

    // ----------------------------------------------------------------- request

    /**
     * Record a decision (the maker step).
     *
     * @param array<string,mixed> $data kind, title (required), detail, amount,
     *                                  effect {action, ...}
     *
     * @return Result data: {decision_id, status, required_permission, needs_approval}
     */
    public function request(string $organizationId, string $eventId, string $actorId, array $data): Result
    {
        $committee = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)->where('status', 'active')
            ->get()->getRowArray();
        if ($committee === null) {
            return Result::notFound('Events.decision.errNoCommittee', 'COMMITTEE_NOT_FOUND');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::fail('TITLE_REQUIRED', 'Events.decision.errTitleRequired', 422);
        }

        $kind   = CommitteeOversight::normalizeKind((string) ($data['kind'] ?? CommitteeOversight::KIND_OTHER));
        $cfg    = $this->configFor($committee);
        if (! $cfg->enabled) {
            return Result::fail('COMMITTEES_DISABLED', 'Events.decision.errDisabled', 403);
        }

        // Only the committee (chair or member) proposes; the leader may record a
        // direction of their own, which is how a leader's instruction enters the trail.
        if ($this->committees === null) {
            // Membership and scope cannot be verified: refuse rather than guess.
            return Result::fail('OVERSIGHT_UNAVAILABLE', 'Events.decision.errOversightUnavailable', 503);
        }
        $isMember = $this->committees->memberRow($organizationId, $eventId, $actorId) !== null;
        // A leader recording a direction of their own must hold the governance
        // capability over the oversight group (PDP decision, delegations included).
        $isLeader = $this->authority !== null
            && $this->authority->holds($organizationId, $actorId, 'event.create', $this->oversightOf($committee));
        if (! $isMember && ! $isLeader) {
            return Result::denied('Events.decision.errNotAuthorized', 'NOT_AUTHORIZED');
        }

        $amount = isset($data['amount']) && is_numeric($data['amount']) ? round((float) $data['amount'], 2) : null;
        $effect = $this->normalizeEffect($data['effect'] ?? null);
        $needs  = CommitteeOversight::requiresApproval($cfg->oversight, $kind, $amount, $cfg->budgetApprovalThreshold);

        // Idempotent per (committee, kind, title): a double click or a re-run never
        // opens two identical requests on the leader's desk.
        $open = $this->db->table('event_committee_decisions')
            ->where('committee_id', (string) $committee['id'])
            ->where('kind', $kind)->where('title', mb_substr($title, 0, 200))
            ->whereIn('status', ['pending', 'noted'])
            ->get()->getRowArray();
        if ($open !== null) {
            return Result::ok([
                'decision_id'         => (string) $open['id'],
                'status'              => (string) $open['status'],
                'needs_approval'      => (string) $open['status'] === 'pending',
                'required_permission' => (string) ($open['required_permission'] ?? ''),
            ], 200, ['deduplicated' => true]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_committee_decisions')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'event_id'            => $eventId,
            'committee_id'        => (string) $committee['id'],
            'kind'                => $kind,
            'title'               => mb_substr($title, 0, 200),
            'detail'              => $this->textOrNull($data['detail'] ?? null),
            'amount'              => $amount,
            'required_permission' => CommitteeOversight::permissionForKind($kind),
            'oversight_group_id'  => $committee['oversight_group_id'] ?? null,
            'requested_by'        => $actorId,
            'status'              => $needs ? 'pending' : 'noted',
            'effect_json'         => $effect !== null ? json_encode($effect) : null,
            'effect_applied'      => 0,
            'created_at'          => $now,
        ]);

        // No approval needed ⇒ the committee is inside its delegated authority, so
        // the effect applies now and the row stands as the record of it.
        $applied = null;
        if (! $needs && $effect !== null) {
            $applied = $this->applyEffect($organizationId, $id, $effect, $actorId);
            $this->db->table('event_committee_decisions')->where('id', $id)
                ->update(['effect_applied' => $applied->ok ? 1 : 0, 'updated_at' => $this->clock->nowUtcMicro()]);
        }

        $this->writeAudit($organizationId, $needs ? 'event.committee.decision.requested' : 'event.committee.decision.noted', $actorId, $id, [
            'event_id'            => $eventId,
            'committee_id'        => (string) $committee['id'],
            'kind'                => $kind,
            'amount'              => $amount,
            'oversight_mode'      => $cfg->oversight,
            'required_permission' => CommitteeOversight::permissionForKind($kind),
            'needs_approval'      => $needs,
            'effect'              => $effect['action'] ?? null,
            'effect_applied'      => $applied !== null ? ($applied->ok ? 'success' : 'failed') : null,
        ]);

        return Result::created([
            'decision_id'         => $id,
            'status'              => $needs ? 'pending' : 'noted',
            'needs_approval'      => $needs,
            'required_permission' => CommitteeOversight::permissionForKind($kind),
            'oversight_mode'      => $cfg->oversight,
            'effect_applied'      => $applied !== null ? $applied->ok : null,
        ]);
    }

    // ----------------------------------------------------------------- decide

    /**
     * Approve a pending decision (the checker step).
     *
     * Refuses when the requester is the decider (SoD), when the decider's own
     * authority does not reach the oversight group, or when the world moved while
     * the request sat in the queue (committee dissolved, event closed). The effect
     * is applied BEFORE the row is marked approved, so a decision whose effect
     * fails stays pending with the reason visible instead of reading as done.
     */
    public function approve(string $organizationId, string $decisionId, string $actorId, ?string $note = null): Result
    {
        $decision = $this->find($organizationId, $decisionId);
        if ($decision === null) {
            return Result::notFound('Events.decision.errNotFound', 'DECISION_NOT_FOUND');
        }
        if ((string) $decision['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'Events.decision.errBadState', 409, ['status' => (string) $decision['status']]);
        }
        if ((string) $decision['requested_by'] === $actorId) {
            return Result::fail('SELF_APPROVAL', 'Events.decision.errSelfApproval', 403);
        }

        // Approval demands the SAME authority as the act: the capability this kind
        // of decision requires (`event.expense.approve` for money,
        // `event.schedule.approve` for dates, `event.create` for the committee's own
        // constitution), held over the oversight group. Decided by the PDP, so a
        // leader's own delegation counts and a committee member's never does — the
        // queue cannot become a way round an existing gate.
        $oversight = $this->textOrNull($decision['oversight_group_id'] ?? null);
        $required  = (string) ($decision['required_permission'] ?? CommitteeOversight::permissionForKind((string) $decision['kind']));
        if ($this->authority === null || ! $this->authority->holds($organizationId, $actorId, $required, $oversight)) {
            return Result::denied('Events.decision.errOutsideScope', 'OUTSIDE_SCOPE');
        }

        // Re-check the CURRENT world, not the one the request was written in.
        $committee = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('id', (string) $decision['committee_id'])
            ->get()->getRowArray();
        if ($committee === null || (string) $committee['status'] !== 'active') {
            return Result::fail('COMMITTEE_DISSOLVED', 'Events.decision.errCommitteeDissolved', 409);
        }
        $event = $this->db->table('events')
            ->where('organization_id', $organizationId)->where('id', (string) $decision['event_id'])
            ->get()->getRowArray();
        if ($event === null || in_array((string) ($event['status'] ?? ''), ['cancelled', 'completed', 'completed_no_attendance'], true)) {
            return Result::fail('EVENT_CLOSED', 'Events.decision.errEventClosed', 409, ['status' => (string) ($event['status'] ?? '')]);
        }

        $effect = $this->decodeEffect($decision['effect_json'] ?? null);
        $applied = null;
        if ($effect !== null) {
            $applied = $this->applyEffect($organizationId, $decisionId, $effect, $actorId);
            if (! $applied->ok) {
                $this->writeAudit($organizationId, 'event.committee.decision.approval_blocked', $actorId, $decisionId, [
                    'effect' => $effect['action'] ?? null,
                    'reason' => (string) ($applied->code ?? 'EFFECT_FAILED'),
                ]);

                return Result::fail(
                    (string) ($applied->code ?? 'EFFECT_FAILED'),
                    'Events.decision.errEffectFailed',
                    $applied->status >= 400 ? $applied->status : 409,
                    ['effect' => $effect['action'] ?? null, 'reason' => $applied->message],
                );
            }
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_committee_decisions')->where('id', $decisionId)->update([
            'status'         => 'approved',
            'decided_by'     => $actorId,
            'decided_at'     => $now,
            'decision_note'  => $this->textOrNull($note),
            'effect_applied' => $effect !== null ? 1 : 0,
            'updated_at'     => $now,
        ]);

        $this->writeAudit($organizationId, 'event.committee.decision.approved', $actorId, $decisionId, [
            'event_id'            => (string) $decision['event_id'],
            'committee_id'        => (string) $decision['committee_id'],
            'kind'                => (string) $decision['kind'],
            'amount'              => $decision['amount'] ?? null,
            'requested_by'        => (string) $decision['requested_by'],
            'required_permission' => (string) ($decision['required_permission'] ?? ''),
            'effect'              => $effect['action'] ?? null,
        ]);

        return Result::ok([
            'decision_id'    => $decisionId,
            'status'         => 'approved',
            'effect_applied' => $effect !== null,
        ]);
    }

    /** Reject a pending decision, with the reason (the maker sees why). */
    public function reject(string $organizationId, string $decisionId, string $actorId, ?string $note = null): Result
    {
        return $this->decide($organizationId, $decisionId, $actorId, 'rejected', $note);
    }

    /** Cancel a pending decision — the requester's own withdrawal, or the chair's. */
    public function cancel(string $organizationId, string $decisionId, string $actorId, ?string $note = null): Result
    {
        $decision = $this->find($organizationId, $decisionId);
        if ($decision === null) {
            return Result::notFound('Events.decision.errNotFound', 'DECISION_NOT_FOUND');
        }
        if ((string) $decision['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'Events.decision.errBadState', 409, ['status' => (string) $decision['status']]);
        }
        // Withdrawing your own request needs no authority beyond having made it;
        // clearing somebody else's is the chair's or the leader's act.
        if ((string) $decision['requested_by'] !== $actorId) {
            $committee = $this->db->table('event_committees')
                ->where('organization_id', $organizationId)->where('id', (string) $decision['committee_id'])
                ->get()->getRowArray();
            $isChair = $committee !== null && (string) ($committee['chair_user_id'] ?? '') === $actorId;
            $isLeader = $this->authority !== null
                && $this->authority->holds($organizationId, $actorId, 'event.create', $this->textOrNull($decision['oversight_group_id'] ?? null));
            if (! $isChair && ! $isLeader) {
                return Result::denied('Events.decision.errNotAuthorized', 'NOT_AUTHORIZED');
            }
        }

        return $this->decide($organizationId, $decisionId, $actorId, 'cancelled', $note);
    }

    // ------------------------------------------------------------------ reads

    /**
     * The queue. Bounded to the groups passed in (the caller resolves the actor's
     * own subtree, so a leader sees their own committees' requests and nobody
     * else's).
     *
     * @param array<string,mixed> $filters status, kind, event_id, committee_id,
     *                                     oversight_group_id, group_ids (list),
     *                                     limit
     *
     * @return list<array<string,mixed>>
     */
    public function queue(string $organizationId, array $filters = []): array
    {
        $q = $this->db->table('event_committee_decisions')
            ->where('organization_id', $organizationId);

        $status = isset($filters['status']) ? (string) $filters['status'] : 'pending';
        if ($status !== '' && $status !== 'all' && in_array($status, self::STATUSES, true)) {
            $q->where('status', $status);
        }
        if (isset($filters['kind']) && CommitteeOversight::normalizeKind((string) $filters['kind']) !== CommitteeOversight::KIND_OTHER) {
            $q->where('kind', CommitteeOversight::normalizeKind((string) $filters['kind']));
        }
        foreach (['event_id', 'committee_id', 'oversight_group_id'] as $f) {
            if (isset($filters[$f]) && $filters[$f] !== '') {
                $q->where($f, (string) $filters[$f]);
            }
        }
        if (isset($filters['group_ids']) && is_array($filters['group_ids']) && $filters['group_ids'] !== []) {
            $q->whereIn('oversight_group_id', array_values(array_map('strval', $filters['group_ids'])));
        }

        $rows = $q->orderBy('created_at', 'DESC')
            ->limit(max(1, min((int) ($filters['limit'] ?? 100), 500)))
            ->get()->getResultArray();

        return $this->decorate($organizationId, $rows);
    }

    /**
     * Decisions awaiting THIS actor as overseer: pending rows whose oversight group
     * their own authority reaches. NULL scope (org-wide) sees all of them.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForOversight(string $organizationId, string $actorId, int $limit = 50): array
    {
        $groups = $this->committees !== null
            ? $this->committees->scopeGroupsForUser($organizationId, $actorId)
            : null;

        return $this->queue($organizationId, $groups === null
            ? ['status' => 'pending', 'limit' => $limit]
            : ['status' => 'pending', 'group_ids' => $groups, 'limit' => $limit]);
    }

    /**
     * Attach the context a queue needs — event title/status and the people's names
     * — in two queries for the whole page, never one per row.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>>
     */
    private function decorate(string $organizationId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $eventIds = [];
        $userIds  = [];
        foreach ($rows as $r) {
            $eventIds[] = (string) ($r['event_id'] ?? '');
            $userIds[]  = (string) ($r['requested_by'] ?? '');
            $userIds[]  = (string) ($r['decided_by'] ?? '');
        }
        $events = [];
        foreach ($this->db->table('events')->select('id, title, status')
            ->whereIn('id', array_values(array_unique(array_filter($eventIds))))->get()->getResultArray() as $e) {
            $events[(string) $e['id']] = $e;
        }
        $names = $this->committees !== null
            ? $this->committees->displayNames($organizationId, array_values(array_unique(array_filter($userIds))))
            : [];

        foreach ($rows as $i => $r) {
            $e = $events[(string) ($r['event_id'] ?? '')] ?? null;
            $rows[$i]['event_title']       = (string) ($e['title'] ?? '');
            $rows[$i]['event_status']      = (string) ($e['status'] ?? '');
            $rows[$i]['requested_by_name'] = $names[(string) ($r['requested_by'] ?? '')] ?? null;
            $rows[$i]['decided_by_name']   = $names[(string) ($r['decided_by'] ?? '')] ?? null;
            $rows[$i]['effect']            = $this->decodeEffect($r['effect_json'] ?? null);
        }

        return $rows;
    }

    /** One decision, with its event title and committee mandate for context. @return array<string,mixed>|null */
    public function find(string $organizationId, string $decisionId): ?array
    {
        $row = $this->db->table('event_committee_decisions')
            ->where('organization_id', $organizationId)->where('id', $decisionId)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $event = $this->db->table('events')->select('id, title, status, starts_at, ends_at')
            ->where('id', (string) $row['event_id'])->get()->getRowArray();
        $committee = $this->db->table('event_committees')->select('id, mandate, chair_user_id, status')
            ->where('id', (string) $row['committee_id'])->get()->getRowArray();

        $row['event']     = $event;
        $row['committee'] = $committee;
        $row['effect']    = $this->decodeEffect($row['effect_json'] ?? null);

        return $row;
    }

    /** Pending count for a badge (optionally bounded to one oversight group). */
    public function countPending(string $organizationId, ?string $oversightGroupId = null): int
    {
        $q = $this->db->table('event_committee_decisions')
            ->where('organization_id', $organizationId)->where('status', 'pending');
        if ($oversightGroupId !== null && $oversightGroupId !== '') {
            $q->where('oversight_group_id', $oversightGroupId);
        }

        return (int) $q->countAllResults();
    }

    // -------------------------------------------------------------- internals

    /** Shared reject/cancel state move. */
    private function decide(string $organizationId, string $decisionId, string $actorId, string $status, ?string $note): Result
    {
        $decision = $this->find($organizationId, $decisionId);
        if ($decision === null) {
            return Result::notFound('Events.decision.errNotFound', 'DECISION_NOT_FOUND');
        }
        if ((string) $decision['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'Events.decision.errBadState', 409, ['status' => (string) $decision['status']]);
        }
        if ($status === 'rejected' && (string) $decision['requested_by'] === $actorId) {
            return Result::fail('SELF_APPROVAL', 'Events.decision.errSelfApproval', 403);
        }
        if ($status === 'rejected') {
            // Rejecting is the same authority as approving: the decider must hold the
            // capability this decision requires, over the oversight group.
            $required = (string) ($decision['required_permission'] ?? CommitteeOversight::permissionForKind((string) $decision['kind']));
            if ($this->authority === null
                || ! $this->authority->holds($organizationId, $actorId, $required, $this->textOrNull($decision['oversight_group_id'] ?? null))) {
                return Result::denied('Events.decision.errOutsideScope', 'OUTSIDE_SCOPE');
            }
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_committee_decisions')->where('id', $decisionId)->update([
            'status'        => $status,
            'decided_by'    => $actorId,
            'decided_at'    => $now,
            'decision_note' => $this->textOrNull($note),
            'updated_at'    => $now,
        ]);

        $this->writeAudit($organizationId, 'event.committee.decision.' . $status, $actorId, $decisionId, [
            'event_id'     => (string) $decision['event_id'],
            'committee_id' => (string) $decision['committee_id'],
            'kind'         => (string) $decision['kind'],
            'requested_by' => (string) $decision['requested_by'],
        ]);

        return Result::ok(['decision_id' => $decisionId, 'status' => $status]);
    }

    /**
     * Apply a decision's effect. Unknown actions are refused rather than ignored:
     * a decision that says it will do something must do it or fail loudly.
     *
     * @param array<string,mixed> $effect
     */
    private function applyEffect(string $organizationId, string $decisionId, array $effect, string $actorId): Result
    {
        $action = (string) ($effect['action'] ?? '');
        $decision = $this->find($organizationId, $decisionId);
        $committeeId = (string) ($decision['committee_id'] ?? '');
        $eventId     = (string) ($decision['event_id'] ?? '');

        switch ($action) {
            case 'chair.appoint':
                if ($this->committees === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->committees->appointChair($organizationId, $committeeId, $actorId, (string) ($effect['user_id'] ?? ''), [
                    'authorized_by_decision' => $decisionId,
                    'reason'                 => (string) ($decision['title'] ?? ''),
                ]);

            case 'member.add':
                if ($this->committees === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->committees->addMember($organizationId, $committeeId, $actorId, [
                    'user_id'        => (string) ($effect['user_id'] ?? ''),
                    'responsibility' => (string) ($effect['responsibility'] ?? 'general'),
                    'label'          => isset($effect['label']) ? (string) $effect['label'] : null,
                ]);

            case 'member.remove':
                if ($this->committees === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->committees->removeMember($organizationId, (string) ($effect['member_id'] ?? ''), $actorId, (string) ($decision['title'] ?? 'decision'));

            case 'task.status':
                if ($this->work === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->work->updateTask($organizationId, (string) ($effect['task_id'] ?? ''), $actorId, [
                    'status'         => WorkPlan::normalizeStatus((string) ($effect['status'] ?? WorkPlan::STATUS_IN_PROGRESS)),
                    'blocked_reason' => isset($effect['blocked_reason']) ? (string) $effect['blocked_reason'] : null,
                    'force'          => ! empty($effect['force']),
                ]);

            case 'task.assign':
                if ($this->work === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->work->updateTask($organizationId, (string) ($effect['task_id'] ?? ''), $actorId, [
                    'assignee_user_id' => (string) ($effect['user_id'] ?? ''),
                    'due_at'           => $effect['due_at'] ?? null,
                ]);

            case 'milestone.meet':
                if ($this->work === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->work->meetMilestone($organizationId, (string) ($effect['milestone_id'] ?? ''), $actorId, isset($effect['evidence']) ? (string) $effect['evidence'] : null);

            case 'milestone.miss':
                if ($this->work === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->work->missMilestone($organizationId, (string) ($effect['milestone_id'] ?? ''), $actorId, isset($effect['evidence']) ? (string) $effect['evidence'] : null);

            case 'workstream.status':
                if ($this->work === null) {
                    return Result::fail('EFFECT_UNAVAILABLE', 'Events.decision.errEffectUnavailable', 503);
                }

                return $this->work->updateWorkstream($organizationId, (string) ($effect['workstream_id'] ?? ''), $actorId, [
                    'status' => (string) ($effect['status'] ?? WorkPlan::WS_TRACK),
                ]);

            default:
                return Result::fail('UNKNOWN_EFFECT', 'Events.decision.errUnknownEffect', 422, [
                    'action'   => $action,
                    'event_id' => $eventId,
                ]);
        }
    }

    /** Validate an incoming effect against the known vocabulary. @return array<string,mixed>|null */
    private function normalizeEffect(mixed $effect): ?array
    {
        if (! is_array($effect)) {
            return null;
        }
        $action = strtolower(trim((string) ($effect['action'] ?? '')));
        if ($action === '' || ! in_array($action, self::EFFECTS, true)) {
            return null;
        }

        return $effect + ['action' => $action];
    }

    /** @return array<string,mixed>|null */
    private function decodeEffect(mixed $json): ?array
    {
        if (is_array($json)) {
            return $this->normalizeEffect($json);
        }
        if (! is_string($json) || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $this->normalizeEffect($decoded) : null;
    }

    /** @param array<string,mixed> $committee */
    private function configFor(array $committee): CommitteeConfig
    {
        $groupId = $this->oversightOf($committee);
        if ($groupId === null) {
            return CommitteeConfig::off();
        }

        try {
            return CommitteeConfig::fromResolved($this->config->value($groupId, CommitteeConfig::CAPABILITY));
        } catch (Throwable) {
            return CommitteeConfig::off();
        }
    }

    /** @param array<string,mixed> $committee */
    private function oversightOf(array $committee): ?string
    {
        return $this->textOrNull($committee['oversight_group_id'] ?? null);
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /** @param array<string,mixed> $metadata */
    private function writeAudit(string $organizationId, string $action, string $actorId, string $decisionId, array $metadata): void
    {
        $this->audit?->record($organizationId, [
            'action'      => $action,
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'event_committee_decision',
            'object_id'   => $decisionId,
            'outcome'     => 'success',
            'metadata'    => $metadata,
        ]);
    }
}
