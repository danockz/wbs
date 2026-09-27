<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Gamification\Controllers\Concerns\ResolvesTargetGroup;
use WBS\Gamification\Services\PointsEngine;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Awards surface of the gamification module (SRS FR-GAM-*): the held-award
 * approval queue plus the admin catalogs whose grants ARE awards — badges,
 * ranks, achievements, and streak definitions — and the manual award actions.
 *
 * Split out of the former GamificationController god-class. Behaviour is
 * unchanged: point rules stay versioned/immutable, ledger entries stay
 * immutable, and group-scoped approvers only ever see/act on awards inside a
 * group their grant covers.
 */
final class AwardsController extends BaseController
{
    use ResolvesTargetGroup;

    // --- Approval queue ------------------------------------------------------

    public function pending()
    {
        $orgId  = $this->orgId();
        $engine = GamificationServices::pointsEngine();
        $rows   = $engine->pendingAwards($orgId, (int) $this->field('limit', 50), (int) $this->field('offset', 0));

        // Group-scope the queue: a group-scoped approver only sees awards whose
        // subject falls inside a group their grant covers; an org-wide approver
        // sees everything. Filtered in-app because point_ledger has no group_id.
        $visible = [];
        foreach ($rows as $row) {
            if ($this->awardWithinScope($engine, $orgId, $row)) {
                $visible[] = $row;
            }
        }

        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($visible), 'Awards — pending approval');
        }

        return $this->respondWith(Result::ok($visible), 'WBS\Gamification\Views\awards_pending', null, [
            'awards' => $visible,
            // Token the global webcsrfissue filter minted this request, so the
            // inline approve/reject forms satisfy the webcsrf check.
            'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function approve(string $ledgerId = '')
    {
        $engine = GamificationServices::pointsEngine();
        if ($deny = $this->authorizeAwardScope($engine, $ledgerId)) {
            if (! $this->wantsJson()) {
                return redirect()->to('/gamification/pending')->with('error', $this->errText((string) $deny->message));
            }

            return $this->respondWith($deny);
        }
        $approver = $this->currentUserId('approver_id');
        $result   = $engine->approveAward($ledgerId, $approver, $this->field('notes'));

        return $this->respondDecision($result, 'approvedFlash');
    }

    public function reject(string $ledgerId = '')
    {
        $engine = GamificationServices::pointsEngine();
        if ($deny = $this->authorizeAwardScope($engine, $ledgerId)) {
            if (! $this->wantsJson()) {
                return redirect()->to('/gamification/pending')->with('error', $this->errText((string) $deny->message));
            }

            return $this->respondWith($deny);
        }
        $approver = $this->currentUserId('approver_id');
        $result   = $engine->rejectAward($ledgerId, $approver, (string) $this->field('reason', ''));

        return $this->respondDecision($result, 'rejectedFlash');
    }

    /**
     * PRG for a browser approve/reject decision: redirect back to the queue with a
     * success/error flash; API clients keep the JSON Result. Success copy comes
     * from the localized awardsPending flash keys, failures surface the Result
     * message (which the service localizes).
     */
    private function respondDecision(Result $result, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/gamification/pending')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Gamification.admin.awardsPending.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /**
     * Post/Redirect/Get for the in-page config catalogs (badges, ranks, streaks):
     * a browser define/disable redirects back to the list with a localized flash;
     * API clients keep the JSON Result. Because define() upserts, a successful
     * save uses the created/updated flash from the result's own `updated` marker.
     *
     * @param string $listPath absolute path of the catalog list to return to
     * @param string $section   lang subsection under Gamification.admin.<section>.form
     * @param string $okKey     success flash key; defaults to createdFlash/updatedFlash
     */
    private function respondConfigDecision(Result $result, string $listPath, string $section, string $okKey = '')
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to($listPath)->with('error', $this->errText((string) $result->message));
        }

        if ($okKey === '') {
            $okKey = ! empty($result->data['updated']) ? 'updatedFlash' : 'createdFlash';
        }

        return redirect()->to($listPath)->with(
            'success',
            (string) lang('Gamification.admin.' . $section . '.form.' . $okKey),
        );
    }

    /**
     * The active-member roster offered as the subject picker on the manual-award
     * action forms (grant/revoke badge, unlock achievement, record streak). One
     * bounded query — resource-light, no per-row fan-out. The service still
     * enforces group-scope on the actual write, so this is purely the picker.
     *
     * @return list<array<string,mixed>>
     */
    private function awardRoster(): array
    {
        return \WBS\Identity\Config\Services::accounts()->listMembers($this->orgId(), 'active');
    }

    /**
     * Post-Redirect-Get for a manual-award action triggered from a detail page.
     * API clients (JSON) get the raw Result; a browser is redirected back to the
     * detail page with a success/error flash so the action is no-JS friendly and
     * refresh-safe. $okKey names the flash under the section's `actions.*` block.
     */
    private function respondAwardAction(Result $result, string $detailPath, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to($detailPath)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($detailPath)->with(
            'success',
            (string) lang('Gamification.admin.' . $okKey),
        );
    }

    /**
     * True when the caller may manage the group(s) the award's subject belongs
     * to. An award whose subject has no group is treated as org-wide (only an
     * org-wide grant covers it). Any covered group grants access.
     */
    private function awardWithinScope(PointsEngine $engine, string $orgId, array $row): bool
    {
        $groups = $engine->subjectGroupIds(
            $orgId,
            (string) ($row['subject_id'] ?? ''),
            (string) ($row['subject_type'] ?? 'user'),
        );
        if ($groups === []) {
            return $this->canManageGroupScope('gamification.manage', null);
        }
        foreach ($groups as $gid) {
            if ($this->canManageGroupScope('gamification.manage', $gid)) {
                return true;
            }
        }

        return false;
    }

    /** Returns a denied Result when the caller may not act on this award, else null. */
    private function authorizeAwardScope(PointsEngine $engine, string $ledgerId): ?Result
    {
        $entry = $engine->findEntry($ledgerId);
        if ($entry === null) {
            return Result::notFound('gamification.ledger_not_found', 'LEDGER_NOT_FOUND');
        }
        if (! $this->awardWithinScope($engine, $this->orgId(), $entry)) {
            return Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        return null;
    }

    // --- Ranks (admin) -------------------------------------------------------

    public function listRankDefinitions()
    {
        $tiers = GamificationServices::ranks()->listDefinitions($this->orgId(), $this->targetGroupId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($tiers), 'Rank definitions');
        }

        return $this->respondWith(Result::ok($tiers), 'WBS\Gamification\Views\ranks_admin', null, [
            'tiers' => $tiers,
            'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showRank(string $code = '')
    {
        $result = GamificationServices::ranks()->show($this->orgId(), $code, $this->targetGroupId());
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Rank', $code);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\rank_show', null, ['tier' => $result->ok ? $result->data : null]);
    }

    public function defineRank()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/rank-definitions', 'ranks');
        }

        return $this->respondConfigDecision(
            GamificationServices::ranks()->define($this->orgId(), $this->input()),
            '/gamification/rank-definitions',
            'ranks',
        );
    }

    public function updateRank(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::ranks()->update(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableRank(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/rank-definitions', 'ranks');
        }

        return $this->respondConfigDecision(
            GamificationServices::ranks()->disable($this->orgId(), $code, $this->targetGroupId()),
            '/gamification/rank-definitions',
            'ranks',
            'disabledFlash',
        );
    }

    // --- Achievements (admin definitions + manual unlock) --------------------

    public function listAchievementDefinitions()
    {
        // Admin catalog shows the org-wide definitions INCLUDING secret ones (an
        // admin manages them) and inactive/disabled rows (so they can be seen).
        $achievements = GamificationServices::achievements()->listForAdmin($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($achievements), 'Achievement definitions');
        }

        return $this->respondWith(Result::ok($achievements), 'WBS\Gamification\Views\achievements_admin', null, [
            'achievements' => $achievements,
            'triggers'     => GamificationServices::achievements()->triggerTypes(),
            'phases'       => ['general', 'win', 'build', 'send'],
            'csrf'         => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showAchievement(string $code = '')
    {
        $result = GamificationServices::achievements()->show($this->orgId(), $code, $this->targetGroupId());
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Achievement', $code);
        }
        // Browser detail page carries a manual "unlock for member" action form
        // (webcsrf-guarded POST). renderForm mints the token + sets the cookie.
        return $this->renderForm('WBS\Gamification\Views\achievement_show', [
            'achievement' => $result->ok ? $result->data : null,
            'roster'      => $this->awardRoster(),
            'title'       => (string) lang('Gamification.admin.achievementShow.title'),
        ]);
    }

    public function defineAchievement()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/achievement-definitions', 'achievements');
        }

        return $this->respondConfigDecision(
            GamificationServices::achievements()->define($this->orgId(), $this->input()),
            '/gamification/achievement-definitions',
            'achievements',
        );
    }

    public function updateAchievement(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::achievements()->update(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableAchievement(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/achievement-definitions', 'achievements');
        }

        return $this->respondConfigDecision(
            GamificationServices::achievements()->disable($this->orgId(), $code, $this->targetGroupId()),
            '/gamification/achievement-definitions',
            'achievements',
            'disabledFlash',
        );
    }

    public function unlockAchievement(string $subjectId = '')
    {
        $in        = $this->input();
        $code      = (string) ($in['achievement_code'] ?? '');
        $grantedBy = $this->currentUserId('granted_by');

        $result = GamificationServices::achievements()->unlockManually(
            $this->orgId(),
            $subjectId,
            $code,
            $grantedBy,
            $in['notes'] ?? null,
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/achievements/' . rawurlencode($code),
            'achievementShow.actions.unlockedFlash',
        );
    }

    public function reevaluate(string $subjectId = '')
    {
        $result = GamificationServices::achievements()->reevaluateAll($this->orgId(), $subjectId);

        // No single achievement context — redirect back to the definitions list.
        return $this->respondAwardAction(
            $result,
            '/gamification/achievement-definitions',
            'achievementShow.actions.reevaluatedFlash',
        );
    }

    /**
     * Browser alias for {@see unlockAchievement()} from the achievement DETAIL
     * page: the achievement CODE is the path segment and the subject is chosen in
     * the POST body. Redirects back to the detail page (PRG).
     */
    public function unlockAchievementForCode(string $code = '')
    {
        $in        = $this->input();
        $subjectId = (string) ($in['subject_id'] ?? '');
        $grantedBy = $this->currentUserId('granted_by');

        $result = GamificationServices::achievements()->unlockManually(
            $this->orgId(),
            $subjectId,
            $code,
            $grantedBy,
            $in['notes'] ?? null,
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/achievements/' . rawurlencode($code),
            'achievementShow.actions.unlockedFlash',
        );
    }

    /** Browser alias for {@see reevaluate()} from the achievement detail page. */
    public function reevaluateForCode(string $code = '')
    {
        $subjectId = (string) ($this->field('subject_id', ''));
        $result    = GamificationServices::achievements()->reevaluateAll($this->orgId(), $subjectId);

        return $this->respondAwardAction(
            $result,
            '/gamification/achievements/' . rawurlencode($code),
            'achievementShow.actions.reevaluatedFlash',
        );
    }

    // --- Streak definitions (admin catalog + manual record) ------------------

    public function listStreakDefinitions()
    {
        $streaks = GamificationServices::streaks()->definitions($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($streaks), 'Streak definitions');
        }

        return $this->respondWith(Result::ok($streaks), 'WBS\Gamification\Views\streaks_admin', null, [
            'streaks' => $streaks,
            'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showStreak(string $code = '')
    {
        $result = GamificationServices::streaks()->show($this->orgId(), $code, $this->targetGroupId());
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Streak', $code);
        }
        // Browser detail page carries a manual "record for member" action form.
        return $this->renderForm('WBS\Gamification\Views\streak_show', [
            'streak' => $result->ok ? $result->data : null,
            'roster' => $this->awardRoster(),
            'title'  => (string) lang('Gamification.admin.streakShow.title'),
        ]);
    }

    public function defineStreak()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/streak-definitions', 'streaks');
        }

        return $this->respondConfigDecision(
            GamificationServices::streaks()->define($this->orgId(), $this->input()),
            '/gamification/streak-definitions',
            'streaks',
        );
    }

    public function updateStreak(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::streaks()->updateDefinition(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableStreak(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/streak-definitions', 'streaks');
        }

        return $this->respondConfigDecision(
            GamificationServices::streaks()->disableDefinition($this->orgId(), $code, $this->targetGroupId()),
            '/gamification/streak-definitions',
            'streaks',
            'disabledFlash',
        );
    }

    public function recordStreak(string $subjectId = '')
    {
        $in   = $this->input();
        $code = (string) ($in['streak_code'] ?? '');

        $result = GamificationServices::streaks()->record(
            $this->orgId(),
            $subjectId,
            $code,
            $in['season_id'] ?? null,
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/streak-definitions/' . rawurlencode($code),
            'streakShow.actions.recordedFlash',
        );
    }

    /**
     * Browser alias for {@see recordStreak()} from the streak DETAIL page: the
     * streak CODE is the path segment and the subject is chosen in the POST body.
     * Redirects back to the detail page (PRG).
     */
    public function recordStreakForCode(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondAwardAction($deny, '/gamification/streak-definitions/' . rawurlencode($code), 'streakShow.actions.recordedFlash');
        }

        $in        = $this->input();
        $subjectId = (string) ($in['subject_id'] ?? '');

        $result = GamificationServices::streaks()->record(
            $this->orgId(),
            $subjectId,
            $code,
            $in['season_id'] ?? null,
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/streak-definitions/' . rawurlencode($code),
            'streakShow.actions.recordedFlash',
        );
    }

    // --- Badges (admin catalog + manual grant/revoke) ------------------------

    public function listBadges()
    {
        $badges = GamificationServices::badges()->list(
            $this->orgId(),
            (bool) $this->field('active_only', false),
            $this->targetGroupId(),
        );
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($badges), 'Badges');
        }

        return $this->respondWith(Result::ok($badges), 'WBS\Gamification\Views\badges_admin', null, [
            'badges' => $badges,
            'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showBadge(string $code = '')
    {
        $result = GamificationServices::badges()->show($this->orgId(), $code, $this->targetGroupId());
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Badge', $code);
        }
        // Browser detail page carries manual grant/revoke action forms.
        return $this->renderForm('WBS\Gamification\Views\badge_show', [
            'badge'  => $result->ok ? $result->data : null,
            'roster' => $this->awardRoster(),
            'title'  => (string) lang('Gamification.admin.badgeShow.title'),
        ]);
    }

    public function defineBadge()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/badges', 'badges');
        }

        return $this->respondConfigDecision(
            GamificationServices::badges()->define($this->orgId(), $this->input()),
            '/gamification/badges',
            'badges',
        );
    }

    public function updateBadge(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::badges()->update(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableBadge(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/badges', 'badges');
        }

        return $this->respondConfigDecision(
            GamificationServices::badges()->disable($this->orgId(), $code, $this->targetGroupId()),
            '/gamification/badges',
            'badges',
            'disabledFlash',
        );
    }

    public function grantBadge(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondAwardAction($deny, '/gamification/badges/' . rawurlencode($code), 'badgeShow.actions.grantedFlash');
        }

        $in = $this->input();

        $result = GamificationServices::badges()->grant(
            $this->orgId(),
            $code,
            (string) ($in['subject_id'] ?? ''),
            [
                'season_id'  => $in['season_id'] ?? null,
                'source_ref' => $in['source_ref'] ?? null,
                'visibility' => $in['visibility'] ?? null,
                'group_id'   => $this->targetGroupId(),
            ],
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/badges/' . rawurlencode($code),
            'badgeShow.actions.grantedFlash',
        );
    }

    public function revokeBadge(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondAwardAction($deny, '/gamification/badges/' . rawurlencode($code), 'badgeShow.actions.revokedFlash');
        }

        $result = GamificationServices::badges()->revoke(
            $this->orgId(),
            $code,
            (string) ($this->field('subject_id', '')),
            $this->field('season_id'),
        );

        return $this->respondAwardAction(
            $result,
            '/gamification/badges/' . rawurlencode($code),
            'badgeShow.actions.revokedFlash',
        );
    }

    /** Badge awards held by a subject (member-facing; ?include_revoked=1 for admin). */
    public function subjectBadges(string $subjectId = '')
    {
        return $this->respondPage(
            Result::ok(GamificationServices::badges()->awardsForSubject(
                $this->orgId(),
                $subjectId,
                (bool) $this->field('include_revoked', false),
            )),
            'gam_subject_badges',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['awards'] ?? $d['badges'] ?? [])],
        );
    }
}
