<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Support\Uuid;

/**
 * Demo of a group PROJECT / campaign that is INDIVIDUAL-FIRST with an OPTIONAL
 * team challenge running alongside — end-to-end, so both scoreboards render.
 *
 * It seeds a real 2-level group hierarchy (Region → Accra/Tema districts) and
 * places each member in a DISTRICT, so a member's SUBGROUP is visible and is
 * clearly independent of their ad hoc Red/Blue team.
 *
 * It seeds a "Q3 Giving Challenge" owned by the region group:
 *   - metric = amount (minor units), award_mode = TIERED (silver/gold/diamond),
 *     each tier granting its own badge — the core individual mechanic;
 *   - an OPTIONAL ad hoc team challenge (team_mode = adhoc): "Red" vs "Blue",
 *     rosters hand-picked from members, so each member's SAME giving ALSO tallies
 *     into the team it was directed at;
 *   - each TEAM has its OWN milestone too (team_target_value + team_badge_code):
 *     a team earns its own badge when its directed total reaches the target;
 *   - recognize_top_n individuals + recognize_top_teams at close.
 *
 * Then it drives real activity through CampaignService::recordProgress() so the
 * individual leaderboard, tier badges, and team standings all populate. Finally
 * it closes the campaign to snapshot recognition.
 *
 * NOT for production. Idempotent: keyed off the campaign code; re-running skips.
 * Run AFTER migrations + RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignDemoSeeder'
 */
class CampaignDemoSeeder extends Seeder
{
    private const CAMPAIGN_CODE = 'q3-giving-challenge';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->say(STDERR, "CampaignDemoSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");

