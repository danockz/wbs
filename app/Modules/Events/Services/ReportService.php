<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Mobilization report (SRS FR-EVT-014).
 *
 * Aggregates, for one event: invitations/prospects, RSVP responses, expected &
 * actual attendance, qualified streaming attendance, contributions, feedback &
 * quiz results, expenses, media, and points — as AGGREGATE figures only (no
 * identifiable rows), suitable to roll up to the organizing group and
 * authorized ancestors.
 *
 * The live report is computed on demand from current data; `snapshot()` freezes
 * an immutable point-in-time copy for roll-up.
 */
final class ReportService
{
    /** A stream viewer counts as "qualified" attendance after this watch time. */
    private const QUALIFIED_WATCH_SECONDS = 300;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        // Optional hierarchy seam (gap L5). When wired, groupRollup() can sum a
        // group's WHOLE subtree (self + descendants) — the platform's
        // "credit every ancestor" model — instead of just the one group's own
        // events. Null → subtree scope falls back to single-group (the pre-L5
        // behaviour), so the pure snapshot tests need no resolver.
        private readonly ?GroupScopeResolver $scope = null,
    ) {
    }

    /** Compute the full live mobilization report for an event. */
    public function build(string $eventId): Result
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        $orgId = (string) $event['organization_id'];

        $metrics = [
            'event_id'    => $eventId,
            'title'       => $event['title'],
            'status'      => $event['status'],
            'group_id'    => $event['group_id'],
            'mobilization' => $this->mobilization($orgId, $eventId),
            'attendance'  => $this->attendance($eventId),
            'streaming'   => $this->qualifiedStreaming($eventId),
            'contributions' => $this->contributions($eventId),
            'feedback'    => $this->feedback($eventId),
            'expenses'    => $this->expenses($eventId),
            'media'       => $this->media($eventId),
            'points'      => $this->points($eventId),
            'generated_at' => $this->clock->nowUtcString(),
        ];

        return Result::ok($metrics);
    }

    /**
     * Persist an immutable snapshot of the current report for roll-up. Stores
     * only aggregate metrics (no identifiable rows).
     */
    public function snapshot(string $eventId, ?string $generatedBy): Result
    {
        $report = $this->build($eventId);
        if ($report->failed()) {
            return $report;
        }
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();

        $id = Uuid::v7();
        $this->db->table('event_report_snapshots')->insert([
            'id'              => $id,
            'organization_id' => $event['organization_id'],
            'event_id'        => $eventId,
            'group_id'        => $event['group_id'] ?? null,
            'metrics'         => json_encode($report->data, JSON_UNESCAPED_UNICODE),
            'generated_by'    => $generatedBy,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['snapshot_id' => $id, 'event_id' => $eventId]);
    }

    /** Roll-up scopes: just this group, or the whole subtree (self + descendants). */
    private const ROLLUP_SCOPES = ['self', 'subtree'];

    /**
     * Roll aggregate figures up across a group's events (from their latest
     * snapshots). Authorized-ancestor scoping is enforced by the caller (route
     * permission + PDP); this only sums aggregates, never raw rows.
     *
     * SCOPE (gap L5): the platform's attribution rule is "contributions
     * accumulate to EVERY ancestor", so an ancestor's roll-up should include its
     * descendant groups' events — not just events snapshotted against the
     * ancestor itself. With `$scope = 'subtree'` (the default), the figures sum
     * across the resolved subtree (self + descendants) via GroupScopeResolver,
     * consistent with how every other group-scoped surface reads the hierarchy;
     * `$scope = 'self'` keeps the legacy single-group view. Terminal/archived
     * descendant groups are already pruned by the resolver (GR2/GR3). When no
     * resolver is wired, subtree degrades safely to single-group.
     *
     * @param string $scope self|subtree (invalid values fall back to 'self')
     */
    public function groupRollup(string $groupId, string $scope = 'subtree'): Result
    {
        $scope = in_array($scope, self::ROLLUP_SCOPES, true) ? $scope : 'self';

        // Resolve the set of groups whose events count towards this roll-up.
        $groupIds = [$groupId];
        if ($scope === 'subtree' && $this->scope !== null) {
            foreach ($this->scope->descendants($groupId) as $d) {
                $groupIds[] = $d;
            }
        }
        $groupIds = array_values(array_unique($groupIds));

        // Latest snapshot per event across the resolved group set. One event may
        // be owned by exactly one group, so summing per-group latest snapshots
        // never double-counts an event.
        $ph   = implode(',', array_fill(0, count($groupIds), '?'));
        $rows = $this->db->query(
            'SELECT s.metrics FROM event_report_snapshots s
             INNER JOIN (
                SELECT event_id, MAX(created_at) AS mx
                FROM event_report_snapshots WHERE group_id IN (' . $ph . ') GROUP BY event_id
             ) latest ON latest.event_id = s.event_id AND latest.mx = s.created_at
             WHERE s.group_id IN (' . $ph . ')',
            [...$groupIds, ...$groupIds],
        )->getResultArray();

        $agg = [
            'events'                 => 0,
            'invitations'            => 0,
            'responses'              => 0,
            'expected_attendance'    => 0,
            'actual_attendance'      => 0,
            'qualified_streaming'    => 0,
            'contributions_count'    => 0,
            'contributions_minor'    => 0,
        ];
        foreach ($rows as $r) {
            $m = json_decode((string) $r['metrics'], true);
            if (! is_array($m)) {
                continue;
            }
            $agg['events']++;
            $agg['invitations']         += (int) ($m['mobilization']['invitations'] ?? 0);
            $agg['responses']           += (int) ($m['mobilization']['responses'] ?? 0);
            $agg['expected_attendance'] += (int) ($m['attendance']['expected'] ?? 0);
            $agg['actual_attendance']   += (int) ($m['attendance']['actual'] ?? 0);
            $agg['qualified_streaming'] += (int) ($m['streaming']['qualified'] ?? 0);
            $agg['contributions_count'] += (int) ($m['contributions']['count'] ?? 0);
            $agg['contributions_minor'] += (int) ($m['contributions']['amount_minor'] ?? 0);
        }

        return Result::ok([
            'group_id'      => $groupId,
            'scope'         => $scope,
            'groups_counted' => count($groupIds),
            'rollup'        => $agg,
        ]);
    }

    // ---- section builders --------------------------------------------------

    /** @return array<string,int> */
    private function mobilization(string $orgId, string $eventId): array
    {
        // Invitations are EVENT-SCOPED (gap G4): direct invitations issued for THIS
        // event + sign-ups redeemed through its shareable link. Previously this
        // counted ALL org prospects, inflating every event's funnel with the whole
        // address book. `converted_prospects` = registrations that originated from
        // an outreach follow-up (a contact:{id} source_ref), the event-scoped
        // analogue of the old org conversion figure.
        $inviteCounts = (new InvitationService($this->db, $this->clock))->countInvitationsFor($eventId);

        $converted = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)
            ->like('source_ref', 'contact:', 'after')
            ->countAllResults();
        $responses = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->countAllResults();

        return [
            'invitations'         => $inviteCounts['total'],
            'invitations_direct'  => $inviteCounts['direct'],
            'invitations_link'    => $inviteCounts['redemptions'],
            'converted_prospects' => $converted,
            'responses'           => $responses,
        ];
    }

    /** @return array<string,int|null> */
    private function attendance(string $eventId): array
    {
        $confirmed = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('status', 'registered')->countAllResults();
        $waitlisted = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('status', 'waitlisted')->countAllResults();
        $actual = (int) $this->db->table('event_attendance')
            ->where('event_id', $eventId)->where('status', 'present')->countAllResults();

        return [
            'confirmed_registrations' => $confirmed,
            'waitlisted'              => $waitlisted,
            'expected'                => $confirmed,
            'actual'                  => $actual,
        ];
    }

    /**
     * Qualified streaming attendance (FR-EVT-014 / relates to FR-STR): distinct
     * viewers of streams linked to this event whose watch time meets the
     * qualification threshold. Uses hashed/opaque viewer ids only.
     *
     * @return array<string,int>
     */
    private function qualifiedStreaming(string $eventId): array
    {
        $streamIds = $this->db->table('streams')
            ->select('id')->where('event_id', $eventId)->get()->getResultArray();
        if ($streamIds === []) {
            return ['streams' => 0, 'qualified' => 0];
        }
        $ids = array_map(static fn ($r) => $r['id'], $streamIds);

        // Sum watch seconds per (stream, viewer); count viewers meeting threshold.
        $qualified = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM (
                SELECT stream_id, viewer_id,
                       SUM(TIMESTAMPDIFF(SECOND, joined_at, COALESCE(left_at, joined_at))) AS secs
                FROM stream_viewers
                WHERE stream_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                  AND viewer_id IS NOT NULL
                GROUP BY stream_id, viewer_id
                HAVING secs >= ?
             ) q',
            [...$ids, self::QUALIFIED_WATCH_SECONDS],
        )->getRowArray()['c'] ?? 0);

        return ['streams' => count($ids), 'qualified' => $qualified];
    }

    /** @return array<string,int> */
    private function contributions(string $eventId): array
    {
        // Ticket orders (event-scoped) + any cause add-ons flow through VBCS
        // separately; here we report paid ticket orders tied to the event.
        $row = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(total_minor),0) AS amt
             FROM event_orders WHERE event_id = ? AND status = "paid"',
            [$eventId],
        )->getRowArray();

        return [
            'count'        => (int) ($row['n'] ?? 0),
            'amount_minor' => (int) ($row['amt'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function feedback(string $eventId): array
    {
        $forms = (int) $this->db->table('event_feedback_forms')->where('event_id', $eventId)->countAllResults();
        $responses = (int) $this->db->table('event_feedback_responses')->where('event_id', $eventId)->countAllResults();
        $quizRow = $this->db->query(
            'SELECT AVG(score) AS avg_score, COUNT(score) AS scored,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passers
             FROM event_feedback_responses WHERE event_id = ? AND score IS NOT NULL',
            [$eventId],
        )->getRowArray();

        return [
            'forms'      => $forms,
            'responses'  => $responses,
            'quiz'       => [
                'scored'    => (int) ($quizRow['scored'] ?? 0),
                'avg_score' => $quizRow['avg_score'] !== null ? round((float) $quizRow['avg_score'], 2) : null,
                'passers'   => (int) ($quizRow['passers'] ?? 0),
            ],
        ];
    }

    /** @return array<string,int|null> */
    private function expenses(string $eventId): array
    {
        $budget = $this->db->table('event_budgets')->where('event_id', $eventId)->get()->getRowArray();
        $row = $this->db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN status IN ("approved","reimbursed") THEN COALESCE(approved_amount_minor, amount_minor) ELSE 0 END),0) AS committed,
                COUNT(*) AS n
             FROM event_expenses WHERE event_id = ?',
            [$eventId],
        )->getRowArray();

        return [
            'budget_minor'    => $budget !== null ? (int) $budget['amount_minor'] : null,
            'committed_minor' => (int) ($row['committed'] ?? 0),
            'count'           => (int) ($row['n'] ?? 0),
        ];
    }

    /** @return array<string,int> */
    private function media(string $eventId): array
    {
        $total = (int) $this->db->table('event_media')->where('event_id', $eventId)->countAllResults();
        $approved = (int) $this->db->table('event_media')
            ->where('event_id', $eventId)->where('review_state', 'approved')->countAllResults();

        return ['total' => $total, 'approved' => $approved];
    }

    /** @return array<string,int> */
    private function points(string $eventId): array
    {
        // Points attributed to this event via source_ref convention "event:{id}".
        $sum = (int) ($this->db->query(
            'SELECT COALESCE(SUM(points),0) AS p FROM point_ledger WHERE source_ref LIKE ?',
            ['event:' . $eventId . '%'],
        )->getRowArray()['p'] ?? 0);

        return ['awarded' => $sum];
    }
}
