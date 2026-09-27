<?php

declare(strict_types=1);

namespace WBS\Reporting\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * Member self-service dashboard ("my home").
 *
 * This is the personal counterpart to the admin WBS funnel dashboard: it
 * surfaces a SINGLE member's own certificates, upcoming events, course progress,
 * gamification standing (points / rank / badges) and group memberships.
 *
 * Privacy model: every query is scoped to the one authenticated user id passed
 * in by the controller (never a caller-supplied id), so a member can only ever
 * see their own data. No admin/report permission is required — the only gate is
 * being authenticated (`auth` filter) — precisely because nothing here crosses
 * the self boundary. Because it is inherently single-subject, the small-cohort
 * suppression the funnel needs does not apply.
 */
final class MemberDashboardService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Assemble the member's personal dashboard payload.
     */
    public function forUser(string $organizationId, string $userId): Result
    {
        if ($userId === '') {
            return Result::fail('NOT_AUTHENTICATED', 'dashboard.no_user', 401);
        }

        $now = $this->clock->nowUtcString();

        return Result::ok([
            'profile'      => $this->profile($organizationId, $userId),
            'gamification' => $this->gamification($organizationId, $userId),
            'certificates' => $this->certificates($organizationId, $userId),
            'events'       => $this->upcomingEvents($organizationId, $userId, $now),
            'courses'      => $this->courses($organizationId, $userId),
            'groups'       => $this->groups($organizationId, $userId),
            'milestones'   => $this->milestones($organizationId, $userId),
            'metadata'     => [
                'as_of'           => $now,
                'organization_id' => $organizationId,
                'scope'           => 'self',
                'user_id'         => $userId,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function profile(string $org, string $userId): array
    {
        $u = $this->db->table('users')
            ->select('display_name, email, created_at, last_login_at')
            ->where('id', $userId)->where('organization_id', $org)
            ->get()->getRowArray() ?? [];

        return [
            'display_name' => (string) ($u['display_name'] ?? 'Member'),
            'email'        => (string) ($u['email'] ?? ''),
            'member_since' => (string) ($u['created_at'] ?? ''),
            'last_login'   => $u['last_login_at'] ?? null,
        ];
    }

    /**
     * Points in the active season + resolved rank + public badge count.
     *
     * @return array<string,mixed>
     */
    /**
     * Points in the active season + resolved rank (with progress toward the
     * next tier) + earned badges + achievements + streaks.
     *
     * Enriches the earlier points-only summary: a member's standing is a
     * composite of rank progression, badges, achievements and streaks — the
     * same "award/badge/streak/achievement/rank" surface the platform's
     * Gamification module maintains. Everything stays self-scoped to $userId.
     *
     * @return array<string,mixed>
     */
    private function gamification(string $org, string $userId): array
    {
        $season = $this->db->table('gamification_seasons')
            ->where('organization_id', $org)->where('status', 'active')
            ->orderBy('season_year', 'DESC')
            ->get()->getRowArray();

        $seasonId = $season['id'] ?? null;

        $points = 0;
        if ($seasonId !== null) {
            $row = $this->db->query(
                'SELECT COALESCE(SUM(points),0) AS total FROM point_ledger
                 WHERE organization_id = ? AND season_id = ? AND subject_id = ?
                   AND subject_type = "user" AND state = "final" AND archived = 0',
                [$org, $seasonId, $userId],
            )->getRowArray();
            $points = (int) ($row['total'] ?? 0);
        }

        $ranks = $this->db->table('rank_definitions')
            ->select('code, name, min_points, icon, color')
            ->where('organization_id', $org)->where('status', 'active')
            ->orderBy('min_points', 'ASC')
            ->get()->getResultArray();

        $rank = self::resolveRank($points, $ranks);

        return [
            'season_year'    => $season['season_year'] ?? null,
            'points'         => $points,
            'rank'           => $rank['current'],
            'next_rank'      => $rank['next'],
            'points_to_next' => $rank['points_to_next'],
            'progress_pct'   => self::rankProgressPercent($points, $rank['current'], $rank['next']),
            'badges'         => $this->badges($org, $userId),
            'achievements'   => $this->achievements($org, $userId),
            'streaks'        => $this->streaks($org, $userId, $seasonId),
        ];
    }

    /**
     * Percent progress from the current rank's floor toward the next rank's
     * floor. 100 when at the top rank or when ranks aren't configured. Pure
     * (static) so it can be unit-tested without a database.
     *
     * @param array<string,mixed>|null $current
     * @param array<string,mixed>|null $next
     */
    public static function rankProgressPercent(int $points, ?array $current, ?array $next): int
    {
        if ($current === null || $next === null
            || ! isset($current['min_points'], $next['min_points'])) {
            return 100; // top rank reached, or ranks not configured
        }

        $span = (int) $next['min_points'] - (int) $current['min_points'];
        if ($span <= 0) {
            return 100;
        }

        $progressed = $points - (int) $current['min_points'];

        return (int) max(0, min(100, round(($progressed / $span) * 100)));
    }

    /**
     * Earned (non-revoked) badges with their display metadata, newest first.
     *
     * @return array{count:int, items:list<array<string,mixed>>}
     */
    private function badges(string $org, string $userId): array
    {
        $rows = $this->db->table('badge_awards ba')
            ->select('b.code, b.name, b.visibility, ba.awarded_at, ba.season_id')
            ->join('badges b', 'b.id = ba.badge_id', 'left')
            ->where('ba.organization_id', $org)
            ->where('ba.subject_id', $userId)
            ->where('ba.state', 'awarded')
            ->orderBy('ba.awarded_at', 'DESC')
            ->get()->getResultArray();

        $items = array_map(static fn (array $r): array => [
            'code'       => (string) ($r['code'] ?? ''),
            'name'       => (string) ($r['name'] ?? ''),
            'awarded_at' => $r['awarded_at'] ?? null,
        ], $rows);

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * Achievements the member has unlocked, plus the closest still-in-progress
     * ones (by completion percentage) to give a "what's next" nudge.
     *
     * @return array{unlocked_count:int, unlocked:list<array<string,mixed>>, in_progress:list<array<string,mixed>>}
     */
    private function achievements(string $org, string $userId): array
    {
        $unlocked = $this->db->table('user_achievements ua')
            ->select('ua.achievement_code, ad.name, ad.category, ad.icon, ua.xp_awarded, ua.unlocked_at')
            ->join('achievement_definitions ad', 'ad.id = ua.achievement_id', 'left')
            ->where('ua.organization_id', $org)
            ->where('ua.subject_id', $userId)
            ->orderBy('ua.unlocked_at', 'DESC')
            ->get()->getResultArray();

        $unlockedItems = array_map(static fn (array $r): array => [
            'code'        => (string) ($r['achievement_code'] ?? ''),
            'name'        => (string) ($r['name'] ?? ''),
            'category'    => $r['category'] ?? null,
            'xp'          => (int) ($r['xp_awarded'] ?? 0),
            'unlocked_at' => $r['unlocked_at'] ?? null,
        ], $unlocked);

        // Nearest in-progress (exclude anything already unlocked; secret
        // achievements are not surfaced as teasers).
        $progress = $this->db->table('user_achievement_progress uap')
            ->select('ad.code, ad.name, ad.category, ad.icon, ad.secret, uap.percentage, uap.current_value, uap.required_value')
            ->join('achievement_definitions ad', 'ad.id = uap.achievement_id', 'left')
            ->where('uap.organization_id', $org)
            ->where('uap.subject_id', $userId)
            ->where('uap.percentage <', 100)
            ->orderBy('uap.percentage', 'DESC')
            ->get()->getResultArray();

        $unlockedCodes = array_column($unlocked, 'achievement_code');
        $inProgress    = [];
        foreach ($progress as $p) {
            if ((int) ($p['secret'] ?? 0) === 1) {
                continue;
            }
            if (in_array($p['code'] ?? '', $unlockedCodes, true)) {
                continue;
            }
            $inProgress[] = [
                'code'       => (string) ($p['code'] ?? ''),
                'name'       => (string) ($p['name'] ?? ''),
                'category'   => $p['category'] ?? null,
                'percentage' => (float) ($p['percentage'] ?? 0),
                'current'    => (float) ($p['current_value'] ?? 0),
                'required'   => (float) ($p['required_value'] ?? 0),
            ];
            if (count($inProgress) >= 3) {
                break;
            }
        }

        return [
            'unlocked_count' => count($unlockedItems),
            'unlocked'       => array_slice($unlockedItems, 0, 6),
            'in_progress'    => $inProgress,
        ];
    }

    /**
     * Active streaks for the member (current + best run), best first.
     *
     * @return list<array<string,mixed>>
     */
    private function streaks(string $org, string $userId, ?string $seasonId): array
    {
        $q = $this->db->table('user_streaks us')
            ->select('us.streak_code, sd.name, sd.cadence, sd.icon, us.current_count, us.best_count, us.last_event_date')
            ->join('streak_definitions sd', 'sd.code = us.streak_code AND sd.organization_id = us.organization_id', 'left')
            ->where('us.organization_id', $org)
            ->where('us.subject_id', $userId);
        if ($seasonId !== null) {
            $q->groupStart()->where('us.season_id', $seasonId)->orWhere('us.season_id', null)->groupEnd();
        }
        $rows = $q->orderBy('us.current_count', 'DESC')->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'code'            => (string) $r['streak_code'],
            'name'            => (string) ($r['name'] ?? $r['streak_code']),
            'cadence'         => $r['cadence'] ?? null,
            'current_count'   => (int) $r['current_count'],
            'best_count'      => (int) $r['best_count'],
            'last_event_date' => $r['last_event_date'] ?? null,
        ], $rows);
    }

    /**
     * Pure rank resolver: given a point total and the ascending rank ladder,
     * return the current rank, the next rank, and points needed to reach it.
     * Kept static + pure so it can be unit-tested without a database.
     *
     * @param list<array<string,mixed>> $ranks ascending by min_points
     *
     * @return array{current: ?array<string,mixed>, next: ?array<string,mixed>, points_to_next: ?int}
     */
    public static function resolveRank(int $points, array $ranks): array
    {
        $current = null;
        $next    = null;

        foreach ($ranks as $r) {
            $min = (int) ($r['min_points'] ?? 0);
            if ($points >= $min) {
                $current = $r;
            } elseif ($next === null) {
                $next = $r; // first rung above the member's points
            }
        }

        $toNext = $next !== null ? max(0, (int) $next['min_points'] - $points) : null;

        return ['current' => $current, 'next' => $next, 'points_to_next' => $toNext];
    }

    /**
     * The member's issued certificates (never pending/revoked internals beyond
     * status), newest first, with the public verification id + artifact ref.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * Personal milestones — the member's own journey markers.
     *
     * These are distinct from gamification achievements (which are configurable,
     * points/trigger-driven, and admin-defined): milestones are intrinsic life-
     * of-membership facts derived directly from the member's activity —
     * tenure, cumulative events attended, courses completed, certificates
     * earned, and verified giving. Each track reports what's been achieved plus
     * the next threshold, so the card doubles as a light "what's next" nudge.
     *
     * @return array{achieved:list<array<string,mixed>>, upcoming:list<array<string,mixed>>, stats:array<string,mixed>}
     */
    private function milestones(string $org, string $userId): array
    {
        // -- Lifetime counts (self-scoped) ---------------------------------
        $memberSince = (string) ($this->db->table('users')
            ->select('created_at')->where('id', $userId)->where('organization_id', $org)
            ->get()->getRowArray()['created_at'] ?? '');

        $tenureYears = 0;
        $tenureDays  = 0;
        if ($memberSince !== '') {
            $start = strtotime($memberSince);
            if ($start !== false) {
                $tenureDays  = (int) floor((time() - $start) / 86400);
                $tenureYears = (int) floor($tenureDays / 365);
            }
        }

        $eventsAttended = (int) $this->db->table('event_attendance')
            ->where('organization_id', $org)->where('user_id', $userId)
            ->where('status', 'present')->countAllResults();

        $coursesCompleted = (int) $this->db->table('enrollments')
            ->where('organization_id', $org)->where('user_id', $userId)
            ->where('status', 'completed')->countAllResults();

        $certificatesEarned = (int) $this->db->table('event_certificates')
            ->where('organization_id', $org)->where('user_id', $userId)
            ->where('status', 'issued')->countAllResults();

        $givingRow = $this->db->query(
            'SELECT COALESCE(SUM(amount_minor),0) AS total, COUNT(*) AS n
             FROM contributions
             WHERE organization_id = ? AND user_id = ? AND state = "succeeded"',
            [$org, $userId],
        )->getRowArray();
        $givingCount = (int) ($givingRow['n'] ?? 0);

        // -- Track definitions: label + tiered thresholds ------------------
        // Each track yields the highest tier reached (achieved) and the next
        // tier to aim for (upcoming), computed by a shared helper.
        $achieved = [];
        $upcoming = [];

        foreach ([
            ['key' => 'tenure', 'icon' => '📅', 'noun' => 'year', 'value' => $tenureYears,
                'tiers' => [1, 2, 3, 5, 10], 'label' => static fn (int $t): string => $t . '-year member'],
            ['key' => 'events', 'icon' => '📣', 'noun' => 'event', 'value' => $eventsAttended,
                'tiers' => [1, 5, 10, 25, 50], 'label' => static fn (int $t): string => 'Attended ' . $t . ' event' . ($t > 1 ? 's' : '')],
            ['key' => 'courses', 'icon' => '🎓', 'noun' => 'course', 'value' => $coursesCompleted,
                'tiers' => [1, 3, 5, 10], 'label' => static fn (int $t): string => 'Completed ' . $t . ' course' . ($t > 1 ? 's' : '')],
            ['key' => 'certificates', 'icon' => '📜', 'noun' => 'certificate', 'value' => $certificatesEarned,
                'tiers' => [1, 3, 5, 10], 'label' => static fn (int $t): string => 'Earned ' . $t . ' certificate' . ($t > 1 ? 's' : '')],
            ['key' => 'giving', 'icon' => '💚', 'noun' => 'gift', 'value' => $givingCount,
                'tiers' => [1, 5, 10, 25], 'label' => static fn (int $t): string => $t . ' verified gift' . ($t > 1 ? 's' : '')],
        ] as $track) {
            $m = self::milestoneForTrack((int) $track['value'], $track['tiers'], $track['label']);
            if ($m['achieved'] !== null) {
                $achieved[] = ['key' => $track['key'], 'icon' => $track['icon']] + $m['achieved'];
            }
            if ($m['next'] !== null) {
                $upcoming[] = ['key' => $track['key'], 'icon' => $track['icon']] + $m['next'];
            }
        }

        return [
            'achieved' => $achieved,
            'upcoming' => $upcoming,
            'stats'    => [
                'member_since'        => $memberSince,
                'tenure_days'         => $tenureDays,
                'events_attended'     => $eventsAttended,
                'courses_completed'   => $coursesCompleted,
                'certificates_earned' => $certificatesEarned,
                'verified_gifts'      => $givingCount,
            ],
        ];
    }

    /**
     * Given a current value and ascending tier thresholds, return the highest
     * tier reached and the next tier to aim for (with remaining count). Pure +
     * static so the tiering logic is unit-testable without a database.
     *
     * @param list<int>          $tiers ascending thresholds
     * @param callable(int):string $label produces a human label for a tier
     *
     * @return array{achieved: ?array{label:string, tier:int, value:int}, next: ?array{label:string, tier:int, value:int, remaining:int}}
     */
    public static function milestoneForTrack(int $value, array $tiers, callable $label): array
    {
        $achievedTier = null;
        $nextTier     = null;

        foreach ($tiers as $t) {
            if ($value >= $t) {
                $achievedTier = $t;
            } elseif ($nextTier === null) {
                $nextTier = $t;
            }
        }

        return [
            'achieved' => $achievedTier === null ? null : [
                'label' => $label($achievedTier),
                'tier'  => $achievedTier,
                'value' => $value,
            ],
            'next' => $nextTier === null ? null : [
                'label'     => $label($nextTier),
                'tier'      => $nextTier,
                'value'     => $value,
                'remaining' => $nextTier - $value,
            ],
        ];
    }

    private function certificates(string $org, string $userId): array
    {
        $rows = $this->db->table('event_certificates c')
            ->select('c.id, c.status, c.verification_id, c.render_ref, c.issued_at, e.title AS event_title, e.starts_at')
            ->join('events e', 'e.id = c.event_id', 'left')
            ->where('c.organization_id', $org)
            ->where('c.user_id', $userId)
            ->whereIn('c.status', ['issued', 'revoked'])
            ->orderBy('c.issued_at', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'              => $r['id'],
            'event_title'     => (string) ($r['event_title'] ?? ''),
            'status'          => $r['status'],
            'verification_id' => $r['verification_id'],
            'download_ref'    => $r['status'] === 'issued' ? ($r['render_ref'] ?? null) : null,
            'issued_at'       => $r['issued_at'] ?? null,
        ], $rows);
    }

    /**
     * Events the member is registered for that have not started yet.
     *
     * @return list<array<string,mixed>>
     */
    private function upcomingEvents(string $org, string $userId, string $now): array
    {
        $rows = $this->db->table('event_registrations r')
            ->select('e.id, e.title, e.starts_at, e.mode, e.timezone, r.status AS reg_status, r.rsvp_state')
            ->join('events e', 'e.id = r.event_id', 'inner')
            ->where('r.organization_id', $org)
            ->where('r.user_id', $userId)
            ->where('r.status !=', 'cancelled')
            ->where('e.starts_at >=', $now)
            ->where('e.status !=', 'cancelled')
            ->orderBy('e.starts_at', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'         => $r['id'],
            'title'      => (string) $r['title'],
            'starts_at'  => $r['starts_at'],
            'mode'       => $r['mode'],
            'timezone'   => $r['timezone'],
            'reg_status' => $r['reg_status'],
            'rsvp_state' => $r['rsvp_state'],
        ], $rows);
    }

    /**
     * Course enrollments with status, plus a compact completed/active summary.
     *
     * @return array<string,mixed>
     */
    private function courses(string $org, string $userId): array
    {
        $rows = $this->db->table('enrollments en')
            ->select('en.status, en.enrolled_at, en.completed_at, c.title, c.category')
            ->join('courses c', 'c.id = en.course_id', 'left')
            ->where('en.organization_id', $org)
            ->where('en.user_id', $userId)
            ->orderBy('en.enrolled_at', 'DESC')
            ->get()->getResultArray();

        $active    = 0;
        $completed = 0;
        $list      = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'completed') {
                $completed++;
            } elseif ($r['status'] === 'active') {
                $active++;
            }
            $list[] = [
                'title'        => (string) ($r['title'] ?? ''),
                'category'     => $r['category'] ?? null,
                'status'       => $r['status'],
                'enrolled_at'  => $r['enrolled_at'] ?? null,
                'completed_at' => $r['completed_at'] ?? null,
            ];
        }

        return [
            'active'    => $active,
            'completed' => $completed,
            'items'     => $list,
        ];
    }

    /**
     * Groups the member belongs to (name + their role in each).
     *
     * @return list<array<string,mixed>>
     */
    private function groups(string $org, string $userId): array
    {
        $rows = $this->db->table('group_members gm')
            ->select('g.id, g.name, g.type, g.path, gm.role, gm.joined_at')
            ->join('groups g', 'g.id = gm.group_id', 'inner')
            ->where('gm.organization_id', $org)
            ->where('gm.user_id', $userId)
            ->orderBy('gm.joined_at', 'ASC')
            ->get()->getResultArray();

        if ($rows === []) {
            return [];
        }

        // Resolve the full hierarchical PATH for each membership without
        // recomputing ancestry: groups.path already materializes the ancestor
        // UUID chain, so we union every referenced ancestor id and map them to
        // names in ONE bounded query (the org's group set is tiny). Cost is
        // O(memberships) + O(1) queries.
        $need = [];
        foreach ($rows as $r) {
            foreach (self::parsePathIds((string) ($r['path'] ?? '')) as $id) {
                $need[$id] = true;
            }
        }
        $nameById = [];
        if ($need !== []) {
            foreach (
                $this->db->table('groups')
                    ->select('id, name')
                    ->where('organization_id', $org)
                    ->whereIn('id', array_keys($need))
                    ->get()->getResultArray() as $g
            ) {
                $nameById[(string) $g['id']] = (string) $g['name'];
            }
        }

        return array_map(static function (array $r) use ($nameById): array {
            $path = self::groupPathNames(self::parsePathIds((string) ($r['path'] ?? '')), $nameById);

            return [
                'id'         => $r['id'],
                'name'       => (string) ($r['name'] ?? ''),
                'type'       => $r['type'] ?? null,
                'role'       => $r['role'],
                'joined_at'  => $r['joined_at'] ?? null,
                // Full hierarchical group path, root→leaf (names only, no PII).
                'group_path' => $path,
            ];
        }, $rows);
    }

    /**
     * Parse a materialized `groups.path` ("/uuidA/uuidB/") into an ordered list
     * of ancestor ids (root→leaf).
     *
     * @return list<string>
     */
    public static function parsePathIds(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn ($s): bool => $s !== ''));
    }

    /**
     * Map an ordered id chain to an ordered list of names, skipping unknown ids.
     *
     * @param list<string>         $chain    ancestor ids root→leaf
     * @param array<string,string> $nameById id→name lookup
     *
     * @return list<string>
     */
    public static function groupPathNames(array $chain, array $nameById): array
    {
        $out = [];
        foreach ($chain as $id) {
            if (isset($nameById[$id])) {
                $out[] = $nameById[$id];
            }
        }

        return $out;
    }
}
