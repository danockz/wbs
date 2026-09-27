<?php

declare(strict_types=1);

namespace WBS\Referrals\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Referrals\Services\IntegrationService;
use WBS\Referrals\Support\IntegrationDecision;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Integration lifecycle (FR-REF-3b) — the dated decisions a person makes on
 * the way in, each standalone: salvation, water baptism, Holy Spirit baptism,
 * foundation course.
 *
 * Two surfaces, both service-gated (auth + webcsrf only — the authoritative
 * decision is IntegrationService's config gate + ownership/sponsor check, so no
 * new permission bit):
 *
 *   - `my/integration`        — a member states their own decisions and dates.
 *     A self-declaration is stored PENDING and only counts once their sponsor
 *     confirms it.
 *   - `me/integration-decisions` — the owning mentor's queue of pending
 *     declarations to confirm or reject (no TTL: it stays until a human decides).
 */
final class IntegrationController extends BaseController
{
    private function svc(): IntegrationService
    {
        // Non-shared: CI4 getSharedInstance('integration') returns null on this
        // request path (accounts → journey → integration). Never pass that to
        // checklistForUser — GET /my/integration TypeErrors.
        $svc = ReferralServices::integration(false);
        if (! $svc instanceof IntegrationService) {
            throw new \LogicException('Referrals Services::integration(false) must return IntegrationService');
        }

        return $svc;
    }

    /** Member self-service: the checklist + my declarations. */
    public function mine(): ResponseInterface
    {
        $orgId   = $this->orgId();
        $userId  = $this->currentUserId();
        $groupId = SharedServices::groupScope()->primaryMembershipGroup($orgId, $userId);

        $state = $this->svc()->checklistForUser($orgId, $userId, $groupId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($state));
        }

        return $this->renderForm('WBS\\Referrals\\Views\\integration', [
            'state'        => $state,
            'declarations' => $this->svc()->declarationsForUser($orgId, $userId),
            'groups'       => IntegrationDecision::GROUPS,
            'types'        => IntegrationDecision::REQUIRED_TYPES,
        ]);
    }

    /** POST my/integration — state one of my decisions. */
    public function declareSelf(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();
        $in     = $this->input();

        $res = $this->svc()->declareSelf($orgId, $userId, [
            'decision_type' => $in['decision_type'] ?? '',
            'decision_date' => $in['decision_date'] ?? '',
            'note'          => $in['note'] ?? null,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: browser failures must FLASH, never silent PRG.
        if (! $res->ok) {
            return redirect()->to('/my/integration')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/my/integration');
    }

    /** The owning mentor's pending-confirmation queue. */
    public function queue(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();

        $pending = $this->svc()->pendingForOwner($orgId, $userId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['pending' => $pending]));
        }

        return $this->renderForm('WBS\\Referrals\\Views\\decisions_queue', [
            'pending' => $pending,
        ]);
    }

    /** POST me/integration-decisions/confirm/{id} — the mentor vouches. */
    public function confirm(string $decisionId = ''): ResponseInterface
    {
        return $this->decide($decisionId, 'confirm');
    }

    /** POST me/integration-decisions/reject/{id} — the mentor declines. */
    public function reject(string $decisionId = ''): ResponseInterface
    {
        return $this->decide($decisionId, 'reject');
    }

    private function decide(string $decisionId, string $which): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();

        $res = $which === 'confirm'
            ? $this->svc()->confirm($orgId, $userId, $decisionId)
            : $this->svc()->reject($orgId, $userId, $decisionId);

        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        // FR-ARC-002 sweep: browser failures must FLASH, never silent PRG.
        if (! $res->ok) {
            return redirect()->to('/me/integration-decisions')->with('error', $this->errText((string) $res->message));
        }

        return redirect()->to('/me/integration-decisions');
    }
}
