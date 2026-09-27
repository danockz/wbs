<?php

declare(strict_types=1);

namespace WBS\Events\Support;

/**
 * Pure arithmetic for an event's plan: progress roll-up, late/at-risk detection,
 * milestone health and dependency-graph rules.
 *
 * Deliberately DB-free and side-effect free (the same shape as
 * {@see \WBS\Events\Services\EventReadiness}) so the numbers a leader sees can be
 * unit-tested without a database and reused by the console, the API and the
 * close-out report. `EventWorkService` owns the rows; this class owns the maths.
 *
 * Conventions:
 *  - `cancelled` tasks are EXCLUDED from every roll-up (they are history, not work);
 *  - a `done` task is 100% whatever its stored `progress_pct` says, and any
 *    in-flight percentage is clamped to 0..99 so "done" is only ever reached by
 *    actually finishing;
 *  - dates are compared as `Y-m-d` strings against the caller's "today", so the
 *    clock stays injectable and the logic stays deterministic;
 *  - the dependency graph must stay a DAG: `wouldCreateCycle()` is checked before
 *    an edge is written, never after.
 */
final class WorkPlan
{
    public const STATUS_TODO        = 'todo';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED     = 'blocked';
    public const STATUS_DONE        = 'done';
    public const STATUS_CANCELLED   = 'cancelled';

