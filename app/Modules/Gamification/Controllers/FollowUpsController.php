<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Gamification\Controllers\Concerns\ResolvesTargetGroup;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Follow-ups surface of the gamification module (SRS FR-GAM-*): the configurable
 * follow-up TYPES and METHODS catalogs plus the follow-up records themselves
 * (which earn points as a Build/Send activity).
 *
 * Split out of the former GamificationController god-class; behaviour unchanged.
 */
final class FollowUpsController extends BaseController
{
    use ResolvesTargetGroup;

    // --- Types (group-scoped catalog) ----------------------------------------

    public function listFollowUpTypes()
    {
        $types = GamificationServices::followUps()->listTypes(
            $this->orgId(),
            (bool) $this->field('active_only', false),
            $this->targetGroupId(),
        );
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($types), 'Follow-up types');
        }

        return $this->respondWith(Result::ok($types), 'WBS\Gamification\Views\followup_types', null, [
            'types' => $types,
            'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function defineFollowUpType()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/follow-up-types', 'followupTypes');
        }

        return $this->respondConfigDecision(
            GamificationServices::followUps()->defineType($this->orgId(), $this->input()),
            '/gamification/follow-up-types',
            'followupTypes',
        );
    }

    public function showFollowUpType(string $code = '')
    {
        $result = GamificationServices::followUps()->showType($this->orgId(), $code, $this->field('group_id'));
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Follow-up type', $code);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\followup_type_show', null, ['type' => $result->ok ? $result->data : null]);
    }

    public function updateFollowUpType(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::followUps()->updateType(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableFollowUpType(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/follow-up-types', 'followupTypes');
        }

        return $this->respondConfigDecision(
            GamificationServices::followUps()->disableType($this->orgId(), $code, $this->field('group_id')),
            '/gamification/follow-up-types',
            'followupTypes',
            'disabledFlash',
        );
    }

    // --- Methods (org catalog) -----------------------------------------------

    public function listFollowUpMethods()
    {
        $methods = GamificationServices::followUps()->listMethods($this->orgId(), (bool) $this->field('active_only', false));
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($methods), 'Follow-up methods');
        }

        return $this->respondWith(Result::ok($methods), 'WBS\Gamification\Views\followup_methods', null, [
            'methods' => $methods,
            'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function defineFollowUpMethod()
    {
        return $this->respondConfigDecision(
            GamificationServices::followUps()->defineMethod($this->orgId(), $this->input()),
            '/gamification/follow-up-methods',
            'followupMethods',
        );
    }

    public function showFollowUpMethod(string $code = '')
    {
        $result = GamificationServices::followUps()->showMethod($this->orgId(), $code);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Follow-up method', $code);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\followup_method_show', null, ['method' => $result->ok ? $result->data : null]);
    }

    public function updateFollowUpMethod(string $code = '')
    {
        return $this->respondWith(GamificationServices::followUps()->updateMethod(
            $this->orgId(),
            $code,
            $this->input(),
        ));
    }

    public function disableFollowUpMethod(string $code = '')
    {
        return $this->respondConfigDecision(
            GamificationServices::followUps()->disableMethod($this->orgId(), $code),
            '/gamification/follow-up-methods',
            'followupMethods',
            'disabledFlash',
        );
    }

    /**
     * Post/Redirect/Get for the in-page follow-up catalogs (types, methods): a
     * browser define/disable redirects back to the list with a localized flash;
     * API clients keep the JSON Result. defineType()/defineMethod() upsert, so a
     * successful save picks created vs updated from the result's `updated` marker.
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

    // --- Records --------------------------------------------------------------

    /**
     * GET gamification/follow-ups/new — the bespoke "record a follow-up" capture
     * form (browser). API clients get a small JSON descriptor of the vocabularies.
     *
     * The entity-reference fields are rendered as pickers: type_code + method_code
     * from the active catalogs, and subject_user_id from the FOLLOWER'S OWN SCOPE
     * (their group(s) first, then descendants) — never a global roster.
     */
    public function recordFollowUpForm()
    {
        $orgId    = $this->orgId();
        $follower = (string) ($this->actorId() ?? '');
        $groupId  = $this->targetGroupId();

        $types    = GamificationServices::followUps()->listTypes($orgId, true, $groupId);
        $methods  = GamificationServices::followUps()->listMethods($orgId, true);
        $subjects = $this->subjectsInScope($orgId, $follower);

        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok([
                'types'    => $types,
                'methods'  => $methods,
                'subjects' => $subjects,
                'statuses' => GamificationServices::followUps()->recordStatuses(),
                'health'   => GamificationServices::followUps()->healthLevels(),
            ]), 'Record follow-up');
        }

        return $this->renderForm('WBS\Gamification\Views\followup_record', [
            'types'    => $types,
            'methods'  => $methods,
            'subjects' => $subjects,
            'statuses' => GamificationServices::followUps()->recordStatuses(),
            'health'   => GamificationServices::followUps()->healthLevels(),
            'old'      => [],
            'error'    => '',
        ]);
    }

    public function recordFollowUp()
    {
        $orgId    = $this->orgId();
        $follower = (string) ($this->actorId() ?? $this->field('follower_user_id', ''));
        $result   = GamificationServices::followUps()->record($orgId, $follower, $this->input());

        // Browser: PRG to the new record on success; re-render the capture form
        // with the error + sticky values on failure. API clients: JSON.
        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/gamification/follow-ups/' . (string) ($result->data['id'] ?? ''))
                    ->with('success', (string) lang('Gamification.admin.followupRecord.createdFlash'));
            }

            $groupId = $this->targetGroupId();

            return $this->renderForm('WBS\Gamification\Views\followup_record', [
                'types'    => GamificationServices::followUps()->listTypes($orgId, true, $groupId),
                'methods'  => GamificationServices::followUps()->listMethods($orgId, true),
                'subjects' => $this->subjectsInScope($orgId, $follower),
                'statuses' => GamificationServices::followUps()->recordStatuses(),
                'health'   => GamificationServices::followUps()->healthLevels(),
                'old'      => $this->input(),
                'error'    => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /**
     * Members a follower may record a follow-up against: their OWN groups first,
     * then those groups' descendants (bounded to the follower's scope). Resolved
     * in a single batched roster query (no N+1) — resource-light. Empty follower
     * (no session actor) yields no candidates, so the view falls back to a bounded
     * free-text input.
     *
     * @return list<array<string,mixed>>
     */
    private function subjectsInScope(string $orgId, string $followerUserId): array
    {
        if ($followerUserId === '') {
            return [];
        }

        $memberships = \WBS\Groups\Config\Services::memberships()
            ->listForUser($orgId, $followerUserId, 'active');

        $scope = \WBS\Shared\Config\Services::groupScope();
        $groupIds = [];
        foreach ($memberships as $m) {
            $gid = (string) ($m['group_id'] ?? '');
            if ($gid === '') {
                continue;
            }
            $groupIds[$gid] = true;
            foreach ($scope->descendants($gid) as $d) {
                $groupIds[$d] = true;
            }
        }

        if ($groupIds === []) {
            return [];
        }

        return \WBS\Groups\Config\Services::memberships()
            ->listDistinctForGroups($orgId, array_keys($groupIds), 'active');
    }

    public function dueFollowUps()
    {
        // Optional journey-aware triage filters: narrow the queue to a single
        // journey stage and/or WBS phase so a leader can work, say, only the
        // "new believers" who are due. The stage picker options come from the
        // effective stage ladder for the (optional) group context.
        $groupId = $this->field('group_id');
        $groupId = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        $opts = [];
        $stageCode = $this->field('stage_code');
        if ($stageCode !== null && $stageCode !== '') {
            $opts['stage_code'] = (string) $stageCode;
        }
        $phase = $this->field('phase');
        if ($phase !== null && $phase !== '') {
            $opts['phase'] = (string) $phase;
        }

        $ladder = \WBS\Journey\Config\Services::journey()->ladder($this->orgId(), $groupId);
        $stageOptions = [];
        foreach ($ladder as $s) {
            $stageOptions[] = ['value' => (string) ($s['code'] ?? ''), 'label' => (string) ($s['name'] ?? ($s['code'] ?? ''))];
        }
        $phaseOptions = [
            ['value' => 'win', 'labelKey' => 'Pages.common.phaseWin'],
            ['value' => 'build', 'labelKey' => 'Pages.common.phaseBuild'],
            ['value' => 'send', 'labelKey' => 'Pages.common.phaseSend'],
        ];

        return $this->respondPage(
            Result::ok(
                GamificationServices::followUps()->dueFollowUps(
                    $this->orgId(),
                    $this->field('follower_user_id'),
                    $this->field('cutoff'),
                    (int) $this->field('limit', 100),
                    $opts,
                ),
            ),
            'gam_followups_due',
            static fn (array $d): array => [
                'rows'    => array_is_list($d) ? $d : ($d['follow_ups'] ?? $d['due'] ?? []),
                'filters' => [
                    ['name' => 'stage_code', 'labelKey' => 'Pages.common.colStage', 'anyLabelKey' => 'Pages.common.anyStage', 'options' => $stageOptions],
                    ['name' => 'phase', 'labelKey' => 'Pages.common.colPhase', 'anyLabelKey' => 'Pages.common.anyPhase', 'options' => $phaseOptions],
                ],
                'filterValues' => [
                    'stage_code' => (string) ($opts['stage_code'] ?? ''),
                    'phase'      => (string) ($opts['phase'] ?? ''),
                ],
                'filterHidden' => $groupId !== null ? ['group_id' => $groupId] : [],
            ],
        );
    }

    public function subjectFollowUps(string $subjectId = '')
    {
        return $this->respondPage(
            Result::ok(
                GamificationServices::followUps()->historyForSubject($this->orgId(), $subjectId, (int) $this->field('limit', 50)),
            ),
            'gam_subject_followups',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['history'] ?? $d['follow_ups'] ?? [])],
        );
    }

    public function showFollowUp(string $id = '')
    {
        $result = GamificationServices::followUps()->showRecord($this->orgId(), $id);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Follow-up', $id);
        }

        // The detail page carries the inline edit + cancel write controls, so it
        // needs the status/health vocabularies for the edit form's pickers. A CSRF
        // token is issued (like renderForm) so the guarded write routes accept the
        // POST/PATCH. type_code/method_code/subject/follower are immutable on edit
        // (re-record instead), so no entity-ref pickers are needed here.
        $csrf = \WBS\Identity\Config\Services::webAuth()->issueCsrf();

        return $this->respondWith($result, 'WBS\Gamification\Views\followup_show', null, [
            'followup' => $result->ok ? $result->data : null,
            'canceled' => $result->ok && (($result->data['status'] ?? '') === 'cancelled'),
            'statuses' => GamificationServices::followUps()->recordStatuses(),
            'health'   => GamificationServices::followUps()->healthLevels(),
            'csrf'     => $csrf,
        ]);
    }

    /**
     * Authorize a write against ONE follow-up record. Follow-ups are member
     * self-service (the follower records and edits their own, earning points),
     * so the gate is ownership-OR-leader-scope — NOT a coarse manage cap that
     * would lock members out of their own records:
     *   - the authenticated actor IS the record's follower_user_id, OR
     *   - the actor holds gamification.manage over the record's group scope.
     * Without this the update/cancel routes are an IDOR: any authenticated
     * caller could mutate any record by id (the service loads by org+id only).
     * Returns a denial Result to short-circuit, or null when allowed.
     */
    private function guardFollowUpWrite(string $id): ?Result
    {
        $record = GamificationServices::followUps()->showRecord($this->orgId(), $id);
        if (! $record->ok) {
            return $record; // not-found (or other) passes straight through.
        }

        $actor    = (string) ($this->actorId() ?? '');
        $follower = (string) ($record->data['follower_user_id'] ?? '');
        if ($actor !== '' && $actor === $follower) {
            return null; // owner edits their own follow-up.
        }

        $groupId = isset($record->data['group_id']) && $record->data['group_id'] !== ''
            ? (string) $record->data['group_id']
            : null;
        if ($this->canManageGroupScope('gamification.manage', $groupId)) {
            return null; // a leader with scope over the record's group.
        }

        return Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
    }

    public function updateFollowUp(string $id = '')
    {
        if ($deny = $this->guardFollowUpWrite($id)) {
            if (! $this->wantsJson()) {
                return redirect()->to('/gamification/follow-ups/' . $id)
                    ->with('error', $this->errText((string) lang('Gamification.admin.followupRecord.forbiddenFlash')));
            }

            return $this->respondWith($deny);
        }

        $result = GamificationServices::followUps()->updateRecord($this->orgId(), $id, $this->input());

        // Browser: PRG back to the record with a flash; API: JSON.
        if (! $this->wantsJson()) {
            $to = '/gamification/follow-ups/' . $id;

            return $result->ok
                ? redirect()->to($to)->with('success', (string) lang('Gamification.admin.followupRecord.updatedFlash'))
                : redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return $this->respondWith($result);
    }

    public function cancelFollowUp(string $id = '')
    {
        if ($deny = $this->guardFollowUpWrite($id)) {
            if (! $this->wantsJson()) {
                return redirect()->to('/gamification/follow-ups/' . $id)
                    ->with('error', $this->errText((string) lang('Gamification.admin.followupRecord.forbiddenFlash')));
            }

            return $this->respondWith($deny);
        }

        $result = GamificationServices::followUps()->cancelRecord($this->orgId(), $id);

        if (! $this->wantsJson()) {
            $to = '/gamification/follow-ups/' . $id;

            return $result->ok
                ? redirect()->to($to)->with('success', (string) lang('Gamification.admin.followupRecord.canceledFlash'))
                : redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return $this->respondWith($result);
    }
}
