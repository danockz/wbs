<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Group-aware management of role assignments (SRS FR-ACL-003).
 *
 * The route filter uses `authorize:access.assignment.manage,any` — a coarse
 * capability gate that admits any holder of the management permission (in any
 * scope), because the assignment's target group is only known from the request
 * body. The AUTHORITATIVE per-group check (delegated administration: an issuer
 * may only manage within a branch their own grant covers) is enforced inside
 * {@see \WBS\AccessControl\Services\RoleAssignmentService}. This mirrors the
 * StreamService/Gamification pattern already used across the platform.
 */
final class RoleAssignmentController extends BaseController
{
    /** POST assignments — grant/refresh a role assignment within a scope. */
    public function assign()
    {
        $issuer = $this->currentUserId('issued_by');

        return $this->respondWith(AccessControlServices::roleAssignments()->assign(
            $this->orgId(),
            $issuer,
            $this->input(),
        ));
    }

    /** POST assignments/{id}/revoke — soft-revoke an assignment. */
    public function revoke(string $assignmentId = '')
    {
        $issuer = $this->currentUserId('issued_by');

        return $this->respondWith(AccessControlServices::roleAssignments()->revoke(
            $this->orgId(),
            $issuer,
            $assignmentId,
            (string) $this->field('reason', ''),
        ));
    }

    /** GET subjects/{id}/assignments — a subject's assignments (management view). */
    public function forSubject(string $subjectId = '')
    {
        $rows = AccessControlServices::roleAssignments()->listForSubject($this->orgId(), $subjectId);

        // API clients get JSON; browsers get the bespoke assignments view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($rows),
                lang('AccessControl.roleAssignments'),
                str_replace('{0}', $subjectId, lang('AccessControl.subjectSub')),
            );
        }

        return $this->respondWith(
            Result::ok($rows),
            'WBS\AccessControl\Views\role_assignments',
            null,
            ['assignments' => $rows, 'subjectId' => $subjectId],
        );
    }

    /** GET assignments/{id} — read one assignment. */
    public function show(string $assignmentId = '')
    {
        $result = AccessControlServices::roleAssignments()->show($this->orgId(), $assignmentId);

        // API clients get JSON; browsers get the bespoke assignment-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.roleAssignment'), $assignmentId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\role_assignment_show',
            null,
            ['assignment' => $result->data],
        );
    }
}
