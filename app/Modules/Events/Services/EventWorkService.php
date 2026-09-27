<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Events\Support\CommitteeConfig;
use WBS\Events\Support\CommitteeResponsibility;
use WBS\Events\Support\WorkPlan;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * The event's WORK ENGINE — workstreams → tasks → dependencies, plus milestones,
 * roll-up progress and late/at-risk detection.
 *
 * This is the "project management" half of the committee feature: the plan a
 * committee (or, before one exists, the organizer) actually runs the event with.
 * It sits ON TOP of the existing surfaces rather than beside them — a task may say
 * "book the venue" while the venue booking itself still lives in
 * `event_logistics`, and "submit the transport budget" while the money still runs
 * through `event_expenses` with its own approval. Nothing here re-implements
 * those; it tracks whether the work got done, by whom, by when, and what it is
 * waiting on.
 *
 * Rules worth stating:
 *
 *  - GATED by the same hierarchical group capability as the committee itself
 *    (`event_committee`, default OFF) and anchored to `events.group_id`: no config,
 *    no plan. A plan does NOT require a committee — an organizer may plan alone —
 *    but when a committee exists every row is stamped with `committee_id`, and
 *    only its members (or the leader above them) may work it.
 *  - Progress is DERIVED, never asserted: a task's percentage is what
 *    {@see WorkPlan::completionOf()} says (done ⇒ 100, in-flight clamped to 99),
 *    a workstream's is the mean of its live tasks, and the plan's blends tasks with
 *    milestones. The denormalized counters on `event_workstreams` are refreshed
 *    after every mutation so listing stays cheap, but the source of truth is the
 *    task rows.
 *  - Dependencies form a DAG: an edge is refused when it would close a cycle, and a
 *    task cannot be marked done while a finish-to-start predecessor is still open
 *    (unless the actor explicitly forces it, which is recorded).
 *  - Cancelled work is history, not noise: it stays on the row set but is excluded
 *    from every roll-up.
 */
