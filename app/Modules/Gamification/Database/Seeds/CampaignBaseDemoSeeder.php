<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Seeds;

use CodeIgniter\Database\Seeder;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Support\Uuid;

/**
 * Demo of the BASE group PROJECT / campaign — the core case, with NO team
 * challenge. This is what a project fundamentally is: bundle some Win-Build-Send
 * activities, give them a target, set start/close dates. Members individually
 * make progress and earn the award when they hit the target.
 *
 * Companion to the two team-flavour seeders:
 *   - CampaignDemoSeeder        — OPTIONAL ad hoc team challenge (Red vs Blue)
 *   - CampaignSubtreeDemoSeeder — OPTIONAL subtree team challenge (subgroups)
 *
 * This one omits `team_challenge` entirely, so there are no teams, rosters, or
 * subgroup standings — just an individual leaderboard toward a target.
 *
 * It seeds "Q3 Outreach Drive" owned by the region group:
 *   - activity_scope bundles event.attended + contribution.verified +
 *     course.completed (the Win/Build/Send mix);
 *   - metric = count, award_mode = single, target = 40 → an Outreach Champion
 *     badge (+300 points) the first time a member reaches 40 activities.
 *
 * Then it drives activity via recordProgress() (in small increments, mirroring
 * real events) so the leaderboard populates, and closes to snapshot the top-3.
 *
 * NOT for production. Idempotent: keyed off the campaign code; re-running skips.
 * Shares the same demo groups/members as the other campaign seeders (idempotent
 * by slug/email), so all three coexist. Run AFTER migrations + RbacBootstrapSeeder:
 *
 *   php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
 *   php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignBaseDemoSeeder'
 */
class CampaignBaseDemoSeeder extends Seeder
{
    private const CAMPAIGN_CODE = 'q3-outreach-drive';

    public function run(): void
    {
        $org = $this->db->table('organizations')->where('slug', 'wbs')->get()->getRowArray();
        if ($org === null) {
            $this->say(STDERR, "CampaignBaseDemoSeeder: run RbacBootstrapSeeder first (no 'wbs' org).\n");

            return;
        }
        $orgId = (string) $org['id'];

        // Idempotency guard.
        $exists = $this->db->table('group_campaigns')
            ->where('organization_id', $orgId)->where('code', self::CAMPAIGN_CODE)
            ->countAllResults();
        if ($exists > 0) {
            $this->say(STDOUT, "CampaignBaseDemoSeeder: demo campaign already present; skipping.\n");

            return;
        }

        $now      = date('Y-m-d H:i:s');
        $svc      = GamificationServices::campaigns(false);
        $groups   = $this->ensureGroups($orgId, $now);   // region + 2 districts
        $groupId  = $groups['region'];                    // the campaign owner
        $users    = $this->ensureUsers($orgId, $groups, $now);
        $seasonId = $this->activeSeasonId($orgId);
        $this->ensureBadge($orgId, $now);

        // -- Create: individual-first, single target, NO team challenge -------
        $created = $svc->create($orgId, [
            'group_id'            => $groupId,
            'include_descendants' => 1,
            'season_id'           => $seasonId,
            'code'                => self::CAMPAIGN_CODE,
            'name'                => 'Q3 Outreach Drive',
            'description'         => 'Bundle your Win, Build and Send activities — reach 40 this quarter.',
            'category'            => 'custom',
            // The BUNDLE — which activities feed progress (empty = all).
            'activity_scope'      => ['event.attended', 'contribution.verified', 'course.completed'],
            'metric'              => 'count',
            'award_mode'          => 'single',
            'target_value'        => 40,
            'badge_code'          => 'campaign_outreach_champion',
            'award_points'        => 300,
            'rollup_to_general'   => 1,
            'recognize_top_n'     => 3,
            // team_challenge intentionally OMITTED (defaults to off).
            'starts_at'           => date('Y-m-d H:i:s', strtotime('-30 days')),
            'ends_at'             => date('Y-m-d H:i:s', strtotime('+60 days')),
        ]);
        if (! $created->ok) {
            $this->say(STDERR, 'CampaignBaseDemoSeeder: create failed: ' . (string) $created->code . "\n");

            return;
        }
        $campaignId = (string) $created->data['campaign_id'];

        // -- Activate (no team/tier prerequisites for a base single campaign) -
        $svc->activate($orgId, $campaignId);

        // -- Drive activity. metric=count, so each recordProgress adds to the
        // member's running activity count. Drive in a few increments per member
        // (mirroring separate real events), deduped per source_ref. Totals:
        // Ama 52, Yaw 44, Kofi 38, Abena 31, Kwame 22, Esi 12 → 2 hit target 40.
        $plan = [
            'ama'   => [20, 20, 12], // 52  ✔ target
            'yaw'   => [20, 24],     // 44  ✔ target
            'kofi'  => [20, 18],     // 38
            'abena' => [16, 15],     // 31
            'kwame' => [12, 10],     // 22
            'esi'   => [12],         // 12
        ];
        foreach ($plan as $key => $increments) {
            foreach ($increments as $i => $inc) {
                $svc->recordProgress($orgId, $campaignId, (string) $users[$key], (int) $inc, [
                    'sourceRef' => 'demo:outreach:' . $key . ':' . $i,
                ]);
            }
        }

        // -- Close to snapshot recognition (top-3 individuals) ----------------
        $svc->close($orgId, $campaignId);

        $this->say(STDOUT, "CampaignBaseDemoSeeder: seeded '{$campaignId}' (base project, no teams; 2 of 6 reached the target).\n");
    }

    // -------------------------------------------------------------------------

    /**
     * Region → Accra/Tema districts (idempotent by slug; shared with the other
     * campaign seeders). @return array<string,string> keys: region, accra, tema
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
     * Ensure the six demo members exist, each in a DISTRICT subgroup (shown on
     * the leaderboard for context — this project has no teams).
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

    /** The org's active season id, or null (points roll-up target). */
    private function activeSeasonId(string $orgId): ?string
    {
        $s = $this->db->table('gamification_seasons')
            ->where('organization_id', $orgId)->where('status', 'active')
            ->get()->getRowArray();

        return $s !== null ? (string) $s['id'] : null;
    }

    /** Seed the single target badge (idempotent by code). */
    private function ensureBadge(string $orgId, string $now): void
    {
        $exists = $this->db->table('badges')
            ->where('organization_id', $orgId)->where('code', 'campaign_outreach_champion')->countAllResults();
        if ($exists > 0) {
            return;
        }
        $this->db->table('badges')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'group_id'        => null,
            'code'            => 'campaign_outreach_champion',
            'name'            => 'Outreach Champion',
            'criteria'        => null,
            'visibility'      => 'public',
            'permanent'       => 1,
            'created_at'      => $now,
        ]);
    }

    private function say($stream, string $msg): void
    {
        if (is_cli()) {
            fwrite($stream, $msg);
        }
    }
}
