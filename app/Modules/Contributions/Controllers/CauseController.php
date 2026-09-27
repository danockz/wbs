<?php

declare(strict_types=1);

namespace WBS\Contributions\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Http\BaseController;

/**
 * Cause endpoints (SRS FR-VBCS-001).
 *
 * A cause is group-owned; targets are integer minor units to stay consistent
 * with the ledger. Reads are org-scoped. Writes are gated `contribution.manage`
 * on the routes. Browser callers get bespoke server-rendered views + PRG forms;
 * API callers (Accept: application/json) get JSON from the same actions.
 *
 * Deletion is SAFE: a cause with any contribution/intent is CLOSED rather than
 * hard-deleted (ledger integrity), decided authoritatively in CauseService.
 */
final class CauseController extends BaseController
{
    /** GET causes — list this org's causes (browser directory / JSON). */
    public function index()
    {
        $result = ContributionServices::causes()->list($this->orgId(), [
            'status'     => $this->field('status'),
            'visibility' => $this->field('visibility'),
            'group_id'   => $this->field('group_id'),
            'q'          => $this->field('q'),
            'limit'      => (int) $this->field('limit', 20),
            'offset'     => (int) $this->field('offset', 0),
        ]);
        $data = is_array($result->data) ? $result->data : [];

        return $this->respondWith(
            $result,
            'WBS\Contributions\Views\causes',
            null,
            [
                'causes' => $data['causes'] ?? [],
                'total'  => (int) ($data['total'] ?? 0),
                'limit'  => (int) ($data['limit'] ?? 20),
                'offset' => (int) ($data['offset'] ?? 0),
                // Token the global webcsrfissue filter minted this request, so the
                // inline status/delete forms satisfy the webcsrf check.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET causes/{id} — read one cause (browser detail / JSON). */
    public function show(string $causeId = '')
    {
        $result = ContributionServices::causes()->show($this->orgId(), $causeId);

        return $this->respondWith(
            $result,
            'WBS\Contributions\Views\cause_show',
            null,
            ['cause' => $result->ok ? $result->data : null],
        );
    }

    /** GET causes/new — the CREATE form (browser). */
    public function createForm()
    {
        return $this->renderForm('WBS\Contributions\Views\cause_form', [
            'mode'  => 'create',
            'cause' => [],
        ]);
    }

    /** GET causes/{id}/edit — the EDIT form (browser), prefilled. */
    public function editForm(string $causeId = '')
    {
        $result = ContributionServices::causes()->show($this->orgId(), $causeId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/causes')->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\Contributions\Views\cause_form', [
            'mode'  => 'edit',
            'cause' => (array) $result->data,
        ]);
    }

    /** POST causes — create a cause. */
    public function create()
    {
        $result = ContributionServices::causes()->create($this->orgId(), $this->input());

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/causes/' . (string) ($result->data['cause_id'] ?? ''))
                    ->with('success', (string) lang('Contributions.causes.form.createdFlash'));
            }

            return $this->renderForm('WBS\Contributions\Views\cause_form', [
                'mode'  => 'create',
                'cause' => $this->input(),
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST causes/{id} — update a cause. */
    public function update(string $causeId = '')
    {
        $result = ContributionServices::causes()->update($this->orgId(), $causeId, $this->input());

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/causes/' . $causeId)
                    ->with('success', (string) lang('Contributions.causes.form.updatedFlash'));
            }

            return $this->renderForm('WBS\Contributions\Views\cause_form', [
                'mode'  => 'edit',
                'cause' => ['id' => $causeId] + $this->input(),
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    /** POST causes/{id}/status — transition lifecycle (draft|active|closed). */
    public function setStatus(string $causeId = '')
    {
        $result = ContributionServices::causes()->setStatus(
            $this->orgId(),
            $causeId,
            (string) $this->field('status', ''),
        );

        if (! $this->wantsJson()) {
            return redirect()->to('/causes')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Contributions.causes.form.statusFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** POST causes/{id}/delete — safe-delete (closes if it has contributions). */
    public function delete(string $causeId = '')
    {
        $result = ContributionServices::causes()->delete($this->orgId(), $causeId);

        if (! $this->wantsJson()) {
            $key = 'Contributions.causes.form.deletedFlash';
            if ($result->ok && isset($result->data['deleted']) && $result->data['deleted'] === false) {
                $key = 'Contributions.causes.form.closedInsteadFlash';
            }

            return redirect()->to('/causes')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang($key) : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
