<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Groups\Services\GroupLifecycleService;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Group lifecycle endpoints — archive / reactivate / dissolve / merge with
 * evidence (SRS FR-GRP-005). Governance actions: each is authorised per-group
 * against the target group's own scope (a leader may only act within their own
 * subtree), on top of the coarse `group.change.approve` route gate. Every write
 * requires a stated reason and accepts optional `approval_ref` + `evidence`.
 *
 * The history page is now a bespoke GOVERNANCE CONSOLE (not raw JSON / the
 * generic admin page): the transition timeline plus the controls allowed from the
 * group's CURRENT state — archive/dissolve/merge from active, reactivate/dissolve/
 * merge from archived, nothing from a terminal state. Each control POSTs a stated
 * reason (+ optional approval reference; a survivor group on merge) to a webcsrf-
 * guarded route and PRG-redirects back to the console with a localized flash. The
 * actor identity comes from the authenticated session. API clients keep the
 * identical JSON payloads.
 */
final class GroupLifecycleController extends BaseController
{
    public function archive(string $groupId = '')
    {
        if ($denied = $this->authorizeGroupScope('group.change.approve', $groupId)) {
            return $this->respondLifecycle($denied, $groupId, 'archivedFlash');
        }

        return $this->respondLifecycle(GroupServices::groupLifecycle()->archive(
            $this->orgId(),
            $groupId,
            (string) $this->field('reason', ''),
            $this->transitionOpts(),
        ), $groupId, 'archivedFlash');
    }

    public function reactivate(string $groupId = '')
    {
        if ($denied = $this->authorizeGroupScope('group.change.approve', $groupId)) {
            return $this->respondLifecycle($denied, $groupId, 'reactivatedFlash');
        }

        return $this->respondLifecycle(GroupServices::groupLifecycle()->reactivate(
            $this->orgId(),
            $groupId,
            (string) $this->field('reason', ''),
            $this->transitionOpts(),
        ), $groupId, 'reactivatedFlash');
    }

    public function dissolve(string $groupId = '')
    {
        if ($denied = $this->authorizeGroupScope('group.change.approve', $groupId)) {
            return $this->respondLifecycle($denied, $groupId, 'dissolvedFlash');
        }

        return $this->respondLifecycle(GroupServices::groupLifecycle()->dissolve(
            $this->orgId(),
            $groupId,
            (string) $this->field('reason', ''),
            $this->transitionOpts(),
        ), $groupId, 'dissolvedFlash');
    }

    public function merge(string $groupId = '')
    {
        $survivorId = (string) $this->field('survivor_id', $this->field('merged_into_id', ''));

        // The actor must be authorised over BOTH the merged group and the
        // survivor (a merge affects both subtrees).
        if ($denied = $this->authorizeGroupScope('group.change.approve', $groupId)) {
            return $this->respondLifecycle($denied, $groupId, 'mergedFlash');
        }
        if ($survivorId !== '' && ($denied = $this->authorizeGroupScope('group.change.approve', $survivorId))) {
            return $this->respondLifecycle($denied, $groupId, 'mergedFlash');
        }

        return $this->respondLifecycle(GroupServices::groupLifecycle()->merge(
            $this->orgId(),
            $groupId,
            $survivorId,
            (string) $this->field('reason', ''),
            $this->transitionOpts(),
        ), $groupId, 'mergedFlash');
    }

    public function history(string $groupId = '')
    {
        $lifecycle   = GroupServices::groupLifecycle();
        $transitions = $lifecycle->history($this->orgId(), $groupId);
        $current     = $lifecycle->current($this->orgId(), $groupId);
        $status      = $current['status'] ?? 'active';

        // The states reachable from here drive which controls the console shows.
        $allowed = [];
        foreach (GroupLifecycleService::STATES as $to) {
            if (GroupLifecycleService::canTransition($status, $to)) {
                $allowed[] = $to;
            }
        }

        return $this->respondWith(
            Result::ok(['group_id' => $groupId, 'transitions' => $transitions]),
            htmlView: 'WBS\Groups\Views\lifecycle_history',
            viewData: [
                'transitions' => $transitions,
                'groupId'     => $groupId,
                'status'      => $status,
                'groupName'   => $current['name'] ?? '',
                'allowed'     => $allowed,
                // survivor_id (merge form) is an entity reference → offer the org
                // groups as a picker (the view excludes the group being merged away).
                'groups'      => GroupServices::groups()->listForOrg($this->orgId()),
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** @return array<string,mixed> actor_id + optional approval_ref/evidence */
    private function transitionOpts(): array
    {
        $in   = $this->input();
        $opts = ['actor_id' => $this->actorId()];
        if (isset($in['approval_ref']) && $in['approval_ref'] !== '') {
            $opts['approval_ref'] = (string) $in['approval_ref'];
        }
        if (isset($in['evidence']) && is_array($in['evidence'])) {
            $opts['evidence'] = $in['evidence'];
        }

        return $opts;
    }

    /**
     * Post/Redirect/Get for a browser lifecycle write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the group's lifecycle console
     * with a localized success flash, or the failing Result's message as an error
     * flash.
     */
    private function respondLifecycle(Result $result, string $groupId, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $groupId !== '' ? '/groups/' . rawurlencode($groupId) . '/lifecycle' : '/groups';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Groups.lifecycle.' . $okKey));
    }
}
