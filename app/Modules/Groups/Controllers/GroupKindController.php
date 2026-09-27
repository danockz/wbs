<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Configurable group-kind taxonomy (leadership-responsibility model, option B).
 *
 * Reads need an authenticated session; mutations are governance actions gated by
 * `group.change.approve`. A kind classifies WHAT a group is (department /
 * activity team / ministry / committee) independently of placement — it never
 * changes access scope.
 *
 * Both read pages double as MANAGEMENT CONSOLES: the catalogue (index) carries a
 * create form + per-row edit link; the detail page (show) carries an edit form.
 * Browser writes PRG-redirect (create → the catalogue; update → the kind's own
 * page) with a localized flash; API clients keep the identical JSON Result. Each
 * write posts to a webcsrf-guarded route and its actor is the session user.
 */
final class GroupKindController extends BaseController
{
    /** GET group-kinds — list kinds (optionally active only via ?active=1). */
    public function index()
    {
        $activeOnly = (bool) $this->field('active', false);
        $kinds      = GroupServices::groupKinds()->listWithCounts($this->orgId(), $activeOnly);

        return $this->respondWith(
            Result::ok($kinds),
            htmlView: 'WBS\Groups\Views\kinds_index',
            viewData: [
                'kinds' => $kinds,
                'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET group-kinds/{id} — read one kind. */
    public function show(string $kindId = '')
    {
        $result = GroupServices::groupKinds()->show($this->orgId(), $kindId);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Groups\Views\kind_show',
            viewData: [
                'kind'   => $result->ok ? $result->data : null,
                'kindId' => $kindId,
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** POST group-kinds — create a kind. */
    public function create()
    {
        $result = GroupServices::groupKinds()->create(
            $this->orgId(),
            $this->input(),
            $this->currentUserId('issued_by'),
        );

        return $this->respondKind($result, '/group-kinds', 'createdFlash');
    }

    /** POST group-kinds/{id} — update a kind. */
    public function update(string $kindId = '')
    {
        $result = GroupServices::groupKinds()->update(
            $this->orgId(),
            $kindId,
            $this->input(),
            $this->currentUserId('issued_by'),
        );

        $to = $kindId !== '' ? '/group-kinds/' . rawurlencode($kindId) : '/group-kinds';

        return $this->respondKind($result, $to, 'updatedFlash');
    }

    /**
     * Post/Redirect/Get for a browser kind write. API clients keep the raw Result
     * (JSON); browsers redirect to $default with a localized success flash, or the
     * failing Result's message as an error flash (kept on the form page so the
     * validation error is visible).
     */
    private function respondKind(Result $result, string $default, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            $back = $this->safeReturnTo($default);

            return redirect()->to($back)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($default)->with('success', lang('Groups.kinds.' . $okKey));
    }

    /**
     * A `return_to` value is only honoured when it is a local absolute path
     * ("/group-kinds/…") — never an absolute URL or scheme-relative link — so the
     * PRG redirect can't be turned into an open redirect. Falls back to $default.
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
