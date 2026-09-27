<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Group campaigns / "projects" (SRS FR-GAM-010/011).
 *
 * A hierarchical group can run a time-boxed project bundling Win/Build/Send (or
 * any specified) activities toward a configurable target. Distinct from the
 * general annual season (SeasonService): a campaign has an ARBITRARY duration,
 * is scoped to ONE group (optionally including its subgroups), and is highly
 * configurable —
 *
 *   - metric        : points | amount | volume | count
 *   - target_value  : threshold to hit
 *   - award_mode    : how the target-award is configured —
 *        * single     — won once when the target is first reached;
 *        * repeatable — won each time a further target multiple is crossed
 *                       (max_awards caps the repeats);
 *        * tiered      — a ladder of thresholds (e.g. silver/gold/diamond),
 *                       each with its own badge/points, granted once each
 *                       (see campaign_tiers / defineTier()).
 *   - badge_code    : badge granted on each target hit (single/repeatable)
 *   - award_points  : points granted on each target hit (single/repeatable)
 *   - rollup_to_general : whether campaign points also count to the season
 *   - recognize_top_n   : end-of-campaign top-N recognition
 *   - activity_scope    : which rule codes / activity types feed progress
 *
 * Progress is accumulated per subject; awards are granted idempotently via
 * UNIQUE(campaign_id, subject_id, award_index). For repeatable campaigns the
 * award_index is the target multiple (1,2,3…); for tiered campaigns it is the
 * tier position (1=silver, 2=gold, 3=diamond…). Closing a campaign snapshots a
 * configurable top-N recognition list.
 */
final class CampaignService
{
    private const METRICS    = ['points', 'amount', 'volume', 'count'];
    private const MODES      = ['single', 'repeatable', 'tiered'];
    private const TEAM_MODES = ['subtree', 'adhoc'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The fixed campaign vocabularies for admin form pickers (metric / award
     * mode / team mode). Exposes the private consts without leaking the class.
     *
     * @return array{metrics:list<string>,modes:list<string>,teamModes:list<string>}
     */
    public function vocab(): array
    {
        return [
            'metrics'   => self::METRICS,
            'modes'     => self::MODES,
            'teamModes' => self::TEAM_MODES,
        ];
    }

    /**
     * Create a campaign (draft). group_id is required — a campaign is always a
     * group project. Validates metric, target, and the time window.
     *
     * @param array<string,mixed> $data
     */
    public function create(string $organizationId, array $data): Result
    {
        $groupId = trim((string) ($data['group_id'] ?? ''));
        $code    = trim((string) ($data['code'] ?? ''));
        $name    = trim((string) ($data['name'] ?? ''));
        $metric  = (string) ($data['metric'] ?? 'points');
        $starts  = trim((string) ($data['starts_at'] ?? ''));
        $ends    = trim((string) ($data['ends_at'] ?? ''));

        // Award mode. Legacy `repeatable` flag maps onto award_mode for
        // backward compatibility if award_mode is not supplied.
        $mode = (string) ($data['award_mode'] ?? (! empty($data['repeatable']) ? 'repeatable' : 'single'));
        if (! in_array($mode, self::MODES, true)) {
            return Result::fail('BAD_AWARD_MODE', 'campaign.bad_award_mode', 422, ['allowed' => self::MODES]);
        }

        // Tiered campaigns carry their thresholds in campaign_tiers, so a single
        // target_value is optional; single/repeatable require a positive target.
        $target = (int) ($data['target_value'] ?? 0);

        if ($groupId === '' || $code === '' || $name === '') {
            return Result::fail('MISSING_FIELDS', 'campaign.missing_fields', 422);
        }
        if (! in_array($metric, self::METRICS, true)) {
            return Result::fail('BAD_METRIC', 'campaign.bad_metric', 422, ['allowed' => self::METRICS]);
        }
        if ($mode !== 'tiered' && $target <= 0) {
            return Result::fail('BAD_TARGET', 'campaign.bad_target', 422);
        }
        if ($starts === '' || $ends === '' || strtotime($ends) <= strtotime($starts)) {
            return Result::fail('BAD_WINDOW', 'campaign.bad_window', 422);
        }

        // The campaign is ALWAYS about the individual member doing more — the
        // award subject is the individual, and individual target/tiered awards
        // are the core mechanic. A TEAM CHALLENGE is an OPTIONAL side-competition
        // overlay: the same individual contributions ALSO tally into team totals.
        //   - team_mode "subtree" — teams are the owner group's subgroups;
        //   - team_mode "adhoc"   — teams are explicit rosters aggregated from
        //     members ANYWHERE in the hierarchy (defineTeam/addTeamMember).
        $teamChallenge = ! empty($data['team_challenge']);
        $teamMode      = (string) ($data['team_mode'] ?? 'subtree');
        if ($teamChallenge && ! in_array($teamMode, self::TEAM_MODES, true)) {
            return Result::fail('BAD_TEAM_MODE', 'campaign.bad_team_mode', 422, ['allowed' => self::TEAM_MODES]);
        }

        $repeatable = $mode === 'repeatable';
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();

        try {
            $this->db->table('group_campaigns')->insert([
                'id'                  => $id,
                'organization_id'     => $organizationId,
                'group_id'            => $groupId,
                'include_descendants' => ! empty($data['include_descendants']) ? 1 : 0,
                'season_id'           => $data['season_id'] ?? null,
                'code'                => $code,
                'name'                => mb_substr($name, 0, 200),
                'description'         => $data['description'] ?? null,
                'category'            => $data['category'] ?? null,
                'activity_scope'      => isset($data['activity_scope']) ? json_encode(array_values((array) $data['activity_scope'])) : null,
                'metric'              => $metric,
                'award_mode'          => $mode,
                'target_value'        => max($target, 0),
                'repeatable'          => $repeatable ? 1 : 0,
                'max_awards'          => $repeatable ? ($data['max_awards'] ?? null) : 1,
                'badge_code'          => $data['badge_code'] ?? null,
                'award_points'        => isset($data['award_points']) ? (int) $data['award_points'] : null,
                'rollup_to_general'   => ! empty($data['rollup_to_general']) ? 1 : 0,
                'rollup_awards'       => ! empty($data['rollup_awards']) ? 1 : 0,
                'recognize_top_n'     => isset($data['recognize_top_n']) ? (int) $data['recognize_top_n'] : null,
                'subject_type'        => 'user', // the individual member always competes
                'team_challenge'      => $teamChallenge ? 1 : 0,
                'team_mode'           => $teamMode,
                'recognize_top_teams' => isset($data['recognize_top_teams']) ? (int) $data['recognize_top_teams'] : null,
                // Group/team MILESTONE (single target + single award), parity
                // with individuals. Configurable at creation; only meaningful
                // when team_challenge=1. NULL team_target_value = teams keep a
                // running scoreboard only (no milestone award).
                'team_target_value'   => isset($data['team_target_value']) ? max(0, (int) $data['team_target_value']) : null,
                'team_badge_code'     => $data['team_badge_code'] ?? null,
                'team_award_points'   => isset($data['team_award_points']) ? (int) $data['team_award_points'] : null,
                'starts_at'           => date('Y-m-d H:i:s', (int) strtotime($starts)),
                'ends_at'             => date('Y-m-d H:i:s', (int) strtotime($ends)),
                'status'              => 'draft',
                'created_by'          => $data['created_by'] ?? null,
                'created_at'          => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('CAMPAIGN_EXISTS', 'campaign.exists', 409);
        }

        // Inline tier ladder (tiered mode): accept data['tiers'] = [ {code,name,
        // threshold_value, badge_code?, award_points?, icon?, color?}, ... ].
        if ($mode === 'tiered' && ! empty($data['tiers']) && is_array($data['tiers'])) {
            $pos = 0;
            foreach ($data['tiers'] as $tier) {
                $pos++;
                $this->insertTier($organizationId, $id, $pos, (array) $tier, $now);
            }
        }

        return Result::created(['campaign_id' => $id, 'code' => $code, 'status' => 'draft', 'award_mode' => $mode]);
    }

    /**
     * Read one campaign by id, with its tier ladder and (ad hoc) teams inlined.
     * `activity_scope` is decoded back to an array for the client.
     */
    public function show(string $organizationId, string $campaignId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }

        $c['activity_scope'] = $c['activity_scope'] !== null && $c['activity_scope'] !== ''
            ? (json_decode((string) $c['activity_scope'], true) ?: [])
            : [];
        $c['tiers'] = $this->tiers($organizationId, $campaignId);
        $c['teams'] = ! empty($c['team_challenge']) && ($c['team_mode'] ?? 'subtree') === 'adhoc'
            ? $this->listTeams($organizationId, $campaignId)
            : [];

        return Result::ok($c);
    }

    /**
     * Update a DRAFT campaign's editable fields. Only draft campaigns may be
     * edited — once active/completed/cancelled the rules are frozen (a running
     * competition must not have its target/window/scope changed underneath
     * participants). Identity (`code`, `group_id`), award_mode and subject_type
     * are immutable even in draft; recreate for those.
     *
     * Editable: name, description, category, activity_scope, metric,
     * target_value, starts_at, ends_at, season_id, include_descendants,
     * badge_code, award_points, rollup_to_general, recognize_top_n, max_awards,
     * and the team-overlay fields (team_challenge, team_mode, recognize_top_teams,
     * team_target_value, team_badge_code, team_award_points).
     *
     * @param array<string,mixed> $data partial set of editable fields
     */
    public function update(string $organizationId, string $campaignId, array $data): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status'], 'expected' => 'draft']);
        }

        $set = [];

        // Simple pass-through strings / nullable fields.
        foreach (['description', 'category', 'badge_code', 'team_badge_code'] as $k) {
            if (array_key_exists($k, $data)) {
                $set[$k] = $data[$k] !== null && $data[$k] !== '' ? (string) $data[$k] : null;
            }
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return Result::fail('MISSING_FIELDS', 'campaign.missing_fields', 422);
            }
            $set['name'] = mb_substr($name, 0, 200);
        }
        if (array_key_exists('metric', $data)) {
            $metric = (string) $data['metric'];
            if (! in_array($metric, self::METRICS, true)) {
                return Result::fail('BAD_METRIC', 'campaign.bad_metric', 422, ['allowed' => self::METRICS]);
            }
            $set['metric'] = $metric;
        }
        if (array_key_exists('activity_scope', $data)) {
            $set['activity_scope'] = $data['activity_scope'] !== null
                ? json_encode(array_values((array) $data['activity_scope']))
                : null;
        }

