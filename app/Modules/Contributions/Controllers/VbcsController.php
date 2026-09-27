<?php

declare(strict_types=1);

namespace WBS\Contributions\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * VBCS metrics, partnership, commitments, manual givings, and leader reporting
 * (adaptation of GivingsLibrary). Member reads never expose PII beyond the
 * subject id + display name. Admin actions sit behind
 * `authorize:contribution.manage` (partnership tiers, manual approvals) or the
 * existing refund permissions.
 */
final class VbcsController extends BaseController
{
    // --- Member-facing reads -------------------------------------------------

    /** A subject's PGV/GGV snapshot. */
    public function metrics(string $subjectId = '')
    {
        $data = ContributionServices::metrics()->forSubject($this->orgId(), $subjectId);

        return $this->respondWith(
            Result::ok($data),
            htmlView: 'WBS\Contributions\Views\metrics',
            viewData: ['result' => $data, 'title' => 'Giving metrics'],
        );
    }

    /** A subject's partnership status (level, streak, PGV/GGV). */
    public function partnershipStatus(string $subjectId = '')
    {
        $data = ContributionServices::partnership()->status($this->orgId(), $subjectId);

        return $this->respondWith(
            Result::ok($data),
            htmlView: 'WBS\Contributions\Views\partnership_status',
            viewData: ['result' => $data, 'title' => 'Partnership status'],
        );
    }

    /** Active partnership tiers (public catalog). */
    public function partnershipTiers()
    {
        $tiers = ContributionServices::partnership()->tiers($this->orgId());

        return $this->respondWith(
            Result::ok($tiers),
            htmlView: 'WBS\Contributions\Views\partnership_tiers',
            viewData: ['result' => $tiers, 'title' => 'Partnership tiers'],
        );
    }

    // --- Commitments (member-owned) ------------------------------------------

