<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Contributions\Services\MetricsService;
use WBS\Gamification\Services\PointsEngine;
use WBS\Referrals\Services\SponsorshipService;

/**
 * Production InvolvementSourcePort: gathers a member's involvement metrics from
 * the real source modules. This is the ONLY place the expensive cross-module
 * fan-out happens, and it runs strictly on the snapshot WRITE path (a journey
 * transition or a batch recompute) — never on the pipeline render hot path,
 * which reads the materialized snapshot. That is what keeps the feature
 * resource-light per the standing constraint.
 *
 * Sources (each grounded on an existing, verified read):
 *   - activity / last activity : event_attendance (present) + course enrollments
 *     + follow_ups where the member was the SUBJECT, unioned by SQL-side counts;
 *   - sponsorship_count        : SponsorshipService::directRecruits (active);
 *   - giving_minor             : Contributions MetricsService::forSubject (pgv);
 *   - points                   : PointsEngine::balance (point_ledger, active season);
 *   - downline_own_quantum     : SUM of each active downline member's OWN quantum,
 *     read from the snapshot table when present (cheap) so a discipler's fruit is
 *     reflected without recursively recomputing the whole subtree here.
 */
final class InvolvementSourceAdapter implements InvolvementSourcePort
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?SponsorshipService $sponsorships = null,
        private readonly ?MetricsService $giving = null,
        private readonly ?PointsEngine $points = null,
    ) {
    }

    public function metricsFor(string $organizationId, string $userId, ?string $groupId, string $sinceUtc): array
    {
        return [
            'last_activity_at'     => $this->lastActivityAt($organizationId, $userId),
            'activity_count'       => $this->activityCount($organizationId, $userId, $sinceUtc),
            'sponsorship_count'    => $this->sponsorshipCount($userId),
            'giving_minor'         => $this->givingMinor($organizationId, $userId),
            'points'               => $this->points?->balance($organizationId, $userId) ?? 0,
            'downline_own_quantum' => $this->downlineOwnQuantum($organizationId, $userId),
        ];
    }

    /** Most recent participation timestamp across activity sources (any time). */
    private function lastActivityAt(string $organizationId, string $userId): ?string
    {
        $stamps = [];

        $ea = $this->maxDate('event_attendance', 'checked_in_at', [
            'organization_id' => $organizationId, 'user_id' => $userId, 'status' => 'present',
        ]);
        if ($ea !== null) {
            $stamps[] = $ea;
        }

        $fu = $this->maxDate('follow_ups', 'performed_at', [
            'organization_id' => $organizationId, 'subject_user_id' => $userId,
        ]);
        if ($fu !== null) {
            $stamps[] = $fu;
        }

        $en = $this->maxDate('enrollments', 'enrolled_at', [
            'organization_id' => $organizationId, 'user_id' => $userId,
        ]);
        if ($en !== null) {
            $stamps[] = $en;
        }

        if ($stamps === []) {
            return null;
        }
        rsort($stamps);

        return $stamps[0];
    }

    /** Count of participations within the window (attendance + follow-ups + enrollments). */
    private function activityCount(string $organizationId, string $userId, string $sinceUtc): int
    {
        $n = 0;
        $n += $this->countSince('event_attendance', 'checked_in_at', $sinceUtc, [
            'organization_id' => $organizationId, 'user_id' => $userId, 'status' => 'present',
        ]);
        $n += $this->countSince('follow_ups', 'performed_at', $sinceUtc, [
            'organization_id' => $organizationId, 'subject_user_id' => $userId,
        ]);
        $n += $this->countSince('enrollments', 'enrolled_at', $sinceUtc, [
            'organization_id' => $organizationId, 'user_id' => $userId,
        ]);

        return $n;
    }

    private function sponsorshipCount(string $userId): int
    {
        if ($this->sponsorships === null) {
            return 0;
        }
        try {
            return count($this->sponsorships->directRecruits($userId));
        } catch (Throwable) {
            return 0;
        }
    }

    private function givingMinor(string $organizationId, string $userId): int
    {
        if ($this->giving === null) {
            return 0;
        }
        try {
            $m = $this->giving->forSubject($organizationId, $userId);

            return max(0, (int) ($m['pgv_minor'] ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * SUM of the member's active downline's OWN quantum, read from already-computed
     * snapshots (cheap; no recursive recompute). Members without a snapshot yet
     * contribute 0 until their own refresh runs.
     */
    private function downlineOwnQuantum(string $organizationId, string $userId): int
    {
        if ($this->sponsorships === null) {
            return 0;
        }
        try {
            $downline = $this->sponsorships->downline($userId);
        } catch (Throwable) {
            return 0;
        }
        if ($downline === []) {
            return 0;
        }

        $row = $this->db->table('member_involvement_snapshots')
            ->selectSum('own_quantum', 'total')
            ->where('organization_id', $organizationId)
            ->whereIn('user_id', $downline)
            ->where('group_id', null) // roll up the org-wide primary snapshot
            ->get()->getRowArray();

        return max(0, (int) ($row['total'] ?? 0));
    }

    // ---- tiny SQL helpers ---------------------------------------------------

    /** @param array<string,mixed> $where */
    private function maxDate(string $table, string $col, array $where): ?string
    {
        try {
            $q = $this->db->table($table)->select("MAX({$col}) AS m");
            foreach ($where as $k => $v) {
                $q->where($k, $v);
            }
            $row = $q->get()->getRowArray();
            $v   = $row['m'] ?? null;

            return $v !== null && $v !== '' ? (string) $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $where */
    private function countSince(string $table, string $col, string $sinceUtc, array $where): int
    {
        try {
            $q = $this->db->table($table)->where("{$col} >=", $sinceUtc);
            foreach ($where as $k => $v) {
                $q->where($k, $v);
            }

            return (int) $q->countAllResults();
        } catch (Throwable) {
            return 0;
        }
    }
}
