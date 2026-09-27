<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\Gamification\Services\CampaignService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * End-to-end (DB-backed) proof that a GROUP/TEAM earns its OWN milestone award.
 *
 * Unlike the pure CampaignAwardsDeservedTest (which locks the decision math),
 * this drives CampaignService::recordProgress() against a real database and
 * asserts the side effects actually land: the team's standing row crosses its
 * target, awards_count flips 0 -> 1, and concrete campaign_awards + badge_awards
 * rows are written with the TEAM as the subject.
 *
 * It also proves attribution is CONTRIBUTION-DIRECTED: a member's gift is
 * credited to the team it was directed at (team_ref), and the team milestone is
 * granted exactly once (idempotent) no matter how far the total overshoots.
 *
 * Requires a test database (migrations run via DatabaseTestTrait). When no test
 * DB is configured/reachable it self-skips, so the suite still passes in
 * environments without MySQL (e.g. a bare sandbox).
 *
 * @internal
 */
final class CampaignTeamMilestoneTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // Run all module migrations for a clean schema, fresh per test.
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null; // every namespace, so module migrations apply

    private CampaignService $svc;
    private string $orgId;
    private string $groupId;
    private string $districtId;
    private string $seasonId;
    /** @var array<string,string> short-name => user id */
    private array $users = [];

    protected function setUp(): void
    {
        // Skip cleanly when there is no reachable test database.
        try {
            $db = Database::connect();
            $db->initialize();
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No test database available: ' . $e->getMessage());
        }

        parent::setUp();

        $this->svc = new CampaignService($this->db, new Clock());
        $this->seedFixtures();
    }

    public function testTeamReachesItsOwnMilestoneAndEarnsBadge(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: 190000);
        $redId      = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];
        $blueId     = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'blue', 'name' => 'Blue Team'])->data['team_id'];

        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['ama']);
        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['kofi']);
        $this->svc->addTeamMember($this->orgId, $campaignId, $blueId, $this->users['yaw']);

        $this->assertTrue($this->svc->activate($this->orgId, $campaignId)->ok);

        // Red gifts total 195000 (>= 190000 target); Blue totals 90000 (< target).
        $this->drive($campaignId, $this->users['ama'], 120000, $redId, 'g1');
        $this->drive($campaignId, $this->users['kofi'], 75000, $redId, 'g2');
        $this->drive($campaignId, $this->users['yaw'], 90000, $blueId, 'g3');

        // -- Team standings: Red crossed its milestone, Blue did not ----------
        $red = $this->standing($campaignId, $redId);
        $this->assertSame(195000, (int) $red['total_value']);
        $this->assertSame(2, (int) $red['contributors']);       // distinct members
        $this->assertSame(1, (int) $red['awards_count']);       // milestone granted

        $blue = $this->standing($campaignId, $blueId);
        $this->assertSame(90000, (int) $blue['total_value']);
        $this->assertSame(0, (int) $blue['awards_count']);      // short of target

        // -- Concrete award rows written with the TEAM as subject ------------
        $award = $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $redId)
            ->get()->getRowArray();
        $this->assertNotNull($award, 'Red team should have a campaign_awards row');
        $this->assertSame('team', (string) $award['subject_type']);
        $this->assertSame(1, (int) $award['award_index']);
        $this->assertNotNull($award['badge_award_id'], 'team milestone should mint a badge');

        // Badge award subject is the team, not a user.
        $badgeAward = $this->db->table('badge_awards')->where('id', $award['badge_award_id'])->get()->getRowArray();
        $this->assertNotNull($badgeAward);
        $this->assertSame($redId, (string) $badgeAward['subject_id']);

        // Blue has NO team award row.
        $this->assertSame(0, $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $blueId)->countAllResults());
    }

    public function testTeamMilestoneGrantedExactlyOnceOnOvershoot(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: 100000);
        $redId      = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];
        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['ama']);
        $this->svc->activate($this->orgId, $campaignId);

        // Three separate gifts, each individually over target and cumulatively 3x.
        $this->drive($campaignId, $this->users['ama'], 120000, $redId, 'a');
        $this->drive($campaignId, $this->users['ama'], 120000, $redId, 'b');
        $this->drive($campaignId, $this->users['ama'], 120000, $redId, 'c');

        $red = $this->standing($campaignId, $redId);
        $this->assertSame(360000, (int) $red['total_value']);
        $this->assertSame(1, (int) $red['awards_count'], 'milestone must not re-grant on overshoot');

        // Exactly one team award row (award_index 1) despite three crossings.
        $this->assertSame(1, $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $redId)->countAllResults());
    }

    public function testNoTeamTargetMeansScoreboardOnlyNoAward(): void
    {
        // team_target_value NULL: teams still tally, but earn no milestone award.
        $campaignId = $this->createGivingCampaign(teamTarget: null);
        $redId      = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];
        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['ama']);
        $this->svc->activate($this->orgId, $campaignId);

        $this->drive($campaignId, $this->users['ama'], 500000, $redId, 'big');

        $red = $this->standing($campaignId, $redId);
        $this->assertSame(500000, (int) $red['total_value']);
        $this->assertSame(0, (int) $red['awards_count']);
        $this->assertSame(0, $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $redId)->countAllResults());
    }

    public function testLeaderboardCarriesEachMembersHierarchicalGroupPath(): void
    {
        // Plain individual campaign (no team challenge → no roster needed).
        $campaignId = $this->createGivingCampaign(teamTarget: null, teamChallenge: false);
        $this->svc->activate($this->orgId, $campaignId);

        // No explicit target_ref/subject_group_id: recordProgress must resolve
        // each member's most-specific group (the district) on first contribution.
        $this->drive($campaignId, $this->users['ama'], 120000, null, 'l1');
        $this->drive($campaignId, $this->users['kofi'], 60000, null, 'l2');

        $board = $this->svc->leaderboard($this->orgId, $campaignId, 20);
        $this->assertNotEmpty($board);

        $top = $board[0];
        $this->assertSame($this->users['ama'], (string) $top['subject_id']);
        $this->assertSame($this->districtId, (string) $top['group_id']);
        $this->assertSame('District', (string) $top['group_name']); // leaf name
        // Full path root→leaf.
        $this->assertSame(['Region', 'District'], $top['group_path']);
    }

    public function testSubtreeSubgroupEarnsItsOwnMilestoneAsGroupSubject(): void
    {
        // SUBTREE flavour: the competing team is a real SUBGROUP (a group id),
        // credited via team_kind='group' — no roster tables. The subgroup earns
        // its own milestone award with subject_type='group'.
        $campaignId = $this->createGivingCampaign(teamTarget: 150000, subtree: true);
        $this->svc->activate($this->orgId, $campaignId);

        // Two members of the district give; their gifts roll up to the district.
        $this->drive($campaignId, $this->users['ama'], 90000, $this->districtId, 's1', 'group');
        $this->drive($campaignId, $this->users['kofi'], 70000, $this->districtId, 's2', 'group');

        // Subgroup standing crossed its target (90000 + 70000 = 160000 >= 150000).
        $standing = $this->standing($campaignId, $this->districtId);
        $this->assertSame(160000, (int) $standing['total_value']);
        $this->assertSame('group', (string) $standing['team_kind']);
        $this->assertSame(2, (int) $standing['contributors']);
        $this->assertSame(1, (int) $standing['awards_count']);

        // A concrete award row with the SUBGROUP (group id) as the subject.
        $award = $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $this->districtId)
            ->get()->getRowArray();
        $this->assertNotNull($award, 'the subgroup should have a campaign_awards row');
        $this->assertSame('group', (string) $award['subject_type']);
        $this->assertSame(1, (int) $award['award_index']);
        $this->assertNotNull($award['badge_award_id'], 'subgroup milestone should mint a badge');

        // Badge award subject is the group id.
        $badgeAward = $this->db->table('badge_awards')->where('id', $award['badge_award_id'])->get()->getRowArray();
        $this->assertNotNull($badgeAward);
        $this->assertSame($this->districtId, (string) $badgeAward['subject_id']);
    }

    public function testBaseProjectNoTeamsGrantsIndividualAwardAndHasNoTeamStandings(): void
    {
        // BASE case: no team challenge at all. A member reaching the individual
        // target earns the single award; nothing touches team standings.
        $campaignId = $this->createGivingCampaign(teamTarget: null, teamChallenge: false);
        $this->svc->activate($this->orgId, $campaignId);

        // Individual target is 1,000,000 (see createGivingCampaign). Cross it.
        $this->drive($campaignId, $this->users['ama'], 600000, null, 'b1');
        $this->drive($campaignId, $this->users['ama'], 500000, null, 'b2'); // cumulative 1,100,000

        $progress = $this->db->table('campaign_progress')
            ->where('campaign_id', $campaignId)->where('subject_id', $this->users['ama'])
            ->get()->getRowArray();
        $this->assertSame(1100000, (int) $progress['current_value']);
        $this->assertSame(1, (int) $progress['awards_count']); // single award granted once

        // A concrete individual award row (subject_type='user').
        $award = $this->db->table('campaign_awards')
            ->where('campaign_id', $campaignId)->where('subject_id', $this->users['ama'])
            ->get()->getRowArray();
        $this->assertNotNull($award);
        $this->assertSame('user', (string) $award['subject_type']);

        // No team standings exist for a base (no-challenge) campaign.
        $this->assertSame(0, $this->db->table('campaign_team_standings')
            ->where('campaign_id', $campaignId)->countAllResults());
    }

    public function testShowReturnsCampaignWithTiersAndTeamsInlined(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: 100000);
        $redId = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];

        $res = $this->svc->show($this->orgId, $campaignId);
        $this->assertTrue($res->ok);
        $this->assertSame($campaignId, (string) $res->data['id']);
        $this->assertIsArray($res->data['activity_scope']);   // decoded back to array
        $this->assertArrayHasKey('tiers', $res->data);
        $this->assertArrayHasKey('teams', $res->data);
        $this->assertSame($redId, (string) $res->data['teams'][0]['id']);
    }

    public function testUpdateEditsDraftFieldsButRejectsOnceActive(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: null, teamChallenge: false);

        // Draft: editable.
        $res = $this->svc->update($this->orgId, $campaignId, [
            'name'         => 'Renamed Drive',
            'target_value' => 250000,
            'description'  => 'updated',
        ]);
        $this->assertTrue($res->ok);
        $row = $this->db->table('group_campaigns')->where('id', $campaignId)->get()->getRowArray();
        $this->assertSame('Renamed Drive', (string) $row['name']);
        $this->assertSame(250000, (int) $row['target_value']);

        // Once active: frozen.
        $this->svc->activate($this->orgId, $campaignId);
        $blocked = $this->svc->update($this->orgId, $campaignId, ['name' => 'Nope']);
        $this->assertFalse($blocked->ok);
        $this->assertSame('BAD_STATE', (string) $blocked->code);
    }

    public function testUpdateRejectsNonPositiveTargetForSingleMode(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: null, teamChallenge: false);
        $res = $this->svc->update($this->orgId, $campaignId, ['target_value' => 0]);
        $this->assertFalse($res->ok);
        $this->assertSame('BAD_TARGET', (string) $res->code);
    }

    public function testUpdateTeamRenamesAndDeleteTeamRemovesRosterInDraft(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: 100000);
        $redId = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];
        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['ama']);

        // Rename / recolor.
        $upd = $this->svc->updateTeam($this->orgId, $campaignId, $redId, ['name' => 'Crimson', 'color' => '#900']);
        $this->assertTrue($upd->ok);
        $team = $this->db->table('campaign_teams')->where('id', $redId)->get()->getRowArray();
        $this->assertSame('Crimson', (string) $team['name']);
        $this->assertSame('#900', (string) $team['color']);

        // Delete (draft): team + its roster gone.
        $del = $this->svc->deleteTeam($this->orgId, $campaignId, $redId);
        $this->assertTrue($del->ok);
        $this->assertSame(0, $this->db->table('campaign_teams')->where('id', $redId)->countAllResults());
        $this->assertSame(0, $this->db->table('campaign_team_members')->where('team_id', $redId)->countAllResults());
    }

    public function testDeleteTeamRejectedOnceCampaignActive(): void
    {
        $campaignId = $this->createGivingCampaign(teamTarget: 100000);
        $redId = (string) $this->svc->defineTeam($this->orgId, $campaignId, ['code' => 'red', 'name' => 'Red Team'])->data['team_id'];
        $this->svc->addTeamMember($this->orgId, $campaignId, $redId, $this->users['ama']);
        $this->svc->activate($this->orgId, $campaignId);

        $del = $this->svc->deleteTeam($this->orgId, $campaignId, $redId);
        $this->assertFalse($del->ok);
        $this->assertSame('BAD_STATE', (string) $del->code);
        $this->assertSame(1, $this->db->table('campaign_teams')->where('id', $redId)->countAllResults());
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function drive(string $campaignId, string $userId, int $amount, ?string $teamRef, string $ref, string $teamKind = 'team'): void
    {
        $opts = ['sourceRef' => 'it:' . $ref];
        if ($teamRef !== null) {
            $opts['team_ref']  = $teamRef;
            $opts['team_kind'] = $teamKind;
        }
        $res = $this->svc->recordProgress($this->orgId, $campaignId, $userId, $amount, $opts);
        $this->assertTrue($res->ok, 'recordProgress should succeed: ' . (string) $res->code);
    }

    private function standing(string $campaignId, string $teamRef): array
    {
        return $this->db->table('campaign_team_standings')
            ->where('campaign_id', $campaignId)->where('team_ref', $teamRef)
            ->get()->getRowArray() ?? [];
    }

    private function createGivingCampaign(?int $teamTarget, bool $teamChallenge = true, bool $subtree = false): string
    {
        $res = $this->svc->create($this->orgId, [
            'group_id'            => $this->groupId,
            'season_id'           => $this->seasonId,
            'code'                => 'it-giving-' . substr(Uuid::v7(), 0, 8),
            'name'                => 'IT Giving Challenge',
            'metric'              => 'amount',
            'award_mode'          => 'single',
            'target_value'        => 1000000, // individual target (not under test here)
            'badge_code'          => 'it_individual',
            'team_challenge'      => $teamChallenge ? 1 : 0,
            'team_mode'           => $subtree ? 'subtree' : 'adhoc',
            'team_target_value'   => $teamTarget,
            'team_badge_code'     => $teamTarget !== null ? 'it_team_champion' : null,
            'team_award_points'   => 250,
            'rollup_to_general'   => 1,
            'recognize_top_teams' => 2,
            'starts_at'           => date('Y-m-d H:i:s', strtotime('-1 day')),
            'ends_at'             => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);
        $this->assertTrue($res->ok, 'create should succeed: ' . (string) $res->code);

        return (string) $res->data['campaign_id'];
    }

    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->orgId = Uuid::v7();
        $this->db->table('organizations')->insert([
            'id' => $this->orgId, 'name' => 'IT Org', 'slug' => 'it-org-' . substr($this->orgId, 0, 8),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->groupId = Uuid::v7();
        $this->db->table('groups')->insert([
            'id' => $this->groupId, 'organization_id' => $this->orgId, 'parent_id' => null,
            'name' => 'Region', 'slug' => 'it-region-' . substr($this->groupId, 0, 8), 'type' => 'region',
            'depth' => 1, 'path' => '/' . $this->groupId . '/', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $this->groupId, 'descendant_id' => $this->groupId, 'distance' => 0,
        ]);

        // A child DISTRICT so members have a specific hierarchical group and
        // the leaderboard can render a real Region › District path.
        $this->districtId = Uuid::v7();
        $this->db->table('groups')->insert([
            'id' => $this->districtId, 'organization_id' => $this->orgId, 'parent_id' => $this->groupId,
            'name' => 'District', 'slug' => 'it-district-' . substr($this->districtId, 0, 8), 'type' => 'district',
            'depth' => 2, 'path' => '/' . $this->groupId . '/' . $this->districtId . '/', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $this->districtId, 'descendant_id' => $this->districtId, 'distance' => 0,
        ]);
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $this->groupId, 'descendant_id' => $this->districtId, 'distance' => 1,
        ]);

        // Active season (points roll-up target).
        $this->seasonId = Uuid::v7();
        $this->db->table('gamification_seasons')->insert([
            'id' => $this->seasonId, 'organization_id' => $this->orgId,
            'season_year' => (int) date('Y'), 'status' => 'active',
            'starts_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'ends_at'   => date('Y-m-d H:i:s', strtotime('+80 days')),
            'created_at' => $now,
        ]);

        // Badges the campaign references.
        foreach (['it_individual' => 'Individual Giver', 'it_team_champion' => 'Team Champion'] as $code => $name) {
            $this->db->table('badges')->insert([
                'id' => Uuid::v7(), 'organization_id' => $this->orgId, 'group_id' => null,
                'code' => $code, 'name' => $name, 'criteria' => null, 'visibility' => 'public',
                'permanent' => 1, 'expiry_policy' => null, 'created_at' => $now,
            ]);
        }

        // Members (active in the group).
        foreach (['ama', 'kofi', 'yaw'] as $key) {
            $id = Uuid::v7();
            $this->users[$key] = $id;
            $this->db->table('users')->insert([
                'id' => $id, 'organization_id' => $this->orgId, 'email' => $key . '.' . substr($id, 0, 8) . '@it.test',
                'email_verified' => 1, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
                'display_name' => ucfirst($key), 'status' => 'active', 'locale' => 'en',
                'timezone' => 'UTC', 'mfa_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // Members live in the DISTRICT (their most-specific hierarchical
            // group), so the leaderboard renders Region › District.
            $this->db->table('group_members')->insert([
                'id' => Uuid::v7(), 'organization_id' => $this->orgId, 'group_id' => $this->districtId,
                'user_id' => $id, 'role' => 'member', 'status' => 'active', 'joined_at' => $now,
            ]);
        }
    }
}