            return;
        }
        $orgId = (string) $org['id'];

        // Idempotency guard.
        $exists = $this->db->table('group_campaigns')
            ->where('organization_id', $orgId)->where('code', self::CAMPAIGN_CODE)
            ->countAllResults();
        if ($exists > 0) {
            $this->say(STDOUT, "CampaignDemoSeeder: demo campaign already present; skipping.\n");

            return;
        }

        $now       = date('Y-m-d H:i:s');
        $svc       = GamificationServices::campaigns(false);
        $groups    = $this->ensureGroups($orgId, $now);   // region + 2 districts
        $groupId   = $groups['region'];                    // the campaign owner
        $users     = $this->ensureUsers($orgId, $groups, $now);
        $seasonId  = $this->activeSeasonId($orgId);
        $this->ensureTierBadges($orgId, $now);

        // -- Create the campaign: individual-first, tiered, + team challenge ---
        $created = $svc->create($orgId, [
            'group_id'            => $groupId,
            'include_descendants' => 1,
            'season_id'           => $seasonId,
            'code'                => self::CAMPAIGN_CODE,
            'name'                => 'Q3 Giving Challenge',
            'description'         => 'Every member, give more this quarter. Reach Silver, Gold, or Diamond — and help your team win.',
            'category'            => 'send',
            'activity_scope'      => ['contribution.verified'],
            'metric'              => 'amount',
            'award_mode'          => 'tiered',
            'rollup_to_general'   => 0,
            'recognize_top_n'     => 3,   // top individuals
            'team_challenge'      => 1,   // OPTIONAL overlay
            'team_mode'           => 'adhoc',
            'recognize_top_teams' => 2,   // top teams
            // Each competing TEAM has its OWN milestone (parity with members):
            // a single target that earns the team its own badge. 190000 pesewas
            // = GHS 1,900 — Red (195000) reaches it, Blue (185000) does not.
            'team_target_value'   => 190000,
            'team_badge_code'     => 'campaign_team_champion',
            'starts_at'           => date('Y-m-d H:i:s', strtotime('-30 days')),
            'ends_at'             => date('Y-m-d H:i:s', strtotime('+30 days')),
            'tiers'               => [
                ['code' => 'silver',  'name' => 'Silver',  'threshold_value' => 10000,  'badge_code' => 'campaign_silver',  'award_points' => 50,  'color' => '#94a3b8'],
                ['code' => 'gold',    'name' => 'Gold',    'threshold_value' => 50000,  'badge_code' => 'campaign_gold',    'award_points' => 150, 'color' => '#f59e0b'],
                ['code' => 'diamond', 'name' => 'Diamond', 'threshold_value' => 100000, 'badge_code' => 'campaign_diamond', 'award_points' => 400, 'color' => '#38bdf8'],
            ],
        ]);
        if (! $created->ok) {
            $this->say(STDERR, 'CampaignDemoSeeder: create failed: ' . (string) $created->code . "\n");

            return;
        }
        $campaignId = (string) $created->data['campaign_id'];

        // -- Define the two ad hoc teams (rosters from across the group) -------
        $redId  = (string) $svc->defineTeam($orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team', 'color' => '#ef4444'])->data['team_id'];
        $blueId = (string) $svc->defineTeam($orgId, $campaignId, ['code' => 'blue', 'name' => 'Blue Team', 'color' => '#3b82f6'])->data['team_id'];

        // Roster: 3 members each (hand-picked, not by group structure).
        $roster = [
            $users['ama']   => $redId,
            $users['kofi']  => $redId,
            $users['esi']   => $redId,
            $users['yaw']   => $blueId,
            $users['abena'] => $blueId,
            $users['kwame'] => $blueId,
        ];
        foreach ($roster as $userId => $teamId) {
            $svc->addTeamMember($orgId, $campaignId, $teamId, $userId, null);
        }

        // -- Activate ---------------------------------------------------------
        $svc->activate($orgId, $campaignId);

        // -- Drive giving activity (individual amount -> tiers + team tally) ---
        // Amounts in pesewas (minor units). Chosen to spread across tiers and
        // let Red edge Blue on the team scoreboard.
        $gifts = [
            ['user' => $users['ama'],   'team' => $redId,  'amount' => 120000], // Diamond
            ['user' => $users['kofi'],  'team' => $redId,  'amount' => 60000],  // Gold
            ['user' => $users['esi'],   'team' => $redId,  'amount' => 15000],  // Silver
            ['user' => $users['yaw'],   'team' => $blueId, 'amount' => 90000],  // Gold
            ['user' => $users['abena'], 'team' => $blueId, 'amount' => 55000],  // Gold
            ['user' => $users['kwame'], 'team' => $blueId, 'amount' => 40000],  // Silver
        ];
        foreach ($gifts as $i => $g) {
            $svc->recordProgress($orgId, $campaignId, (string) $g['user'], (int) $g['amount'], [
                'sourceRef' => 'demo:gift:' . $i,
                'team_ref'  => $g['team'],
                'team_kind' => 'team',
            ]);
        }

        // -- Close to snapshot recognition (top individuals + top teams) ------
        $svc->close($orgId, $campaignId);

        $this->say(STDOUT, "CampaignDemoSeeder: seeded '{$campaignId}' (tiered giving + Red vs Blue; Red hits the team milestone).\n");
    }

    // -------------------------------------------------------------------------

    /**
     * Ensure a real 2-level hierarchy so a member's SUBGROUP is visible:
     *
     *   Greater Accra Region              (owner of the campaign)
     *     ├─ Accra Metro District
     *     └─ Tema District
     *
     * Each member is placed in a DISTRICT (not the flat region). The districts
     * deliberately CUT ACROSS the ad hoc Red/Blue teams, so the demo shows that
     * "which subgroup you belong to" and "which team you're on" are independent.
     *
     * @return array<string,string> slug-key => group id (keys: region, accra, tema)
     */
    private function ensureGroups(string $orgId, string $now): array
    {
        $region = $this->ensureGroup($orgId, null, 'Greater Accra Region', 'greater-accra-region', 'region', 1, $now);
        $accra  = $this->ensureGroup($orgId, $region, 'Accra Metro District', 'accra-metro-district', 'district', 2, $now);
        $tema   = $this->ensureGroup($orgId, $region, 'Tema District', 'tema-district', 'district', 2, $now);

        return ['region' => $region, 'accra' => $accra, 'tema' => $tema];
    }

    /**
     * Create (idempotently, by slug) one group under an optional parent, wiring
     * the transitive-closure rows (self + every ancestor via the parent's rows).
     */
    private function ensureGroup(string $orgId, ?string $parentId, string $name, string $slug, string $type, int $depth, string $now): string
    {
        $g = $this->db->table('groups')
            ->where('organization_id', $orgId)->where('slug', $slug)
            ->get()->getRowArray();
        if ($g !== null) {
            return (string) $g['id'];
        }

        $groupId    = Uuid::v7();
        $parentPath = '';
        if ($parentId !== null) {
            $p          = $this->db->table('groups')->where('id', $parentId)->get()->getRowArray();
            $parentPath = $p !== null ? (string) $p['path'] : '';
        }

        $this->db->table('groups')->insert([
            'id'              => $groupId,
            'organization_id' => $orgId,
            'parent_id'       => $parentId,
            'name'            => $name,
            'slug'            => $slug,
            'type'            => $type,
            'depth'           => $depth,
            'path'            => rtrim($parentPath, '/') . '/' . $groupId . '/',
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // Self row (distance 0).
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $groupId, 'descendant_id' => $groupId, 'distance' => 0,
        ]);
        // Inherit every ancestor of the parent, one step further away.
        if ($parentId !== null) {
            $ancestors = $this->db->table('group_closure')
                ->select('ancestor_id, distance')
                ->where('descendant_id', $parentId)
                ->get()->getResultArray();
            foreach ($ancestors as $a) {
                $this->db->table('group_closure')->insert([
                    'ancestor_id'   => (string) $a['ancestor_id'],
                    'descendant_id' => $groupId,
                    'distance'      => (int) $a['distance'] + 1,
                ]);
            }
        }

        return $groupId;
    }

    /**
     * Ensure the six demo members exist and are active members of the group.
     *
     * @return array<string,string> keyed short-name => user id
     */
    private function ensureUsers(string $orgId, array $groups, string $now): array
    {
        // Each member is placed in a DISTRICT subgroup (accra|tema). The
        // districts cut across the Red/Blue teams on purpose.
        $people = [
            'ama'   => ['ama.demo@example.test', 'Ama Mensah', 'accra'],
            'kofi'  => ['kofi.demo@example.test', 'Kofi Boateng', 'tema'],
            'esi'   => ['esi.demo@example.test', 'Esi Owusu', 'accra'],
            'yaw'   => ['yaw.demo@example.test', 'Yaw Darko', 'tema'],
            'abena' => ['abena.demo@example.test', 'Abena Asante', 'accra'],
            'kwame' => ['kwame.demo@example.test', 'Kwame Osei', 'tema'],
        ];

        $ids = [];
        foreach ($people as $key => [$email, $name, $districtKey]) {
            $groupId = $groups[$districtKey];
            $existing = $this->db->table('users')
                ->where('organization_id', $orgId)->where('email', $email)->get()->getRowArray();
            if ($existing !== null) {
                $ids[$key] = (string) $existing['id'];
            } else {
                $id = Uuid::v7();
                $this->db->table('users')->insert([
                    'id'              => $id,
                    'organization_id' => $orgId,
                    'email'           => $email,
                    'email_verified'  => 1,
                    'password_hash'   => password_hash('demo-not-for-prod', PASSWORD_BCRYPT),
                    'display_name'    => $name,
                    'status'          => 'active',
                    'locale'          => 'en',
                    'timezone'        => 'Africa/Accra',
                    'mfa_enabled'     => 0,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $ids[$key] = $id;
            }

            // Active group membership (needed for group-scope matching).
            $member = $this->db->table('group_members')
                ->where('organization_id', $orgId)->where('group_id', $groupId)->where('user_id', $ids[$key])
                ->countAllResults();
            if ($member === 0) {
                $this->db->table('group_members')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $orgId,
                    'group_id'        => $groupId,
                    'user_id'         => $ids[$key],
                    'role'            => 'member',
                    'status'          => 'active',
                    'joined_at'       => $now,
                ]);
            }
        }

        return $ids;
    }

    /** The org's active season id, or null (badge_awards.season_id is nullable). */
    private function activeSeasonId(string $orgId): ?string
    {
        $s = $this->db->table('gamification_seasons')
            ->where('organization_id', $orgId)->where('status', 'active')
            ->get()->getRowArray();

        return $s !== null ? (string) $s['id'] : null;
    }

    /** Seed the three tier badges the campaign grants (idempotent by code). */
    private function ensureTierBadges(string $orgId, string $now): void
    {
        foreach ([
            ['campaign_silver', 'Silver Giver'],
            ['campaign_gold', 'Gold Giver'],
            ['campaign_diamond', 'Diamond Giver'],
            ['campaign_team_champion', 'Team Champion'],
        ] as [$code, $name]) {
            $exists = $this->db->table('badges')
                ->where('organization_id', $orgId)->where('code', $code)->countAllResults();
            if ($exists > 0) {
                continue;
            }
            $this->db->table('badges')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $orgId,
                'group_id'        => null,
                'code'            => $code,
                'name'            => $name,
                'criteria'        => null,
                'visibility'      => 'public',
                'permanent'       => 1,
                'created_at'      => $now,
            ]);
        }
    }

    private function say($stream, string $msg): void
    {
        if (is_cli()) {
            fwrite($stream, $msg);
        }
    }
}
