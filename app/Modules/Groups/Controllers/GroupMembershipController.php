<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Group membership endpoints (SRS FR-GRP-003).
 *
 * Full membership lifecycle: add/request, approve/reject, leave, role/scope
 * change, plus multi-group listing and configurable conflict rules. Mutations
 * are gated in Routes.php by `authorize:group.change.approve` (membership and
 * approval are governance actions). The acting user (actor/approver/added_by)
 * always comes from the authenticated session, never from client input.
 */
final class GroupMembershipController extends BaseController
{
    /** Add or request a membership for a group. */
    public function add(string $groupId = '')
    {
        $in = $this->input();

        $result = GroupServices::memberships()->add($this->orgId(), $groupId, [
            'user_id'           => $in['user_id'] ?? '',
            'membership_type'   => $in['membership_type'] ?? 'member',
            'role'              => $in['role'] ?? 'member',
            'source'            => $in['source'] ?? 'manual',
            'permission_scope'  => $in['permission_scope'] ?? null,
            'effective_from'    => $in['effective_from'] ?? null,
            'effective_to'      => $in['effective_to'] ?? null,
            'requires_approval' => $in['requires_approval'] ?? false,
            'approval_ref'      => $in['approval_ref'] ?? null,
            'approval_evidence' => $in['approval_evidence'] ?? null,
            'added_by'          => $this->currentUserId('actor_id'),
            'actor_id'          => $this->currentUserId('actor_id'),
        ]);

        return $this->respondRoster($result, $groupId, 'addedFlash');
    }

