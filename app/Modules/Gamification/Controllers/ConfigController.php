<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Gamification\Controllers\Concerns\ResolvesTargetGroup;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Configuration surface of the gamification module (SRS FR-GAM-*): the
 * versioned/immutable point RULES, the Win-Build-Send activity catalog, and the
 * typed runtime key/value config.
 *
 * Split out of the former GamificationController god-class with behaviour
 * unchanged: rule edits supersede the active version rather than mutating award
 * history, and group-scoped config writes go through the hierarchy-aware PDP.
 */
final class ConfigController extends BaseController
{
    use ResolvesTargetGroup;

    // --- Point rules (versioned/immutable) -----------------------------------

    public function listRules()
    {
        $rules = GamificationServices::rules()->list($this->orgId(), (bool) $this->field('active_only', false));
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($rules), 'Point rules');
        }

        return $this->respondWith(Result::ok($rules), 'WBS\Gamification\Views\rules_admin', null, [
            'rules' => $rules,
            // Token the global webcsrfissue filter minted this request, so the
            // inline disable forms in the list satisfy the webcsrf check.
            'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    /** GET gamification/rules/new — the CREATE form (browser). */
    public function createRuleForm()
    {
        return $this->renderForm('WBS\Gamification\Views\rule_form', [
            'mode' => 'create',
            'rule' => [],
        ]);
    }

    /** GET gamification/rules/{code}/edit — the EDIT form (browser), prefilled. */
    public function editRuleForm(string $code = '')
    {
        $result = GamificationServices::rules()->show($this->orgId(), $code);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/gamification/rules')->with('error', $this->errText((string) $result->message));
        }

        // show() returns ['code','current'=>row,'versions'=>...]; the form edits
        // the current (highest) version, which update() supersedes.
        $data    = (array) $result->data;
        $current = is_array($data['current'] ?? null) ? $data['current'] : $data;
        $current['code'] ??= $code;

        return $this->renderForm('WBS\Gamification\Views\rule_form', [
            'mode' => 'edit',
            'rule' => $current,
        ]);
    }

    public function createRule()
    {
        // A rule may be org-wide (group_id null) or scoped to a group; the
        // AUTHORITATIVE per-group check runs here on the target group from the
        // body, behind the coarse `gamification.manage,any` route gate.
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            if (! $this->wantsJson()) {
                return $this->renderForm('WBS\Gamification\Views\rule_form', [
                    'mode'  => 'create',
                    'rule'  => $this->input(),
                    'error' => (string) $deny->message,
                ]);
            }

            return $this->respondWith($deny);
        }

        $result = GamificationServices::rules()->create($this->orgId(), $this->input());

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/gamification/rules/' . rawurlencode((string) ($result->data['code'] ?? '')))
                    ->with('success', (string) lang('Gamification.admin.rules.form.createdFlash'));
            }

            return $this->renderForm('WBS\Gamification\Views\rule_form', [
                'mode'  => 'create',
                'rule'  => $this->input(),
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function updateRule(string $code = '')
    {
        if ($deny = $this->authorizeRuleScope($code)) {
            if (! $this->wantsJson()) {
                return $this->renderForm('WBS\Gamification\Views\rule_form', [
                    'mode'  => 'edit',
                    'rule'  => ['code' => $code] + $this->input(),
                    'error' => (string) $deny->message,
                ]);
            }

            return $this->respondWith($deny);
        }

        $result = GamificationServices::rules()->update($this->orgId(), $code, $this->input());

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/gamification/rules/' . rawurlencode($code))
                    ->with('success', (string) lang('Gamification.admin.rules.form.updatedFlash'));
            }

            return $this->renderForm('WBS\Gamification\Views\rule_form', [
                'mode'  => 'edit',
                'rule'  => ['code' => $code] + $this->input(),
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function showRule(string $code = '')
    {
        $result = GamificationServices::rules()->show($this->orgId(), $code);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Point rule', $code);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\rule_show', null, ['rule' => $result->ok ? $result->data : null]);
    }

    public function disableRule(string $code = '')
    {
        if ($deny = $this->authorizeRuleScope($code)) {
            if (! $this->wantsJson()) {
                return redirect()->to('/gamification/rules')->with('error', $this->errText((string) $deny->message));
            }

            return $this->respondWith($deny);
        }

        $result = GamificationServices::rules()->disable($this->orgId(), $code);

        if (! $this->wantsJson()) {
            return redirect()->to('/gamification/rules')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Gamification.admin.rules.form.disabledFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /**
     * Authorize a write against an EXISTING rule's owning group. Returns a
     * denied/not-found Result to return as-is, or null when permitted.
     */
    private function authorizeRuleScope(string $code): ?Result
    {
        $scope = GamificationServices::rules()->ruleGroupScope($this->orgId(), $code);
        if (! $scope['exists']) {
            return Result::notFound('gamification.rule_not_found', 'RULE_NOT_FOUND');
        }

        return $this->authorizeGroupScope('gamification.manage', $scope['group_id']);
    }

    // --- Activity catalog (Win/Build/Send grouping of earning activities) ----

    public function activityCatalog()
    {
        $catalog = GamificationServices::activityCatalog()->catalog($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($catalog), 'Activity catalog');
        }

        return $this->respondWith(Result::ok($catalog), 'WBS\Gamification\Views\activity_catalog', null, ['catalog' => $catalog]);
    }

    public function listActivityCategories()
    {
        $categories = GamificationServices::activityCatalog()->listCategories(
            $this->orgId(),
            $this->field('phase'),
            $this->targetGroupId(),
        );
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($categories), 'Activity categories');
        }

        return $this->respondWith(Result::ok($categories), 'WBS\Gamification\Views\activity_categories', null, [
            'categories' => $categories,
            'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showActivityCategory(string $code = '')
    {
        $result = GamificationServices::activityCatalog()->showCategory($this->orgId(), $code, $this->field('group_id'));
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Activity category', $code);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\activity_category_show', null, ['category' => $result->ok ? $result->data : null]);
    }

    public function defineActivityCategory()
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/activity-categories', 'categories');
        }

        return $this->respondConfigDecision(
            GamificationServices::activityCatalog()->define($this->orgId(), $this->input()),
            '/gamification/activity-categories',
            'categories',
        );
    }

    public function updateActivityCategory(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::activityCatalog()->update(
            $this->orgId(),
            $code,
            $this->input(),
            $this->targetGroupId(),
        ));
    }

    public function disableActivityCategory(string $code = '')
    {
        if ($deny = $this->authorizeGroupScope('gamification.manage', $this->targetGroupId())) {
            return $this->respondConfigDecision($deny, '/gamification/activity-categories', 'categories');
        }

        return $this->respondConfigDecision(
            GamificationServices::activityCatalog()->disable($this->orgId(), $code, $this->field('group_id')),
            '/gamification/activity-categories',
            'categories',
            'disabledFlash',
        );
    }

    // --- Runtime configuration (typed key/value) -----------------------------

    public function listConfig()
    {
        $config = GamificationServices::config()->all($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($config), 'Runtime config');
        }

        return $this->respondWith(Result::ok($config), 'WBS\Gamification\Views\config_list', null, [
            'config' => $config,
            'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
        ]);
    }

    public function showConfig(string $key = '')
    {
        $result = GamificationServices::config()->show($this->orgId(), $key);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Config', $key);
        }

        return $this->respondWith($result, 'WBS\Gamification\Views\config_show', null, ['entry' => $result->ok ? $result->data : null]);
    }

    public function deleteConfig(string $key = '')
    {
        return $this->respondConfigDecision(
            GamificationServices::config()->delete($this->orgId(), $key),
            '/gamification/config',
            'config',
            'deletedFlash',
        );
    }

    public function setConfig(string $key = '')
    {
        $in   = $this->input();
        $opts = [
            'type'        => $in['type'] ?? null,
            'description' => $in['description'] ?? null,
            'updated_by'  => $this->actorId(),
        ];
        // Only forward is_editable when the caller explicitly set it, so new
        // keys default to editable (the service treats a present key as intent).
        if (array_key_exists('is_editable', $in)) {
            $opts['is_editable'] = $in['is_editable'];
        }

        return $this->respondConfigDecision(
            GamificationServices::config()->set($this->orgId(), $key, $in['value'] ?? null, $opts),
            '/gamification/config',
            'config',
        );
    }

    /**
     * Post/Redirect/Get for the in-page config catalogs (activity categories,
     * runtime config): a browser define/disable/set redirects back to the list
     * with a localized flash; API clients keep the JSON Result. define()/set()
     * upsert, so a successful save picks created vs updated from the result marker.
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
}
