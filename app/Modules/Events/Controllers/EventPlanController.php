<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Events\Support\CommitteeResponsibility;
use WBS\Events\Support\WorkPlan;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * The event's PLAN — the project-management console: workstreams, tasks with owners
 * and due dates, dependencies between them, milestones, and the progress/late/
 * at-risk numbers derived from all of it.
 *
 * It sits on top of the event's existing surfaces rather than beside them: a task
 * may say "book the venue" while the booking itself still lives in logistics, or
 * "submit the transport budget" while the money still runs through expenses with its
 * own approval. This tracks whether the work got done, by whom, by when, and what it
 * is waiting on — nothing here re-implements another module's gate.
 *
 * AUTHORIZATION follows {@see CommitteeController}: `auth` (+ `webcsrf`) at the route,
 * and the authoritative decision in EventWorkService::gate() — an active committee
 * seat, or `event.logistics.manage` over the event's group asked of the platform's
 * PDP (so a delegation counts and mere scope coverage does not). The whole surface is
 * also behind the hierarchical group capability `event_committee`, DEFAULT OFF: a body
 * that has not opted in gets a page that says so and no writes at all.
 *
 * No JavaScript: every action is a PRG form, so the plan is workable on a phone in a
 * hall with one bar of signal, and the page renders inside the platform's CSP.
 */