    /** @var list<string> */
    public const TASK_STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /** @var list<string> */
    public const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT];

    public const MILESTONE_PENDING   = 'pending';
    public const MILESTONE_MET       = 'met';
    public const MILESTONE_MISSED    = 'missed';
    public const MILESTONE_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const MILESTONE_STATUSES = [
        self::MILESTONE_PENDING,
        self::MILESTONE_MET,
        self::MILESTONE_MISSED,
        self::MILESTONE_CANCELLED,
    ];

    public const WS_OPEN    = 'open';
    public const WS_TRACK   = 'on_track';
    public const WS_AT_RISK = 'at_risk';
    public const WS_BLOCKED = 'blocked';
    public const WS_DONE    = 'done';

    /** @var list<string> */
    public const WORKSTREAM_STATUSES = [self::WS_OPEN, self::WS_TRACK, self::WS_AT_RISK, self::WS_BLOCKED, self::WS_DONE];

    public const DEP_FINISH_TO_START = 'finish_to_start';
    public const DEP_START_TO_START  = 'start_to_start';
    public const DEP_FINISH_TO_FINISH = 'finish_to_finish';

    /** @var list<string> */
    public const DEPENDENCY_TYPES = [self::DEP_FINISH_TO_START, self::DEP_START_TO_START, self::DEP_FINISH_TO_FINISH];

    /** Risk labels returned by riskOf(). @var list<string> */
    public const RISKS = ['ok', 'due_soon', 'late', 'blocked'];

    /** Plan-health verdicts returned by planProgress(). @var list<string> */
    public const HEALTH = ['on_track', 'at_risk', 'behind', 'blocked', 'complete'];

    /**
     * Allowed status transitions. `cancelled` is terminal; `done` may be reopened
     * (a finished task can be sent back), which is why it is not.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::STATUS_TODO        => [self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED, self::STATUS_DONE, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_BLOCKED, self::STATUS_DONE, self::STATUS_TODO, self::STATUS_CANCELLED],
        self::STATUS_BLOCKED     => [self::STATUS_IN_PROGRESS, self::STATUS_TODO, self::STATUS_CANCELLED],
        self::STATUS_DONE        => [self::STATUS_IN_PROGRESS, self::STATUS_TODO],
        self::STATUS_CANCELLED   => [self::STATUS_TODO],
    ];

    private function __construct()
    {
    }

    public static function normalizeStatus(?string $status): string
    {
        $s = strtolower(trim((string) $status));

        return in_array($s, self::TASK_STATUSES, true) ? $s : self::STATUS_TODO;
    }

    public static function normalizePriority(?string $priority): string
    {
        $p = strtolower(trim((string) $priority));

        return in_array($p, self::PRIORITIES, true) ? $p : self::PRIORITY_NORMAL;
    }

    public static function normalizeDependencyType(?string $type): string
    {
        $t = strtolower(trim((string) $type));

        return in_array($t, self::DEPENDENCY_TYPES, true) ? $t : self::DEP_FINISH_TO_START;
    }

    public static function normalizeMilestoneStatus(?string $status): string
    {
        $s = strtolower(trim((string) $status));

        return in_array($s, self::MILESTONE_STATUSES, true) ? $s : self::MILESTONE_PENDING;
    }

    public static function canTransition(string $from, string $to): bool
    {
        $from = self::normalizeStatus($from);
        $to   = self::normalizeStatus($to);
        if ($from === $to) {
            return true; // a no-op update is always allowed
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Priority sort weight (higher sorts first). */
    public static function priorityWeight(string $priority): int
    {
        return match (self::normalizePriority($priority)) {
            self::PRIORITY_URGENT => 4,
            self::PRIORITY_HIGH   => 3,
            self::PRIORITY_LOW    => 1,
            default               => 2,
        };
    }

    /**
     * The percentage a task actually represents: done ⇒ 100, cancelled ⇒ 0
     * (excluded anyway), otherwise the stored value clamped to 0..99.
     *
     * @param array<string,mixed> $task
     */
    public static function completionOf(array $task): int
    {
        $status = self::normalizeStatus((string) ($task['status'] ?? self::STATUS_TODO));
        if ($status === self::STATUS_DONE) {
            return 100;
        }
        if ($status === self::STATUS_CANCELLED) {
            return 0;
        }
        $pct = (int) ($task['progress_pct'] ?? 0);

        return max(0, min(99, $pct));
    }

    /**
     * Roll a set of tasks up into counts + a percentage.
     *
     * @param list<array<string,mixed>> $tasks
     *
     * @return array{progress_pct:int,total:int,open:int,done:int,blocked:int,cancelled:int,late:int,due_soon:int,unassigned:int}
     */
    public static function rollUp(array $tasks, string $today = '', int $dueSoonDays = 3): array
    {
        $total = 0;
        $done = 0;
        $blocked = 0;
        $cancelled = 0;
        $late = 0;
        $dueSoon = 0;
        $unassigned = 0;
        $sum = 0;

        foreach ($tasks as $t) {
            $status = self::normalizeStatus((string) ($t['status'] ?? self::STATUS_TODO));
            if ($status === self::STATUS_CANCELLED) {
                $cancelled++;

                continue;
            }
            $total++;
            $sum += self::completionOf($t);
            if ($status === self::STATUS_DONE) {
                $done++;

                continue;
            }
            if ($status === self::STATUS_BLOCKED) {
                $blocked++;
            }
            if (trim((string) ($t['assignee_user_id'] ?? '')) === '') {
                $unassigned++;
            }
            $risk = self::riskOf($t, $today, $dueSoonDays);
            if ($risk === 'late') {
                $late++;
            } elseif ($risk === 'due_soon') {
                $dueSoon++;
            }
        }

        return [
            'progress_pct' => $total > 0 ? (int) floor($sum / $total) : 0,
            'total'        => $total,
            'open'         => $total - $done,
            'done'         => $done,
            'blocked'      => $blocked,
            'cancelled'    => $cancelled,
            'late'         => $late,
            'due_soon'     => $dueSoon,
            'unassigned'   => $unassigned,
        ];
    }

    /**
     * Classify one open task against "today".
     *
     * `blocked` wins over lateness (a blocked task cannot be chased for being
     * late), then `late` (due date passed), then `due_soon` (inside the look-ahead
     * window). Finished, cancelled and undated tasks are `ok`.
     *
     * @param array<string,mixed> $task
     */
    public static function riskOf(array $task, string $today = '', int $dueSoonDays = 3): string
    {
        $status = self::normalizeStatus((string) ($task['status'] ?? self::STATUS_TODO));
        if ($status === self::STATUS_DONE || $status === self::STATUS_CANCELLED) {
            return 'ok';
        }
        if ($status === self::STATUS_BLOCKED) {
            return 'blocked';
        }
        $due  = self::dateOf($task['due_at'] ?? null);
        $day  = self::dateOf($today);
        if ($due === null || $day === null) {
            return 'ok';
        }
        if ($due < $day) {
            return 'late';
        }
        if ($dueSoonDays > 0) {
            $horizon = date('Y-m-d', (int) (new \DateTimeImmutable($day . ' 00:00:00'))->modify('+' . $dueSoonDays . ' days')->getTimestamp());
            if ($due <= $horizon) {
                return 'due_soon';
            }
        }

        return 'ok';
    }

    /**
     * Group tasks by workstream and roll each up, plus the plan total.
     *
     * @param list<array<string,mixed>> $workstreams
     * @param list<array<string,mixed>> $tasks
     *
     * @return array{streams:list<array<string,mixed>>,progress_pct:int}
     */
    public static function byWorkstream(array $workstreams, array $tasks, string $today = '', int $dueSoonDays = 3): array
    {
        $buckets = [];
        foreach ($tasks as $t) {
            $key             = (string) ($t['workstream_id'] ?? '');
            $buckets[$key][] = $t;
        }

        $streams  = [];
        $weighted = 0;
        $weights  = 0;
        foreach ($workstreams as $ws) {
            $id     = (string) ($ws['id'] ?? '');
            $rolled = self::rollUp($buckets[$id] ?? [], $today, $dueSoonDays);
            $weight = max(1, (int) ($ws['weight'] ?? 1));

            // A stream's status is derived, not asserted: blocked work outranks
            // lateness, which outranks a nearing deadline.
            $status = (string) ($ws['status'] ?? self::WS_OPEN);
            if ($rolled['total'] > 0 && $rolled['done'] === $rolled['total']) {
                $status = self::WS_DONE;
            } elseif ($rolled['blocked'] > 0) {
                $status = self::WS_BLOCKED;
            } elseif ($rolled['late'] > 0) {
                $status = self::WS_AT_RISK;
            } elseif ($status === self::WS_DONE || $status === self::WS_BLOCKED) {
                // No longer true — fall back to the neutral derived states.
                $status = $rolled['due_soon'] > 0 ? self::WS_AT_RISK : self::WS_TRACK;
            } elseif ($rolled['due_soon'] > 0) {
                $status = self::WS_AT_RISK;
            } elseif ($status === self::WS_OPEN) {
                $status = self::WS_TRACK;
            }

            $weighted += $rolled['progress_pct'] * $weight;
            $weights  += $weight;

            $streams[] = [
                'id'              => $id,
                'name'            => (string) ($ws['name'] ?? ''),
                'owner_user_id'   => $ws['owner_user_id'] ?? null,
                'responsibility'  => $ws['responsibility'] ?? null,
                'weight'          => $weight,
                'status'          => $status,
                'progress_pct'    => $rolled['progress_pct'],
                'task_count'      => $rolled['total'],
                'done_count'      => $rolled['done'],
                'blocked_count'   => $rolled['blocked'],
                'late_count'      => $rolled['late'],
                'due_soon_count'  => $rolled['due_soon'],
                'unassigned_count' => $rolled['unassigned'],
                'due_date'        => self::dateOf($ws['due_date'] ?? null),
            ];
        }

        // Tasks with no workstream still count towards the plan.
        $loose = self::rollUp($buckets[''] ?? [], $today, $dueSoonDays);

        return [
            'streams'      => $streams,
            'progress_pct' => $weights > 0 ? (int) floor($weighted / $weights) : $loose['progress_pct'],
            'unstreamed'   => $loose,
        ];
    }

    /**
     * Milestone health: counts plus the overdue set (pending past their date,
     * which is what "missed" means until a human records it).
     *
     * @param list<array<string,mixed>> $milestones
     *
     * @return array{progress_pct:int,total:int,met:int,pending:int,missed:int,overdue:int,cancelled:int,next_due:?string}
     */
    public static function milestoneRollUp(array $milestones, string $today = ''): array
    {
        $met = 0;
        $pending = 0;
        $missed = 0;
        $cancelled = 0;
        $overdue = 0;
        $total = 0;
        $sum = 0;
        $weightSum = 0;
        $nextDue = null;
        $day = self::dateOf($today);

        foreach ($milestones as $m) {
            $status = self::normalizeMilestoneStatus((string) ($m['status'] ?? self::MILESTONE_PENDING));
            if ($status === self::MILESTONE_CANCELLED) {
                $cancelled++;

                continue;
            }
            $due    = self::dateOf($m['due_at'] ?? null);
            $weight = max(1, (int) ($m['weight'] ?? 1));
            $total++;
            $weightSum += $weight;

            if ($status === self::MILESTONE_MET) {
                $met++;
                $sum += 100 * $weight;

                continue;
            }
            if ($status === self::MILESTONE_MISSED) {
                $missed++;

                continue;
            }

            // Pending: still open, and past its date reads as overdue for
            // reporting while the stored status waits for a human to mark it.
            $pending++;
            if ($day !== null && $due !== null && $due < $day) {
                $overdue++;
            }
            if ($due !== null && ($nextDue === null || $due < $nextDue)) {
                $nextDue = $due;
            }
        }

        // Weighted percentage: a met milestone scores 100 * its weight.
        $progress = $weightSum > 0 ? (int) floor($sum / $weightSum) : 0;

        return [
            'progress_pct' => $progress,
            'total'        => $total,
            'met'          => $met,
            'pending'      => $pending,
            'missed'       => $missed,
            'overdue'      => $overdue,
            'cancelled'    => $cancelled,
            'next_due'     => $nextDue,
        ];
    }

    /**
     * The plan-level roll-up a leader or chair sees: one percentage, the counts
     * that justify it, and a health verdict.
     *
     * @param list<array<string,mixed>> $tasks
     * @param list<array<string,mixed>> $milestones
     *
     * @return array{progress_pct:int,health:string,tasks:array<string,int>,milestones:array<string,mixed>}
     */
    public static function planProgress(array $tasks, array $milestones, string $today = '', int $dueSoonDays = 3): array
    {
        $t = self::rollUp($tasks, $today, $dueSoonDays);
        $m = self::milestoneRollUp($milestones, $today);

        // Milestones are the proof of progress, tasks are the work: blend them
        // when both exist, and fall back to tasks alone otherwise.
        $progress = $m['total'] > 0
            ? (int) floor(($t['progress_pct'] * 0.7) + ($m['progress_pct'] * 0.3))
            : $t['progress_pct'];

        $health = 'on_track';
        if ($t['blocked'] > 0) {
            $health = 'blocked';
        } elseif ($t['late'] > 0 || ($m['missed'] + $m['overdue']) > 0) {
            $health = 'behind';
        } elseif ($t['due_soon'] > 0 || $t['unassigned'] > 0) {
            $health = 'at_risk';
        }
        if ($t['total'] > 0 && $t['done'] === $t['total'] && ($m['total'] === 0 || $m['met'] === $m['total'])) {
            $health = 'complete';
        }

        return [
            'progress_pct' => $progress,
            'health'       => $health,
            'tasks'        => $t,
            'milestones'   => $m,
        ];
    }

    /**
     * Would adding `task` → `dependsOn` (task waits for dependsOn) close a cycle?
     *
     * @param list<array<string,mixed>> $edges rows with task_id + depends_on_task_id
     */
    public static function wouldCreateCycle(array $edges, string $taskId, string $dependsOnId): bool
    {
        if ($taskId === '' || $dependsOnId === '' || $taskId === $dependsOnId) {
            return true; // self-dependency is the smallest possible cycle
        }

        // dependsOnId already (transitively) waits for taskId ⇒ cycle.
        $preds = [];
        foreach ($edges as $e) {
            $succ = (string) ($e['task_id'] ?? '');
            $pred = (string) ($e['depends_on_task_id'] ?? '');
            if ($succ === '' || $pred === '') {
                continue;
            }
            $preds[$succ][] = $pred;
        }

        $seen  = [];
        $stack = [$dependsOnId];
        while ($stack !== []) {
            $node = (string) array_pop($stack);
            if ($node === $taskId) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($preds[$node] ?? [] as $p) {
                $stack[] = $p;
            }
        }

        return false;
    }

    /**
     * Kahn topological order over the dependency graph (predecessors first). Any
     * nodes left in a cycle are appended in id order so the caller still gets a
     * complete list — the write path refuses cycles, so this is only defensive.
     *
     * @param list<string>              $taskIds
     * @param list<array<string,mixed>> $edges
     *
     * @return list<string>
     */
    public static function topoSort(array $taskIds, array $edges): array
    {
        $inDegree = [];
        $succs    = [];
        foreach ($taskIds as $id) {
            $inDegree[$id] = 0;
            $succs[$id]    = [];
        }
        foreach ($edges as $e) {
            $pred = (string) ($e['depends_on_task_id'] ?? '');
            $succ = (string) ($e['task_id'] ?? '');
            if (! isset($inDegree[$pred], $inDegree[$succ]) || $pred === $succ) {
                continue;
            }
            $inDegree[$succ]++;
            $succs[$pred][] = $succ;
        }

        $queue = [];
        foreach ($taskIds as $id) {
            if (($inDegree[$id] ?? 0) === 0) {
                $queue[] = $id;
            }
        }
        $out = [];
        while ($queue !== []) {
            $id    = array_shift($queue);
            $out[] = (string) $id;
            foreach ($succs[$id] ?? [] as $s) {
                $inDegree[$s]--;
                if ($inDegree[$s] === 0) {
                    $queue[] = $s;
                }
            }
        }
        foreach ($taskIds as $id) {
            if (! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * Predecessors that still gate a task, given the dependency type:
     * finish-to-start needs the predecessor DONE; start-to-start needs it STARTED;
     * finish-to-finish needs it DONE too (they must finish together, so the
     * successor cannot complete first).
     *
     * @param array<string,mixed>       $edges    all edges for the plan
     * @param array<string,array<string,mixed>> $byId task id ⇒ row
     *
     * @return list<array<string,mixed>>
     */
    public static function openPredecessors(string $taskId, array $edges, array $byId): array
    {
        $open = [];
        foreach ($edges as $e) {
            if ((string) ($e['task_id'] ?? '') !== $taskId) {
                continue;
            }
            $predId = (string) ($e['depends_on_task_id'] ?? '');
            $pred   = $byId[$predId] ?? null;
            if ($pred === null) {
                continue;
            }
            $status = self::normalizeStatus((string) ($pred['status'] ?? self::STATUS_TODO));
            if ($status === self::STATUS_CANCELLED) {
                continue; // a cancelled predecessor gates nothing
            }
            $type = self::normalizeDependencyType((string) ($e['dependency_type'] ?? self::DEP_FINISH_TO_START));
            $gates = $type === self::DEP_START_TO_START
                ? $status === self::STATUS_TODO
                : $status !== self::STATUS_DONE;
            if ($gates) {
                $open[] = [
                    'task_id'         => $predId,
                    'title'           => (string) ($pred['title'] ?? ''),
                    'status'          => $status,
                    'dependency_type' => $type,
                    'lag_days'        => (int) ($e['lag_days'] ?? 0),
                ];
            }
        }

        return $open;
    }

    /** Normalize a DATE/DATETIME value to `Y-m-d`, or null. */
    public static function dateOf(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }

        return null;
    }
}