final class EventWorkService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly EventConfigPort $config,
        private readonly ?CommitteeService $committees = null,
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
            new DelegationAuthorityAdapter(
                \WBS\AccessControl\Config\Services::delegations(),
                \WBS\AccessControl\Config\Services::authorization(),
            ),
            \WBS\Audit\Config\Services::auditLogger(),
        );
    }

    // ------------------------------------------------------------- workstreams

    /**
     * @param array<string,mixed> $data name (required), description, owner_user_id,
     *                                  responsibility, weight, start_date, due_date
     */
    public function createWorkstream(string $organizationId, string $eventId, string $actorId, array $data): Result
    {
        $gate = $this->gate($organizationId, $eventId, $actorId);
        if (! $gate->ok) {
            return $gate;
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'Events.plan.errNameRequired', 422);
        }
        /** @var array<string,mixed> $event */
        $event = $gate->meta['event'];

        $responsibility = isset($data['responsibility']) && trim((string) $data['responsibility']) !== ''
            ? CommitteeResponsibility::normalize((string) $data['responsibility']) : null;
        $owner = trim((string) ($data['owner_user_id'] ?? ''));
        if ($owner !== '' && ! $this->userExists($organizationId, $owner)) {
            return Result::notFound('Events.plan.errUserNotFound', 'USER_NOT_FOUND');
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_workstreams')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'committee_id'    => $gate->meta['committee_id'],
            'name'            => mb_substr($name, 0, 160),
            'description'     => $this->textOrNull($data['description'] ?? null),
            'owner_user_id'   => $owner !== '' ? $owner : null,
            'responsibility'  => $responsibility,
            'status'          => WorkPlan::WS_OPEN,
            'weight'          => max(1, min(100, (int) ($data['weight'] ?? 1))),
            'start_date'      => WorkPlan::dateOf($data['start_date'] ?? null),
            'due_date'        => WorkPlan::dateOf($data['due_date'] ?? null),
            'sort_order'      => (int) ($data['sort_order'] ?? $this->nextSort('event_workstreams', $eventId)),
            'created_at'      => $now,
            'created_by'      => $actorId,
        ]);

        $this->writeAudit($organizationId, 'event.workstream.created', $actorId, 'event_workstream', $id, [
            'event_id' => $eventId, 'name' => mb_substr($name, 0, 160), 'owner_user_id' => $owner !== '' ? $owner : null,
        ]);

        return Result::created([
            'workstream_id' => $id,
            'event_id'      => $eventId,
            'name'          => mb_substr($name, 0, 160),
            'due_date'      => WorkPlan::dateOf($data['due_date'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $data any of the createWorkstream fields + status */
    public function updateWorkstream(string $organizationId, string $workstreamId, string $actorId, array $data): Result
    {
        $row = $this->db->table('event_workstreams')
            ->where('organization_id', $organizationId)->where('id', $workstreamId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errWorkstreamNotFound', 'WORKSTREAM_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $patch = ['updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId];
        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $patch['name'] = mb_substr(trim((string) $data['name']), 0, 160);
        }
        if (array_key_exists('description', $data)) {
            $patch['description'] = $this->textOrNull($data['description']);
        }
        if (array_key_exists('owner_user_id', $data)) {
            $owner = trim((string) ($data['owner_user_id'] ?? ''));
            if ($owner !== '' && ! $this->userExists($organizationId, $owner)) {
                return Result::notFound('Events.plan.errUserNotFound', 'USER_NOT_FOUND');
            }
            $patch['owner_user_id'] = $owner !== '' ? $owner : null;
        }
        if (array_key_exists('responsibility', $data)) {
            $patch['responsibility'] = trim((string) ($data['responsibility'] ?? '')) !== ''
                ? CommitteeResponsibility::normalize((string) $data['responsibility']) : null;
        }
        if (isset($data['weight'])) {
            $patch['weight'] = max(1, min(100, (int) $data['weight']));
        }
        if (array_key_exists('start_date', $data)) {
            $patch['start_date'] = WorkPlan::dateOf($data['start_date']);
        }
        if (array_key_exists('due_date', $data)) {
            $patch['due_date'] = WorkPlan::dateOf($data['due_date']);
        }
        if (isset($data['sort_order'])) {
            $patch['sort_order'] = (int) $data['sort_order'];
        }
        if (isset($data['status'])) {
            $status = strtolower(trim((string) $data['status']));
            if (! in_array($status, WorkPlan::WORKSTREAM_STATUSES, true)) {
                return Result::fail('BAD_STATUS', 'Events.plan.errBadStatus', 422, ['status' => $status]);
            }
            // A hand-set status holds until the next roll-up contradicts it; the
            // derived states (done/blocked/at_risk) are recomputed from tasks.
            $patch['status'] = $status;
        }

        $this->db->table('event_workstreams')->where('id', $workstreamId)->update($patch);

        return Result::ok(['workstream_id' => $workstreamId] + $patch);
    }

    /**
     * Delete a workstream. Refused while tasks still belong to it unless
     * `$reassign` is set, in which case they become unstreamed (still on the plan).
     */
    public function deleteWorkstream(string $organizationId, string $workstreamId, string $actorId, bool $reassign = false): Result
    {
        $row = $this->db->table('event_workstreams')
            ->where('organization_id', $organizationId)->where('id', $workstreamId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errWorkstreamNotFound', 'WORKSTREAM_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $tasks = (int) $this->db->table('event_tasks')->where('workstream_id', $workstreamId)->countAllResults();
        if ($tasks > 0 && ! $reassign) {
            return Result::fail('WORKSTREAM_NOT_EMPTY', 'Events.plan.errWorkstreamNotEmpty', 409, ['tasks' => $tasks]);
        }
        if ($tasks > 0) {
            $this->db->table('event_tasks')->where('workstream_id', $workstreamId)
                ->update(['workstream_id' => null, 'updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId]);
        }
        $this->db->table('event_milestones')->where('workstream_id', $workstreamId)
            ->update(['workstream_id' => null, 'updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId]);
        $this->db->table('event_workstreams')->where('id', $workstreamId)->delete();

        $this->writeAudit($organizationId, 'event.workstream.deleted', $actorId, 'event_workstream', $workstreamId, [
            'event_id' => (string) $row['event_id'], 'reassigned_tasks' => $tasks,
        ]);

        return Result::ok(['workstream_id' => $workstreamId, 'deleted' => true, 'reassigned_tasks' => $tasks]);
    }

    // ------------------------------------------------------------------- tasks

    /**
     * @param array<string,mixed> $data title (required), description, workstream_id,
     *                                  assignee_user_id, responsibility, due_at,
     *                                  priority, progress_pct, estimated_hours,
     *                                  milestone_id, sort_order
     */
    public function createTask(string $organizationId, string $eventId, string $actorId, array $data): Result
    {
        $gate = $this->gate($organizationId, $eventId, $actorId);
        if (! $gate->ok) {
            return $gate;
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::fail('TITLE_REQUIRED', 'Events.plan.errTitleRequired', 422);
        }

        $workstreamId = $this->belongsToEvent('event_workstreams', $organizationId, $eventId, $data['workstream_id'] ?? null);
        if ($workstreamId === false) {
            return Result::fail('WORKSTREAM_MISMATCH', 'Events.plan.errWorkstreamMismatch', 422);
        }
        $milestoneId = $this->belongsToEvent('event_milestones', $organizationId, $eventId, $data['milestone_id'] ?? null);
        if ($milestoneId === false) {
            return Result::fail('MILESTONE_MISMATCH', 'Events.plan.errMilestoneMismatch', 422);
        }
        $assignee = trim((string) ($data['assignee_user_id'] ?? ''));
        if ($assignee !== '' && ! $this->userExists($organizationId, $assignee)) {
            return Result::notFound('Events.plan.errUserNotFound', 'USER_NOT_FOUND');
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_tasks')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'event_id'         => $eventId,
            'committee_id'     => $gate->meta['committee_id'],
            'workstream_id'    => $workstreamId,
            'milestone_id'     => $milestoneId,
            'title'            => mb_substr($title, 0, 200),
            'description'      => $this->textOrNull($data['description'] ?? null),
            'assignee_user_id' => $assignee !== '' ? $assignee : null,
            'responsibility'   => isset($data['responsibility']) && trim((string) $data['responsibility']) !== ''
                ? CommitteeResponsibility::normalize((string) $data['responsibility']) : null,
            'status'           => WorkPlan::STATUS_TODO,
            'priority'         => WorkPlan::normalizePriority((string) ($data['priority'] ?? WorkPlan::PRIORITY_NORMAL)),
            'progress_pct'     => max(0, min(99, (int) ($data['progress_pct'] ?? 0))),
            'due_at'           => WorkPlan::dateOf($data['due_at'] ?? null),
            'estimated_hours'  => $this->hoursOrNull($data['estimated_hours'] ?? null),
            'sort_order'       => (int) ($data['sort_order'] ?? $this->nextSort('event_tasks', $eventId)),
            'created_at'       => $now,
            'created_by'       => $actorId,
        ]);

        if ($workstreamId !== null) {
            $this->refreshWorkstream($organizationId, $workstreamId);
        }
        $this->writeAudit($organizationId, 'event.task.created', $actorId, 'event_task', $id, [
            'event_id' => $eventId, 'workstream_id' => $workstreamId, 'assignee_user_id' => $assignee !== '' ? $assignee : null,
            'due_at' => WorkPlan::dateOf($data['due_at'] ?? null),
        ]);

        return Result::created(['task_id' => $id, 'event_id' => $eventId, 'status' => WorkPlan::STATUS_TODO, 'workstream_id' => $workstreamId]);
    }

    /**
     * Update a task. Status changes go through {@see WorkPlan::canTransition()},
     * and finishing is refused while a finish-to-start predecessor is still open
     * unless the actor forces it (recorded in the audit trail, because overriding
     * a dependency is exactly the kind of thing a leader wants to see).
     *
     * @param array<string,mixed> $data
     */
    public function updateTask(string $organizationId, string $taskId, string $actorId, array $data): Result
    {
        $row = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('id', $taskId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errTaskNotFound', 'TASK_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }
        $eventId = (string) $row['event_id'];
        $from    = WorkPlan::normalizeStatus((string) $row['status']);
        $to      = isset($data['status']) ? WorkPlan::normalizeStatus((string) $data['status']) : $from;

        if ($to !== $from && ! WorkPlan::canTransition($from, $to)) {
            return Result::fail('BAD_TRANSITION', 'Events.plan.errBadTransition', 409, ['from' => $from, 'to' => $to]);
        }

        $patch = ['updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId];
        if (isset($data['title']) && trim((string) $data['title']) !== '') {
            $patch['title'] = mb_substr(trim((string) $data['title']), 0, 200);
        }
        if (array_key_exists('description', $data)) {
            $patch['description'] = $this->textOrNull($data['description']);
        }
        if (array_key_exists('workstream_id', $data)) {
            $ws = $this->belongsToEvent('event_workstreams', $organizationId, $eventId, $data['workstream_id']);
            if ($ws === false) {
                return Result::fail('WORKSTREAM_MISMATCH', 'Events.plan.errWorkstreamMismatch', 422);
            }
            $patch['workstream_id'] = $ws;
        }
        if (array_key_exists('milestone_id', $data)) {
            $ms = $this->belongsToEvent('event_milestones', $organizationId, $eventId, $data['milestone_id']);
            if ($ms === false) {
                return Result::fail('MILESTONE_MISMATCH', 'Events.plan.errMilestoneMismatch', 422);
            }
            $patch['milestone_id'] = $ms;
        }
        if (array_key_exists('assignee_user_id', $data)) {
            $assignee = trim((string) ($data['assignee_user_id'] ?? ''));
            if ($assignee !== '' && ! $this->userExists($organizationId, $assignee)) {
                return Result::notFound('Events.plan.errUserNotFound', 'USER_NOT_FOUND');
            }
            $patch['assignee_user_id'] = $assignee !== '' ? $assignee : null;
        }
        if (array_key_exists('responsibility', $data)) {
            $patch['responsibility'] = trim((string) ($data['responsibility'] ?? '')) !== ''
                ? CommitteeResponsibility::normalize((string) $data['responsibility']) : null;
        }
        if (isset($data['priority'])) {
            $patch['priority'] = WorkPlan::normalizePriority((string) $data['priority']);
        }
        if (array_key_exists('due_at', $data)) {
            $patch['due_at'] = WorkPlan::dateOf($data['due_at']);
        }
        if (array_key_exists('estimated_hours', $data)) {
            $patch['estimated_hours'] = $this->hoursOrNull($data['estimated_hours']);
        }
        if (array_key_exists('actual_hours', $data)) {
            $patch['actual_hours'] = $this->hoursOrNull($data['actual_hours']);
        }
        if (isset($data['sort_order'])) {
            $patch['sort_order'] = (int) $data['sort_order'];
        }
        if (isset($data['progress_pct'])) {
            $pct = (int) $data['progress_pct'];
            // In-flight work is clamped below 100: only finishing reaches 100.
            $patch['progress_pct'] = $to === WorkPlan::STATUS_DONE ? 100 : max(0, min(99, $pct));
            if ($pct > 0 && $to === WorkPlan::STATUS_TODO && ! isset($data['status'])) {
                $to                = WorkPlan::STATUS_IN_PROGRESS;
                $patch['status']   = $to;
                $patch['started_at'] = $row['started_at'] ?? $this->clock->nowUtcMicro();
            }
        }

        if ($to !== $from) {
            $patch['status'] = $to;
            if ($to === WorkPlan::STATUS_IN_PROGRESS && empty($row['started_at'])) {
                $patch['started_at'] = $this->clock->nowUtcMicro();
                $patch['blocked_reason'] = null;
                $patch['blocked_at']     = null;
            }
            if ($to === WorkPlan::STATUS_BLOCKED) {
                $reason = trim((string) ($data['blocked_reason'] ?? ''));
                if ($reason === '') {
                    return Result::fail('BLOCK_REASON_REQUIRED', 'Events.plan.errBlockReasonRequired', 422);
                }
                $patch['blocked_reason'] = mb_substr($reason, 0, 500);
                $patch['blocked_at']     = $this->clock->nowUtcMicro();
            }
            if ($to === WorkPlan::STATUS_DONE) {
                $open = $this->openPredecessors($organizationId, $taskId);
                if ($open !== [] && empty($data['force'])) {
                    return Result::fail('PREDECESSOR_OPEN', 'Events.plan.errPredecessorOpen', 409, ['predecessors' => $open]);
                }
                $patch['progress_pct']  = 100;
                $patch['completed_at']  = $this->clock->nowUtcMicro();
                $patch['completed_by']  = $actorId;
                $patch['blocked_reason'] = null;
                $patch['blocked_at']     = null;
                if ($open !== []) {
                    $this->writeAudit($organizationId, 'event.task.dependency_overridden', $actorId, 'event_task', $taskId, [
                        'event_id' => $eventId, 'predecessors' => $open,
                    ]);
                }
            }
            if ($to === WorkPlan::STATUS_CANCELLED) {
                $patch['progress_pct'] = 0;
            }
            if ($from === WorkPlan::STATUS_DONE && $to !== WorkPlan::STATUS_DONE) {
                // Reopened: the completion record stays in the audit trail, but the
                // row goes back to being live work.
                $patch['completed_at'] = null;
                $patch['completed_by'] = null;
                $patch['progress_pct'] = isset($data['progress_pct']) ? max(0, min(99, (int) $data['progress_pct'])) : 0;
            }
        } elseif ($to === WorkPlan::STATUS_BLOCKED && isset($data['blocked_reason'])) {
            $patch['blocked_reason'] = mb_substr(trim((string) $data['blocked_reason']), 0, 500);
        }

        $this->db->table('event_tasks')->where('id', $taskId)->update($patch);

        $wsId = $patch['workstream_id'] ?? $row['workstream_id'] ?? null;
        if (is_string($wsId) && $wsId !== '') {
            $this->refreshWorkstream($organizationId, $wsId);
        }
        if (isset($patch['workstream_id']) && $patch['workstream_id'] !== ($row['workstream_id'] ?? null) && ! empty($row['workstream_id'])) {
            $this->refreshWorkstream($organizationId, (string) $row['workstream_id']);
        }

        if ($to !== $from) {
            $this->writeAudit($organizationId, 'event.task.status_changed', $actorId, 'event_task', $taskId, [
                'event_id' => $eventId, 'from' => $from, 'to' => $to, 'assignee_user_id' => $row['assignee_user_id'] ?? null,
            ]);
        }

        return Result::ok(['task_id' => $taskId, 'status' => $to, 'progress_pct' => (int) ($patch['progress_pct'] ?? $row['progress_pct'] ?? 0)]);
    }

    /** Set a task's percentage, inferring the status move (0 ⇒ todo, 100 ⇒ done). */
    public function setProgress(string $organizationId, string $taskId, string $actorId, int $pct): Result
    {
        $pct = max(0, min(100, $pct));

        return $this->updateTask($organizationId, $taskId, $actorId, $pct === 100
            ? ['status' => WorkPlan::STATUS_DONE]
            : ['progress_pct' => $pct]);
    }

    /** Mark a task blocked with the reason it is blocked (required). */
    public function blockTask(string $organizationId, string $taskId, string $actorId, string $reason): Result
    {
        return $this->updateTask($organizationId, $taskId, $actorId, [
            'status'         => WorkPlan::STATUS_BLOCKED,
            'blocked_reason' => $reason,
        ]);
    }

    /** Unblock a task back into progress. */
    public function unblockTask(string $organizationId, string $taskId, string $actorId): Result
    {
        return $this->updateTask($organizationId, $taskId, $actorId, ['status' => WorkPlan::STATUS_IN_PROGRESS]);
    }

    /** Finish a task (respects open predecessors unless forced). */
    public function completeTask(string $organizationId, string $taskId, string $actorId, bool $force = false): Result
    {
        return $this->updateTask($organizationId, $taskId, $actorId, $force
            ? ['status' => WorkPlan::STATUS_DONE, 'force' => true]
            : ['status' => WorkPlan::STATUS_DONE]);
    }

    /** Cancel a task: it stays on the plan as history but leaves every roll-up. */
    public function cancelTask(string $organizationId, string $taskId, string $actorId, ?string $reason = null): Result
    {
        $res = $this->updateTask($organizationId, $taskId, $actorId, ['status' => WorkPlan::STATUS_CANCELLED]);
        if ($res->ok && $reason !== null && trim($reason) !== '') {
            $this->db->table('event_tasks')->where('id', $taskId)
                ->update(['description' => trim($reason), 'updated_at' => $this->clock->nowUtcMicro()]);
        }

        return $res;
    }

    /** Delete a task and its dependency edges (hard delete: a plan is not a record). */
    public function deleteTask(string $organizationId, string $taskId, string $actorId): Result
    {
        $row = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('id', $taskId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errTaskNotFound', 'TASK_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $this->db->table('event_task_dependencies')->where('task_id', $taskId)->delete();
        $this->db->table('event_task_dependencies')->where('depends_on_task_id', $taskId)->delete();
        $this->db->table('event_tasks')->where('id', $taskId)->delete();

        if (! empty($row['workstream_id'])) {
            $this->refreshWorkstream($organizationId, (string) $row['workstream_id']);
        }
        $this->writeAudit($organizationId, 'event.task.deleted', $actorId, 'event_task', $taskId, [
            'event_id' => (string) $row['event_id'], 'title' => (string) ($row['title'] ?? ''),
        ]);

        return Result::ok(['task_id' => $taskId, 'deleted' => true]);
    }

    // ----------------------------------------------------------- dependencies

    /**
     * Add a predecessor edge: `$taskId` waits for `$dependsOnTaskId`.
     * Refused when the two tasks are not on the same plan, when the edge already
     * exists, or when it would close a cycle (a plan whose tasks wait on each other
     * in a ring can never be worked, so the graph is kept a DAG at write time).
     */
    public function addDependency(string $organizationId, string $taskId, string $dependsOnTaskId, string $actorId, array $data = []): Result
    {
        if ($taskId === $dependsOnTaskId) {
            return Result::fail('SELF_DEPENDENCY', 'Events.plan.errSelfDependency', 422);
        }
        $task = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('id', $taskId)->get()->getRowArray();
        $pred = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('id', $dependsOnTaskId)->get()->getRowArray();
        if ($task === null || $pred === null) {
            return Result::notFound('Events.plan.errTaskNotFound', 'TASK_NOT_FOUND');
        }
        if ((string) $task['event_id'] !== (string) $pred['event_id']) {
            return Result::fail('CROSS_EVENT_DEPENDENCY', 'Events.plan.errCrossEventDependency', 422);
        }
        $gate = $this->gate($organizationId, (string) $task['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $edges = $this->db->table('event_task_dependencies')
            ->where('organization_id', $organizationId)->where('event_id', (string) $task['event_id'])
            ->get()->getResultArray();
        foreach ($edges as $e) {
            if ((string) $e['task_id'] === $taskId && (string) $e['depends_on_task_id'] === $dependsOnTaskId) {
                return Result::ok(['task_id' => $taskId, 'depends_on_task_id' => $dependsOnTaskId], 200, ['deduplicated' => true]);
            }
        }
        if (WorkPlan::wouldCreateCycle($edges, $taskId, $dependsOnTaskId)) {
            return Result::fail('DEPENDENCY_CYCLE', 'Events.plan.errDependencyCycle', 409, [
                'task_id' => $taskId, 'depends_on_task_id' => $dependsOnTaskId,
            ]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_task_dependencies')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'event_id'           => (string) $task['event_id'],
            'task_id'            => $taskId,
            'depends_on_task_id' => $dependsOnTaskId,
            'dependency_type'    => WorkPlan::normalizeDependencyType((string) ($data['dependency_type'] ?? WorkPlan::DEP_FINISH_TO_START)),
            'lag_days'           => max(-365, min(365, (int) ($data['lag_days'] ?? 0))),
            'created_at'         => $now,
            'created_by'         => $actorId,
        ]);

        return Result::created([
            'dependency_id'      => $id,
            'task_id'            => $taskId,
            'depends_on_task_id' => $dependsOnTaskId,
            'dependency_type'    => WorkPlan::normalizeDependencyType((string) ($data['dependency_type'] ?? WorkPlan::DEP_FINISH_TO_START)),
        ]);
    }

    /** Remove a predecessor edge (by the pair, so callers need not know the id). */
    public function removeDependency(string $organizationId, string $taskId, string $dependsOnTaskId, string $actorId): Result
    {
        $task = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('id', $taskId)->get()->getRowArray();
        if ($task === null) {
            return Result::notFound('Events.plan.errTaskNotFound', 'TASK_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $task['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $this->db->table('event_task_dependencies')
            ->where('task_id', $taskId)->where('depends_on_task_id', $dependsOnTaskId)->delete();

        return Result::ok(['task_id' => $taskId, 'depends_on_task_id' => $dependsOnTaskId, 'removed' => true]);
    }

    // ------------------------------------------------------------- milestones

    /** @param array<string,mixed> $data title (required), due_at (required), description, weight, workstream_id */
    public function createMilestone(string $organizationId, string $eventId, string $actorId, array $data): Result
    {
        $gate = $this->gate($organizationId, $eventId, $actorId);
        if (! $gate->ok) {
            return $gate;
        }
        $title = trim((string) ($data['title'] ?? ''));
        $due   = WorkPlan::dateOf($data['due_at'] ?? null);
        if ($title === '') {
            return Result::fail('TITLE_REQUIRED', 'Events.plan.errTitleRequired', 422);
        }
        if ($due === null) {
            // A milestone without a date is a wish, not a checkpoint.
            return Result::fail('DUE_DATE_REQUIRED', 'Events.plan.errDueDateRequired', 422);
        }
        $ws = $this->belongsToEvent('event_workstreams', $organizationId, $eventId, $data['workstream_id'] ?? null);
        if ($ws === false) {
            return Result::fail('WORKSTREAM_MISMATCH', 'Events.plan.errWorkstreamMismatch', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_milestones')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'committee_id'    => $gate->meta['committee_id'],
            'workstream_id'   => $ws,
            'title'           => mb_substr($title, 0, 200),
            'description'     => $this->textOrNull($data['description'] ?? null),
            'due_at'          => $due,
            'status'          => WorkPlan::MILESTONE_PENDING,
            'weight'          => max(1, min(100, (int) ($data['weight'] ?? 1))),
            'sort_order'      => (int) ($data['sort_order'] ?? $this->nextSort('event_milestones', $eventId)),
            'created_at'      => $now,
            'created_by'      => $actorId,
        ]);

        $this->writeAudit($organizationId, 'event.milestone.created', $actorId, 'event_milestone', $id, [
            'event_id' => $eventId, 'due_at' => $due, 'title' => mb_substr($title, 0, 200),
        ]);

        return Result::created(['milestone_id' => $id, 'event_id' => $eventId, 'due_at' => $due, 'status' => WorkPlan::MILESTONE_PENDING]);
    }

    /** @param array<string,mixed> $data title, description, due_at, weight, workstream_id, sort_order */
    public function updateMilestone(string $organizationId, string $milestoneId, string $actorId, array $data): Result
    {
        $row = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('id', $milestoneId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errMilestoneNotFound', 'MILESTONE_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $patch = ['updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId];
        if (isset($data['title']) && trim((string) $data['title']) !== '') {
            $patch['title'] = mb_substr(trim((string) $data['title']), 0, 200);
        }
        if (array_key_exists('description', $data)) {
            $patch['description'] = $this->textOrNull($data['description']);
        }
        if (isset($data['due_at'])) {
            $due = WorkPlan::dateOf($data['due_at']);
            if ($due === null) {
                return Result::fail('DUE_DATE_REQUIRED', 'Events.plan.errDueDateRequired', 422);
            }
            $patch['due_at'] = $due;
        }
        if (isset($data['weight'])) {
            $patch['weight'] = max(1, min(100, (int) $data['weight']));
        }
        if (array_key_exists('workstream_id', $data)) {
            $ws = $this->belongsToEvent('event_workstreams', $organizationId, (string) $row['event_id'], $data['workstream_id']);
            if ($ws === false) {
                return Result::fail('WORKSTREAM_MISMATCH', 'Events.plan.errWorkstreamMismatch', 422);
            }
            $patch['workstream_id'] = $ws;
        }
        if (isset($data['sort_order'])) {
            $patch['sort_order'] = (int) $data['sort_order'];
        }
        $this->db->table('event_milestones')->where('id', $milestoneId)->update($patch);

        return Result::ok(['milestone_id' => $milestoneId] + $patch);
    }

    /** Record a milestone as met, with the evidence that it was. */
    public function meetMilestone(string $organizationId, string $milestoneId, string $actorId, ?string $evidence = null): Result
    {
        return $this->setMilestoneStatus($organizationId, $milestoneId, $actorId, WorkPlan::MILESTONE_MET, $evidence);
    }

    /** Record a milestone as missed (it stays visible: a missed checkpoint is a fact). */
    public function missMilestone(string $organizationId, string $milestoneId, string $actorId, ?string $evidence = null): Result
    {
        return $this->setMilestoneStatus($organizationId, $milestoneId, $actorId, WorkPlan::MILESTONE_MISSED, $evidence);
    }

    /** @param array<string,mixed> $data re-open a milestone (status pending) */
    public function reopenMilestone(string $organizationId, string $milestoneId, string $actorId): Result
    {
        return $this->setMilestoneStatus($organizationId, $milestoneId, $actorId, WorkPlan::MILESTONE_PENDING, null);
    }

    public function deleteMilestone(string $organizationId, string $milestoneId, string $actorId): Result
    {
        $row = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('id', $milestoneId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errMilestoneNotFound', 'MILESTONE_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }
        $this->db->table('event_tasks')->where('milestone_id', $milestoneId)
            ->update(['milestone_id' => null, 'updated_at' => $this->clock->nowUtcMicro(), 'updated_by' => $actorId]);
        $this->db->table('event_milestones')->where('id', $milestoneId)->delete();

        return Result::ok(['milestone_id' => $milestoneId, 'deleted' => true]);
    }

    // ------------------------------------------------------------------ reads

    /**
     * The whole plan for one event: workstreams with their rolled-up progress, the
     * tasks (grouped and ordered), milestones, dependency edges, the derived plan
     * progress and health, and what needs attention now.
     *
     * @return array<string,mixed>
     */
    public function plan(string $organizationId, string $eventId, int $dueSoonDays = 3): array
    {
        $today       = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $workstreams = $this->db->table('event_workstreams')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('sort_order', 'ASC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
        $tasks = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('sort_order', 'ASC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
        $milestones = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('due_at', 'ASC')->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();
        $edges = $this->db->table('event_task_dependencies')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->get()->getResultArray();

        $byId = [];
        foreach ($tasks as $t) {
            $byId[(string) $t['id']] = $t;
        }

        // Annotate each task with its risk, completion and the predecessors that
        // still gate it, then order the plan topologically so it reads as a schedule.
        $annotated = [];
        foreach ($tasks as $t) {
            $id     = (string) $t['id'];
            $status = WorkPlan::normalizeStatus((string) ($t['status'] ?? WorkPlan::STATUS_TODO));
            $annotated[$id] = $t + [
                'status'        => $status,
                'completion'    => WorkPlan::completionOf($t),
                'risk'          => WorkPlan::riskOf($t, $today, $dueSoonDays),
                'predecessors'  => WorkPlan::openPredecessors($id, $edges, $byId),
            ];
        }
        $order = WorkPlan::topoSort(array_keys($annotated), $edges);
        $ordered = [];
        foreach ($order as $id) {
            if (isset($annotated[$id])) {
                $ordered[] = $annotated[$id];
            }
        }

        $streams = WorkPlan::byWorkstream($workstreams, $tasks, $today, $dueSoonDays);
        $plan    = WorkPlan::planProgress($tasks, $milestones, $today, $dueSoonDays);

        // Bucket the ordered tasks per workstream for rendering, keeping the
        // topological order inside each bucket.
        $tasksByStream = [];
        $unstreamed    = [];
        foreach ($ordered as $t) {
            $ws = (string) ($t['workstream_id'] ?? '');
            if ($ws === '') {
                $unstreamed[] = $t;

                continue;
            }
            $tasksByStream[$ws][] = $t;
        }
        foreach ($streams['streams'] as $i => $s) {
            $streams['streams'][$i]['tasks'] = $tasksByStream[(string) $s['id']] ?? [];
        }

        return [
            'event_id'      => $eventId,
            'today'         => $today,
            'workstreams'   => $streams['streams'],
            'unstreamed'    => $unstreamed,
            'tasks'         => $ordered,
            'milestones'    => $milestones,
            'dependencies'  => $edges,
            'progress_pct'  => $plan['progress_pct'],
            'health'        => $plan['health'],
            'task_rollup'   => $plan['tasks'],
            'milestone_rollup' => $plan['milestones'],
            'attention'     => $this->attention($ordered, $milestones, $today, $dueSoonDays),
        ];
    }

    /** Tasks grouped into status columns (the no-JS board). @return array<string,list<array<string,mixed>>> */
    public function board(string $organizationId, string $eventId, int $dueSoonDays = 3): array
    {
        $today = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $tasks = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('sort_order', 'ASC')->get()->getResultArray();

        $columns = [
            WorkPlan::STATUS_TODO        => [],
            WorkPlan::STATUS_IN_PROGRESS => [],
            WorkPlan::STATUS_BLOCKED     => [],
            WorkPlan::STATUS_DONE        => [],
            WorkPlan::STATUS_CANCELLED   => [],
        ];
        foreach ($tasks as $t) {
            $status = WorkPlan::normalizeStatus((string) ($t['status'] ?? WorkPlan::STATUS_TODO));
            $t['risk'] = WorkPlan::riskOf($t, $today, $dueSoonDays);
            $t['completion'] = WorkPlan::completionOf($t);
            $columns[$status][] = $t;
        }
        // Urgent first inside each column, then by due date.
        foreach ($columns as $status => $rows) {
            usort($rows, static function (array $a, array $b): int {
                $p = WorkPlan::priorityWeight((string) ($b['priority'] ?? '')) <=> WorkPlan::priorityWeight((string) ($a['priority'] ?? ''));
                if ($p !== 0) {
                    return $p;
                }
                $ad = WorkPlan::dateOf($a['due_at'] ?? null) ?? '9999-12-31';
                $bd = WorkPlan::dateOf($b['due_at'] ?? null) ?? '9999-12-31';

                return strcmp($ad, $bd);
            });
            $columns[$status] = $rows;
        }

        return $columns;
    }

    /**
     * One user's open work across the events they sit on (or, for a leader, across
     * the events in their scope): the "what do I owe?" list.
     *
     * @return list<array<string,mixed>>
     */
    public function myTasks(string $organizationId, string $userId, bool $includeDone = false, int $limit = 100): array
    {
        $today = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $q = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)
            ->where('assignee_user_id', $userId)
            ->orderBy('due_at', 'ASC')
            ->limit(max(1, min($limit, 500)));
        if (! $includeDone) {
            $q->whereIn('status', [WorkPlan::STATUS_TODO, WorkPlan::STATUS_IN_PROGRESS, WorkPlan::STATUS_BLOCKED]);
        }

        $rows = $q->get()->getResultArray();
        foreach ($rows as $i => $t) {
            $rows[$i]['risk']       = WorkPlan::riskOf($t, $today);
            $rows[$i]['completion'] = WorkPlan::completionOf($t);
        }

        return $rows;
    }

    /**
     * What needs attention: late tasks, tasks due inside the look-ahead window,
     * blocked tasks with their reason, unassigned open tasks and overdue
     * milestones. This is the chair's morning list and the leader's oversight view.
     *
     * @return array{late:list<array<string,mixed>>,due_soon:list<array<string,mixed>>,blocked:list<array<string,mixed>>,unassigned:list<array<string,mixed>>,overdue_milestones:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function atRisk(string $organizationId, string $eventId, int $dueSoonDays = 3): array
    {
        $today = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $tasks = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->get()->getResultArray();
        $milestones = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->where('status', WorkPlan::MILESTONE_PENDING)
            ->get()->getResultArray();

        return $this->attention($tasks, $milestones, $today, $dueSoonDays);
    }

    /** The event's plan progress + health, without the row detail. @return array<string,mixed> */
    public function progress(string $organizationId, string $eventId, int $dueSoonDays = 3): array
    {
        $today = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $tasks = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)->get()->getResultArray();
        $milestones = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)->get()->getResultArray();

        return WorkPlan::planProgress($tasks, $milestones, $today, $dueSoonDays) + ['event_id' => $eventId, 'today' => $today];
    }

    /** @return list<array<string,mixed>> */
    public function listWorkstreams(string $organizationId, string $eventId): array
    {
        return $this->db->table('event_workstreams')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('sort_order', 'ASC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function listTasks(string $organizationId, string $eventId, ?string $workstreamId = null): array
    {
        $q = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('sort_order', 'ASC');
        if ($workstreamId !== null && $workstreamId !== '') {
            $q->where('workstream_id', $workstreamId);
        }

        return $q->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> */
    public function listMilestones(string $organizationId, string $eventId): array
    {
        return $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->orderBy('due_at', 'ASC')->get()->getResultArray();
    }

    /**
     * The event a plan row belongs to — a task, workstream or milestone — so a
     * controller holding only the row id can redirect back to the right plan.
     */
    public function eventIdFor(string $organizationId, string $rowId): ?string
    {
        foreach (['event_tasks', 'event_workstreams', 'event_milestones'] as $table) {
            $row = $this->db->table($table)->select('event_id')
                ->where('organization_id', $organizationId)->where('id', $rowId)
                ->get()->getRowArray();
            if ($row !== null) {
                return (string) $row['event_id'];
            }
        }

        return null;
    }

    /**
     * Recompute a workstream's counters and derived status from its live tasks.
     * Called after every task mutation so listing stays cheap and the numbers on
     * screen are never stale.
     */
    public function refreshWorkstream(string $organizationId, string $workstreamId): void
    {
        $row = $this->db->table('event_workstreams')
            ->where('organization_id', $organizationId)->where('id', $workstreamId)->get()->getRowArray();
        if ($row === null) {
            return;
        }
        $today = WorkPlan::dateOf($this->clock->nowUtcString()) ?? '';
        $tasks = $this->db->table('event_tasks')
            ->where('workstream_id', $workstreamId)->get()->getResultArray();
        $rolled = WorkPlan::rollUp($tasks, $today);
        $derived = WorkPlan::byWorkstream([$row], $tasks, $today)['streams'][0] ?? null;

        $this->db->table('event_workstreams')->where('id', $workstreamId)->update([
            'task_count'   => $rolled['total'],
            'done_count'   => $rolled['done'],
            'progress_pct' => $rolled['progress_pct'],
            'status'       => (string) ($derived['status'] ?? $row['status']),
            'updated_at'   => $this->clock->nowUtcMicro(),
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * The one gate every write passes: the event exists, its group has committees
     * enabled (default OFF), and the actor may work this plan — the leader whose
     * scope covers the event's group, or an active committee member.
     *
     * @return Result ok ⇒ meta carries {event, committee_id, config}
     */
    private function gate(string $organizationId, string $eventId, string $actorId): Result
    {
        $event = $this->db->table('events')
            ->where('organization_id', $organizationId)->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('Events.plan.errEventNotFound', 'EVENT_NOT_FOUND');
        }

        $groupId = isset($event['group_id']) && $event['group_id'] !== '' ? (string) $event['group_id'] : null;
        $cfg     = CommitteeConfig::fromResolved($this->configValue($groupId));
        if (! $cfg->enabled) {
            return Result::fail('PLAN_DISABLED', 'Events.plan.errDisabled', 403, ['capability' => CommitteeConfig::CAPABILITY]);
        }

        $committee = $this->db->table('event_committees')
            ->where('organization_id', $organizationId)->where('event_id', $eventId)
            ->where('status', 'active')->get()->getRowArray();
        $committeeId = $committee !== null ? (string) $committee['id'] : null;

        // Who may work this plan: an ACTIVE committee member (the seat itself is the
        // authority to plan), or somebody who HOLDS `event.logistics.manage` over the
        // event's group — the organizer, or the leader above them. That is a PDP
        // decision, so a delegation counts and being merely scoped to the group does
        // not: reach is not authority.
        $member = $committeeId !== null && $this->committees !== null
            ? $this->committees->memberRow($organizationId, $eventId, $actorId) : null;
        if ($member === null) {
            $anchor  = $committee !== null && ! empty($committee['oversight_group_id'])
                ? (string) $committee['oversight_group_id'] : $groupId;
            $mayPlan = $this->authority !== null
                && $this->authority->holds($organizationId, $actorId, 'event.logistics.manage', $anchor);
            if (! $mayPlan) {
                return Result::denied('Events.plan.errNotAuthorized', 'NOT_AUTHORIZED');
            }
        }

        return Result::ok(null, 200, [
            'event'        => $event,
            'committee_id' => $committeeId,
            'config'       => $cfg,
            'member'       => $member,
        ]);
    }

    /** Predecessors that still gate a task, annotated with their titles. @return list<array<string,mixed>> */
    private function openPredecessors(string $organizationId, string $taskId): array
    {
        $task = $this->db->table('event_tasks')->where('id', $taskId)->get()->getRowArray();
        if ($task === null) {
            return [];
        }
        $edges = $this->db->table('event_task_dependencies')
            ->where('organization_id', $organizationId)->where('event_id', (string) $task['event_id'])
            ->get()->getResultArray();
        $tasks = $this->db->table('event_tasks')
            ->where('organization_id', $organizationId)->where('event_id', (string) $task['event_id'])
            ->get()->getResultArray();
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(string) $t['id']] = $t;
        }

        return WorkPlan::openPredecessors($taskId, $edges, $byId);
    }

    /**
     * Build the attention lists from already-loaded rows (shared by plan() and
     * atRisk() so the two can never disagree).
     *
     * @param list<array<string,mixed>> $tasks
     * @param list<array<string,mixed>> $milestones
     *
     * @return array<string,mixed>
     */
    private function attention(array $tasks, array $milestones, string $today, int $dueSoonDays): array
    {
        $late = [];
        $soon = [];
        $blocked = [];
        $unassigned = [];
        foreach ($tasks as $t) {
            $status = WorkPlan::normalizeStatus((string) ($t['status'] ?? WorkPlan::STATUS_TODO));
            if ($status === WorkPlan::STATUS_DONE || $status === WorkPlan::STATUS_CANCELLED) {
                continue;
            }
            $risk = WorkPlan::riskOf($t, $today, $dueSoonDays);
            $t['risk'] = $risk;
            if ($risk === 'late') {
                $late[] = $t;
            } elseif ($risk === 'due_soon') {
                $soon[] = $t;
            } elseif ($risk === 'blocked') {
                $blocked[] = $t;
            }
            if (trim((string) ($t['assignee_user_id'] ?? '')) === '') {
                $unassigned[] = $t;
            }
        }

        $overdue = [];
        foreach ($milestones as $m) {
            $status = WorkPlan::normalizeMilestoneStatus((string) ($m['status'] ?? WorkPlan::MILESTONE_PENDING));
            $due    = WorkPlan::dateOf($m['due_at'] ?? null);
            if ($status === WorkPlan::MILESTONE_PENDING && $today !== '' && $due !== null && $due < $today) {
                $overdue[] = $m;
            }
        }

        return [
            'late'               => $late,
            'due_soon'           => $soon,
            'blocked'            => $blocked,
            'unassigned'         => $unassigned,
            'overdue_milestones' => $overdue,
            'counts'             => [
                'late'               => count($late),
                'due_soon'           => count($soon),
                'blocked'            => count($blocked),
                'unassigned'         => count($unassigned),
                'overdue_milestones' => count($overdue),
            ],
        ];
    }

    private function setMilestoneStatus(string $organizationId, string $milestoneId, string $actorId, string $status, ?string $evidence): Result
    {
        $row = $this->db->table('event_milestones')
            ->where('organization_id', $organizationId)->where('id', $milestoneId)->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('Events.plan.errMilestoneNotFound', 'MILESTONE_NOT_FOUND');
        }
        $gate = $this->gate($organizationId, (string) $row['event_id'], $actorId);
        if (! $gate->ok) {
            return $gate;
        }

        $now   = $this->clock->nowUtcMicro();
        $patch = [
            'status'     => $status,
            'updated_at' => $now,
            'updated_by' => $actorId,
        ];
        if ($evidence !== null && trim($evidence) !== '') {
            $patch['evidence'] = mb_substr(trim($evidence), 0, 500);
        }
        if ($status === WorkPlan::MILESTONE_MET) {
            $patch['met_at'] = $now;
            $patch['met_by'] = $actorId;
        } elseif ($status === WorkPlan::MILESTONE_PENDING) {
            $patch['met_at'] = null;
            $patch['met_by'] = null;
        }
        $this->db->table('event_milestones')->where('id', $milestoneId)->update($patch);

        $this->writeAudit($organizationId, 'event.milestone.' . $status, $actorId, 'event_milestone', $milestoneId, [
            'event_id' => (string) $row['event_id'], 'due_at' => $row['due_at'] ?? null, 'evidence' => $patch['evidence'] ?? null,
        ]);

        return Result::ok(['milestone_id' => $milestoneId, 'status' => $status]);
    }

    /** Validate that a referenced child row belongs to THIS event. false ⇒ mismatch. */
    private function belongsToEvent(string $table, string $organizationId, string $eventId, mixed $id): string|false|null
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') {
            return null;
        }
        $row = $this->db->table($table)
            ->where('organization_id', $organizationId)->where('id', $id)->get()->getRowArray();
        if ($row === null || (string) ($row['event_id'] ?? '') !== $eventId) {
            return false;
        }

        return $id;
    }

    private function nextSort(string $table, string $eventId): int
    {
        $rows = $this->db->table($table)->select('sort_order')
            ->where('event_id', $eventId)->get()->getResultArray();
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int) ($r['sort_order'] ?? 0));
        }

        return $max + 10;
    }

    private function configValue(?string $groupId): mixed
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }

        try {
            return $this->config->value($groupId, CommitteeConfig::CAPABILITY);
        } catch (Throwable) {
            return null;
        }
    }

    private function userExists(string $organizationId, string $userId): bool
    {
        return $this->db->table('users')
            ->where('organization_id', $organizationId)->where('id', $userId)->countAllResults() > 0;
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private function hoursOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $h = round((float) $value, 2);

        return $h >= 0 && $h <= 9999.99 ? $h : null;
    }

    /** @param array<string,mixed> $metadata */
    private function writeAudit(string $organizationId, string $action, string $actorId, string $objectType, string $objectId, array $metadata): void
    {
        $this->audit?->record($organizationId, [
            'action'      => $action,
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => $objectType,
            'object_id'   => $objectId,
            'outcome'     => 'success',
            'metadata'    => $metadata,
        ]);
    }
}