final class EventPlanController extends BaseController
{
    /** GET /events/{id}/plan — the whole plan on one page. */
    public function console(string $eventId = '')
    {
        $org   = $this->orgId();
        $actor = (string) ($this->actorId() ?? '');
        $plan  = EventServices::eventWork(false)->plan($org, $eventId);
        $event = EventServices::events()->find($eventId);

        $names = EventServices::eventCommittees(false)->displayNames($org, $this->personIds($plan));

        $data = [
            'eventId'          => $eventId,
            'event'            => is_array($event) ? $event : null,
            'plan'             => $plan,
            'board'            => EventServices::eventWork(false)->board($org, $eventId),
            'myTasks'          => EventServices::eventWork(false)->myTasks($org, $actor, false, 25),
            'names'            => $names,
            'roster'           => IdentityServices::accounts()->listMembers($org, 'active'),
            'committee'        => EventServices::eventCommittees(false)->find($org, $eventId),
            'responsibilities' => CommitteeResponsibility::ALL,
            'priorities'       => WorkPlan::PRIORITIES,
            'statuses'         => WorkPlan::TASK_STATUSES,
            'depTypes'         => WorkPlan::DEPENDENCY_TYPES,
            'actorId'          => $actor,
        ];

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($data));
        }

        return $this->renderForm('WBS\Events\Views\event_plan', $data);
    }

    // ------------------------------------------------------------- workstreams

    /** POST /events/{id}/plan/workstreams */
    public function addWorkstream(string $eventId = '')
    {
        return $this->respondPlan(
            EventServices::eventWork(false)->createWorkstream($this->orgId(), $eventId, $this->actor(), [
                'name'            => (string) $this->field('name', ''),
                'description'     => $this->field('description', null),
                'owner_user_id'   => (string) $this->field('owner_user_id', ''),
                'responsibility'  => $this->field('responsibility', null),
                'weight'          => $this->field('weight', 1),
                'start_date'      => $this->field('start_date', null),
                'due_date'        => $this->field('due_date', null),
            ]),
            $eventId,
            'flashWorkstreamAdded',
        );
    }

    /** POST /event-plan/workstreams/{id} */
    public function updateWorkstream(string $workstreamId = '')
    {
        $eventId = $this->eventFor($workstreamId);
        $data    = [];
        foreach (['name', 'description', 'owner_user_id', 'responsibility', 'weight', 'start_date', 'due_date', 'sort_order', 'status'] as $key) {
            $value = $this->field($key, null);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $this->respondPlan(
            EventServices::eventWork(false)->updateWorkstream($this->orgId(), $workstreamId, $this->actor(), $data),
            $eventId,
            'flashWorkstreamUpdated',
        );
    }

    /** POST /event-plan/workstreams/{id}/delete */
    public function deleteWorkstream(string $workstreamId = '')
    {
        $eventId = $this->eventFor($workstreamId);

        return $this->respondPlan(
            EventServices::eventWork(false)->deleteWorkstream($this->orgId(), $workstreamId, $this->actor(), (string) $this->field('reassign', '') !== ''),
            $eventId,
            'flashWorkstreamDeleted',
        );
    }

    // ------------------------------------------------------------------- tasks

    /** POST /events/{id}/plan/tasks */
    public function addTask(string $eventId = '')
    {
        return $this->respondPlan(
            EventServices::eventWork(false)->createTask($this->orgId(), $eventId, $this->actor(), [
                'title'            => (string) $this->field('title', ''),
                'description'      => $this->field('description', null),
                'workstream_id'    => $this->field('workstream_id', null),
                'milestone_id'     => $this->field('milestone_id', null),
                'assignee_user_id' => (string) $this->field('assignee_user_id', ''),
                'responsibility'   => $this->field('responsibility', null),
                'priority'         => (string) $this->field('priority', WorkPlan::PRIORITY_NORMAL),
                'due_at'           => $this->field('due_at', null),
                'estimated_hours'  => $this->field('estimated_hours', null),
            ]),
            $eventId,
            'flashTaskAdded',
        );
    }

    /** POST /event-plan/tasks/{id} — edit the task's fields. */
    public function updateTask(string $taskId = '')
    {
        $eventId = $this->eventFor($taskId);
        $data    = [];
        foreach (['title', 'description', 'workstream_id', 'milestone_id', 'assignee_user_id', 'responsibility', 'priority', 'due_at', 'estimated_hours', 'actual_hours', 'sort_order', 'progress_pct', 'status', 'blocked_reason'] as $key) {
            $value = $this->field($key, null);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $this->respondPlan(
            EventServices::eventWork(false)->updateTask($this->orgId(), $taskId, $this->actor(), $data),
            $eventId,
            'flashTaskUpdated',
        );
    }

    /**
     * POST /event-plan/tasks/{id}/action — the one-click moves a plan needs:
     * start, block (with the reason), unblock, done (respecting dependencies unless
     * forced), cancel, reopen.
     */
    public function taskAction(string $taskId = '')
    {
        $eventId = $this->eventFor($taskId);
        $work    = EventServices::eventWork(false);
        $actor   = $this->actor();
        $action  = strtolower(trim((string) $this->field('action', '')));
        $reason  = $this->field('reason', null);

        $result = match ($action) {
            'start'   => $work->updateTask($this->orgId(), $taskId, $actor, ['status' => WorkPlan::STATUS_IN_PROGRESS]),
            'block'   => $work->blockTask($this->orgId(), $taskId, $actor, (string) ($reason ?? '')),
            'unblock' => $work->unblockTask($this->orgId(), $taskId, $actor),
            'done'    => $work->completeTask($this->orgId(), $taskId, $actor, (string) $this->field('force', '') !== ''),
            'cancel'  => $work->cancelTask($this->orgId(), $taskId, $actor, $reason === null ? null : (string) $reason),
            'reopen'  => $work->updateTask($this->orgId(), $taskId, $actor, ['status' => WorkPlan::STATUS_TODO]),
            'delete'  => $work->deleteTask($this->orgId(), $taskId, $actor),
            default   => Result::fail('UNKNOWN_ACTION', 'Events.plan.errBadStatus', 422, ['action' => $action]),
        };

        $okKey = $action === 'done' ? 'flashTaskUpdated' : ($action === 'delete' ? 'flashTaskDeleted' : 'flashTaskUpdated');

        return $this->respondPlan($result, $eventId, $okKey);
    }

    /** POST /event-plan/tasks/{id}/delete */
    public function deleteTask(string $taskId = '')
    {
        $eventId = $this->eventFor($taskId);

        return $this->respondPlan(
            EventServices::eventWork(false)->deleteTask($this->orgId(), $taskId, $this->actor()),
            $eventId,
            'flashTaskDeleted',
        );
    }

    // ------------------------------------------------------------ dependencies

    /** POST /event-plan/tasks/{id}/dependencies — task {id} waits for another. */
    public function addDependency(string $taskId = '')
    {
        $eventId = $this->eventFor($taskId);

        return $this->respondPlan(
            EventServices::eventWork(false)->addDependency(
                $this->orgId(),
                $taskId,
                (string) $this->field('depends_on_task_id', ''),
                $this->actor(),
                [
                    'dependency_type' => (string) $this->field('dependency_type', WorkPlan::DEP_FINISH_TO_START),
                    'lag_days'        => (int) $this->field('lag_days', 0),
                ],
            ),
            $eventId,
            'flashDepAdded',
        );
    }

    /** POST /event-plan/tasks/{id}/dependencies/remove */
    public function removeDependency(string $taskId = '')
    {
        $eventId = $this->eventFor($taskId);

        return $this->respondPlan(
            EventServices::eventWork(false)->removeDependency($this->orgId(), $taskId, (string) $this->field('depends_on_task_id', ''), $this->actor()),
            $eventId,
            'flashDepAdded',
        );
    }

    // -------------------------------------------------------------- milestones

    /** POST /events/{id}/plan/milestones */
    public function addMilestone(string $eventId = '')
    {
        return $this->respondPlan(
            EventServices::eventWork(false)->createMilestone($this->orgId(), $eventId, $this->actor(), [
                'title'         => (string) $this->field('title', ''),
                'description'   => $this->field('description', null),
                'due_at'        => $this->field('due_at', null),
                'weight'        => $this->field('weight', 1),
                'workstream_id' => $this->field('workstream_id', null),
            ]),
            $eventId,
            'flashMilestoneAdded',
        );
    }

    /** POST /event-plan/milestones/{id}/action — meet (with evidence), miss, reopen. */
    public function milestoneAction(string $milestoneId = '')
    {
        $eventId  = $this->eventFor($milestoneId);
        $work     = EventServices::eventWork(false);
        $actor    = $this->actor();
        $action   = strtolower(trim((string) $this->field('action', '')));
        $evidence = $this->field('evidence', null);

        $result = match ($action) {
            'meet'   => $work->meetMilestone($this->orgId(), $milestoneId, $actor, $evidence === null ? null : (string) $evidence),
            'miss'   => $work->missMilestone($this->orgId(), $milestoneId, $actor, $evidence === null ? null : (string) $evidence),
            'reopen' => $work->reopenMilestone($this->orgId(), $milestoneId, $actor),
            'delete' => $work->deleteMilestone($this->orgId(), $milestoneId, $actor),
            default  => Result::fail('UNKNOWN_ACTION', 'Events.plan.errBadStatus', 422, ['action' => $action]),
        };

        return $this->respondPlan($result, $eventId, $action === 'meet' ? 'flashMilestoneMet' : 'flashMilestoneAdded');
    }

    /** POST /event-plan/milestones/{id}/delete */
    public function deleteMilestone(string $milestoneId = '')
    {
        $eventId = $this->eventFor($milestoneId);

        return $this->respondPlan(
            EventServices::eventWork(false)->deleteMilestone($this->orgId(), $milestoneId, $this->actor()),
            $eventId,
            'flashMilestoneAdded',
        );
    }

    // --------------------------------------------------------------- internals

    private function actor(): string
    {
        return (string) ($this->actorId() ?? '');
    }

    /** The event a plan row belongs to, for the redirect back. */
    private function eventFor(string $rowId): string
    {
        return (string) (EventServices::eventWork(false)->eventIdFor($this->orgId(), $rowId) ?? '');
    }

    /**
     * Every person the plan mentions, so the console can show names in ONE lookup
     * instead of one query per row.
     *
     * @param array<string,mixed> $plan
     *
     * @return list<string>
     */
    private function personIds(array $plan): array
    {
        $ids = [];
        foreach ($plan['tasks'] ?? [] as $t) {
            $ids[] = (string) ($t['assignee_user_id'] ?? '');
        }
        foreach ($plan['workstreams'] ?? [] as $w) {
            $ids[] = (string) ($w['owner_user_id'] ?? '');
        }

        return array_values(array_unique(array_filter($ids, static fn (string $v): bool => $v !== '')));
    }

    /** PRG back to the plan console. */
    private function respondPlan(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/plan' : '/event-committees';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.plan.' . $okKey));
    }
}
