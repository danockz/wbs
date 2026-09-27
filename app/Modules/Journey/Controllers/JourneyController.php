<?php

declare(strict_types=1);

namespace WBS\Journey\Controllers;

use WBS\Journey\Config\Services as JourneyServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Shared\Http\BaseController;

/**
 * Membership Journey endpoints (assessment Option B).
 *
 * Two surfaces:
 *   - stage-ladder configuration (journey-stages) — gated by gamification.manage,
 *     the platform's existing "configure the engagement model" permission;
 *   - per-member journey operations (open/transition/status/read) and the
 *     pipeline view — mutations are group-scope-checked so a leader can only
 *     move members within their scope, matching the ACL scope model.
 *
 * The scope check reuses authorizeGroupScope() against the transition's target
 * group context; a NULL context (org-wide primary journey) requires an org-wide
 * grant, exactly like other org-wide operations.
 */
final class JourneyController extends BaseController
{
    private const SCOPE_ACTION = 'gamification.manage';

    // ---- Stage ladder -------------------------------------------------------

    public function listStages()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $stages = JourneyServices::journey()->ladder($this->orgId(), $groupId);

        // Browsers get the bespoke stage-ladder admin (create/edit/deactivate);
        // API clients keep the JSON payload.
        return $this->respondWith(
            \WBS\Shared\Support\Result::ok([
                'group_id' => $groupId,
                'stages'   => $stages,
            ]),
            'WBS\Journey\Views\stages_admin',
            null,
            [
                'group_id' => $groupId,
                'stages'   => $stages,
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function defineStage()
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;

        // Group-scoped stage config is bounded by the leader's scope, exactly like
        // the per-member moves; an org-wide stage (NULL context) needs an org grant.
        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondJourneyDecision($denied, '/journey/stages', 'stages', $groupId);
        }

        return $this->respondJourneyDecision(
            JourneyServices::journey()->defineStage($this->orgId(), $in),
            '/journey/stages',
            'stages',
            $groupId,
        );
    }

    // ---- Involvement-triage config console ----------------------------------

