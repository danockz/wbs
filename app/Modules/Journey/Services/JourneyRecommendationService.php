<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;

/**
 * Recommended next activities for a member (Option D follow-on).
 *
 * Reads the nullable `stage_code` added by migration 000060 on the three
 * configurable catalogs — activity earning rules (`gamification_rules`),
 * activity categories (`activity_categories`), and follow-up types
 * (`follow_up_types`) — and surfaces the ones linked to WHERE THE MEMBER IS on
 * the journey ladder. Pure read: it computes nothing new and awards nothing; it
 * just answers "given this person's stage, what should they (or their discipler)
 * do next?".
 *
 * Design choices, consistent with the rest of the platform:
 *   - The member's stage is resolved via JourneyService (org-wide primary
 *     journey unless a group context is given).
 *   - By default we recommend activities tagged to the member's CURRENT stage
 *     AND the NEXT stage on the effective ladder (the immediate step up), so the
 *     view both reinforces the current phase and points forward. Callers can ask
 *     for just the current stage, just the next stage, or a wider window.
 *   - Catalog rows are group-scope resolved with the SAME most-specific-wins
 *     rule used by ActivityCatalogService / FollowUpService: the group's own row
 *     beats a nearer ancestor (with include_descendants) beats org-wide (NULL).
 *   - Only ACTIVE rows are returned; a stage with no linked activities simply
 *     comes back empty (stage_code is advisory, never required).
 */
