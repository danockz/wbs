<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Cross-cutting group links (leadership-responsibility model).
 *
 * A cross-cut group (worship team, youth network, choir) is linked to the
 * hierarchy node(s) it spans. Mutations are governance actions gated by
 * `group.change.approve`; reads only need an authenticated session. The access
 * layer consumes these links (opt-in, down-only) to extend a leader's hierarchy
 * scope to the cross-cut groups attached to nodes they cover.
 *
 * Both read pages double as MANAGEMENT CONSOLES: the node page (forNode) carries
 * a link form + per-row unlink; the cross-cut page (forCrosscut) carries per-row
 * unlink. Browser writes PRG-redirect back to the originating console with a
 * localized flash; API clients keep the identical JSON Result. Each write posts
 * to a webcsrf-guarded route and its actor is the authenticated session user.
 */
final class GroupCrosscutController extends BaseController
{
    /** GET groups/{id}/crosscuts — cross-cut groups attached to a hierarchy node. */
    public function forNode(string $hierarchyGroupId = '')
    {
        $links = GroupServices::crosscut()->crosscutsForNode($this->orgId(), $hierarchyGroupId);

        return $this->respondWith(
            Result::ok($links),
            htmlView: 'WBS\Groups\Views\crosscut_for_node',
            viewData: [
                'links'  => $links,
                'nodeId' => $hierarchyGroupId,
                // crosscut_group_id is an entity reference → offer the org groups as
                // a picker (the view excludes the node itself + already-linked ids).
                'groups' => GroupServices::groups()->listForOrg($this->orgId()),
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET groups/{id}/crosscut-nodes — hierarchy nodes a cross-cut group spans. */
    public function forCrosscut(string $crosscutGroupId = '')
    {
        $nodes = GroupServices::crosscut()->nodesForCrosscut($this->orgId(), $crosscutGroupId);

        return $this->respondWith(
            Result::ok($nodes),
            htmlView: 'WBS\Groups\Views\crosscut_nodes',
            viewData: [
                'nodes'      => $nodes,
                'crosscutId' => $crosscutGroupId,
                'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** POST groups/{id}/crosscuts — link a cross-cut group to this hierarchy node. */
    public function link(string $hierarchyGroupId = '')
    {
        $result = GroupServices::crosscut()->link(
            $this->orgId(),
            (string) $this->field('crosscut_group_id', ''),
            $hierarchyGroupId,
            $this->currentUserId('issued_by'),
        );

        return $this->respondCrosscut($result, '/groups/' . rawurlencode($hierarchyGroupId) . '/crosscuts', 'linkedFlash');
    }

    /** POST groups/{id}/crosscuts/unlink — remove a cross-cut link from this node. */
    public function unlink(string $hierarchyGroupId = '')
    {
        $result = GroupServices::crosscut()->unlink(
            $this->orgId(),
            (string) $this->field('crosscut_group_id', ''),
            $hierarchyGroupId,
            $this->currentUserId('issued_by'),
        );

        return $this->respondCrosscut($result, '/groups/' . rawurlencode($hierarchyGroupId) . '/crosscuts', 'unlinkedFlash');
    }

    /**
     * Post/Redirect/Get for a browser cross-cut write. API clients keep the raw
     * Result (JSON); browsers redirect back to the console they came from — the
     * caller's own `return_to` when it is a safe same-site path, else $default —
     * with a localized success flash or the failing Result's message.
     */
    private function respondCrosscut(Result $result, string $default, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $this->safeReturnTo($default);
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Groups.crosscutNode.' . $okKey));
    }

    /**
     * A `return_to` value is only honoured when it is a local absolute path
     * ("/groups/…") — never an absolute URL or scheme-relative link — so the PRG
     * redirect can't be turned into an open redirect. Falls back to $default.
     */
    private function safeReturnTo(string $default): string
    {
        $rt = trim((string) $this->field('return_to', ''));
        if ($rt !== '' && $rt[0] === '/' && ! str_starts_with($rt, '//')) {
            return $rt;
        }

        return $default;
    }
}
