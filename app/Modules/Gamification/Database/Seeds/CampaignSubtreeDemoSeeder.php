<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Support\Uuid;

/**
 * Demo of the HIERARCHICAL (SUBTREE) flavour of a group PROJECT / campaign —
 * the companion to CampaignDemoSeeder (which demos the AD HOC flavour).
 *
 * Same individual-first core (tiered silver/gold/diamond), but the OPTIONAL team
 * challenge uses `team_mode = "subtree"`: the competing teams ARE the owner
 * group's immediate subgroups (the districts), and membership is DERIVED from
 * the hierarchy — there are NO hand-picked rosters (no defineTeam/addTeamMember).
 * A member's gift rolls up to the subgroup they belong to (team_kind='group').
 *
 * It seeds (idempotently, sharing the same groups/users as CampaignDemoSeeder):
 *   Greater Accra Region            (campaign owner)
 *     ├─ Accra Metro District       (competing team) → Ama, Abena, Esi
 *     └─ Tema District              (competing team) → Yaw, Kofi, Kwame
 *
 * Then it drives giving so each member earns their tier AND their district's
 * standing accrues, with a district milestone (GHS 1,920) that Accra reaches and
 * Tema just misses. Finally it closes to snapshot recognition (top individuals +
 * top subgroups).
 *
 * NOT for production. Idempotent: keyed off the campaign code; re-running skips.
 * Run AFTER migrations + RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignSubtreeDemoSeeder'
 */
class CampaignSubtreeDemoSeeder extends Seeder
{
    private const CAMPAIGN_CODE = 'q3-regional-giving';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->say(STDERR, "CampaignSubtreeDemoSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");

