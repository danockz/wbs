<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Administrative CRUD for the RBAC role catalogue and its permission grants
 * (SRS FR-ACL-002/003).
 *
 * Routes are gated `authorize:access.role.manage` (EXACT/org-wide, not `,any`):
 * a role definition is org-wide in effect, so only an org-wide grant passes the
 * gate — a merely group-scoped manager is turned away at the filter. The service
 * re-checks org-wide coverage authoritatively and adds the system-role and
 * privilege-escalation guards ({@see \WBS\AccessControl\Services\RoleService}).
 */
final class RoleController extends BaseController
{
    /** GET roles — list roles with their permission codes. */
    public function index()
    {
        $roles = AccessControlServices::roles()->list($this->orgId());

        // API clients get JSON; browsers get the bespoke role-catalogue view
        // (no raw JSON, and richer than the generic admin console).
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok($roles), lang('AccessControl.roles'));
        }

        return $this->respondWith(
            Result::ok($roles),
            'WBS\AccessControl\Views\roles',
            null,
            [
                'roles' => $roles,
                // Token the global webcsrfissue filter minted this request, so the
                // inline delete forms on the catalogue satisfy the webcsrf check.
                'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET roles/{id} — read one role. */
    public function show(string $roleId = '')
    {
        $result = AccessControlServices::roles()->show($this->orgId(), $roleId);

        // API clients get JSON; browsers get the bespoke role-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.role'), $roleId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\role_show',
            null,
            ['role' => $result->data],
        );
    }

    /**
     * GET roles/new — the CREATE form (browser). The form also carries the full
     * permission catalogue so a role's permissions can be set at creation time
     * (a follow-up setPermissions call). API clients get the JSON descriptor.
     */
    public function createForm()
    {
        return $this->renderForm('WBS\AccessControl\Views\role_form', [
            'mode'    => 'create',
            'role'    => [],
            'catalog' => AccessControlServices::roles()->listPermissionCatalog(),
        ]);
    }

    /** GET roles/{id}/edit — the EDIT form (browser), prefilled from the role. */
    public function editForm(string $roleId = '')
    {
        $result = AccessControlServices::roles()->show($this->orgId(), $roleId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/roles')->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\AccessControl\Views\role_form', [
            'mode'    => 'edit',
            'role'    => (array) $result->data,
            'catalog' => AccessControlServices::roles()->listPermissionCatalog(),
        ]);
    }

    /** POST roles — create a role (and set its permissions when supplied). */
    public function create()
    {
        $orgId  = $this->orgId();
        $issuer = $this->currentUserId('issued_by');
        $input  = $this->input();

        $result = AccessControlServices::roles()->create($orgId, $issuer, $input);

        // On success, apply any permissions selected on the create form (the
        // service creates the role first, then binds the grant set).
        if ($result->ok && isset($input['permissions']) && is_array($input['permissions'])) {
            $roleId = (string) ($result->data['role_id'] ?? '');
            if ($roleId !== '') {
                $perm = AccessControlServices::roles()->setPermissions($orgId, $issuer, $roleId, $input['permissions']);
                // Surface a permission-binding failure without losing the created role.
                if (! $perm->ok && ! $this->wantsJson()) {
                    return redirect()->to('/roles/' . $roleId)->with('error', $this->errText((string) $perm->message));
                }
            }
        }

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/roles/' . (string) ($result->data['role_id'] ?? ''))
                    ->with('success', (string) lang('AccessControl.roleForm.createdFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\role_form', [
                'mode'    => 'create',
                'role'    => $input,
                'catalog' => AccessControlServices::roles()->listPermissionCatalog(),
                'error'   => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST roles/{id} — update a role's name/description (+ permissions). */
    public function update(string $roleId = '')
    {
        $orgId  = $this->orgId();
        $issuer = $this->currentUserId('issued_by');
        $input  = $this->input();

        $result = AccessControlServices::roles()->update($orgId, $issuer, $roleId, $input);

        // The edit form submits the permission set alongside name/description.
        if ($result->ok && isset($input['permissions']) && is_array($input['permissions'])) {
            $perm = AccessControlServices::roles()->setPermissions($orgId, $issuer, $roleId, $input['permissions']);
            if (! $perm->ok) {
                if (! $this->wantsJson()) {
                    return $this->renderForm('WBS\AccessControl\Views\role_form', [
                        'mode'    => 'edit',
                        'role'    => ['id' => $roleId] + $input,
                        'catalog' => AccessControlServices::roles()->listPermissionCatalog(),
                        'error'   => (string) $perm->message,
                    ]);
                }

                return $this->respondWith($perm);
            }
        }

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/roles/' . $roleId)
                    ->with('success', (string) lang('AccessControl.roleForm.updatedFlash'));
            }

            return $this->renderForm('WBS\AccessControl\Views\role_form', [
                'mode'    => 'edit',
                'role'    => ['id' => $roleId] + $input,
                'catalog' => AccessControlServices::roles()->listPermissionCatalog(),
                'error'   => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST roles/{id}/permissions — replace the role's permission set. */
    public function setPermissions(string $roleId = '')
    {
        $codes = $this->field('permissions', []);

        $result = AccessControlServices::roles()->setPermissions(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $roleId,
            is_array($codes) ? $codes : [],
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/roles/' . $roleId)->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.roleForm.permsFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** POST roles/{id}/delete — delete a (non-system, unused) role. */
    public function delete(string $roleId = '')
    {
        $result = AccessControlServices::roles()->delete(
            $this->orgId(),
            $this->currentUserId('issued_by'),
            $roleId,
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/roles')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.roleForm.deletedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