    public function listCommitments(string $subjectId = '')
    {
        $items = ContributionServices::commitments()->listForSubject($this->orgId(), $subjectId);

        return $this->respondWith(
            Result::ok($items),
            htmlView: 'WBS\Contributions\Views\commitments',
            viewData: [
                'result'    => $items,
                'title'     => 'Giving commitments',
                'subjectId' => $subjectId,
                // Token the global webcsrfissue filter minted this request, so the
                // inline cancel forms satisfy the webcsrf check.
                'csrf'      => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /**
     * GET vbcs/commitments/new — the pledge CAPTURE form (browser).
     *
     * A commitment is member-owned; the subject defaults to the acting user but
     * an explicit ?subject_id= is honoured (e.g. a leader pledging on behalf of
     * a member they manage). The cause is chosen from the org's ACTIVE causes.
     */
    public function createCommitmentForm()
    {
        $subjectId = (string) ($this->field('subject_id') ?? $this->currentUserId('actor_id'));

        return $this->renderForm('WBS\Contributions\Views\commitment_form', [
            'subjectId'   => $subjectId,
            'causes'      => $this->activeCauses(),
            'frequencies' => ['monthly', 'quarterly', 'annual', 'one_time'],
            'commitment'  => [],
            'error'       => '',
        ]);
    }

    public function createCommitment()
    {
        $subjectId = (string) ($this->field('subject_id') ?? $this->currentUserId('actor_id'));
        $result    = ContributionServices::commitments()->create($this->orgId(), $subjectId, $this->input());

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/vbcs/subjects/' . rawurlencode($subjectId) . '/commitments')
                    ->with('success', (string) lang('Contributions.commitmentForm.createdFlash'));
            }

            return $this->renderForm('WBS\Contributions\Views\commitment_form', [
                'subjectId'   => $subjectId,
                'causes'      => $this->activeCauses(),
                'frequencies' => ['monthly', 'quarterly', 'annual', 'one_time'],
                'commitment'  => $this->input(),
                'error'       => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function cancelCommitment(string $commitmentId = '')
    {
        $subjectId = (string) ($this->field('subject_id') ?? $this->currentUserId('actor_id'));
        $result    = ContributionServices::commitments()->cancel($this->orgId(), $subjectId, $commitmentId);

        if (! $this->wantsJson()) {
            return redirect()->to('/vbcs/subjects/' . rawurlencode($subjectId) . '/commitments')
                ->with(
                    $result->ok ? 'success' : 'error',
                    (string) ($result->ok ? lang('Contributions.commitmentForm.cancelledFlash') : $result->message),
                );
        }

        return $this->respondWith($result);
    }

    /** @return list<array<string,mixed>> The org's active causes for the pledge picker. */
    private function activeCauses(): array
    {
        $result = ContributionServices::causes()->list($this->orgId(), ['status' => 'active', 'limit' => 200]);
        $rows   = is_array($result->data['causes'] ?? null) ? $result->data['causes'] : [];

        return $rows;
    }

    // --- Leader reporting ----------------------------------------------------

    public function groupReport(string $groupId = '')
    {
        $result = ContributionServices::causes()->groupGivingReport(
            $this->orgId(),
            $groupId,
            (int) $this->field('days', 30),
        );

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Contributions\Views\group_report',
            viewData: ['result' => $result->ok ? $result->data : [], 'title' => 'Group giving report'],
        );
    }

    public function causeProgress(string $causeId = '')
    {
        $result = ContributionServices::causes()->progress($this->orgId(), $causeId);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Contributions\Views\cause_progress',
            viewData: ['result' => $result->data, 'title' => 'Cause progress'],
        );
    }

    public function causeDonors(string $causeId = '')
    {
        $donors = ContributionServices::causes()->donors(
            $this->orgId(),
            $causeId,
            (int) $this->field('limit', 50),
            (int) $this->field('offset', 0),
        );

        return $this->respondWith(
            Result::ok($donors),
            htmlView: 'WBS\Contributions\Views\cause_donors',
            viewData: ['result' => $donors, 'title' => 'Donors', 'causeId' => $causeId],
        );
    }

    // --- Admin: partnership tier config --------------------------------------

    /**
     * GET vbcs/partnership/tiers/manage — the admin CRUD catalog (browser) or
     * JSON. Lists ALL tiers (incl. disabled) with an inline "New tier" create
     * form + per-row Edit (prefilled) and Disable controls. define() is an
     * UPSERT keyed on (org, code), so a single POST serves both create + edit.
     */
    public function managePartnershipTiers()
    {
        $tiers = ContributionServices::partnership()->listForAdmin($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($tiers));
        }

        return $this->respondWith(
            Result::ok($tiers),
            htmlView: 'WBS\Contributions\Views\partnership_tiers_admin',
            viewData: [
                'tiers' => $tiers,
                'title' => 'Partnership tiers',
                'csrf'  => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function definePartnershipTier()
    {
        return $this->respondTierDecision(
            ContributionServices::partnership()->define($this->orgId(), $this->input()),
        );
    }

    public function disablePartnershipTier(string $code = '')
    {
        return $this->respondTierDecision(
            ContributionServices::partnership()->disable($this->orgId(), $code),
            'disabledFlash',
        );
    }

    /**
     * PRG helper for the partnership-tier catalog: JSON for API clients, else
     * redirect back to the manage list with a localized flash. Default flash is
     * created/updated inferred from the upsert result marker.
     */
    private function respondTierDecision(Result $result, string $okKey = '')
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $list = '/vbcs/partnership/tiers/manage';
        if (! $result->ok) {
            return redirect()->to($list)->with('error', $this->errText((string) $result->message));
        }

        if ($okKey === '') {
            $okKey = ! empty($result->data['updated']) ? 'updatedFlash' : 'createdFlash';
        }

        return redirect()->to($list)->with('success', (string) lang('Contributions.partnershipTierForm.' . $okKey));
    }

    // --- Admin: manual / in-kind givings (maker-checker) ---------------------

    /**
     * Maker capture form (GET) for a manual / in-kind giving. The browser face of
     * the maker step whose write previously had no form and could only be posted
     * as JSON. Cause is chosen from the org's ACTIVE causes; type is a fixed
     * vocabulary; amounts are minor units.
     */
    public function submitManualForm()
    {
        return $this->renderForm('WBS\Contributions\Views\manual_form', [
            'causes'     => $this->activeCauses(),
            'types'      => ['cash', 'cheque', 'bank_transfer', 'in_kind'],
            'categories' => ['goods', 'services', 'time'],
            'record'     => [],
            'error'      => '',
        ]);
    }

    public function submitManual()
    {
        $result = ContributionServices::manualContributions()->submit(
            $this->orgId(),
            $this->currentUserId('actor_id'),
            $this->input(),
        );

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/vbcs/manual/pending')
                    ->with('success', (string) lang('Contributions.manualForm.submittedFlash'));
            }

            return $this->renderForm('WBS\Contributions\Views\manual_form', [
                'causes'     => $this->activeCauses(),
                'types'      => ['cash', 'cheque', 'bank_transfer', 'in_kind'],
                'categories' => ['goods', 'services', 'time'],
                'record'     => $this->input(),
                'error'      => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function pendingManual()
    {
        $rows = ContributionServices::manualContributions()->pending(
            $this->orgId(),
            (int) $this->field('limit', 50),
            (int) $this->field('offset', 0),
        );

        return $this->respondWith(
            Result::ok($rows),
            htmlView: 'WBS\Contributions\Views\manual_pending',
            viewData: [
                'result' => $rows,
                'title'  => 'Manual contributions — pending',
                // Token the global webcsrfissue filter minted this request, so the
                // inline approve/reject forms satisfy the webcsrf check.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function approveManual(string $recordId = '')
    {
        $result = ContributionServices::manualContributions()->approve(
            $this->orgId(),
            $recordId,
            $this->currentUserId('actor_id'),
            $this->input(),
        );

        return $this->respondManualDecision($result, 'approvedFlash');
    }

    public function rejectManual(string $recordId = '')
    {
        $result = ContributionServices::manualContributions()->reject(
            $this->orgId(),
            $recordId,
            $this->currentUserId('actor_id'),
            (string) $this->field('reason', ''),
        );

        return $this->respondManualDecision($result, 'rejectedFlash');
    }

    /**
     * PRG for a browser manual-giving decision: redirect back to the approval
     * queue with a success/error flash; API clients keep the JSON Result. Success
     * copy comes from the localized manualPending flash keys; failures (bad-state,
     * SoD self-approval) surface the Result message, which the service localizes.
     */
    private function respondManualDecision(Result $result, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/vbcs/manual/pending')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Contributions.manualPending.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    // --- Admin: force a metrics/partnership refresh --------------------------

    public function refreshMetrics(string $subjectId = '')
    {
        ContributionServices::metrics()->cascadeToUpline($this->orgId(), $subjectId);

        return $this->respondWith(ContributionServices::partnership()->recomputeStatus($this->orgId(), $subjectId));
    }

}
