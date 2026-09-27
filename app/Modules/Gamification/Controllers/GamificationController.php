<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Member-facing read surface of the gamification module (SRS FR-GAM-*):
 * balances, leaderboards, standings, and a subject's achievements/streaks/ranks.
 *
 * Every read endpoint negotiates its representation in BaseController: a browser
 * gets the server-rendered HTML view named below, an API client (Accept:
 * application/json, ?format=json, or XHR) gets the same payload as JSON. Views
 * live in app/Modules/Gamification/Views.
 *
 * The former god-class (77 endpoints) was decomposed by responsibility; the
 * admin/write surfaces now live in focused controllers:
 *   - {@see AwardsController}    approval queue + badges/ranks/achievements/streaks
 *   - {@see ConfigController}    point rules, activity catalog, runtime config
 *   - {@see FollowUpsController} follow-up types/methods + records
 *   - {@see CampaignsController} group campaigns / "projects"
 *
 * Read endpoints never expose email or other PII — leaderboards return
 * subject_id + display name + points only.
 */
final class GamificationController extends BaseController
{
    public function balance(string $subjectId = '')
    {
        $orgId = $this->orgId();
        $bal   = GamificationServices::pointsEngine()->balance($orgId, $subjectId);

        return $this->respondWith(
            Result::ok(['subject_id' => $subjectId, 'points' => $bal]),
            htmlView: 'WBS\Gamification\Views\balance',
            viewData: ['result' => ['subject_id' => $subjectId, 'points' => $bal], 'title' => 'Points balance'],
        );
    }

    public function leaderboard()
    {
        $orgId  = $this->orgId();
        $result = GamificationServices::leaderboard()->top($orgId, (int) $this->field('limit', 10), [
            'subject_type' => (string) $this->field('subject_type', 'user'),
            'season_id'    => $this->field('season_id'),
        ]);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\_board_subjects',
            viewData: ['result' => $result->data, 'title' => 'Leaderboard'],
        );
    }

    /**
     * INDIVIDUAL board, optionally scoped to one group and its subtree, with a
     * configurable ranking measure and cross-cut filters (design doc B.5).
     * GET gamification/leaderboards/individuals
     *   ?within_group=&include_subtree=1&measure=points|volume|contributions
     *   &category=&project=&phase=&season_id=&limit=
     */
    public function individualsBoard()
    {
        $result = GamificationServices::leaderboard()->individuals(
            $this->orgId(),
            (int) $this->field('limit', 10),
            $this->normalized('within_group'),
            $this->boardFilters() + ['include_subtree' => (bool) $this->field('include_subtree', false)],
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\_board_subjects',
            viewData: ['result' => $result->data, 'title' => 'Individual leaderboard'],
        );
    }

    /**
     * GROUP board — ranks a parent's direct children (or all groups) by their
     * rolled-up subtree total in the chosen measure.
     * GET gamification/leaderboards/groups?parent=&measure=&category=&project=&phase=
     */
    public function groupsBoard()
    {
        $result = GamificationServices::leaderboard()->groups(
            $this->orgId(),
            (int) $this->field('limit', 10),
            $this->normalized('parent'),
            $this->boardFilters(),
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\_board_groups',
            viewData: ['result' => $result->data, 'title' => 'Group leaderboard'],
        );
    }

    /** A single group's own subtree standing + position among its siblings. */
    public function groupStanding(string $groupId = '')
    {
        $result = GamificationServices::leaderboard()->groupStanding(
            $this->orgId(),
            $groupId,
            $this->boardFilters(),
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\group_standing',
            viewData: ['result' => $result->data, 'title' => 'Group standing'],
        );
    }

    /**
     * Cross-cutting board over one axis value — activity category / project /
     * phase — ranking groups by the chosen measure.
     * GET gamification/leaderboards/by/(category|project|phase)/{value}
     */
    public function dimensionBoard(string $axis = '', string $value = '')
    {
        $result = GamificationServices::leaderboard()->byDimension(
            $this->orgId(),
            $axis,
            $value,
            (int) $this->field('limit', 10),
            ['season_id' => $this->field('season_id'), 'measure' => (string) $this->field('measure', 'points')],
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\_board_groups',
            viewData: ['result' => $result->data, 'title' => 'Ranking by ' . $axis],
        );
    }

    /**
     * DEPARTMENT / TEAM board — ranks groups by the summed member totals for a
     * membership type (set aggregation, not an ancestor roll-up).
     * GET gamification/leaderboards/membership/(department|team|...)?measure=&limit=
     */
    public function membershipBoard(string $type = '')
    {
        $result = GamificationServices::leaderboard()->membershipBoard(
            $this->orgId(),
            $type,
            (int) $this->field('limit', 10),
            ['season_id' => $this->field('season_id'), 'measure' => (string) $this->field('measure', 'points')],
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\_board_groups',
            viewData: ['result' => $result->data, 'title' => ucfirst($type) . ' leaderboard'],
        );
    }

    /**
     * Shared board filters from the query string.
     *
     * @return array<string,mixed>
     */
    private function boardFilters(): array
    {
        return [
            'season_id' => $this->field('season_id'),
            'measure'   => (string) $this->field('measure', 'points'),
            'category'  => $this->normalized('category'),
            'project'   => $this->normalized('project'),
            'phase'     => $this->normalized('phase'),
        ];
    }

    /** A trimmed non-empty query field, or null. */
    private function normalized(string $key): ?string
    {
        $v = $this->field($key);

        return $v !== null && $v !== '' ? (string) $v : null;
    }

    public function standing(string $subjectId = '')
    {
        $orgId  = $this->orgId();
        $result = GamificationServices::leaderboard()->standing(
            $orgId,
            $subjectId,
            $this->field('season_id'),
            (string) $this->field('subject_type', 'user'),
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Gamification\Views\standing',
            viewData: ['result' => $result->data, 'title' => 'Standing', 'subjectId' => $subjectId],
        );
    }

    public function achievements(string $subjectId = '')
    {
        $orgId        = $this->orgId();
        $withProgress = (bool) $this->field('progress', false);
        $data         = GamificationServices::achievements()->getUserAchievements($orgId, $subjectId, $withProgress);

        return $this->respondWith(
            Result::ok($data),
            htmlView: 'WBS\Gamification\Views\user_achievements',
            viewData: ['result' => $data, 'title' => 'Achievements', 'subjectId' => $subjectId],
        );
    }

    public function allAchievements()
    {
        $orgId = $this->orgId();
        $items = GamificationServices::achievements()->listAll($orgId, (bool) $this->field('include_secret', false));

        return $this->respondWith(
            Result::ok($items),
            htmlView: 'WBS\Gamification\Views\achievements',
            viewData: ['result' => ['achievements' => $items], 'title' => 'Achievements'],
        );
    }

    public function streaks(string $subjectId = '')
    {
        $orgId = $this->orgId();
        $items = GamificationServices::streaks()->getAll($orgId, $subjectId, $this->field('season_id'));

        return $this->respondWith(
            Result::ok($items),
            htmlView: 'WBS\Gamification\Views\streaks',
            viewData: ['result' => $items, 'title' => 'Streaks', 'subjectId' => $subjectId],
        );
    }

    public function ranks()
    {
        $orgId = $this->orgId();
        $tiers = GamificationServices::ranks()->tiers($orgId);

        return $this->respondWith(
            Result::ok($tiers),
            htmlView: 'WBS\Gamification\Views\ranks',
            viewData: ['result' => $tiers, 'title' => 'Ranks'],
        );
    }
}
