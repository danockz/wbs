<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\AccessControl\Services\RuleService;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Administrative CRUD for RuBAC rules — the general, leader-authored rule engine
 * (leadership-responsibility model).
 *
 * Routes are gated `authorize:access.rule.manage,any` (coarse capability gate):
 * unlike org-wide ABAC policies, a rule is authored WITHIN A LEADER'S OWN SCOPE,
 * so any leader who holds the management permission in *some* scope passes the
 * route gate, and the service then enforces the authoritative containment check
 * ({@see \WBS\AccessControl\Services\RuleService}) — a leader can never author a
 * rule broader than their own authority. Every condition tree is validated on
 * write through the PDP's own evaluator.
 */
final class RuleController extends BaseController
{
    /** GET rules[?facet=access] — list rules in evaluation order. */
    public function index()
    {
        $facet = $this->field('facet');
        $facet = is_string($facet) && $facet !== '' ? $facet : null;

        $rules = AccessControlServices::rules()->list($this->orgId(), $facet);

        // API clients get JSON; browsers get the bespoke rule-catalogue view
        // (no raw JSON, and richer than the generic admin console).
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($rules),
                lang('AccessControl.rules'),
                $facet !== null ? str_replace('{0}', $facet, lang('AccessControl.facetSub')) : '',
            );
        }

        return $this->respondWith(
            Result::ok($rules),
            'WBS\AccessControl\Views\rules',
            null,
            [
                'rules' => $rules,
                'facet' => $facet,
                // The double-submit token the global webcsrfissue filter minted for
                // this request (same value it will set in the wbs_csrf cookie), so
                // the inline enable/disable + delete forms satisfy the webcsrf check.
                'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET rules/{id} — read one rule (with its hand-picked group set). */
    public function show(string $ruleId = '')
    {
        $result = AccessControlServices::rules()->show($this->orgId(), $ruleId);

        // API clients get JSON; browsers get the bespoke rule-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.rule'), $ruleId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\rule_show',
            null,
            ['rule' => $result->data],
        );
    }

    /**
     * GET rules/new — the CREATE form (browser). API clients get a small JSON
     * descriptor of the create endpoint + the allowed vocabularies instead.
     */
    public function createForm()
    {
        return $this->renderForm('WBS\AccessControl\Views\rule_form', [
            'mode'   => 'create',
            'rule'   => [],
            'facets' => RuleService::FACETS,
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
            'facet'  => $this->field('facet'),
        ]);
    }

    /**
     * GET rules/{id}/edit — the EDIT form (browser), prefilled from the rule.
     * A missing rule re-uses the not-found handling of the detail service.
     */
    public function editForm(string $ruleId = '')
    {
        $result = AccessControlServices::rules()->show($this->orgId(), $ruleId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/rules')->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\AccessControl\Views\rule_form', [
            'mode'   => 'edit',
            'rule'   => (array) $result->data,
            'facets' => RuleService::FACETS,
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
        ]);
    }

    /** POST rules — create a rule within the issuer's scope. */
    public function create()
    {
        $result = AccessControlServices::rules()->create(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $this->input(),
        );

        // Browser: post-redirect-get to the new rule on success, re-render the
        // form with the error + submitted values on failure. API clients: JSON.
        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/rules/' . (string) ($result->data['rule_id'] ?? ''))
                    ->with('success', (string) lang('AccessControl.ruleForm.createdFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\rule_form', [
                'mode'   => 'create',
                'rule'   => $this->input(),
                'facets' => RuleService::FACETS,
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
                'error'  => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST rules/{id} — update a rule. */
    public function update(string $ruleId = '')
    {
        $result = AccessControlServices::rules()->update(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $ruleId,
            $this->input(),
        );

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/rules/' . $ruleId)
                    ->with('success', (string) lang('AccessControl.ruleForm.updatedFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\rule_form', [
                'mode'   => 'edit',
                'rule'   => ['id' => $ruleId] + $this->input(),
                'facets' => RuleService::FACETS,
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
                'error'  => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST rules/{id}/enabled — enable/disable a rule. */
    public function setEnabled(string $ruleId = '')
    {
        $result = AccessControlServices::rules()->setEnabled(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $ruleId,
            (bool) $this->field('enabled', true),
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/rules')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.ruleForm.toggledFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** POST rules/{id}/delete — delete a rule. */
    public function delete(string $ruleId = '')
    {
        $result = AccessControlServices::rules()->delete(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $ruleId,
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/rules')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.ruleForm.deletedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