            return;
        }
        $orgId = (string) $org['id'];

        // Idempotency guard.
        $exists = $this->db->table('group_campaigns')
            ->where('organization_id', $orgId)->where('code', self::CAMPAIGN_CODE)
            ->countAllResults();
        if ($exists > 0) {
            $this->say(STDOUT, "CampaignSubtreeDemoSeeder: demo campaign already present; skipping.\n");

            return;
        }

        $now      = date('Y-m-d H:i:s');
        $svc      = GamificationServices::campaigns(false);
        $groups   = $this->ensureGroups($orgId, $now);   // region + 2 districts
        $groupId  = $groups['region'];                    // the campaign owner
        $users    = $this->ensureUsers($orgId, $groups, $now);
        $seasonId = $this->activeSeasonId($orgId);
        $this->ensureBadges($orgId, $now);

        // -- Create: individual-first, tiered, + SUBTREE team challenge -------
        $created = $svc->create($orgId, [
            'group_id'            => $groupId,
            'include_descendants' => 1,
            'season_id'           => $seasonId,
            'code'                => self::CAMPAIGN_CODE,
            'name'                => 'Q3 Regional Giving',
            'description'         => 'Every member, give more this quarter — and lift your district up the board.',
            'category'            => 'send',
            'activity_scope'      => ['contribution.verified'],
            'metric'              => 'amount',
            'award_mode'          => 'tiered',
            'rollup_to_general'   => 0,
            'recognize_top_n'     => 3,          // top individuals
            'team_challenge'      => 1,          // OPTIONAL overlay
            'team_mode'           => 'subtree',  // teams ARE the subgroups
            'recognize_top_teams' => 2,          // top subgroups
            // Each competing SUBGROUP has its own milestone (parity with members):
            // 192000 pesewas = GHS 1,920 — Accra (195000) reaches it, Tema
            // (190000) falls short.
            'team_target_value'   => 192000,
            'team_badge_code'     => 'campaign_district_champion',
            'starts_at'           => date('Y-m-d H:i:s', strtotime('-30 days')),
            'ends_at'             => date('Y-m-d H:i:s', strtotime('+30 days')),
            'tiers'               => [
                ['code' => 'silver',  'name' => 'Silver',  'threshold_value' => 10000,  'badge_code' => 'campaign_silver',  'award_points' => 50,  'color' => '#94a3b8'],
                ['code' => 'gold',    'name' => 'Gold',    'threshold_value' => 50000,  'badge_code' => 'campaign_gold',    'award_points' => 150, 'color' => '#f59e0b'],
                ['code' => 'diamond', 'name' => 'Diamond', 'threshold_value' => 100000, 'badge_code' => 'campaign_diamond', 'award_points' => 400, 'color' => '#38bdf8'],
            ],
        ]);
        if (! $created->ok) {
            $this->say(STDERR, 'CampaignSubtreeDemoSeeder: create failed: ' . (string) $created->code . "\n");

            return;
        }
        $campaignId = (string) $created->data['campaign_id'];

        // NO defineTeam / addTeamMember: subtree teams need no rosters.

        // -- Activate ---------------------------------------------------------
        $svc->activate($orgId, $campaignId);

        // -- Drive giving. Each gift is directed at the member's DISTRICT
        // (team_ref = a group id, team_kind = 'group'). In production the feed
        // resolves this automatically via resolveTeams(); here we pass it
        // explicitly for a deterministic demo.
        $gifts = [
            ['user' => $users['ama'],   'district' => $groups['accra'], 'amount' => 120000], // Diamond
            ['user' => $users['abena'], 'district' => $groups['accra'], 'amount' => 55000],  // Gold
            ['user' => $users['esi'],   'district' => $groups['accra'], 'amount' => 20000],  // Silver
            ['user' => $users['yaw'],   'district' => $groups['tema'],  'amount' => 90000],  // Gold
            ['user' => $users['kofi'],  'district' => $groups['tema'],  'amount' => 60000],  // Gold
            ['user' => $users['kwame'], 'district' => $groups['tema'],  'amount' => 40000],  // Silver
        ];
        foreach ($gifts as $i => $g) {
            $svc->recordProgress($orgId, $campaignId, (string) $g['user'], (int) $g['amount'], [
                'sourceRef' => 'demo:regional-gift:' . $i,
                'team_ref'  => $g['district'],
                'team_kind' => 'group',
            ]);
        }

        // -- Close to snapshot recognition (top individuals + top subgroups) --
        $svc->close($orgId, $campaignId);

        $this->say(STDOUT, "CampaignSubtreeDemoSeeder: seeded '{$campaignId}' (subtree; Accra District hits the milestone, Tema just misses).\n");
    }

    // -------------------------------------------------------------------------

    /**
     * Region → Accra/Tema districts (idempotent by slug; shared with the ad hoc
     * seeder). @return array<string,string> keys: region, accra, tema
     */
    private function ensureGroups(string $orgId, string $now): array
    {
        $region = $this->ensureGroup($orgId, null, 'Greater Accra Region', 'greater-accra-region', 'region', 1, $now);
        $accra  = $this->ensureGroup($orgId, $region, 'Accra Metro District', 'accra-metro-district', 'district', 2, $now);
        $tema   = $this->ensureGroup($orgId, $region, 'Tema District', 'tema-district', 'district', 2, $now);

        return ['region' => $region, 'accra' => $accra, 'tema' => $tema];
    }

    /** Create one group under an optional parent, wiring the closure rows. */
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

        $this->db->table('group_closure')->insert([
            'ancestor_id' => $groupId, 'descendant_id' => $groupId, 'distance' => 0,
        ]);
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
     * Ensure the six demo members exist, each in a DISTRICT subgroup.
     *
     * @param array<string,string> $groups
     *
     * @return array<string,string> short-name => user id
     */
    private function ensureUsers(string $orgId, array $groups, string $now): array
    {
        $people = [
            'ama'   => ['ama.demo@example.test', 'Ama Mensah', 'accra'],
            'abena' => ['abena.demo@example.test', 'Abena Asante', 'accra'],
            'esi'   => ['esi.demo@example.test', 'Esi Owusu', 'accra'],
            'yaw'   => ['yaw.demo@example.test', 'Yaw Darko', 'tema'],
            'kofi'  => ['kofi.demo@example.test', 'Kofi Boateng', 'tema'],
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

    /** Seed the tier badges + the district-champion badge (idempotent by code). */
    private function ensureBadges(string $orgId, string $now): void
    {
        foreach ([
            ['campaign_silver', 'Silver Giver'],
            ['campaign_gold', 'Gold Giver'],
            ['campaign_diamond', 'Diamond Giver'],
            ['campaign_district_champion', 'District Champion'],
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