final class JourneyRecommendationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JourneyService $journey,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Recommend stage-linked activities for a member.
     *
     * @param array{scope?:string,ahead?:int} $opts
     *        scope: 'current' | 'next' | 'both' (default) | 'from_current'
     *               ('from_current' = current plus the next $ahead stages).
     *        ahead: how many stages beyond current to include when
     *               scope='from_current' (default 1, capped at 5).
     *
     * @return Result data: {
     *   user_id, group_id, current_stage|null, has_journey,
     *   stages: [ { code, name, phase, position:'current'|'next'|'ahead',
     *               activities:[...], categories:[...], follow_up_types:[...] } ],
     *   totals: { activities, categories, follow_up_types }
     * }
     */
    public function forMember(string $organizationId, string $userId, ?string $groupId = null, array $opts = []): Result
    {
        $userId = trim($userId);
        if ($userId === '') {
            return Result::fail('BAD_USER', 'journey.bad_user', 422);
        }

        $ladder = $this->journey->ladder($organizationId, $groupId);
        if ($ladder === []) {
            return Result::fail('NO_LADDER', 'journey.no_stage', 422, [
                'detail' => 'No stage ladder for this context; seed or define the ladder first.',
            ]);
        }

        $current    = $this->journey->currentStage($organizationId, $userId, $groupId);
        $hasJourney = $current !== null;

        $targets = $this->targetStages($ladder, $current, $opts);
        if ($targets === []) {
            return $this->emptyResult($userId, $groupId, $current, $hasJourney);
        }

        $codes     = array_map(static fn (array $t): string => (string) $t['stage']['code'], $targets);
        $ancestors = $this->ancestorsOf($groupId);

        $activities = $this->scopedByStage($organizationId, 'gamification_rules', $codes, $groupId, $ancestors, true);
        $categories = $this->scopedByStage($organizationId, 'activity_categories', $codes, $groupId, $ancestors, false);
        $followUps  = $this->scopedByStage($organizationId, 'follow_up_types', $codes, $groupId, $ancestors, false);

        $stages = [];
        $tot    = ['activities' => 0, 'categories' => 0, 'follow_up_types' => 0];
        foreach ($targets as $t) {
            $code = (string) $t['stage']['code'];
            $a    = $this->presentActivities($activities[$code] ?? []);
            $c    = $this->presentCategories($categories[$code] ?? []);
            $f    = $this->presentFollowUps($followUps[$code] ?? []);

            $tot['activities']      += count($a);
            $tot['categories']      += count($c);
            $tot['follow_up_types'] += count($f);

            $stages[] = [
                'code'            => $code,
                'name'            => (string) $t['stage']['name'],
                'phase'           => (string) $t['stage']['phase'],
                'position'        => $t['position'],
                'activities'      => $a,
                'categories'      => $c,
                'follow_up_types' => $f,
            ];
        }

        return Result::ok([
            'user_id'       => $userId,
            'group_id'      => $groupId,
            'current_stage' => $current,
            'has_journey'   => $hasJourney,
            'stages'        => $stages,
            'totals'        => $tot,
        ]);
    }

    private function emptyResult(string $userId, ?string $groupId, ?string $current, bool $hasJourney): Result
    {
        return Result::ok([
            'user_id'       => $userId,
            'group_id'      => $groupId,
            'current_stage' => $current,
            'has_journey'   => $hasJourney,
            'stages'        => [],
            'totals'        => ['activities' => 0, 'categories' => 0, 'follow_up_types' => 0],
        ]);
    }

    /**
     * Which ladder stages to recommend for, each tagged with its position
     * relative to the member's current stage.
     *
     * @param list<array<string,mixed>>       $ladder ordered by sort_order
     * @param array{scope?:string,ahead?:int} $opts
     * @return list<array{stage:array<string,mixed>,position:string}>
     */
    private function targetStages(array $ladder, ?string $current, array $opts): array
    {
        $scope = (string) ($opts['scope'] ?? 'both');
        $ahead = max(1, min(5, (int) ($opts['ahead'] ?? 1)));

        $idx = -1;
        foreach ($ladder as $i => $s) {
            if ((string) $s['code'] === (string) $current) {
                $idx = $i;
                break;
            }
        }

        // No journey yet: recommend for the entry stage (the natural first step).
        if ($idx === -1) {
            $entry = null;
            foreach ($ladder as $s) {
                if ((int) ($s['is_entry'] ?? 0) === 1) {
                    $entry = $s;
                    break;
                }
            }
            $entry ??= $ladder[0];

            return [['stage' => $entry, 'position' => 'next']];
        }

        $out     = [];
        $curr    = $ladder[$idx];
        $nextOne = $ladder[$idx + 1] ?? null;

        $wantCurrent = in_array($scope, ['current', 'both', 'from_current'], true);
        $wantNext    = in_array($scope, ['next', 'both'], true);

        if ($wantCurrent) {
            $out[] = ['stage' => $curr, 'position' => 'current'];
        }
        if ($wantNext && $nextOne !== null) {
            $out[] = ['stage' => $nextOne, 'position' => 'next'];
        }
        if ($scope === 'from_current') {
            for ($k = 1; $k <= $ahead; $k++) {
                $s = $ladder[$idx + $k] ?? null;
                if ($s === null) {
                    break;
                }
                $out[] = ['stage' => $s, 'position' => $k === 1 ? 'next' : 'ahead'];
            }
        }

        return $out;
    }

    /**
     * Fetch active rows of $table whose stage_code is one of $codes, honouring
     * group scope, then collapse to the most-specific row per (stage_code, code).
     * For versioned tables (gamification_rules) the highest version wins ties.
     *
     * @param list<string> $codes     stage codes to match
     * @param list<string> $ancestors nearest-first ancestor group ids
     * @return array<string,list<array<string,mixed>>> keyed by stage_code
     */
    private function scopedByStage(string $organizationId, string $table, array $codes, ?string $groupId, array $ancestors, bool $versioned): array
    {
        if ($codes === []) {
            return [];
        }

        $q = $this->db->table($table)
            ->where('organization_id', $organizationId)
            ->whereIn('stage_code', $codes)
            ->where('status', 'active');

        if ($groupId !== null && $groupId !== '') {
            $q->groupStart()
                ->where('group_id', $groupId)
                ->orWhere('group_id', null);
            if ($ancestors !== []) {
                $q->orGroupStart()
                    ->where('include_descendants', 1)
                    ->whereIn('group_id', $ancestors)
                  ->groupEnd();
            }
            $q->groupEnd();
        } else {
            $q->where('group_id', null);
        }

        $rows = $q->get()->getResultArray();

        // rank: self=0, ancestors=1..n, org-wide(NULL)=PHP_INT_MAX.
        $rank = [];
        if ($groupId !== null && $groupId !== '') {
            $rank[$groupId] = 0;
            foreach ($ancestors as $i => $aid) {
                $rank[$aid] = $i + 1;
            }
        }

        $best = [];
        foreach ($rows as $r) {
            $stage = (string) ($r['stage_code'] ?? '');
            $code  = (string) ($r['code'] ?? $r['id']);
            $key   = $stage . "\0" . $code;
            $g     = $r['group_id'] !== null && $r['group_id'] !== '' ? (string) $r['group_id'] : null;
            $rk    = $g === null ? PHP_INT_MAX : ($rank[$g] ?? PHP_INT_MAX - 1);
            $ver   = $versioned ? (int) ($r['version'] ?? 0) : 0;

            if (! isset($best[$key])
                || $rk < $best[$key]['_rank']
                || ($rk === $best[$key]['_rank'] && $ver > $best[$key]['_ver'])) {
                $r['_rank'] = $rk;
                $r['_ver']  = $ver;
                $best[$key] = $r;
            }
        }

        $byStage = [];
        foreach ($best as $r) {
            $stage = (string) ($r['stage_code'] ?? '');
            unset($r['_rank'], $r['_ver']);
            $byStage[$stage][] = $r;
        }

        foreach ($byStage as &$list) {
            usort($list, static function (array $a, array $b): int {
                return [(int) ($a['sort_order'] ?? 0), (string) ($a['name'] ?? $a['activity_name'] ?? $a['code'] ?? '')]
                   <=> [(int) ($b['sort_order'] ?? 0), (string) ($b['name'] ?? $b['activity_name'] ?? $b['code'] ?? '')];
            });
        }
        unset($list);

        return $byStage;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function presentActivities(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'code'        => (string) $r['code'],
                'name'        => (string) ($r['activity_name'] ?? $r['code']),
                'phase'       => (string) ($r['phase'] ?? 'general'),
                'stage_code'  => (string) $r['stage_code'],
                'points'      => isset($r['points']) ? (int) $r['points'] : null,
                'point_mode'  => (string) ($r['point_mode'] ?? 'fixed'),
                'category_id' => $r['category_id'] !== null && $r['category_id'] !== '' ? (string) $r['category_id'] : null,
                'icon'        => $r['icon'] ?? null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function presentCategories(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'code'       => (string) $r['code'],
                'name'       => (string) $r['name'],
                'phase'      => (string) ($r['phase'] ?? 'general'),
                'stage_code' => (string) $r['stage_code'],
                'icon'       => $r['icon'] ?? null,
                'color'      => $r['color'] ?? null,
            ];
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function presentFollowUps(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'code'            => (string) $r['code'],
                'name'            => (string) $r['name'],
                'phase'           => (string) ($r['phase'] ?? 'build'),
                'stage_code'      => (string) $r['stage_code'],
                'award_rule_code' => $r['award_rule_code'] ?? null,
            ];
        }

        return $out;
    }

    /** @return list<string> nearest-first ancestors, or [] */
    private function ancestorsOf(?string $groupId): array
    {
        if ($groupId === null || $groupId === '' || $this->groupScope === null) {
            return [];
        }

        return $this->groupScope->ancestors($groupId);
    }
}
