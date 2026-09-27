<?php

declare(strict_types=1);

namespace WBS\Reporting\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * WBS funnel dashboards (SRS FR-RPT-001/002/003).
 *
 * Every rollup carries its as-of timestamp, filters, source state and a
 * completeness/delayed-job warning (FR-RPT-002). Counts explicitly distinguish
 * unique people from memberships/actions. Small segments are suppressed below a
 * configurable threshold so a leader cannot infer individual sensitive traits
 * from tiny cohorts (FR-RPT-003).
 */
final class DashboardService
{
    /** Minimum cohort size before a numeric cell is shown; otherwise suppressed. */
    private const SUPPRESSION_THRESHOLD = 5;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The Win–Build–Send funnel rollup for an organization (optionally group-scoped).
     *
     * @param array<string,mixed> $filters
     */
    public function wbsFunnel(string $organizationId, array $filters = []): Result
    {
        $groupId = $filters['group_id'] ?? null;

        // WIN — acquisition & conversion.
        // M11 — the three "prospect" notions are reconciled here so the funnel
        // counts each contact exactly ONCE:
        //   • prospects.state='captured'  = an OPEN outreach lead (address-book
        //     contact not yet converted) → counted in win.prospects.
        //   • prospects.state='converted' = a lead that became a real person; it
        //     now has a linked platform user, so it is represented by that user
        //     in win.members_unique — NOT re-counted as a prospect.
        //   • prospects.state='rejected'  = a dead lead → counted nowhere.
        //   • users.status='prospect' and journey_stages.code='prospect' are
        //     DIFFERENT axes (account lifecycle / discipleship stage) and are not
        //     conflated with the outreach lead count.
        // Counting only OPEN contacts removes the prospects↔members_unique
        // double-count a converted lead used to cause.
        $prospects = $this->countOpenContacts($organizationId, $groupId);
        $members   = $this->uniquePeople($organizationId);
        $conversions = $this->count('referral_attributions', $organizationId, $groupId);

        // BUILD — training, attendance, contributions, community.
        $enrollments = $this->count('enrollments', $organizationId, $groupId);
        $completions = $this->count('course_completions', $organizationId, $groupId, joinCourse: true);
        $eventsHeld  = $this->count('events', $organizationId, $groupId);
        $attendees   = $this->countDistinct('event_attendance', 'user_id', $organizationId, $groupId);
        $verifiedGiving = $this->sumSucceededContributions($organizationId, $groupId);

        // SEND — leadership / gamification.
        $pointsAwarded = $this->sumPoints($organizationId);

        $data = [
            'win' => [
                'prospects'           => $this->suppress($prospects),
                'members_unique'      => $this->suppress($members),
                'referral_conversions' => $this->suppress($conversions),
            ],
            'build' => [
                'course_enrollments'      => $this->suppress($enrollments),
                'course_completions'      => $this->suppress($completions),
                'events_held'             => $eventsHeld, // not person-level; no suppression
                'unique_attendees'        => $this->suppress($attendees),
                'verified_giving_minor'   => $verifiedGiving,
            ],
            'send' => [
                'points_awarded'  => $pointsAwarded,
            ],
        ];

        return Result::ok([
            'funnel'   => $data,
            'metadata' => $this->metadata($organizationId, $filters),
        ]);
    }

    /** Reporting metadata block required on every rollup (FR-RPT-002). */
    private function metadata(string $organizationId, array $filters): array
    {
        $pendingJobs = (int) ($this->db->table('queue_jobs')
            ->whereIn('status', ['ready', 'reserved'])->countAllResults());

        return [
            'as_of'                => $this->clock->nowUtcString(),
            'organization_id'      => $organizationId,
            'filters'              => $filters,
            'source_state'         => 'live',
            'suppression_threshold' => self::SUPPRESSION_THRESHOLD,
            'data_completeness'    => $pendingJobs === 0 ? 'complete' : 'pending_jobs',
            'delayed_job_warning'  => $pendingJobs > 0
                ? "There are {$pendingJobs} queued jobs; some counts may lag."
                : null,
        ];
    }

    /** Suppress small counts to protect individuals in tiny cohorts. */
    private function suppress(int $n): int|string
    {
        return $n > 0 && $n < self::SUPPRESSION_THRESHOLD ? '<' . self::SUPPRESSION_THRESHOLD : $n;
    }

    private function count(string $table, string $org, ?string $groupId, bool $joinCourse = false): int
    {
        $q = $this->db->table($table);
        if ($this->hasColumn($table, 'organization_id')) {
            $q->where('organization_id', $org);
        }
        if ($groupId !== null && $this->hasColumn($table, 'group_id')) {
            $q->where('group_id', $groupId);
        }

        return (int) $q->countAllResults();
    }

    /**
     * M11 — count OPEN outreach leads only: `prospects` rows still in the
     * `captured` state (i.e. not yet `converted` into a real person, and not
     * `rejected`). A converted lead is represented by its linked user in the
     * members count, so excluding it here prevents the historical
     * prospects↔members double-count. Group-scoped via `assigned_group_id`
     * (the address-book's group column) when present, else `group_id`.
     */
    private function countOpenContacts(string $org, ?string $groupId): int
    {
        $q = $this->db->table('prospects');
        if ($this->hasColumn('prospects', 'organization_id')) {
            $q->where('organization_id', $org);
        }
        // Only OPEN leads. If the `state` column is missing (older schema), fall
        // back to counting all rows rather than erroring.
        if ($this->hasColumn('prospects', 'state')) {
            $q->where('state', 'captured');
        }
        if ($groupId !== null) {
            if ($this->hasColumn('prospects', 'assigned_group_id')) {
                $q->where('assigned_group_id', $groupId);
            } elseif ($this->hasColumn('prospects', 'group_id')) {
                $q->where('group_id', $groupId);
            }
        }

        return (int) $q->countAllResults();
    }

    private function countDistinct(string $table, string $col, string $org, ?string $groupId): int
    {
        $q = $this->db->table($table)->select($col);
        if ($this->hasColumn($table, 'organization_id')) {
            $q->where('organization_id', $org);
        }
        $q->distinct();

        return (int) $q->countAllResults();
    }

    private function uniquePeople(string $org): int
    {
        return (int) $this->db->table('users')->countAllResults();
    }

    private function sumSucceededContributions(string $org, ?string $groupId): int
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(amount_minor),0) AS total FROM contributions WHERE organization_id = ? AND state = "succeeded"',
            [$org],
        )->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    private function sumPoints(string $org): int
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(points),0) AS total FROM point_ledger WHERE organization_id = ? AND entry_type = "award"',
            [$org],
        )->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /** @var array<string,list<string>> */
    private array $columnCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        if (! isset($this->columnCache[$table])) {
            try {
                $this->columnCache[$table] = $this->db->getFieldNames($table);
            } catch (\Throwable) {
                $this->columnCache[$table] = [];
            }
        }

        return in_array($column, $this->columnCache[$table], true);
    }
}