        // Target: only meaningful for single/repeatable; must stay positive
        // there (tiered carries thresholds in its ladder).
        if (array_key_exists('target_value', $data)) {
            $target = (int) $data['target_value'];
            if (($c['award_mode'] ?? 'single') !== 'tiered' && $target <= 0) {
                return Result::fail('BAD_TARGET', 'campaign.bad_target', 422);
            }
            $set['target_value'] = max($target, 0);
        }

        // Window: validate the resulting pair (allow changing one side).
        if (array_key_exists('starts_at', $data) || array_key_exists('ends_at', $data)) {
            $starts = trim((string) ($data['starts_at'] ?? $c['starts_at']));
            $ends   = trim((string) ($data['ends_at'] ?? $c['ends_at']));
            if ($starts === '' || $ends === '' || strtotime($ends) <= strtotime($starts)) {
                return Result::fail('BAD_WINDOW', 'campaign.bad_window', 422);
            }
            $set['starts_at'] = date('Y-m-d H:i:s', (int) strtotime($starts));
            $set['ends_at']   = date('Y-m-d H:i:s', (int) strtotime($ends));
        }

        // Team overlay + numeric knobs.
        if (array_key_exists('team_challenge', $data)) {
            $set['team_challenge'] = ! empty($data['team_challenge']) ? 1 : 0;
        }
        if (array_key_exists('team_mode', $data)) {
            $teamMode = (string) $data['team_mode'];
            if (! in_array($teamMode, self::TEAM_MODES, true)) {
                return Result::fail('BAD_TEAM_MODE', 'campaign.bad_team_mode', 422, ['allowed' => self::TEAM_MODES]);
            }
            $set['team_mode'] = $teamMode;
        }
        if (array_key_exists('include_descendants', $data)) {
            $set['include_descendants'] = ! empty($data['include_descendants']) ? 1 : 0;
        }
        if (array_key_exists('rollup_to_general', $data)) {
            $set['rollup_to_general'] = ! empty($data['rollup_to_general']) ? 1 : 0;
        }
        if (array_key_exists('rollup_awards', $data)) {
            $set['rollup_awards'] = ! empty($data['rollup_awards']) ? 1 : 0;
        }
        if (array_key_exists('season_id', $data)) {
            $set['season_id'] = $data['season_id'] !== null && $data['season_id'] !== '' ? (string) $data['season_id'] : null;
        }
        foreach (['award_points', 'recognize_top_n', 'recognize_top_teams', 'team_target_value', 'team_award_points', 'max_awards'] as $k) {
            if (array_key_exists($k, $data)) {
                $set[$k] = $data[$k] !== null ? max(0, (int) $data[$k]) : null;
            }
        }

        if ($set === []) {
            return Result::ok(['campaign_id' => $campaignId, 'updated' => 0]);
        }

        $set['updated_at'] = $this->clock->nowUtcMicro();
        $this->db->table('group_campaigns')
            ->where('organization_id', $organizationId)->where('id', $campaignId)
            ->update($set);