    /**
     * Admin console to switch involvement-based triage ON/OFF and tune the
     * window / activity target / band thresholds / quantum weights for a context
     * (org-wide or a group). Browsers get the bespoke `involvement_config` form;
     * API clients get the JSON view-model. Read is scope-checked like the ladder.
     */
    public function involvementConfig()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondJourneyDecision($denied, '/journey/pipeline', 'involvementConfig', $groupId);
        }

        $view = JourneyServices::involvement()->configView($this->orgId(), $groupId);

        return $this->respondWith(
            \WBS\Shared\Support\Result::ok($view),
            'WBS\Journey\Views\involvement_config',
            null,
            $view + ['csrf' => (string) ($this->request->wbsCsrf ?? '')],
        );
    }

    /** Persist involvement config (PRG back to the console). Scope-checked + webcsrf. */
    public function saveInvolvementConfig()
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondJourneyDecision($denied, '/journey/involvement/config', 'involvementConfig', $groupId);
        }

        return $this->respondJourneyDecision(
            JourneyServices::involvement()->saveConfig($this->orgId(), $groupId, $in, $this->actorId()),
            '/journey/involvement/config',
            'involvementConfig',
            $groupId,
            'savedFlash',
        );
    }

    // ---- Journeys -----------------------------------------------------------

    public function openJourney(string $userId)
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondMemberDecision($denied, $userId, $groupId);
        }

        $in['actor_id'] = $this->actorId();

        return $this->respondMemberDecision(
            JourneyServices::journey()->openJourney($this->orgId(), $userId, $in),
            $userId,
            $groupId,
            'openedFlash',
        );
    }

    public function transition(string $userId)
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondMemberDecision($denied, $userId, $groupId);
        }

        $in['actor_id'] = $this->actorId();
        // Default the discipler to the actor unless one is supplied.
        if (! isset($in['discipler_id']) || $in['discipler_id'] === '') {
            $in['discipler_id'] = $this->actorId();
        }

        return $this->respondMemberDecision(
            JourneyServices::journey()->transition($this->orgId(), $userId, (string) ($in['to_stage'] ?? ''), $in),
            $userId,
            $groupId,
            'transitionedFlash',
        );
    }

    public function setStatus(string $userId)
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondMemberDecision($denied, $userId, $groupId);
        }

        return $this->respondMemberDecision(
            JourneyServices::journey()->setStatus($this->orgId(), $userId, (string) ($in['status'] ?? ''), $groupId, [
                'actor_id' => $this->actorId(),
                'reason'   => isset($in['reason']) && $in['reason'] !== '' ? (string) $in['reason'] : null,
            ]),
            $userId,
            $groupId,
            'statusFlash',
        );
    }

    public function showForUser(string $userId)
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $res = JourneyServices::journey()->getJourney($this->orgId(), $userId, $groupId);

        // API clients get the plain Result (journey + history, or 404). Browsers
        // get the member-detail page: the journey timeline plus manual controls
        // (advance/set stage, pause/resume/archive, or open when none exists).
        // The page renders even on 404 so a leader can OPEN a journey — so build
        // the view data from the Result whether or not a journey was found.
        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        $journey = $res->ok && is_array($res->data) ? $res->data : null;
        $history = is_array($journey['history'] ?? null) ? $journey['history'] : [];

        return $this->respondWith(
            \WBS\Shared\Support\Result::ok($journey),
            'WBS\Journey\Views\member',
            null,
            [
                'user_id' => $userId,
                'group_id' => $groupId,
                'journey' => $journey,
                'history' => $history,
                'ladder'  => JourneyServices::journey()->ladder($this->orgId(), $groupId),
                // Eligible disciplers = the active roster of the member's group, so
                // the view can offer a picker (name → user_id) instead of a raw ID
                // text box. Only meaningful when a group context is known.
                'disciplers' => ($groupId !== null && $groupId !== '')
                    ? GroupServices::memberships()->listForGroup($this->orgId(), $groupId, 'active')
                    : [],
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function listForUser(string $userId)
    {
        return $this->respondPage(
            JourneyServices::journey()->journeysForUser($this->orgId(), $userId),
            'journey_user_all',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['journeys'] ?? [])],
        );
    }

    /**
     * Recommended next activities for a member (Option D follow-on): stage-linked
     * activities/categories/follow-up types for where they are on the ladder.
     * A read scoped to the requested group context — same group-scope check as
     * the other per-member operations.
     */
    public function recommendations(string $userId)
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondWith($denied);
        }

        $opts = [];
        if (($scope = $this->field('scope')) !== null && $scope !== '') {
            $opts['scope'] = (string) $scope;
        }
        if (($ahead = $this->field('ahead')) !== null && $ahead !== '') {
            $opts['ahead'] = (int) $ahead;
        }

        $res = JourneyServices::recommendations()->forMember($this->orgId(), $userId, $groupId, $opts);

        // API clients get the grouped JSON payload. Browsers get the bespoke
        // stage-aware dashboard: one card per target stage, each listing the
        // linked earning activities / activity categories / follow-up types, with
        // a deep link into the member's journey detail. The generic data-page
        // template can't render the nested stages structure, so a dedicated view
        // is used — it renders even on failure (no ladder / bad user) so the
        // leader sees a friendly explanation rather than raw JSON.
        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        $data = $res->ok && is_array($res->data) ? $res->data : [];

        return $this->respondWith(
            \WBS\Shared\Support\Result::ok($data),
            'WBS\Journey\Views\recommendations',
            null,
            [
                'user_id'       => $userId,
                'group_id'      => $groupId,
                'current_stage' => $data['current_stage'] ?? null,
                'has_journey'   => (bool) ($data['has_journey'] ?? false),
                'stages'        => is_array($data['stages'] ?? null) ? $data['stages'] : [],
                'totals'        => is_array($data['totals'] ?? null) ? $data['totals'] : [],
                'scope'         => (string) ($opts['scope'] ?? 'both'),
                // Surface the friendly failure (e.g. NO_LADDER) so the view can
                // explain why there is nothing to recommend yet.
                'error'         => $res->ok ? '' : (string) ($res->message ?? ''),
            ],
        );
    }

    // ---- Pipeline -----------------------------------------------------------

    public function pipeline()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $res  = JourneyServices::journey()->pipeline($this->orgId(), $groupId);
        $data = is_array($res->data) ? $res->data : [];

        // Browsers get the bespoke pipeline view (no raw JSON); API clients JSON.
        return $this->respondWith(
            $res,
            'WBS\Journey\Views\pipeline',
            null,
            [
                'group_id'    => $data['group_id'] ?? $groupId,
                'total'       => (int) ($data['total'] ?? 0),
                'triage'      => $data['triage'] ?? ['hot' => 0, 'warm' => 0, 'cold' => 0],
                'triage_mode' => (string) ($data['triage_mode'] ?? 'time_in_stage'),
                'stages'      => $data['stages'] ?? [],
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /**
     * Discipleship FUNNEL & progression report (journey/funnel) — the cohort
     * lens the assessment named as the reporting gap: how far the body advances
     * along the ladder and where it thins out (current-state funnel + recent
     * momentum). A pure read; browsers get the bespoke view, API clients JSON.
     * An optional ?window=DAYS tunes the momentum window (default 90).
     */
    public function funnel()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $window  = (int) ($this->field('window', 90));

        $res  = JourneyServices::journey()->funnel($this->orgId(), $groupId, $window);
        $data = is_array($res->data) ? $res->data : [];

        return $this->respondWith(
            $res,
            'WBS\Journey\Views\funnel',
            null,
            [
                'group_id'        => $data['group_id'] ?? $groupId,
                'window_days'     => (int) ($data['window_days'] ?? $window),
                'total_active'    => (int) ($data['total_active'] ?? 0),
                'moves_in_window' => (int) ($data['moves_in_window'] ?? 0),
                'stages'          => $data['stages'] ?? [],
            ],
        );
    }

    /**
     * Rebuild the involvement snapshots for a context (org-wide or a group), then
     * PRG back to the pipeline. This is the batch-recompute entry point behind the
     * "recompute" action on the involvement pipeline — a leader/admin action, not
     * the render hot path. The heavy cross-module reads happen here, once per
     * member, inside the service. API clients get the raw Result.
     */
    public function recomputeInvolvement()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $res = JourneyServices::involvement()->recomputeContext($this->orgId(), $groupId);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        $to = '/journey/pipeline' . ($groupId !== null ? '?group_id=' . rawurlencode($groupId) : '');
        if (! $res->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $res->message));
        }
        $n    = (int) (($res->data['refreshed'] ?? 0));
        $flash = str_replace('{0}', (string) $n, lang('Journey.recomputedFlash'));

        return redirect()->to($to)->with('success', $flash);
    }

    public function membersAtStage(string $stageCode)
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $limit   = (int) ($this->field('limit', 200));
        $offset  = (int) ($this->field('offset', 0));
        $temp    = $this->field('temperature');
        $temp    = in_array($temp, ['hot', 'warm', 'cold'], true) ? (string) $temp : null;

        return $this->respondPage(
            JourneyServices::journey()->membersAtStage($this->orgId(), $stageCode, $groupId, $limit, $offset, $temp),
            'journey_stage_members',
            static function (array $d) use ($groupId): array {
                $rows        = array_is_list($d) ? $d : ($d['members'] ?? []);
                $byInvolve   = is_array($d) && ($d['triage_mode'] ?? '') === 'involvement';

                $extra = [
                    'rows'    => $rows,
                    'filters' => [
                        [
                            'name'        => 'temperature',
                            'labelKey'    => 'Pages.common.colTriage',
                            'anyLabelKey' => 'Pages.common.anyTemperature',
                            'options'     => [
                                ['value' => 'hot',  'labelKey' => 'Pages.common.tempHot'],
                                ['value' => 'warm', 'labelKey' => 'Pages.common.tempWarm'],
                                ['value' => 'cold', 'labelKey' => 'Pages.common.tempCold'],
                            ],
                        ],
                    ],
                    'filterValues' => ['temperature' => is_array($d) ? (string) ($d['temperature'] ?? '') : ''],
                    'filterHidden' => $groupId !== null ? ['group_id' => $groupId] : [],
                ];

                // In involvement mode, surface the quantum-of-work figures the
                // leader needs beside each member: flatten the row's involvement.*
                // bundle to top-level presenter-visible keys, override the columns
                // to show them, and add a caption explaining the ordering.
                if ($byInvolve) {
                    foreach ($extra['rows'] as $i => $r) {
                        $inv = is_array($r['involvement'] ?? null) ? $r['involvement'] : [];
                        $extra['rows'][$i]['participation'] = isset($inv['participation_bps'])
                            ? (int) round((int) $inv['participation_bps'] / 100) . '%'
                            : '';
                        $extra['rows'][$i]['sponsors']     = (string) ($inv['sponsorship_count'] ?? '');
                        $extra['rows'][$i]['giving_major'] = isset($inv['giving_minor'])
                            ? number_format((int) $inv['giving_minor'] / 100, 0)
                            : '';
                        $extra['rows'][$i]['points']       = (string) ($inv['points'] ?? '');
                        $extra['rows'][$i]['quantum']      = (string) ($inv['quantum'] ?? '');
                        $extra['rows'][$i]['last_activity_at'] = (string) ($inv['last_activity_at'] ?? '');
                    }
                    $extra['columns'] = [
                        ['key' => 'display_name', 'type' => 'strong', 'labelKey' => 'Pages.common.colName'],
                        ['key' => 'temperature', 'type' => 'chip', 'labelKey' => 'Pages.common.colTriage', 'colors' => ['hot' => '#f87171', 'warm' => '#fbbf24', 'cold' => '#60a5fa']],
                        ['key' => 'participation', 'type' => 'text', 'labelKey' => 'Pages.common.colParticipation'],
                        ['key' => 'sponsors', 'type' => 'num', 'labelKey' => 'Pages.common.colSponsors'],
                        ['key' => 'giving_major', 'type' => 'num', 'labelKey' => 'Pages.common.colGiving'],
                        ['key' => 'points', 'type' => 'num', 'labelKey' => 'Pages.common.colPoints'],
                        ['key' => 'quantum', 'type' => 'num', 'labelKey' => 'Pages.common.colQuantum'],
                        ['key' => 'last_activity_at', 'type' => 'date', 'labelKey' => 'Pages.common.colLastActivity'],
                    ];
                    $extra['subOverride'] = lang('Journey.rosterInvolvementNote');
                }

                return $extra;
            },
        );
    }

    // ---- Disciple-making leaderboard (Option D) -----------------------------

    public function disciplerLeaderboard()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $limit   = (int) ($this->field('limit', 20));
        $filters = [];
        if (($p = $this->field('phase')) !== null && $p !== '') {
            $filters['phase'] = (string) $p;
        }
        if (($s = $this->field('since')) !== null && $s !== '') {
            $filters['since'] = (string) $s;
        }

        return $this->respondPage(
            JourneyServices::journey()->disciplerLeaderboard($this->orgId(), $groupId, $limit, $filters),
            'journey_disciplers',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['leaderboard'] ?? $d['disciplers'] ?? [])],
        );
    }

    // ---- Rule-driven signals & proposals (Option C) -------------------------

    /**
     * Ingest a journey signal (course completed, event attended, follow-up, …).
     * Membership rules decide what happens; matched rules with effect 'adjust'
     * auto-apply, 'require_review'/'flag' queue a proposal. Scope-checked against
     * the signal's group context so a rule only bites within its author's scope.
     */
    public function signal()
    {
        $in      = $this->input();
        $groupId = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;
        // The rule-firing scope: which leaders' rules the signal may fire. It
        // defaults to the journey-context group when the caller omits it (mirrors
        // JourneySignalService::ingest). A caller MUST NOT be able to fire rules
        // in a branch they don't manage, so we authorize the EFFECTIVE
        // scope_group_id — not just the journey context — before ingest reads it.
        $scopeGroupId = isset($in['scope_group_id']) && $in['scope_group_id'] !== ''
            ? (string) $in['scope_group_id']
            : $groupId;

        if ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $groupId)) {
            return $this->respondWith($denied);
        }
        // Only re-check when the rule-scope differs from the journey context we
        // already cleared, so the common (equal) case costs one PDP call.
        if ($scopeGroupId !== $groupId
            && ($denied = $this->authorizeGroupScope(self::SCOPE_ACTION, $scopeGroupId))) {
            return $this->respondWith($denied);
        }

        $in['actor_id'] = $in['actor_id'] ?? $this->actorId();

        return $this->respondWith(
            JourneyServices::journeySignals()->ingest($this->orgId(), $in),
        );
    }

    public function listProposals()
    {
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $limit   = (int) ($this->field('limit', 200));
        $offset  = (int) ($this->field('offset', 0));

        $res  = JourneyServices::journeySignals()->pendingProposals($this->orgId(), $groupId, $limit, $offset);
        $data = is_array($res->data) ? $res->data : [];

        // Browsers get the bespoke maker-checker review queue; API clients JSON.
        return $this->respondWith(
            $res,
            'WBS\Journey\Views\proposals',
            null,
            [
                'group_id'  => $data['group_id'] ?? $groupId,
                'proposals' => $data['proposals'] ?? [],
                'csrf'      => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function approveProposal(string $proposalId)
    {
        $in             = $this->input();
        $in['actor_id'] = $this->actorId();

        return $this->respondJourneyDecision(
            JourneyServices::journeySignals()->approveProposal($this->orgId(), $proposalId, $in),
            '/journey/proposals',
            'proposals',
            null,
            'approvedFlash',
        );
    }

    public function rejectProposal(string $proposalId)
    {
        $in             = $this->input();
        $in['actor_id'] = $this->actorId();

        return $this->respondJourneyDecision(
            JourneyServices::journeySignals()->rejectProposal($this->orgId(), $proposalId, $in),
            '/journey/proposals',
            'proposals',
            null,
            'rejectedFlash',
        );
    }

    /**
     * Post/Redirect/Get for the Journey admin surfaces (stage ladder + proposal
     * review): a browser write redirects back to the list with a localized flash,
     * carrying the group scope in the query string so the reader stays on the same
     * context; API clients keep the JSON Result. defineStage() upserts, so a
     * successful save picks created vs updated from the result's `created` status.
     */
    private function respondJourneyDecision(
        \WBS\Shared\Support\Result $result,
        string $listPath,
        string $section,
        ?string $groupId = null,
        string $okKey = '',
    ) {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        // Preserve the group-context filter on the redirect so the admin lands
        // back on the same ladder/queue they were editing.
        if ($groupId !== null && $groupId !== '') {
            $listPath .= '?group_id=' . rawurlencode($groupId);
        }

        if (! $result->ok) {
            return redirect()->to($listPath)->with('error', $this->errText((string) $result->message));
        }

        if ($okKey === '') {
            // 201 Created vs 200 OK distinguishes a new stage from an edit.
            $okKey = $result->status === 201 ? 'createdFlash' : 'updatedFlash';
        }

        return redirect()->to($listPath)->with(
            'success',
            (string) lang('Journey.admin.' . $section . '.' . $okKey),
        );
    }

    /**
     * Post/Redirect/Get for the per-member journey page: a browser open/transition/
     * status write redirects back to /journey/members/{id} (with the group context
     * preserved) and a localized flash; API clients keep the JSON Result. The flash
     * key selects the message; a failing Result flashes its own message as an error.
     */
    private function respondMemberDecision(
        \WBS\Shared\Support\Result $result,
        string $userId,
        ?string $groupId,
        string $okKey = '',
    ) {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $path = '/journey/members/' . rawurlencode($userId);
        if ($groupId !== null && $groupId !== '') {
            $path .= '?group_id=' . rawurlencode($groupId);
        }

        if (! $result->ok) {
            return redirect()->to($path)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($path)->with(
            'success',
            (string) lang('Journey.admin.member.' . ($okKey !== '' ? $okKey : 'savedFlash')),
        );
    }
}
