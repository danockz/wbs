<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use WBS\Groups\Config\Services as GroupServices;
use WBS\Groups\Services\GroupService;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Group hierarchy endpoints (SRS FR-GRP-*). Canonical HTML/JSON.
 */
final class GroupController extends BaseController
{
    /**
     * GET groups — the hierarchy browser: a pre-order tree of the org's groups
     * (indented by depth) with links to create/show/edit/move and, for empty
     * leaves, a delete control. Browsers get the bespoke tree view; API clients
     * get the flat, path-ordered roster.
     */
    public function index()
    {
        $orgId = $this->orgId();
        $by    = $this->field('by') === 'location' ? 'location' : 'hierarchy';

        if ($by === 'location') {
            $rows     = GroupServices::groups()->listForOrgWithGeo($orgId, 2000);
            $sections = GroupServices::groupPublic()->nestByGeo($rows);

            return $this->respondWith(
                Result::ok(['sections' => $sections, 'count' => count($rows)]),
                htmlView: 'WBS\Groups\Views\index',
                viewData: [
                    'result' => ['sections' => $sections],
                    'by'     => 'location',
                    'title'  => 'Groups',
                    'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
                ],
            );
        }

        $groups = GroupServices::groups()->listForOrg($orgId, 2000);

        return $this->respondWith(
            Result::ok(['groups' => $groups, 'count' => count($groups)]),
            htmlView: 'WBS\Groups\Views\index',
            viewData: [
                'result' => ['groups' => $groups],
                'by'     => 'hierarchy',
                'title'  => 'Groups',
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET groups/create — render the bespoke "create group" form (issues CSRF). */
    public function createForm()
    {
        return $this->renderForm('WBS\Groups\Views\create', [
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
        ]);
    }

    /**
     * GET groups/{id}/edit — bespoke "edit core fields" form (name/slug/type/
     * kind). Governance-gated like other group changes. 404s (via the notFound
     * Result) when the group is absent or belongs to another org.
     */
    public function editForm(string $groupId = '')
    {
        $group = GroupServices::groups()->get($this->orgId(), $groupId);
        if ($group === null) {
            return $this->respondWith(Result::notFound('group.not_found', 'GROUP_NOT_FOUND'));
        }

        return $this->renderForm('WBS\Groups\Views\edit', [
            'group'    => $group,
            'group_id' => $groupId,
            'kinds'    => GroupServices::groupKinds()->list($this->orgId(), true),
        ]);
    }

    /**
     * POST groups/{id}/edit — apply a core-field edit. PRG for browsers (back to
     * the group's detail page with a success flash, or re-render with $error);
     * JSON for API clients.
     */
    public function update(string $groupId = '')
    {
        $in     = $this->input();
        $result = GroupServices::groups()->update($this->orgId(), $groupId, [
            'name'      => $in['name'] ?? null,
            'slug'      => $in['slug'] ?? null,
            'type'      => $in['type'] ?? null,
            'kind_code' => $in['kind_code'] ?? null,
        ]);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/groups/' . rawurlencode($groupId))->with('success', lang('Groups.editCore.savedFlash'));
            }

            $group = GroupServices::groups()->get($this->orgId(), $groupId);
            if ($group === null) {
                return $this->respondWith(Result::notFound('group.not_found', 'GROUP_NOT_FOUND'));
            }

            return $this->renderForm('WBS\Groups\Views\edit', [
                'group'    => $group,
                'group_id' => $groupId,
                'kinds'    => GroupServices::groupKinds()->list($this->orgId(), true),
                'error'    => (string) $result->message,
                'old'      => $in,
            ]);
        }

        return $this->respondWith($result);
    }

    /**
     * POST groups/{id}/delete — hard-delete an EMPTY LEAF group (guarded in the
     * service: no children, members or cross-cut links). Populated/parent nodes
     * must use the lifecycle (archive/dissolve/merge). PRG for browsers: success
     * → the hierarchy browser; blocked/failed → back to the group with $error.
     */
    public function delete(string $groupId = '')
    {
        $result = GroupServices::groups()->deleteHard($this->orgId(), $groupId);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/groups')->with('success', lang('Groups.editCore.deletedFlash'));
            }

            return redirect()->to('/groups/' . rawurlencode($groupId))->with('error', $this->errText((string) $result->message));
        }