    /** Memberships of a group (query: status, type). */
    public function listForGroup(string $groupId = '')
    {
        $status = $this->field('status', 'active');
        $type   = $this->field('type');
        $rows   = GroupServices::memberships()->listForGroup($this->orgId(), $groupId, $status, $type);

        return $this->respondWith(
            Result::ok(['group_id' => $groupId, 'memberships' => $rows, 'count' => count($rows)]),
            htmlView: 'WBS\Groups\Views\memberships_manage',
            viewData: [
                'memberships' => $rows,
                'groupId'     => $groupId,
                'status'      => (string) $status,
                'types'       => \WBS\Groups\Services\GroupMembershipService::TYPES,
                // user_id on the add-member form is an entity reference → offer the
                // org roster as a picker (the view excludes current members).
                'roster'      => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Pending membership requests for a group (reviewer queue). */
    public function pending(string $groupId = '')
    {
        $rows = GroupServices::memberships()->pendingForGroup($this->orgId(), $groupId);

        return $this->respondWith(
            Result::ok(['group_id' => $groupId, 'pending' => $rows, 'count' => count($rows)]),
            htmlView: 'WBS\\Groups\\Views\\memberships_pending',
            viewData: [
                'pending' => $rows,
                'groupId' => $groupId,
                // Token the global webcsrfissue filter minted this request, so the
                // inline approve/reject forms satisfy the webcsrf check.
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** All memberships a person holds across groups (query: status). */
    public function listForUser(string $userId = '')
    {
        $rows = GroupServices::memberships()->listForUser(
            $this->orgId(),
            $userId,
            $this->field('status', 'active'),
        );

        return $this->respondPage(
            Result::ok(['user_id' => $userId, 'memberships' => $rows, 'count' => count($rows)]),
            'groups_user_memberships',
            static fn (array $d): array => ['rows' => $d['memberships'] ?? [], 'count' => $d['count'] ?? null],
        );
    }

    /** A single membership with its event trail. */
    public function show(string $membershipId = '')
    {
        $m = GroupServices::memberships()->find($this->orgId(), $membershipId);
        if ($m === null) {
            return $this->respondWith(Result::notFound('group.membership_not_found', 'MEMBERSHIP_NOT_FOUND'));
        }

        return $this->respondPage(
            Result::ok($m),
            'groups_membership',
            static fn (array $d): array => ['record' => $d],
        );
    }

    public function approve(string $membershipId = '')
    {
        // A browser sends a free-text note; the service takes structured evidence.
        $evidence = (array) ($this->field('approval_evidence') ?? []);
        $note     = (string) $this->field('note', '');
        if ($evidence === [] && $note !== '') {
            $evidence = ['note' => $note];
        }

        $result = GroupServices::memberships()->approve(
            $this->orgId(),
            $membershipId,
            $this->currentUserId('actor_id'),
            $evidence,
        );

        return $this->respondMembershipDecision($result, 'Groups.pending.approvedFlash');
    }

    public function reject(string $membershipId = '')
    {
        $result = GroupServices::memberships()->reject(
            $this->orgId(),
            $membershipId,
            $this->currentUserId('actor_id'),
            (string) $this->field('reason', ''),
        );

        return $this->respondMembershipDecision($result, 'Groups.pending.rejectedFlash');
    }

    /**
     * PRG for a browser membership decision addressed by membership id (approve/
     * reject/leave/changeRole): redirect back to the console it came from (the
     * posted `return` path, open-redirect guarded) with a success/error flash;
     * API clients keep the JSON Result. Success copy is the passed localized key;
     * failures (bad-state, conflict, SoD) surface the Result message, which the
     * service localizes.
     */
    private function respondMembershipDecision(Result $result, string $flashLangKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to($this->safeReturn())->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang($flashLangKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /**
     * PRG for a browser roster write nested under a group (add): redirect back to
     * the group's roster console with a localized success flash, or the failing
     * Result message; API clients keep the JSON Result.
     */
    private function respondRoster(Result $result, string $groupId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $groupId !== '' ? '/groups/' . rawurlencode($groupId) . '/memberships' : '/groups';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Groups.roster.' . $okKey));
    }

    /**
     * A safe local redirect target from the posted `return` field: it must be a
     * relative path under /groups (single leading slash, no scheme/host), so a
     * crafted form can't turn PRG into an open redirect. Falls back to the groups
     * console.
     */
    private function safeReturn(): string
    {
        $return = (string) $this->field('return', '');
        if ($return !== '' && str_starts_with($return, '/groups') && ! str_starts_with($return, '//')) {
            return $return;
        }

        return '/groups';
    }

    public function leave(string $membershipId = '')
    {
        $result = GroupServices::memberships()->leave(
            $this->orgId(),
            $membershipId,
            $this->currentUserId('actor_id'),
            (string) $this->field('reason', ''),
        );

        return $this->respondMembershipDecision($result, 'Groups.roster.leftFlash');
    }

    public function changeRole(string $membershipId = '')
    {
        $in = $this->input();

        $result = GroupServices::memberships()->changeRole(
            $this->orgId(),
            $membershipId,
            $this->currentUserId('actor_id'),
            [
                'role'             => $in['role'] ?? null,
                'permission_scope' => $in['permission_scope'] ?? null,
            ],
        );

        return $this->respondMembershipDecision($result, 'Groups.roster.roleChangedFlash');
    }

    // --- Conflict rules ------------------------------------------------------

    public function defineConflict()
    {
        $result = GroupServices::memberships()->defineConflict($this->orgId(), $this->input());

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to('/memberships/conflicts')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/memberships/conflicts')->with('success', lang('Groups.conflicts.definedFlash'));
    }

    public function listConflicts()
    {
        $rows = GroupServices::memberships()->listConflicts($this->orgId());

        return $this->respondWith(
            Result::ok(['conflicts' => $rows, 'count' => count($rows)]),
            htmlView: 'WBS\Groups\Views\conflicts_manage',
            viewData: [
                'conflicts' => $rows,
                'types'     => \WBS\Groups\Services\GroupMembershipService::TYPES,
                'scopes'    => ['global', 'same_group', 'same_branch'],
                'csrf'      => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }
}
