<?php

declare(strict_types=1);

namespace WBS\Gamification\Controllers;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Group campaigns / "projects" surface of the gamification module (SRS FR-GAM-*):
 * time-boxed, group-scoped, target-based campaigns with an optional tier ladder,
 * ad hoc teams (rosters aggregated across the hierarchy), progress recording,
 * and leaderboards / team standings.
 *
 * A campaign is ALWAYS a group project (`group_campaigns.group_id` is required),
 * so every write is group-hierarchy-aware: the route filter is a coarse
 * capability gate (`authorize:gamification.manage,any`) and the AUTHORITATIVE
 * per-group check happens here via {@see BaseController::authorizeGroupScope()} —
 * a leader may only manage campaigns whose owning group their grant covers
 * (self, or an ancestor grant with include_descendants). This lets a region /
 * district leader run their own group's campaigns instead of requiring an
 * org-wide manager. Reads stay auth-only (leaderboards are member-visible).
 */
final class CampaignsController extends BaseController
{
    /** Permission gating all campaign management. */
    private const MANAGE = 'gamification.manage';

    /**
     * Authorize a write against an EXISTING campaign's owning group. Returns a
     * denied Result to return as-is, or null when permitted.
     */
    private function authorizeCampaign(string $campaignId): ?Result
    {
        $scope = GamificationServices::campaigns()->campaignGroupScope($this->orgId(), $campaignId);
        if ($scope === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }

        return $this->authorizeGroupScope(self::MANAGE, $scope['group_id']);
    }
    /** List a group's campaigns (optionally ?status=active). */
    public function campaigns(string $groupId = '')
    {
        $status = $this->field('status');

        return $this->respondPage(
            Result::ok(GamificationServices::campaigns()->listForGroup(
                $this->orgId(),
                $groupId,
                $status !== null && $status !== '' ? (string) $status : null,
            )),
            'gam_group_campaigns',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['campaigns'] ?? [])],
        );
    }

    /** Read one campaign (config + state, with tiers and ad hoc teams inlined). */
    public function getCampaign(string $campaignId = '')
    {
        return $this->respondPage(
            GamificationServices::campaigns()->show($this->orgId(), $campaignId),
            'gam_campaign_show',
            static fn (array $d): array => ['record' => $d],
        );
    }

    /**
     * GET campaigns/new (create) and campaigns/{id}/edit (draft edit) — the
     * bespoke campaign config form. Create captures identity (group_id, code,
     * award_mode) which are immutable after creation; edit prefills and locks
     * those. metric / award_mode / team_mode render as <select> pickers, the
     * owning group as a group picker. On failure the write actions re-render this
     * with $error + the submitted values.
     */
    public function createCampaignForm()
    {
        return $this->renderForm('WBS\Gamification\Views\campaign_form', $this->campaignFormData('create', []));
    }

    public function editCampaignForm(string $campaignId = '')
    {
        $result = GamificationServices::campaigns()->show($this->orgId(), $campaignId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondWith($result);
            }

            return redirect()->to('/gamification/campaigns/' . rawurlencode($campaignId))
                ->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\Gamification\Views\campaign_form', $this->campaignFormData('edit', (array) $result->data));
    }

    /** Shared view-data for the campaign form (pickers + vocab + values). */
    private function campaignFormData(string $mode, array $campaign): array
    {
        $vocab = GamificationServices::campaigns()->vocab();

        return [
            'mode'      => $mode,
            'campaign'  => $campaign,
            'groups'    => GroupServices::groups()->listForOrg($this->orgId()),
            'metrics'   => $vocab['metrics'],
            'modes'     => $vocab['modes'],
            'teamModes' => $vocab['teamModes'],
            'error'     => '',
        ];
    }

    /** Create a campaign (draft). Gated on the target group from the body. */
    public function createCampaign()
    {
        $in               = $this->input();
        $in['created_by'] = $this->actorId('created_by');

        $targetGroup = isset($in['group_id']) && $in['group_id'] !== '' ? (string) $in['group_id'] : null;
        if ($deny = $this->authorizeGroupScope(self::MANAGE, $targetGroup)) {
            return $this->respondCampaignDecision($deny, 'create', $in);
        }

        return $this->respondCampaignDecision(
            GamificationServices::campaigns()->create($this->orgId(), $in),
            'create',
            $in,
        );
    }

    /** Update a DRAFT campaign's editable fields. */
    public function updateCampaign(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondCampaignDecision($deny, 'edit', ['id' => $campaignId] + $this->input());
        }

        return $this->respondCampaignDecision(
            GamificationServices::campaigns()->update($this->orgId(), $campaignId, $this->input()),
            'edit',
            ['id' => $campaignId] + $this->input(),
        );
    }

    /**
     * PRG helper for the campaign config form: JSON for API clients, else on
     * success redirect to the campaign detail with a localized flash; on failure
     * re-render the form with the submitted values + a friendly error.
     */
    private function respondCampaignDecision(Result $result, string $mode, array $submitted)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if ($result->ok) {
            $campaignId = (string) ($result->data['campaign_id'] ?? $submitted['id'] ?? '');
            $flashKey   = $mode === 'edit' ? 'updatedFlash' : 'createdFlash';

            return redirect()->to('/gamification/campaigns/' . rawurlencode($campaignId))
                ->with('success', (string) lang('Gamification.campaignForm.' . $flashKey));
        }

        $friendly = lang('Gamification.campaignForm.errors.' . ($result->code ?? ''));
        if (str_contains($friendly, 'Gamification.campaignForm.errors.')) {
            $friendly = $this->errText((string) $result->message);
        }

        return $this->renderForm(
            'WBS\Gamification\Views\campaign_form',
            ['error' => $friendly] + $this->campaignFormData($mode, $submitted),
        );
    }

    /** List a campaign's tier ladder (silver/gold/diamond …). */
    public function campaignTiers(string $campaignId = '')
    {
        return $this->respondPage(
            Result::ok(GamificationServices::campaigns()->tiers($this->orgId(), $campaignId)),
            'gam_campaign_tiers',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['tiers'] ?? []), 'back' => 'gamification/campaigns/' . $campaignId],
        );
    }

    /**
     * GET campaigns/{id}/tiers/manage — the reward-ladder MANAGEMENT console
     * (browser) or JSON. Lists the tier ladder ascending by threshold and renders
     * an inline add-tier form + per-tier delete controls. Writes are
     * webcsrf-guarded and PRG back here with a localized flash.
     *
     * The ladder is only editable while the campaign is a draft AND uses
     * award_mode=tiered — the service enforces both; the console surfaces those
     * states so the leader knows what is editable.
     */
    public function manageCampaignTiers(string $campaignId = '')
    {
        $campaign = GamificationServices::campaigns()->show($this->orgId(), $campaignId);
        if (! $campaign->ok) {
            if ($this->wantsJson()) {
                return $this->respondWith($campaign);
            }

            return redirect()->to('/gamification/campaigns/' . rawurlencode($campaignId))
                ->with('error', $this->errText((string) $campaign->message));
        }

        $tiers = GamificationServices::campaigns()->tiers($this->orgId(), $campaignId);
        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['campaign_id' => $campaignId, 'tiers' => $tiers]));
        }

        $c        = (array) $campaign->data;
        $isTiered = ($c['award_mode'] ?? 'single') === 'tiered';

        return $this->respondWith(
            Result::ok($tiers),
            htmlView: 'WBS\Gamification\Views\campaign_tiers_manage',
            viewData: [
                'campaignId' => $campaignId,
                'campaign'   => $c,
                'status'     => (string) ($c['status'] ?? 'draft'),
                'isTiered'   => $isTiered,
                'tiers'      => $tiers,
                'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Add a tier to a tiered campaign (draft only). */
    public function defineCampaignTier(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTierDecision($deny, $campaignId);
        }

        return $this->respondTierDecision(
            GamificationServices::campaigns()->defineTier($this->orgId(), $campaignId, $this->input()),
            $campaignId,
        );
    }

    /** Remove a tier from a tiered campaign's ladder (draft only). */
    public function deleteCampaignTier(string $campaignId = '', string $tierId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTierDecision($deny, $campaignId);
        }

        return $this->respondTierDecision(
            GamificationServices::campaigns()->deleteTier($this->orgId(), $campaignId, $tierId),
            $campaignId,
            'deletedFlash',
        );
    }

    /**
     * PRG helper for the tier-management console: JSON for API clients, else
     * redirect back to the manage page with a localized flash (friendly error
     * copy keyed on the stable code, raw message fallback).
     */
    private function respondTierDecision(Result $result, string $campaignId, string $okKey = 'createdFlash')
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $list = '/gamification/campaigns/' . rawurlencode($campaignId) . '/tiers/manage';
        if (! $result->ok) {
            $friendly = lang('Gamification.campaignTiers.errors.' . ($result->code ?? ''));
            if (str_contains($friendly, 'Gamification.campaignTiers.errors.')) {
                $friendly = $this->errText((string) $result->message);
            }

            return redirect()->to($list)->with('error', $friendly);
        }

        return redirect()->to($list)->with('success', (string) lang('Gamification.campaignTiers.form.' . $okKey));
    }

    // --- Ad hoc teams (rosters aggregated across the hierarchy) --------------

    /** List a campaign's ad hoc teams (with member counts). Data-page view. */
    public function campaignTeams(string $campaignId = '')
    {
        return $this->respondPage(
            Result::ok(GamificationServices::campaigns()->listTeams($this->orgId(), $campaignId)),
            'gam_campaign_teams',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['teams'] ?? []), 'back' => 'gamification/campaigns/' . $campaignId],
        );
    }

    /**
     * GET campaigns/{id}/teams/manage — the ad hoc team MANAGEMENT console
     * (browser) or JSON. Lists every team with its resolved roster and renders
     * inline create/rename/delete team + add/remove member controls. All writes
     * are webcsrf-guarded and PRG back here with a localized flash.
     *
     * Team lifecycle rules are enforced authoritatively in CampaignService
     * (adhoc-only; delete draft-only; a user belongs to at most one team). The
     * console surfaces those states so the leader knows what is editable.
     */
    public function manageCampaignTeams(string $campaignId = '')
    {
        $campaign = GamificationServices::campaigns()->show($this->orgId(), $campaignId);
        if (! $campaign->ok) {
            if ($this->wantsJson()) {
                return $this->respondWith($campaign);
            }

            return redirect()->to('/gamification/campaigns/' . rawurlencode($campaignId))
                ->with('error', $this->errText((string) $campaign->message));
        }

        $teams = GamificationServices::campaigns()->teamsWithRosters($this->orgId(), $campaignId);
        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['campaign_id' => $campaignId, 'teams' => $teams]));
        }

        $c      = (array) $campaign->data;
        $isAdhoc = ! empty($c['team_challenge']) && ($c['team_mode'] ?? 'subtree') === 'adhoc';

        return $this->respondWith(
            Result::ok($teams),
            htmlView: 'WBS\Gamification\Views\campaign_teams_manage',
            viewData: [
                'campaignId' => $campaignId,
                'campaign'   => $c,
                'status'     => (string) ($c['status'] ?? 'draft'),
                'isAdhoc'    => $isAdhoc,
                'teams'      => $teams,
                // user_id on the add-member form is an entity reference → offer the
                // org roster as a picker.
                'roster'     => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Define an ad hoc team on a group campaign (team_mode=adhoc). */
    public function defineCampaignTeam(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTeamDecision($deny, $campaignId);
        }

        return $this->respondTeamDecision(
            GamificationServices::campaigns()->defineTeam($this->orgId(), $campaignId, $this->input()),
            $campaignId,
        );
    }

    /** Rename / re-style an ad hoc team (draft or active). */
    public function updateCampaignTeam(string $campaignId = '', string $teamId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTeamDecision($deny, $campaignId);
        }

        return $this->respondTeamDecision(
            GamificationServices::campaigns()->updateTeam($this->orgId(), $campaignId, $teamId, $this->input()),
            $campaignId,
            'updatedFlash',
        );
    }

    /** Delete an ad hoc team and its roster (draft only). */
    public function deleteCampaignTeam(string $campaignId = '', string $teamId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTeamDecision($deny, $campaignId);
        }

        return $this->respondTeamDecision(
            GamificationServices::campaigns()->deleteTeam($this->orgId(), $campaignId, $teamId),
            $campaignId,
            'deletedFlash',
        );
    }

    /** Add a member (from anywhere in the hierarchy) to an ad hoc team. */
    public function addCampaignTeamMember(string $campaignId = '', string $teamId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTeamDecision($deny, $campaignId);
        }

        $in = $this->input();

        return $this->respondTeamDecision(
            GamificationServices::campaigns()->addTeamMember(
                $this->orgId(),
                $campaignId,
                $teamId,
                (string) ($in['user_id'] ?? ''),
                $this->actorId('added_by'),
            ),
            $campaignId,
            'memberAddedFlash',
        );
    }

    /** Remove a member from any ad hoc team on the campaign. */
    public function removeCampaignTeamMember(string $campaignId = '', string $userId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondTeamDecision($deny, $campaignId);
        }

        return $this->respondTeamDecision(
            GamificationServices::campaigns()->removeTeamMember($this->orgId(), $campaignId, $userId),
            $campaignId,
            'memberRemovedFlash',
        );
    }

    /**
     * PRG helper for the team-management console: JSON for API clients, else
     * redirect back to the manage page with a localized flash. Default OK flash
     * is created/updated inferred from the upsert result marker.
     */
    private function respondTeamDecision(Result $result, string $campaignId, string $okKey = '')
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $list = '/gamification/campaigns/' . rawurlencode($campaignId) . '/teams/manage';
        if (! $result->ok) {
            // Prefer a friendly, localized message keyed on the stable error code;
            // fall back to the raw service message when none is defined.
            $friendly = lang('Gamification.campaignTeams.errors.' . ($result->code ?? ''));
            if (str_contains($friendly, 'Gamification.campaignTeams.errors.')) {
                $friendly = $this->errText((string) $result->message);
            }

            return redirect()->to($list)->with('error', $friendly);
        }

        if ($okKey === '') {
            $okKey = ! empty($result->data['updated']) ? 'updatedFlash' : 'createdFlash';
        }

        return redirect()->to($list)->with('success', (string) lang('Gamification.campaignTeams.form.' . $okKey));
    }

    public function activateCampaign(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::campaigns()->activate($this->orgId(), $campaignId));
    }

    public function cancelCampaign(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::campaigns()->cancel($this->orgId(), $campaignId));
    }

    /** Record progress for a subject toward a campaign target. */
    public function campaignProgress(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondWith($deny);
        }

        $in = $this->input();

        return $this->respondWith(GamificationServices::campaigns()->recordProgress(
            $this->orgId(),
            $campaignId,
            (string) ($in['subject_id'] ?? ''),
            (int) ($in['amount'] ?? 0),
            [
                'sourceRef' => $in['source_ref'] ?? null,
                'team_ref'  => $in['team_ref'] ?? null,   // optional team-challenge tally
                'team_kind' => $in['team_kind'] ?? null,  // group|team
            ],
        ));
    }

    /** Close a campaign and snapshot top-N recognition. */
    public function closeCampaign(string $campaignId = '')
    {
        if ($deny = $this->authorizeCampaign($campaignId)) {
            return $this->respondWith($deny);
        }

        return $this->respondWith(GamificationServices::campaigns()->close($this->orgId(), $campaignId));
    }

    /** Campaign leaderboard — top INDIVIDUAL members by accumulated metric. */
    public function campaignLeaderboard(string $campaignId = '')
    {
        return $this->respondPage(
            Result::ok(GamificationServices::campaigns()->leaderboard(
                $this->orgId(),
                $campaignId,
                (int) $this->field('limit', 20),
            )),
            'gam_campaign_leaderboard',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['leaderboard'] ?? $d['entries'] ?? []), 'back' => 'gamification/campaigns/' . $campaignId],
        );
    }

    /** Optional team-challenge standings — top TEAMS by aggregate contribution. */
    public function campaignTeamStandings(string $campaignId = '')
    {
        return $this->respondPage(
            Result::ok(GamificationServices::campaigns()->teamStandings(
                $this->orgId(),
                $campaignId,
                (int) $this->field('limit', 20),
            )),
            'gam_campaign_team_standings',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['standings'] ?? $d['teams'] ?? []), 'back' => 'gamification/campaigns/' . $campaignId],
        );
    }
}