        return Result::ok(['campaign_id' => $campaignId, 'updated' => count($set) - 1]);
    }

    /**
     * Define (append) a tier on a tiered campaign — e.g. silver → gold →
     * diamond. Tier position is assigned in ascending threshold order across
     * the campaign's existing tiers. Only valid before the campaign is active.
     *
     * @param array<string,mixed> $tier code, name, threshold_value, badge_code?,
     *                                   award_points?, icon?, color?
     */
    public function defineTier(string $organizationId, string $campaignId, array $tier): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['award_mode'] !== 'tiered') {
            return Result::fail('NOT_TIERED', 'campaign.not_tiered', 409);
        }
        if ($c['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status']]);
        }
        if (trim((string) ($tier['code'] ?? '')) === '' || trim((string) ($tier['name'] ?? '')) === '' || (int) ($tier['threshold_value'] ?? 0) <= 0) {
            return Result::fail('BAD_TIER', 'campaign.bad_tier', 422);
        }

        $count = $this->db->table('campaign_tiers')->where('campaign_id', $campaignId)->countAllResults();

        try {
            $id = $this->insertTier($organizationId, $campaignId, $count + 1, $tier, $this->clock->nowUtcMicro());
        } catch (Throwable) {
            return Result::fail('TIER_EXISTS', 'campaign.tier_exists', 409);
        }

        return Result::created(['tier_id' => $id, 'code' => $tier['code'], 'tier_position' => $count + 1]);
    }

    /** @return list<array<string,mixed>> Tier ladder ascending by threshold. */
    public function tiers(string $organizationId, string $campaignId): array
    {
        return $this->db->table('campaign_tiers')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('threshold_value', 'ASC')->orderBy('tier_position', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Remove a single tier from a tiered campaign's ladder. Only valid while the
     * campaign is still a draft (once active, the ladder is frozen so already
     * granted tier awards stay meaningful).
     */
    public function deleteTier(string $organizationId, string $campaignId, string $tierId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status'], 'expected' => 'draft']);
        }

        $tier = $this->db->table('campaign_tiers')
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->where('id', $tierId)
            ->get()->getRowArray();
        if ($tier === null) {
            return Result::notFound('campaign.tier_not_found', 'TIER_NOT_FOUND');
        }

        $this->db->table('campaign_tiers')
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->where('id', $tierId)
            ->delete();

        // Re-pack tier_position so the ladder stays 1..N contiguous.
        $remaining = $this->db->table('campaign_tiers')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('threshold_value', 'ASC')->orderBy('tier_position', 'ASC')
            ->get()->getResultArray();
        $pos = 0;
        foreach ($remaining as $r) {
            $pos++;
            if ((int) $r['tier_position'] !== $pos) {
                $this->db->table('campaign_tiers')->where('id', $r['id'])->update(['tier_position' => $pos]);
            }
        }

        return Result::ok(['deleted' => 1, 'tier_id' => $tierId, 'remaining' => $pos]);
    }

    // -------------------------------------------------------------------------
    // Ad hoc teams (rosters aggregated across the hierarchy)
    // -------------------------------------------------------------------------

    /**
     * Define an ad hoc team on a group campaign that uses team_mode="adhoc".
     * A team is an explicit roster (populated via addTeamMember) rather than a
     * tree subgroup. Editable while the campaign is draft or active.
     *
     * @param array<string,mixed> $data code, name, captain_user_id?, color?, icon?
     */
    public function defineTeam(string $organizationId, string $campaignId, array $data): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if (empty($c['team_challenge']) || ($c['team_mode'] ?? 'subtree') !== 'adhoc') {
            return Result::fail('NOT_ADHOC', 'campaign.not_adhoc', 409);
        }
        if (in_array($c['status'], ['completed', 'cancelled'], true)) {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status']]);
        }
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_TEAM', 'campaign.bad_team', 422);
        }

        $id = Uuid::v7();
        try {
            $this->db->table('campaign_teams')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'campaign_id'     => $campaignId,
                'code'            => mb_substr($code, 0, 80),
                'name'            => mb_substr($name, 0, 200),
                'captain_user_id' => $data['captain_user_id'] ?? null,
                'color'           => $data['color'] ?? null,
                'icon'            => $data['icon'] ?? null,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);
        } catch (Throwable) {
            return Result::fail('TEAM_EXISTS', 'campaign.team_exists', 409);
        }

        return Result::created(['team_id' => $id, 'code' => $code]);
    }

    /**
     * Rename / re-style an ad hoc team (name, code, color, icon, captain). Only
     * while the campaign is draft or active (not completed/cancelled). `code`
     * stays unique per campaign.
     *
     * @param array<string,mixed> $data name?, code?, color?, icon?, captain_user_id?
     */
    public function updateTeam(string $organizationId, string $campaignId, string $teamId, array $data): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if (empty($c['team_challenge']) || ($c['team_mode'] ?? 'subtree') !== 'adhoc') {
            return Result::fail('NOT_ADHOC', 'campaign.not_adhoc', 409);
        }
        if (in_array($c['status'], ['completed', 'cancelled'], true)) {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status']]);
        }

        $team = $this->db->table('campaign_teams')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('id', $teamId)
            ->get()->getRowArray();
        if ($team === null) {
            return Result::notFound('campaign.team_not_found', 'TEAM_NOT_FOUND');
        }

        $set = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return Result::fail('BAD_TEAM', 'campaign.bad_team', 422);
            }
            $set['name'] = mb_substr($name, 0, 200);
        }
        if (array_key_exists('code', $data)) {
            $code = trim((string) $data['code']);
            if ($code === '') {
                return Result::fail('BAD_TEAM', 'campaign.bad_team', 422);
            }
            $set['code'] = mb_substr($code, 0, 80);
        }
        foreach (['color', 'icon', 'captain_user_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $set[$k] = $data[$k] !== null && $data[$k] !== '' ? (string) $data[$k] : null;
            }
        }

        if ($set === []) {
            return Result::ok(['team_id' => $teamId, 'updated' => 0]);
        }

        try {
            $this->db->table('campaign_teams')->where('id', $teamId)->update($set);
        } catch (Throwable) {
            return Result::fail('TEAM_EXISTS', 'campaign.team_exists', 409);
        }

        return Result::ok(['team_id' => $teamId, 'updated' => count($set)]);
    }

    /**
     * Delete an ad hoc team and its roster. Only while the campaign is a DRAFT —
     * once active, its members may already have tallied into standings, so a
     * team must not vanish. Also blocks deletion when the team already has a
     * standings row (defensive; a draft normally has none).
     */
    public function deleteTeam(string $organizationId, string $campaignId, string $teamId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if (empty($c['team_challenge']) || ($c['team_mode'] ?? 'subtree') !== 'adhoc') {
            return Result::fail('NOT_ADHOC', 'campaign.not_adhoc', 409);
        }
        if ($c['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status'], 'expected' => 'draft']);
        }

        $team = $this->db->table('campaign_teams')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('id', $teamId)
            ->get()->getRowArray();
        if ($team === null) {
            return Result::notFound('campaign.team_not_found', 'TEAM_NOT_FOUND');
        }

        $this->db->transStart();
        $this->db->table('campaign_team_members')
            ->where('campaign_id', $campaignId)->where('team_id', $teamId)->delete();
        $this->db->table('campaign_teams')->where('id', $teamId)->delete();
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('DELETE_FAILED', 'campaign.team_delete_failed', 500);
        }

        return Result::ok(['team_id' => $teamId, 'deleted' => true]);
    }

    /**
     * Add a member to an ad hoc team. A user belongs to at most ONE team per
     * campaign (enforced by ctm_user_uq), so activity accrues unambiguously.
     * The user may be drawn from any group in the hierarchy.
     */
    public function addTeamMember(string $organizationId, string $campaignId, string $teamId, string $userId, ?string $addedBy = null): Result
    {
        if ($userId === '' || $teamId === '') {
            return Result::fail('MISSING_FIELDS', 'campaign.missing_fields', 422);
        }
        $team = $this->db->table('campaign_teams')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('id', $teamId)
            ->get()->getRowArray();
        if ($team === null) {
            return Result::notFound('campaign.team_not_found', 'TEAM_NOT_FOUND');
        }

        try {
            $this->db->table('campaign_team_members')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'campaign_id'     => $campaignId,
                'team_id'         => $teamId,
                'user_id'         => $userId,
                'added_by'        => $addedBy,
                'created_at'      => $this->clock->nowUtcMicro(),
            ]);
        } catch (Throwable) {
            return Result::fail('ALREADY_ON_A_TEAM', 'campaign.user_already_on_team', 409);
        }

        return Result::created(['team_id' => $teamId, 'user_id' => $userId]);
    }

    /** Remove a member from any ad hoc team on the campaign. */
    public function removeTeamMember(string $organizationId, string $campaignId, string $userId): Result
    {
        $this->db->table('campaign_team_members')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)->where('user_id', $userId)
            ->delete();

        return Result::ok(['campaign_id' => $campaignId, 'user_id' => $userId, 'removed' => true]);
    }

    /**
     * Ad hoc teams on a campaign, each with its current member count.
     *
     * @return list<array<string,mixed>>
     */
    public function listTeams(string $organizationId, string $campaignId): array
    {
        $teams = $this->db->table('campaign_teams')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('name', 'ASC')->get()->getResultArray();
        foreach ($teams as &$t) {
            $t['member_count'] = $this->db->table('campaign_team_members')
                ->where('team_id', $t['id'])->countAllResults();
        }
        unset($t);

        return $teams;
    }

    /**
     * Ad hoc teams on a campaign, each with its resolved member ROSTER (user_id
     * + display_name), for the team-management console. Names are resolved in ONE
     * bounded `users` query (no per-member lookup): read every roster row, union
     * the distinct user ids, map ids→display_name in memory. Read cost is
     * O(members) + O(1) queries, independent of team/member count.
     *
     * @return list<array<string,mixed>> each team + ['members' => list<{user_id,display_name,added_at}>]
     */
    public function teamsWithRosters(string $organizationId, string $campaignId): array
    {
        $teams = $this->db->table('campaign_teams')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('name', 'ASC')->get()->getResultArray();
        if ($teams === []) {
            return [];
        }

        $members = $this->db->table('campaign_team_members')
            ->select('team_id, user_id, created_at')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('created_at', 'ASC')->get()->getResultArray();

        // ONE users query resolves every referenced display name.
        $names = [];
        $ids   = array_values(array_unique(array_map(static fn ($m): string => (string) $m['user_id'], $members)));
        if ($ids !== []) {
            $rows = $this->db->table('users')->select('id, display_name')
                ->where('organization_id', $organizationId)->whereIn('id', $ids)
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $names[(string) $r['id']] = (string) ($r['display_name'] ?? '');
            }
        }

        $byTeam = [];
        foreach ($members as $m) {
            $uid                       = (string) $m['user_id'];
            $byTeam[(string) $m['team_id']][] = [
                'user_id'      => $uid,
                'display_name' => $names[$uid] ?? '',
                'added_at'     => $m['created_at'] ?? null,
            ];
        }

        foreach ($teams as &$t) {
            $t['members']      = $byTeam[(string) $t['id']] ?? [];
            $t['member_count'] = count($t['members']);
        }
        unset($t);

        return $teams;
    }

    /** Move a draft campaign to active. Tiered campaigns need >=1 tier; ad hoc
     * team campaigns need >=1 team. */
    public function activate(string $organizationId, string $campaignId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['award_mode'] === 'tiered'
            && $this->db->table('campaign_tiers')->where('campaign_id', $campaignId)->countAllResults() === 0) {
            return Result::fail('NO_TIERS', 'campaign.no_tiers', 422);
        }
        if (! empty($c['team_challenge']) && ($c['team_mode'] ?? 'subtree') === 'adhoc'
            && $this->db->table('campaign_teams')->where('campaign_id', $campaignId)->countAllResults() === 0) {
            return Result::fail('NO_TEAMS', 'campaign.no_teams', 422);
        }

        return $this->transition($organizationId, $campaignId, 'draft', 'active');
    }

    /** Cancel a campaign (from draft or active). */
    public function cancel(string $organizationId, string $campaignId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if (in_array($c['status'], ['completed', 'cancelled'], true)) {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status']]);
        }
        $this->db->table('group_campaigns')->where('id', $campaignId)->update([
            'status' => 'cancelled', 'updated_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['campaign_id' => $campaignId, 'status' => 'cancelled']);
    }

    /**
     * Record progress toward a campaign for a subject and grant target awards
     * (badge/points) idempotently for each target multiple crossed.
     *
     * `sourceRef` makes the call idempotent per contributing domain event: the
     * same (campaign, subject, sourceRef) increment is applied at most once.
     *
     * @param array<string,mixed> $opts  sourceRef (recommended), subject_type
     */
    public function recordProgress(string $organizationId, string $campaignId, string $subjectId, int $amount, array $opts = []): Result
    {
        if ($amount <= 0) {
            return Result::fail('BAD_AMOUNT', 'campaign.bad_amount', 422);
        }
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== 'active') {
            return Result::fail('NOT_ACTIVE', 'campaign.not_active', 409, ['status' => $c['status']]);
        }
        $now      = $this->clock->nowUtcMicro();
        $nowSecs  = $this->clock->nowUtcString(); // second precision, matches DATETIME columns
        if ($nowSecs < $c['starts_at'] || $nowSecs > $c['ends_at']) {
            return Result::fail('OUT_OF_WINDOW', 'campaign.out_of_window', 409);
        }

        $subjectType = 'user'; // the individual member is always the subject
        $teamRef     = isset($opts['team_ref']) && $opts['team_ref'] !== '' ? (string) $opts['team_ref'] : null;
        $teamKind    = ($opts['team_kind'] ?? 'group') === 'team' ? 'team' : 'group';

        // Exactly-once idempotency: claim this (campaign, subject, source_ref)
        // in campaign_progress_events. A replayed event clashes on the UNIQUE
        // key and is skipped. This is robust to out-of-order replays and to many
        // members feeding the SAME team row (group campaigns), unlike a simple
        // "last ref" compare. A blank source_ref opts out of dedup (manual use).
        $sourceRef = (string) ($opts['sourceRef'] ?? '');
        if ($sourceRef !== '') {
            try {
                $this->db->table('campaign_progress_events')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'campaign_id'     => $campaignId,
                    'subject_id'      => $subjectId,
                    'source_ref'      => $sourceRef,
                    'amount'          => $amount,
                    'created_at'      => $this->clock->nowUtcMicro(),
                ]);
            } catch (Throwable) {
                $existing = $this->db->table('campaign_progress')
                    ->where('campaign_id', $campaignId)->where('subject_id', $subjectId)
                    ->get()->getRowArray();

                return Result::ok([
                    'campaign_id'   => $campaignId,
                    'current_value' => (int) ($existing['current_value'] ?? 0),
                    'awards_count'  => (int) ($existing['awards_count'] ?? 0),
                ], 200, ['deduplicated' => true]);
            }
        }

        $this->db->transStart();

        $row = $this->db->table('campaign_progress')
            ->where('campaign_id', $campaignId)->where('subject_id', $subjectId)
            ->get()->getRowArray();

        $prevValue  = $row !== null ? (int) $row['current_value'] : 0;
        $prevAwards = $row !== null ? (int) $row['awards_count'] : 0;
        $newValue   = $prevValue + $amount;

        if ($row === null) {
            // Denormalize the member's most-specific hierarchical group ONCE, at
            // (rare) write time, so leaderboard reads stay O(1) per row. Prefer a
            // caller-supplied value (the activity feed already knows the member's
            // groups → zero extra cost); otherwise one cheap indexed lookup.
            $subjectGroupId = isset($opts['subject_group_id']) && $opts['subject_group_id'] !== ''
                ? (string) $opts['subject_group_id']
                : $this->mostSpecificGroup($organizationId, $subjectId);

            $this->db->table('campaign_progress')->insert([
                'id'               => Uuid::v7(),
                'organization_id'  => $organizationId,
                'campaign_id'      => $campaignId,
                'subject_id'       => $subjectId,
                'subject_type'     => $subjectType,
                'current_value'    => $newValue,
                'awards_count'     => $prevAwards,
                'team_ref'         => $teamRef,
                'subject_group_id' => $subjectGroupId,
                'last_source_ref'  => $sourceRef !== '' ? $sourceRef : null,
                'last_progress_at' => $now,
                'updated_at'       => $now,
            ]);
        } else {
            $this->db->table('campaign_progress')->where('id', $row['id'])->update([
                'current_value'    => $newValue,
                'team_ref'         => $teamRef ?? ($row['team_ref'] ?? null),
                'last_source_ref'  => $sourceRef !== '' ? $sourceRef : $row['last_source_ref'] ?? null,
                'last_progress_at' => $now,
                'updated_at'       => $now,
            ]);
        }

        // Optional TEAM CHALLENGE: tally this same contribution into the
        // member's team total. `contributors` counts DISTINCT members, so it is
        // incremented only the first time this member contributes to the team
        // (detected by the individual having had no team_ref / no prior row).
        if ($teamRef !== null) {
            $firstForMember = $row === null || ($row['team_ref'] ?? null) === null;
            $this->tallyTeam($organizationId, $c, $teamRef, $teamKind, $amount, $firstForMember, $now);

            // ANCESTOR AWARD ROLL-UP (OPT-IN, decision iii). When rollup_awards=1
            // on a subtree campaign, the SAME contribution also tallies into
            // EVERY group on the member's own ancestor-or-self path that lies
            // within the campaign owner's subtree — i.e. from the member's own
            // MOST-SPECIFIC (leaf) group all the way up to and including the
            // campaign owner. Each such group is a first-class milestone subject
            // that mints its own single award at team_target_value (parity with
            // individuals). rollup_awards=0 keeps today's behaviour (only the
            // single direct-child team tallies).
            //
            // The member's leaf group is the denormalized subject_group_id (from
            // opts, the progress row, or a cheap lookup). When it is unknown we
            // fall back to the pre-leaf behaviour (above the direct-child team
            // only) so a group-less/edge contribution never mis-mints.
            if (! empty($c['rollup_awards']) && $teamKind === 'group' && ($c['team_mode'] ?? 'subtree') === 'subtree') {
                $memberLeaf = isset($opts['subject_group_id']) && $opts['subject_group_id'] !== ''
                    ? (string) $opts['subject_group_id']
                    : ($row !== null ? ($row['subject_group_id'] ?? null) : ($subjectGroupId ?? null));

                $chain = $memberLeaf !== null && $memberLeaf !== ''
                    ? $this->milestoneAwardChain($organizationId, (string) $c['group_id'], (string) $memberLeaf, $teamRef)
                    : $this->ancestorAwardChain($organizationId, (string) $c['group_id'], $teamRef);

                foreach ($chain as $milestoneRef) {
                    $this->tallyTeam($organizationId, $c, $milestoneRef, 'group', $amount, $firstForMember, $now);
                }
            }
        }

        // Grant any awards now earned, per the campaign's award_mode.
        $granted  = [];
        $deserved = $prevAwards;

        if (($c['award_mode'] ?? 'single') === 'tiered') {
            // Each tier reached (threshold <= newValue) is granted once, keyed
            // by its tier_position as the award_index.
            $tiers      = $this->tiers($organizationId, $campaignId);
            $alreadyIdx = $this->grantedIndexes($campaignId, $subjectId);
            foreach (self::tiersReached($newValue, $tiers, $alreadyIdx) as $tier) {
                $granted[] = $this->grantTierAward($organizationId, $c, $tier, $subjectId, $subjectType, $newValue, $now);
            }
            $deserved = $prevAwards + count($granted);
        } else {
            // single / repeatable: award per target multiple crossed.
            $target   = (int) $c['target_value'];
            $deserved = self::awardsDeserved($newValue, $target, (bool) $c['repeatable'], $c['max_awards'] !== null ? (int) $c['max_awards'] : null);
            for ($i = $prevAwards + 1; $i <= $deserved; $i++) {
                $granted[] = $this->grantAward($organizationId, $c, $subjectId, $subjectType, $i, $newValue, $now);
            }
        }

        if ($granted !== []) {
            $this->db->table('campaign_progress')->where('campaign_id', $campaignId)->where('subject_id', $subjectId)
                ->update(['awards_count' => $deserved]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('PROGRESS_FAILED', 'campaign.progress_failed', 500);
        }

        return Result::ok([
            'campaign_id'    => $campaignId,
            'current_value'  => $newValue,
            'awards_count'   => $deserved,
            'awards_granted' => count($granted),
            'granted'        => $granted,
        ]);
    }

    /**
     * Pure: how many target multiples a cumulative value earns.
     * Non-repeatable campaigns cap at 1; repeatable ones cap at max_awards
     * (when set). Exposed for unit testing.
     */
    public static function awardsDeserved(int $value, int $target, bool $repeatable, ?int $maxAwards): int
    {
        if ($target <= 0 || $value < $target) {
            return 0;
        }
        $times = intdiv($value, $target);
        if (! $repeatable) {
            return 1;
        }
        if ($maxAwards !== null) {
            return min($times, $maxAwards);
        }

        return $times;
    }

    /**
     * Pure: which tiers a cumulative value newly reaches, excluding those whose
     * tier_position is already granted. Returns the tier rows to grant, in
     * ascending position. Exposed for unit testing.
     *
     * @param list<array<string,mixed>> $tiers      ordered ascending by threshold
     * @param list<int>                 $alreadyIdx already-granted tier positions
     *
     * @return list<array<string,mixed>>
     */
    public static function tiersReached(int $value, array $tiers, array $alreadyIdx): array
    {
        $out = [];
        foreach ($tiers as $tier) {
            $pos = (int) ($tier['tier_position'] ?? 0);
            if ($value >= (int) ($tier['threshold_value'] ?? 0) && ! in_array($pos, $alreadyIdx, true)) {
                $out[] = $tier;
            }
        }

        return $out;
    }

    /**
     * Close a campaign: mark completed and snapshot the top-N recognition list
     * (when recognize_top_n is set). Idempotent.
     */
    public function close(string $organizationId, string $campaignId): Result
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] === 'completed') {
            return Result::ok(['campaign_id' => $campaignId, 'status' => 'completed'], 200, ['deduplicated' => true]);
        }
        if ($c['status'] === 'cancelled') {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => 'cancelled']);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->transStart();

        // Recognize the top INDIVIDUAL members (the core purpose).
        $topN = $c['recognize_top_n'] !== null ? (int) $c['recognize_top_n'] : 0;
        $recognized = 0;
        if ($topN > 0) {
            $leaders = $this->db->table('campaign_progress')
                ->select('subject_id, current_value')
                ->where('campaign_id', $campaignId)
                ->orderBy('current_value', 'DESC')
                ->limit($topN)
                ->get()->getResultArray();
            $pos = 0;
            foreach ($leaders as $l) {
                $pos++;
                try {
                    $this->db->table('campaign_recognitions')->insert([
                        'id'              => Uuid::v7(),
                        'organization_id' => $organizationId,
                        'campaign_id'     => $campaignId,
                        'subject_id'      => $l['subject_id'],
                        'subject_type'    => 'user',
                        'rank_position'   => $pos,
                        'final_value'     => (int) $l['current_value'],
                        'created_at'      => $now,
                    ]);
                    $recognized++;
                } catch (Throwable) {
                    // already recognized (idempotent re-close)
                }
            }
        }

        // Optionally recognize the top TEAMS from the team challenge. Stored in
        // the same table with a team-kind subject_type; a UNIQUE(campaign,
        // subject) means an individual and a team never collide on ids.
        $recognizedTeams = 0;
        $topTeams = $c['recognize_top_teams'] !== null ? (int) $c['recognize_top_teams'] : 0;
        if (! empty($c['team_challenge']) && $topTeams > 0) {
            $teamLeaders = $this->db->table('campaign_team_standings')
                ->select('team_ref, team_kind, total_value')
                ->where('campaign_id', $campaignId)
                ->orderBy('total_value', 'DESC')
                ->limit($topTeams)
                ->get()->getResultArray();
            $pos = 0;
            foreach ($teamLeaders as $t) {
                $pos++;
                try {
                    $this->db->table('campaign_recognitions')->insert([
                        'id'              => Uuid::v7(),
                        'organization_id' => $organizationId,
                        'campaign_id'     => $campaignId,
                        'subject_id'      => $t['team_ref'],
                        'subject_type'    => $t['team_kind'] === 'team' ? 'team' : 'group',
                        'rank_position'   => $pos,
                        'final_value'     => (int) $t['total_value'],
                        'created_at'      => $now,
                    ]);
                    $recognizedTeams++;
                } catch (Throwable) {
                    // already recognized (idempotent re-close)
                }
            }
        }

        $this->db->table('group_campaigns')->where('id', $campaignId)->update([
            'status' => 'completed', 'completed_at' => $now, 'updated_at' => $now,
        ]);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('CLOSE_FAILED', 'campaign.close_failed', 500);
        }

        return Result::ok([
            'campaign_id'      => $campaignId,
            'status'           => 'completed',
            'recognized'       => $recognized,
            'recognized_teams' => $recognizedTeams,
        ]);
    }

    /**
     * Campaign leaderboard (top subjects by accumulated metric).
     *
     * @return list<array<string,mixed>>
     */
    public function leaderboard(string $organizationId, string $campaignId, int $limit = 20): array
    {
        // The individual member is always the subject. Per the leaderboard
        // privacy rule we return subject_id + metric only (no PII). The optional
        // team side-competition is exposed separately via teamStandings().
        //
        // Each row carries the member's MOST-SPECIFIC hierarchical group
        // (denormalized at write time). To render the full group PATH we do NOT
        // recompute ancestry per row: groups.path already materializes the
        // ancestor UUID chain, and the org's groups form a tiny, bounded set —
        // so ONE additional indexed query loads every needed group name, and we
        // stitch paths in memory. Read cost is therefore O(rows) + O(1) queries,
        // independent of the concurrent-user count.
        $rows = $this->db->table('campaign_progress')
            ->select('subject_id, current_value, awards_count, team_ref, subject_group_id')
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->orderBy('current_value', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()->getResultArray();

        $this->attachGroupPaths($organizationId, $rows);

        return $rows;
    }

    /**
     * Attach `group_id`, `group_name` (leaf) and `group_path` (list of ancestor
     * names, root→leaf) to leaderboard rows, in a fixed number of queries.
     *
     * Strategy: read the leaf groups' materialized `path` (root→leaf UUID
     * chain), union every id referenced, then ONE indexed `groups` query maps
     * ids→names. No per-row query, no recomputation of ancestry.
     *
     * @param list<array<string,mixed>> $rows modified in place
     */
    private function attachGroupPaths(string $organizationId, array &$rows): void
    {
        $leafIds = [];
        foreach ($rows as $r) {
            if (! empty($r['subject_group_id'])) {
                $leafIds[(string) $r['subject_group_id']] = true;
            }
        }
        if ($leafIds === []) {
            foreach ($rows as &$r) {
                $r['group_id']   = $r['subject_group_id'] ?? null;
                $r['group_name'] = null;
                $r['group_path'] = [];
            }
            unset($r);

            return;
        }

        // Materialized ancestor chains for the leaf groups (one indexed query).
        $pathById = [];
        foreach (
            $this->db->table('groups')
                ->select('id, path')
                ->where('organization_id', $organizationId)
                ->whereIn('id', array_keys($leafIds))
                ->get()->getResultArray() as $g
        ) {
            $pathById[(string) $g['id']] = self::parsePathIds((string) $g['path']);
        }

        // Union of every ancestor id we must name, then ONE names query.
        $need = [];
        foreach ($pathById as $chain) {
            foreach ($chain as $id) {
                $need[$id] = true;
            }
        }
        $nameById = [];
        if ($need !== []) {
            foreach (
                $this->db->table('groups')
                    ->select('id, name')
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', array_keys($need))
                    ->get()->getResultArray() as $g
            ) {
                $nameById[(string) $g['id']] = (string) $g['name'];
            }
        }

        foreach ($rows as &$r) {
            $leaf = ! empty($r['subject_group_id']) ? (string) $r['subject_group_id'] : null;
            $r['group_id']   = $leaf;
            $r['group_path'] = self::groupPathNames($pathById[$leaf] ?? [], $nameById);
            $r['group_name'] = $r['group_path'] !== [] ? end($r['group_path']) : null;
        }
        unset($r);
    }

    /**
     * Pure: parse a materialized `groups.path` ("/uuidA/uuidB/uuidC/") into an
     * ordered list of ancestor ids (root→leaf). Exposed for unit testing.
     *
     * @return list<string>
     */
    public static function parsePathIds(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn ($s): bool => $s !== ''));
    }

    /**
     * Pure: map an ordered id chain to an ordered list of names, skipping ids
     * with no known name. Exposed for unit testing.
     *
     * @param list<string>          $chain    ancestor ids root→leaf
     * @param array<string,string>  $nameById id→name lookup
     *
     * @return list<string>
     */
    public static function groupPathNames(array $chain, array $nameById): array
    {
        $out = [];
        foreach ($chain as $id) {
            if (isset($nameById[$id])) {
                $out[] = $nameById[$id];
            }
        }

        return $out;
    }

    /**
     * HOOK: feed a verified domain activity into every campaign the subject is
     * eligible for. Called from the central award chokepoint (PointsEngine) so
     * that a single verified event advances the general season AND any group
     * projects that bundle that activity.
     *
     * Eligibility: an active, in-window campaign whose `activity_scope` includes
     * $activityCode (or is empty = all activities) AND whose owning group covers
     * the subject — the subject is a direct member of the campaign's group, or
     * (when include_descendants) a member of one of its subgroups.
     *
     * The increment applied is the campaign's own metric drawn from $metrics:
     *   count → 1, points → points awarded, amount → amount_minor, volume → volume.
     *
     * Idempotent: recordProgress() dedupes by $sourceRef per (campaign, subject).
     * Best-effort and self-contained — never throws into the caller.
     *
     * @param array<string,int> $metrics count|points|amount|volume values
     *
     * @return array{fed:int,campaigns:list<string>}
     */
    public function feedFromActivity(string $organizationId, string $subjectId, string $activityCode, array $metrics, string $sourceRef, array $opts = []): array
    {
        $fed = [];
        try {
            // Group-tree campaigns are matched via the user's group memberships;
            // ad hoc team campaigns are matched via explicit rosters (a user may
            // be on a roster without any group membership at all).
            $directIds = $this->subjectGroupIds($organizationId, $subjectId);
            $campaigns = $this->matchingCampaigns($organizationId, $subjectId, $directIds, $activityCode);

            foreach ($campaigns as $c) {
                $metricKey = (string) $c['metric'];
                $inc       = (int) ($metrics[$metricKey] ?? ($metricKey === 'count' ? 1 : 0));
                if ($inc <= 0) {
                    continue;
                }

                // The individual member is ALWAYS the subject — this is what
                // drives them to do more (their own progress + target/tiered
                // awards). When the campaign has an optional TEAM CHALLENGE, the
                // same contribution ALSO tallies into their team's side total.
                // Attribution follows WHERE THE CONTRIBUTION WENT. If the event
                // designates an explicit target group/team (e.g. a donation
                // earmarked to a specific group), that is credited. Only when
                // the event carries no designation do we fall back to the
                // member's own group membership.
                $teamRef  = null;
                $teamKind = null;
                if (! empty($c['team_challenge'])) {
                    if (isset($opts['target_ref']) && $opts['target_ref'] !== '') {
                        $teamRef  = (string) $opts['target_ref'];
                        $teamKind = ($opts['target_kind'] ?? 'group') === 'team' ? 'team' : 'group';
                    } else {
                        [$teamRef, $teamKind] = $this->memberTeam($organizationId, $c, $subjectId, $directIds);
                    }
                }

                // Namespace the source_ref per campaign so distinct campaigns
                // fed by the same event each record once.
                $res = $this->recordProgress($organizationId, (string) $c['id'], $subjectId, $inc, [
                    'sourceRef'    => $sourceRef !== '' ? $sourceRef . ':' . $c['id'] : '',
                    'subject_type' => 'user',
                    'team_ref'     => $teamRef,
                    'team_kind'    => $teamKind,
                ]);
                if ($res->ok) {
                    $fed[] = (string) $c['id'];
                }
            }
        } catch (Throwable) {
            // Feeding is best-effort; the primary award already succeeded.
        }

        return ['fed' => count($fed), 'campaigns' => $fed];
    }

    /**
     * Resolve the single team a member tallies into for a team-challenge
     * campaign. Ad hoc → their roster team (0/1). Subtree → the owner-child
     * subgroup they roll up to (the first, if under several). Returns
     * [team_ref|null, team_kind|null] where team_kind is 'team' (ad hoc, a
     * campaign_teams id) or 'group' (subtree, a group id).
     *
     * @param array<string,mixed> $c         campaign row
     * @param list<string>        $directIds the member's active group ids
     *
     * @return array{0:?string,1:?string}
     */
    private function memberTeam(string $organizationId, array $c, string $subjectId, array $directIds): array
    {
        if (($c['team_mode'] ?? 'subtree') === 'adhoc') {
            $teams = $this->adhocTeamsFor($organizationId, (string) $c['id'], $subjectId);

            return $teams !== [] ? [$teams[0], 'team'] : [null, null];
        }

        $teams = $this->resolveTeams($organizationId, (string) $c['group_id'], $directIds);

        return $teams !== [] ? [$teams[0], 'group'] : [null, null];
    }

    /**
     * The ad hoc team(s) a user is rostered on for a campaign (normally 0 or 1,
     * since ctm_user_uq allows at most one). @return list<string>
     */
    private function adhocTeamsFor(string $organizationId, string $campaignId, string $userId): array
    {
        $rows = $this->db->table('campaign_team_members')
            ->select('team_id')
            ->where('organization_id', $organizationId)
            ->where('campaign_id', $campaignId)
            ->where('user_id', $userId)
            ->get()->getResultArray();

        return array_map(static fn ($r): string => (string) $r['team_id'], $rows);
    }

    /**
     * Upsert a group/team's MILESTONE for the optional team challenge, then
     * grant its single milestone award if it has crossed team_target_value.
     *
     * A group/team is a first-class milestone subject (parity with individuals):
     * it accumulates only the contributions DIRECTED AT IT (target_only — no
     * roll-up to parents), and earns ONE award (badge + optional points) the
     * first time its cumulative total reaches team_target_value. `contributors`
     * counts distinct members, incremented only on a member's first credit.
     *
     * @param array<string,mixed> $c campaign row
     */
    private function tallyTeam(string $organizationId, array $c, string $teamRef, string $teamKind, int $amount, bool $firstForMember, string $now): void
    {
        $campaignId = (string) $c['id'];
        $newTotal   = $amount;
        $prevAwd    = 0;

        $existing = $this->db->table('campaign_team_standings')
            ->where('campaign_id', $campaignId)->where('team_ref', $teamRef)
            ->get()->getRowArray();

        if ($existing === null) {
            try {
                $this->db->table('campaign_team_standings')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'campaign_id'     => $campaignId,
                    'team_ref'        => $teamRef,
                    'team_kind'       => $teamKind,
                    'total_value'     => $amount,
                    'contributors'    => $firstForMember ? 1 : 0,
                    'awards_count'    => 0,
                    'updated_at'      => $now,
                ]);
                $newTotal = $amount;
                $prevAwd  = 0;
            } catch (Throwable) {
                // Concurrent insert — fall through to the update path.
                $existing = $this->db->table('campaign_team_standings')
                    ->where('campaign_id', $campaignId)->where('team_ref', $teamRef)
                    ->get()->getRowArray();
            }
        }

        if ($existing !== null) {
            $newTotal = (int) $existing['total_value'] + $amount;
            $prevAwd  = (int) ($existing['awards_count'] ?? 0);
            $this->db->table('campaign_team_standings')->where('id', $existing['id'])->update([
                'total_value'  => $newTotal,
                'contributors' => (int) $existing['contributors'] + ($firstForMember ? 1 : 0),
                'updated_at'   => $now,
            ]);
        }

        // Group/team MILESTONE award: single target, single award. Grant once
        // when the cumulative total for THIS team first reaches its target.
        $teamTarget = $c['team_target_value'] !== null ? (int) $c['team_target_value'] : 0;
        if (self::teamMilestoneReached($newTotal, $teamTarget, $prevAwd)) {
            $this->grantTeamAward($organizationId, $c, $teamRef, $teamKind, $newTotal, $now);
            $this->db->table('campaign_team_standings')
                ->where('campaign_id', $campaignId)->where('team_ref', $teamRef)
                ->update(['awards_count' => 1]);
        }
    }

    /**
     * Pure: whether a group/team's SINGLE milestone award should fire now.
     * True only when a target is configured (> 0), it hasn't already been
     * granted (prevAwards < 1), and the team's cumulative directed total has
     * reached the target. Exposed for unit testing.
     */
    public static function teamMilestoneReached(int $newTotal, int $target, int $prevAwards): bool
    {
        return $target > 0 && $prevAwards < 1 && $newTotal >= $target;
    }

    /**
     * Grant a GROUP/TEAM its single milestone award: the configured team badge
     * (to the group/team as subject) and optional points, idempotent by a
     * team-scoped source_ref. Mirrors grantAward() but for a team subject.
     *
     * @param array<string,mixed> $c campaign row
     *
     * @return array<string,mixed>
     */
    private function grantTeamAward(string $org, array $c, string $teamRef, string $teamKind, int $value, string $now): array
    {
        $subjectType  = $teamKind === 'team' ? 'team' : 'group';
        $badgeAwardId = null;
        $ledgerId     = null;
        $srcRef       = 'campaign:' . $c['id'] . ':team:' . $teamRef;

        if (! empty($c['team_badge_code'])) {
            $badge = $this->db->table('badges')
                ->where('organization_id', $org)->where('code', $c['team_badge_code'])
                ->get()->getRowArray();
            if ($badge !== null) {
                $badgeAwardId = Uuid::v7();
                try {
                    $this->db->table('badge_awards')->insert([
                        'id'              => $badgeAwardId,
                        'organization_id' => $org,
                        'badge_id'        => $badge['id'],
                        'subject_id'      => $teamRef,
                        'season_id'       => $c['season_id'] ?? null,
                        'source_ref'      => $srcRef,
                        'state'           => 'awarded',
                        'visibility'      => $badge['visibility'] ?? 'public',
                        'awarded_at'      => $now,
                    ]);
                } catch (Throwable) {
                    $badgeAwardId = null; // already awarded (idempotent)
                }
            }
        }

        if (! empty($c['team_award_points']) && (int) $c['team_award_points'] > 0 && ! empty($c['rollup_to_general']) && ! empty($c['season_id'])) {
            $ledgerId = Uuid::v7();
            try {
                $this->db->table('point_ledger')->insert([
                    'id'              => $ledgerId,
                    'organization_id' => $org,
                    'season_id'       => $c['season_id'],
                    'subject_id'      => $teamRef,
                    'subject_type'    => $subjectType,
                    'rule_id'         => $c['id'],
                    'rule_version'    => 1,
                    'entry_type'      => 'award',
                    'points'          => (int) $c['team_award_points'],
                    'source_ref'      => $srcRef,
                    'state'           => 'final',
                    'explanation'     => 'Campaign team milestone: ' . $c['name'],
                    'archived'        => 0,
                    'created_at'      => $now,
                ]);
            } catch (Throwable) {
                $ledgerId = null; // already awarded (idempotent)
            }
        }

        $awardId = Uuid::v7();
        try {
            $this->db->table('campaign_awards')->insert([
                'id'               => $awardId,
                'organization_id'  => $org,
                'campaign_id'      => $c['id'],
                'subject_id'       => $teamRef,
                'subject_type'     => $subjectType,
                'award_index'      => 1,
                'metric_value'     => $value,
                'badge_award_id'   => $badgeAwardId,
                'points_ledger_id' => $ledgerId,
                'awarded_at'       => $now,
            ]);
        } catch (Throwable) {
            // Concurrent grant; treat as done.
        }

        return ['subject_id' => $teamRef, 'subject_type' => $subjectType, 'badge_award_id' => $badgeAwardId, 'points_ledger_id' => $ledgerId];
    }

    /**
     * Team-challenge standings (top teams by aggregate contribution), with each
     * team's display name resolved. @return list<array<string,mixed>>
     */
    public function teamStandings(string $organizationId, string $campaignId, int $limit = 20): array
    {
        $rows = $this->db->table('campaign_team_standings')
            ->select('team_ref, team_kind, total_value, contributors')
            ->where('organization_id', $organizationId)->where('campaign_id', $campaignId)
            ->orderBy('total_value', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()->getResultArray();

        $groupIds = [];
        $teamIds  = [];
        foreach ($rows as $r) {
            if ($r['team_kind'] === 'team') {
                $teamIds[] = (string) $r['team_ref'];
            } else {
                $groupIds[] = (string) $r['team_ref'];
            }
        }
        $names = [];
        if ($groupIds !== []) {
            foreach ($this->db->table('groups')->select('id, name')->whereIn('id', array_values(array_unique($groupIds)))->get()->getResultArray() as $g) {
                $names[(string) $g['id']] = (string) $g['name'];
            }
        }
        if ($teamIds !== []) {
            foreach ($this->db->table('campaign_teams')->select('id, name')->whereIn('id', array_values(array_unique($teamIds)))->get()->getResultArray() as $t) {
                $names[(string) $t['id']] = (string) $t['name'];
            }
        }
        foreach ($rows as &$r) {
            $r['display_name'] = $names[(string) $r['team_ref']] ?? null;
        }
        unset($r);

        return $rows;
    }

    /**
     * The member's MOST-SPECIFIC (deepest) active direct group, or null. Used to
     * denormalize the hierarchical group onto campaign_progress at write time so
     * leaderboard reads never recompute ancestry. One indexed query (bounded by
     * the member's membership count), run only on a member's first contribution.
     */
    private function mostSpecificGroup(string $organizationId, string $subjectId): ?string
    {
        $row = $this->db->table('group_members gm')
            ->select('gm.group_id')
            ->join('groups g', 'g.id = gm.group_id')
            ->where('gm.organization_id', $organizationId)
            ->where('gm.user_id', $subjectId)
            ->where('gm.status', 'active')
            ->orderBy('g.depth', 'DESC')
            ->orderBy('g.id', 'ASC') // deterministic tie-break
            ->limit(1)
            ->get()->getRowArray();

        return $row !== null ? (string) $row['group_id'] : null;
    }

    /**
     * The subject's active direct group memberships. @return list<string>
     */
    private function subjectGroupIds(string $organizationId, string $subjectId): array
    {
        $direct = $this->db->table('group_members')
            ->select('group_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $subjectId)
            ->where('status', 'active')
            ->get()->getResultArray();

        return array_values(array_unique(array_map(static fn ($r): string => (string) $r['group_id'], $direct)));
    }

    /**
     * For a GROUP-subject campaign owned by group $ownerId, resolve which
     * competing TEAM(s) a user (member of $directIds) rolls up to.
     *
     * Teams are the owner's IMMEDIATE children (a race between subgroups). A
     * user accrues to child C when C is an ancestor-or-self of one of the user's
     * groups. When the owner has NO children, the owner competes as a single
     * aggregate subject (any member of its subtree contributes to it).
     *
     * @param list<string> $directIds
     *
     * @return list<string>
     */
    /**
     * LEAF-INCLUSIVE milestone chain (rollup_awards=1). Every group on the
     * MEMBER's OWN ancestor-or-self path that lies within the campaign owner's
     * subtree — i.e. from the member's most-specific ($memberLeaf) group up to
     * AND INCLUDING $ownerId — SHOULD mint its own milestone award, EXCLUDING
     * $teamRef (the owner's direct-child team, already tallied by the caller).
     *
     * This is the leaf-inclusive counterpart to ancestorAwardChain(): where that
     * mints only from ABOVE the direct-child team up to the owner, this ALSO mints
     * for the member's leaf group and every intermediate group between the leaf
     * and the direct-child team — so a member's own group is a first-class
     * milestone subject too (user decision, 2026-09-08).
     *
     * Set math: (ancestor-or-self of $memberLeaf) ∩ (descendant-or-self of
     * $ownerId), minus $teamRef. Ordered nearest-to-leaf → owner. Bounded by tree
     * depth (≤ 9). Falls back to nothing extra when the leaf is unknown (caller
     * uses ancestorAwardChain() in that case).
     *
     * @return list<string>
     */
    private function milestoneAwardChain(string $organizationId, string $ownerId, string $memberLeaf, string $teamRef): array
    {
        // Ancestor-or-self of the member's leaf group, ordered nearest-first
        // (distance 0 = the leaf itself).
        $ancRows = $this->db->table('group_closure')
            ->select('ancestor_id, distance')
            ->where('descendant_id', $memberLeaf)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();

        // Descendant-or-self of the owner — bounds the chain to the campaign's
        // own subtree (never mint outside it).
        $descRows = $this->db->table('group_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $ownerId)
            ->get()->getResultArray();
        $ownerSubtree = array_map(static fn ($r): string => (string) $r['descendant_id'], $descRows);

        // ancRows is already ordered nearest-first (distance ASC).
        $leafPath = array_map(static fn ($r): string => (string) $r['ancestor_id'], $ancRows);

        return self::milestoneChain($leafPath, $ownerSubtree, $teamRef);
    }

    /**
     * Pure: given the member's ancestor-or-self group ids (ordered nearest-first)
     * and the owner's descendant-or-self set, return the milestone groups that
     * should mint — every leaf-path group inside the owner's subtree, EXCLUDING
     * $teamRef (already tallied by the caller). Order is preserved (leaf→owner).
     * Exposed static for unit testing the chain math.
     *
     * @param list<string> $leafAncestorsOrSelf member leaf's ancestor-or-self, nearest-first
     * @param list<string> $ownerSubtree        owner's descendant-or-self ids
     *
     * @return list<string>
     */
    public static function milestoneChain(array $leafAncestorsOrSelf, array $ownerSubtree, string $teamRef): array
    {
        $chain = [];
        foreach ($leafAncestorsOrSelf as $aid) {
            if ($aid === $teamRef) {
                continue; // already tallied by the caller
            }
            if (! in_array($aid, $ownerSubtree, true)) {
                continue; // above the owner / outside the campaign subtree
            }
            $chain[] = $aid;
        }

        return $chain;
    }

    /**
     * The ancestor groups that should ALSO mint their own milestone award for a
     * contribution already credited to $teamRef (a direct-child team of the
     * campaign owner). Returns every group STRICTLY ABOVE $teamRef up to AND
     * INCLUDING $ownerId — i.e. $teamRef's proper ancestors that are themselves
     * descendants-or-self of the owner. $teamRef itself is excluded (tallyTeam
     * already handled it); groups above the owner are excluded (a campaign never
     * mints outside its own subtree).
     *
     * Ordered nearest-ancestor → owner. Bounded by tree depth (≤ 9). Retained as
     * the FALLBACK for milestoneAwardChain() when the member's leaf group is
     * unknown (so an edge contribution never mis-mints).
     *
     * @return list<string>
     */
    private function ancestorAwardChain(string $organizationId, string $ownerId, string $teamRef): array
    {
        if ($teamRef === $ownerId) {
            return []; // the team IS the owner (owner had no children) — nothing above to mint
        }

        // Proper ancestors of the team (distance >= 1), nearest first.
        $rows = $this->db->table('group_closure')
            ->select('ancestor_id, distance')
            ->where('descendant_id', $teamRef)
            ->where('distance >', 0)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();

        // Ancestor-or-self set of the owner, to bound the chain to the campaign's
        // own subtree (never mint for groups above the owner).
        $ownerScope = $this->db->table('group_closure')
            ->select('ancestor_id')
            ->where('descendant_id', $ownerId)
            ->get()->getResultArray();
        $ownerAncestors = array_map(static fn ($r): string => (string) $r['ancestor_id'], $ownerScope);

        $chain = [];
        foreach ($rows as $r) {
            $aid = (string) $r['ancestor_id'];
            // Keep only groups within the owner's subtree-or-self: an ancestor of
            // the team that is NOT an ancestor of the owner lies inside the
            // owner's subtree (good); the owner itself is included; anything that
            // IS a strict ancestor of the owner is above the campaign → skip.
            if ($aid === $ownerId) {
                $chain[] = $aid;
                break; // owner is the top of the chain; stop here
            }
            if (in_array($aid, $ownerAncestors, true)) {
                // $aid is at or above the owner but isn't the owner → above the
                // campaign subtree; stop (ancestors are ordered nearest-first).
                break;
            }
            $chain[] = $aid;
        }

        return $chain;
    }

    public function resolveTeams(string $organizationId, string $ownerId, array $directIds): array
    {
        if ($directIds === []) {
            return [];
        }

        // Owner's immediate children (distance 1) = the teams.
        $childRows = $this->db->table('group_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $ownerId)
            ->where('distance', 1)
            ->get()->getResultArray();
        $children = array_map(static fn ($r): string => (string) $r['descendant_id'], $childRows);

        // Ancestors-or-self of the user's groups (distance 0 rows included).
        $ancRows = $this->db->table('group_closure')
            ->select('ancestor_id')
            ->whereIn('descendant_id', $directIds)
            ->get()->getResultArray();
        $ancestorsOrSelf = array_map(static fn ($r): string => (string) $r['ancestor_id'], $ancRows);

        return self::attributedTeams($children, $ancestorsOrSelf, $ownerId);
    }

    /**
     * Pure: given an owner's child-team ids and the ancestor-or-self ids of a
     * user's groups, return the team(s) the user rolls up to. When the owner has
     * no children, the owner itself is the single aggregate team (if the user is
     * within the owner's subtree). Exposed for unit testing.
     *
     * @param list<string> $children        owner's immediate child group ids
     * @param list<string> $ancestorsOrSelf ancestor-or-self ids of user's groups
     *
     * @return list<string>
     */
    public static function attributedTeams(array $children, array $ancestorsOrSelf, string $ownerId): array
    {
        if ($children === []) {
            // Aggregate: the owner competes as one subject when the user is
            // within its subtree (owner is an ancestor-or-self of a user group).
            return in_array($ownerId, $ancestorsOrSelf, true) ? [$ownerId] : [];
        }

        $teams = array_values(array_unique(array_intersect($children, $ancestorsOrSelf)));

        return $teams;
    }

    /**
     * Active, in-window campaigns whose activity_scope matches $activityCode and
     * that cover the subject. Matching depends on the campaign kind:
     *   - USER campaigns: subject is a direct member of the group, or
     *     (include_descendants) a member of a subgroup.
     *   - GROUP campaigns (subtree): subject is anywhere in the owner's subtree.
     *   - GROUP campaigns (ad hoc): subject is on one of the campaign's rosters
     *     (independent of the group hierarchy — a roster-only user still matches).
     *
     * @param list<string> $directIds the subject's active direct group ids
     *
     * @return list<array<string,mixed>>
     */
    public function matchingCampaigns(string $organizationId, string $subjectId, array $directIds, string $activityCode): array
    {
        $now = $this->clock->nowUtcString();

        // Campaign ids the user is rostered on (ad hoc teams) — matched even
        // when the user has no group memberships.
        $adhocRows = $this->db->table('campaign_team_members')
            ->select('campaign_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $subjectId)
            ->get()->getResultArray();
        $adhocCampaignIds = array_values(array_unique(array_map(static fn ($r): string => (string) $r['campaign_id'], $adhocRows)));

        if ($directIds === [] && $adhocCampaignIds === []) {
            return [];
        }

        // Ancestor groups of the subject's groups (for include_descendants /
        // group-subtree campaigns owned higher up the tree).
        $ancestorIds = [];
        if ($directIds !== []) {
            $ancestorRows = $this->db->table('group_closure')
                ->select('ancestor_id')
                ->whereIn('descendant_id', $directIds)
                ->where('distance >', 0)
                ->get()->getResultArray();
            $ancestorIds = array_values(array_unique(array_map(static fn ($r): string => (string) $r['ancestor_id'], $ancestorRows)));
        }

        $q = $this->db->table('group_campaigns')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('starts_at <=', $now)
            ->where('ends_at >=', $now);

        // The individual competes in a campaign when the owning group covers
        // them — the SAME group-scope rule as any group-scoped campaign:
        //   ( group_id ∈ direct )
        //     OR ( include_descendants AND group_id ∈ ancestors )
        // PLUS any campaign they are explicitly rostered on (ad hoc members are
        // hand-picked from across the hierarchy, so a roster spot alone makes
        // them eligible even outside the owning group's scope).
        $q->groupStart();
        $needOr = false;
        if ($adhocCampaignIds !== []) {
            $q->whereIn('id', $adhocCampaignIds);
            $needOr = true;
        }
        if ($directIds !== []) {
            $needOr ? $q->orWhereIn('group_id', $directIds) : $q->whereIn('group_id', $directIds);
            $needOr = true;
            if ($ancestorIds !== []) {
                $q->orGroupStart()
                    ->where('include_descendants', 1)
                    ->whereIn('group_id', $ancestorIds)
                  ->groupEnd();
            }
        }
        $q->groupEnd();

        $rows = $q->get()->getResultArray();

        // Filter by activity_scope in PHP (JSON column; empty/null = all).
        $out = [];
        foreach ($rows as $c) {
            $scope = $c['activity_scope'] ?? null;
            if ($scope === null || $scope === '' || $scope === '[]') {
                $out[] = $c;
                continue;
            }
            $list = json_decode((string) $scope, true);
            if (! is_array($list) || $list === [] || in_array($activityCode, $list, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** List a group's campaigns (optionally by status). @return list<array<string,mixed>> */
    public function listForGroup(string $organizationId, string $groupId, ?string $status = null): array
    {
        $q = $this->db->table('group_campaigns')
            ->where('organization_id', $organizationId)->where('group_id', $groupId);
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderBy('starts_at', 'DESC')->get()->getResultArray();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Insert a tier row. Returns the tier id. Throws on a UNIQUE clash
     * (duplicate code/position on the campaign).
     *
     * @param array<string,mixed> $tier
     */
    private function insertTier(string $org, string $campaignId, int $position, array $tier, string $now): string
    {
        $id = Uuid::v7();
        $this->db->table('campaign_tiers')->insert([
            'id'              => $id,
            'organization_id' => $org,
            'campaign_id'     => $campaignId,
            'code'            => mb_substr((string) $tier['code'], 0, 40),
            'name'            => mb_substr((string) $tier['name'], 0, 120),
            'tier_position'   => $position,
            'threshold_value' => (int) $tier['threshold_value'],
            'badge_code'      => $tier['badge_code'] ?? null,
            'award_points'    => isset($tier['award_points']) ? (int) $tier['award_points'] : null,
            'icon'            => $tier['icon'] ?? null,
            'color'           => $tier['color'] ?? null,
            'created_at'      => $now,
        ]);

        return $id;
    }

    /**
     * Award indexes already granted to a subject on a campaign (used to skip
     * already-reached tiers idempotently).
     *
     * @return list<int>
     */
    private function grantedIndexes(string $campaignId, string $subjectId): array
    {
        $rows = $this->db->table('campaign_awards')
            ->select('award_index')
            ->where('campaign_id', $campaignId)->where('subject_id', $subjectId)
            ->get()->getResultArray();

        return array_map(static fn ($r): int => (int) $r['award_index'], $rows);
    }

    /**
     * Grant a single tier award (silver/gold/diamond). award_index = tier
     * position, so each tier is granted at most once per subject.
     *
     * @param array<string,mixed> $c    campaign row
     * @param array<string,mixed> $tier tier row
     *
     * @return array<string,mixed>
     */
    private function grantTierAward(string $org, array $c, array $tier, string $subjectId, string $subjectType, int $value, string $now): array
    {
        // Overlay the tier's own badge/points onto the campaign context so the
        // shared grant path posts the tier-specific award.
        $ctx = $c;
        $ctx['badge_code']   = $tier['badge_code'] ?? null;
        $ctx['award_points'] = $tier['award_points'] ?? null;

        $out = $this->grantAward($org, $ctx, $subjectId, $subjectType, (int) $tier['tier_position'], $value, $now);
        $out['tier_code'] = $tier['code'];
        $out['tier_name'] = $tier['name'];

        return $out;
    }

    /**
     * Grant one campaign award: append a campaign_awards row and, when
     * configured, a badge_awards row and/or a point_ledger row. Idempotent per
     * (campaign, subject, award_index).
     *
     * @param array<string,mixed> $c campaign row
     *
     * @return array<string,mixed>
     */
    private function grantAward(string $org, array $c, string $subjectId, string $subjectType, int $index, int $value, string $now): array
    {
        $badgeAwardId = null;
        $ledgerId     = null;

        // Badge (repeatable badges are season-distinct via source_ref; here we
        // scope the idempotency to the campaign award index).
        if (! empty($c['badge_code'])) {
            $badge = $this->db->table('badges')
                ->where('organization_id', $org)->where('code', $c['badge_code'])
                ->get()->getRowArray();
            if ($badge !== null) {
                $badgeAwardId = Uuid::v7();
                try {
                    $this->db->table('badge_awards')->insert([
                        'id'              => $badgeAwardId,
                        'organization_id' => $org,
                        'badge_id'        => $badge['id'],
                        'subject_id'      => $subjectId,
                        'season_id'       => $c['season_id'] ?? null,
                        'source_ref'      => 'campaign:' . $c['id'] . ':' . $index,
                        'state'           => 'awarded',
                        'visibility'      => $badge['visibility'] ?? 'public',
                        'awarded_at'      => $now,
                    ]);
                } catch (Throwable) {
                    $badgeAwardId = null; // already awarded (idempotent)
                }
            }
        }

        // Points (optionally rolled up to the general season).
        if (! empty($c['award_points']) && (int) $c['award_points'] > 0 && ! empty($c['rollup_to_general']) && ! empty($c['season_id'])) {
            $ledgerId = Uuid::v7();
            try {
                $this->db->table('point_ledger')->insert([
                    'id'              => $ledgerId,
                    'organization_id' => $org,
                    'season_id'       => $c['season_id'],
                    'subject_id'      => $subjectId,
                    'subject_type'    => $subjectType,
                    // rule_id is CHAR(36); use the campaign UUID directly.
                    // Idempotency is carried by the distinct source_ref per win.
                    'rule_id'         => $c['id'],
                    'rule_version'    => 1,
                    'entry_type'      => 'award',
                    'points'          => (int) $c['award_points'],
                    'source_ref'      => 'campaign:' . $c['id'] . ':' . $index,
                    'state'           => 'final',
                    'explanation'     => 'Campaign target: ' . $c['name'],
                    'archived'        => 0,
                    'created_at'      => $now,
                ]);
            } catch (Throwable) {
                $ledgerId = null; // already awarded (idempotent)
            }
        }

        $awardId = Uuid::v7();
        try {
            $this->db->table('campaign_awards')->insert([
                'id'               => $awardId,
                'organization_id'  => $org,
                'campaign_id'      => $c['id'],
                'subject_id'       => $subjectId,
                'subject_type'     => $subjectType,
                'award_index'      => $index,
                'metric_value'     => $value,
                'badge_award_id'   => $badgeAwardId,
                'points_ledger_id' => $ledgerId,
                'awarded_at'       => $now,
            ]);
        } catch (Throwable) {
            // Concurrent grant of the same index; treat as done.
        }

        return ['award_index' => $index, 'badge_award_id' => $badgeAwardId, 'points_ledger_id' => $ledgerId];
    }

    private function transition(string $org, string $campaignId, string $from, string $to): Result
    {
        $c = $this->find($org, $campaignId);
        if ($c === null) {
            return Result::notFound('campaign.not_found', 'CAMPAIGN_NOT_FOUND');
        }
        if ($c['status'] !== $from) {
            return Result::fail('BAD_STATE', 'campaign.bad_state', 409, ['status' => $c['status'], 'expected' => $from]);
        }
        $this->db->table('group_campaigns')->where('id', $campaignId)->update([
            'status' => $to, 'updated_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['campaign_id' => $campaignId, 'status' => $to]);
    }

    /** @return array<string,mixed>|null */
    private function find(string $org, string $campaignId): ?array
    {
        return $this->db->table('group_campaigns')
            ->where('organization_id', $org)->where('id', $campaignId)
            ->get()->getRowArray() ?: null;
    }

    /**
     * The owning group scope of an existing campaign, for the controller's
     * authoritative per-group authorization on campaign-scoped writes
     * (update/tier/team/activate/cancel/progress/close). Returns
     * ['group_id' => string|null, 'include_descendants' => bool] or null when the
     * campaign does not exist. `group_id` is never empty in practice (create
     * requires it), but is normalised to null defensively.
     *
     * @return array{group_id: ?string, include_descendants: bool}|null
     */
    public function campaignGroupScope(string $organizationId, string $campaignId): ?array
    {
        $c = $this->find($organizationId, $campaignId);
        if ($c === null) {
            return null;
        }
        $gid = isset($c['group_id']) && $c['group_id'] !== '' ? (string) $c['group_id'] : null;

        return ['group_id' => $gid, 'include_descendants' => ! empty($c['include_descendants'])];
    }
}
