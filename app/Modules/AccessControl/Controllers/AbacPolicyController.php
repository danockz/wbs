<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Administrative CRUD for ABAC policies (SRS: ABAC in the MAC+RBAC+ABAC PDP).
 *
 * Routes are gated `authorize:access.policy.manage` (EXACT/org-wide, not `,any`):
 * a policy influences authorization org-wide, so only an org-wide grant passes
 * the gate. The service re-checks org-wide coverage authoritatively and validates
 * every condition tree through the PDP's own evaluator on write
 * ({@see \WBS\AccessControl\Services\AbacPolicyService}).
 */
final class AbacPolicyController extends BaseController
{
    /** GET abac-policies — list policies in evaluation order. */
    public function index()
    {
        $policies = AccessControlServices::abacPolicies()->list($this->orgId());

        // API clients get JSON; browsers get the bespoke policy-catalogue view
        // (no raw JSON, and richer than the generic admin console).
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($policies),
                lang('AccessControl.abacPolicies'),
            );
        }

        return $this->respondWith(
            Result::ok($policies),
            'WBS\AccessControl\Views\abac_policies',
            null,
            [
                'policies' => $policies,
                // Token the global webcsrfissue filter minted this request, so the
                // inline enable/disable + delete forms satisfy the webcsrf check.
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET abac-policies/{id} — read one policy. */
    public function show(string $policyId = '')
    {
        $result = AccessControlServices::abacPolicies()->show($this->orgId(), $policyId);

        // API clients get JSON; browsers get the bespoke policy-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.abacPolicy'), $policyId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\abac_policy_show',
            null,
            ['policy' => $result->data],
        );
    }

    /** GET abac-policies/new — the CREATE form (browser). */
    public function createForm()
    {
        return $this->renderForm('WBS\AccessControl\Views\abac_policy_form', [
            'mode'   => 'create',
            'policy' => [],
        ]);
    }

    /** GET abac-policies/{id}/edit — the EDIT form (browser), prefilled. */
    public function editForm(string $policyId = '')
    {
        $result = AccessControlServices::abacPolicies()->show($this->orgId(), $policyId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/abac-policies')->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\AccessControl\Views\abac_policy_form', [
            'mode'   => 'edit',
            'policy' => (array) $result->data,
        ]);
    }

    /** POST abac-policies — create a policy. */
    public function create()
    {
        $result = AccessControlServices::abacPolicies()->create(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $this->input(),
        );

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/abac-policies/' . (string) ($result->data['policy_id'] ?? ''))
                    ->with('success', (string) lang('AccessControl.abacForm.createdFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\abac_policy_form', [
                'mode'   => 'create',
                'policy' => $this->input(),
                'error'  => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST abac-policies/{id} — update a policy. */
    public function update(string $policyId = '')
    {
        $result = AccessControlServices::abacPolicies()->update(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $policyId,
            $this->input(),
        );

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/abac-policies/' . $policyId)
                    ->with('success', (string) lang('AccessControl.abacForm.updatedFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\abac_policy_form', [
                'mode'   => 'edit',
                'policy' => ['id' => $policyId] + $this->input(),
                'error'  => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST abac-policies/{id}/enabled — enable/disable a policy. */
    public function setEnabled(string $policyId = '')
    {
        $result = AccessControlServices::abacPolicies()->setEnabled(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $policyId,
            (bool) $this->field('enabled', true),
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/abac-policies')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.abacForm.toggledFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** POST abac-policies/{id}/delete — hard-delete a policy. */
    public function delete(string $policyId = '')
    {
        $result = AccessControlServices::abacPolicies()->delete(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $policyId,
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/abac-policies')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.abacForm.deletedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