        return $this->respondWith($result);
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        $result = GroupServices::groups()->create(
            $orgId,
            $in['parent_id'] ?? null,
            [
                'name'      => $in['name'] ?? null,
                'slug'      => $in['slug'] ?? null,
                'type'      => $in['type'] ?? null,
                'kind_code' => $in['kind_code'] ?? null,
            ],
        );

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/groups/' . (string) ($result->data['id'] ?? $result->data['group_id'] ?? ''));
            }

            return $this->renderForm('WBS\Groups\Views\create', [
                'error'  => (string) $result->message,
                'old'    => $in,
                'groups' => GroupServices::groups()->listForOrg($this->orgId()),
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST groups/{id}/kind — set or clear a group's classification (kind). */
    public function setKind(string $groupId = '')
    {
        $result = GroupServices::groups()->setKind(
            $this->orgId(),
            $groupId,
            $this->field('kind_code'),
        );

        return $this->respondGroupWrite($result, $groupId, 'kindSetFlash');
    }

    public function show(string $groupId = '')
    {
        $svc  = GroupServices::groups();
        $data = [
            'group_id'    => $groupId,
            'ancestors'   => $svc->ancestors($groupId),
            'descendants' => $svc->descendants($groupId),
        ];

        // The detail page doubles as a small governance console (set kind /
        // edit public profile); supply the kind taxonomy + a csrf token for the
        // inline PRG forms. Kinds are best-effort (empty list on any hiccup).
        $kinds = GroupServices::groupKinds()->list($this->orgId(), true);

        return $this->respondWith(
            Result::ok($data),
            htmlView: 'WBS\Groups\Views\detail',
            viewData: [
                'result'  => $data,
                'title'   => 'Group',
                'groupId' => $groupId,
                'kinds'   => $kinds,
                'themes'  => GroupService::PUBLIC_THEMES,
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function move(string $groupId = '')
    {
        return $this->respondWith(GroupServices::groups()->move($groupId, $this->field('new_parent_id')));
    }

    /**
     * GET groups/move — bespoke "move / restructure" launcher (menu landing page).
     * Lists the org's groups so the operator can pick both the group to move and
     * its new parent; `renderForm()` mints the CSRF token the webcsrf-guarded POST
     * needs.
     */
    public function moveForm()
    {
        return $this->renderForm('WBS\Groups\Views\move', [
            'groups' => GroupServices::groups()->listForOrg($this->orgId()),
        ]);
    }

    /**
     * POST groups/move — browser dispatcher for a subtree move. Reads the chosen
     * group + new parent from the body and delegates to the same GroupService::move
     * path the id-scoped route uses (cycle/self-parent/max-depth guards run there).
     * Post/Redirect/Get: success → the moved group; failure → re-render with $error.
     */
    public function moveDispatch()
    {
        $in       = $this->input();
        $groupId  = (string) ($in['group_id'] ?? '');
        $newParent = isset($in['new_parent_id']) && $in['new_parent_id'] !== ''
            ? (string) $in['new_parent_id'] : null;

        $result = GroupServices::groups()->move($groupId, $newParent);

        if (! $this->wantsJson()) {
            if ($result->ok && $groupId !== '') {
                return redirect()->to('/groups/' . $groupId)->with('message', 'groups.moved');
            }

            return $this->renderForm('WBS\Groups\Views\move', [
                'groups' => GroupServices::groups()->listForOrg($this->orgId()),
                'error'  => (string) $result->message,
                'old'    => $in,
            ]);
        }

        return $this->respondWith($result);
    }

    public function addMember(string $groupId = '')
    {
        $in = $this->input();

        return $this->respondWith(GroupServices::groups()->addMember(
            $groupId,
            (string) ($in['user_id'] ?? ''),
            (string) ($in['role'] ?? 'member'),
        ));
    }

    /**
     * Update a group's PUBLIC landing-page profile — theme, presentational and
     * contact fields, and join settings (SRS FR-GRP public pages). Governance
     * action: gated by the same authorize filter as other group changes.
     */
    public function updateProfile(string $groupId = '')
    {
        $result = GroupServices::groups()->updatePublicProfile(
            $this->orgId(),
            $groupId,
            $this->input(),
        );

        return $this->respondGroupWrite($result, $groupId, 'profileSavedFlash');
    }

    /**
     * PRG for a browser group write on the detail console (set kind / edit
     * profile): redirect back to the group's detail page with a localized success
     * flash, or the failing Result's message; API clients keep the JSON Result.
     */
    private function respondGroupWrite(Result $result, string $groupId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $groupId !== '' ? '/groups/' . rawurlencode($groupId) : '/groups';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Groups.detail.' . $okKey));
    }
}
