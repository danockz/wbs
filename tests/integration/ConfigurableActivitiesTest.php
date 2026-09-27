<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\Gamification\Services\ActivityCatalogService;
use WBS\Gamification\Services\ConfigService;
use WBS\Gamification\Services\FollowUpService;
use WBS\Gamification\Services\PointsEngine;
use WBS\Gamification\Services\RuleService;
use WBS\Gamification\Services\SeasonService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * End-to-end (DB-backed) proof that Win-Build-Send activities are CONFIGURABLE
 * and that the extended earning engine honours the new configuration:
 *
 *  - point_mode fixed | variable | formula (with min/max clamp),
 *  - rolling daily/weekly/monthly limits,
 *  - approval routing (approval_role_code lands on the review row),
 *  - the activity catalog groups active rules by Win/Build/Send phase,
 *  - a configurable follow-up type awards points (with method multipliers) to
 *    the follower through the same immutable ledger,
 *  - typed runtime config round-trips and respects read-only keys.
 *
 * Self-skips when no test database is reachable, so the suite still passes in a
 * bare sandbox.
 *
 * @internal
 */
final class ConfigurableActivitiesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private PointsEngine $engine;
    private RuleService $rules;
    private ActivityCatalogService $catalog;
    private FollowUpService $followUps;
    private ConfigService $config;
    private string $orgId;
    private string $userId;

    protected function setUp(): void
    {
        try {
            $db = Database::connect();
            $db->initialize();
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No test database available: ' . $e->getMessage());
        }

        parent::setUp();

        $clock         = new Clock();
        $seasons       = new SeasonService($this->db, $clock);
        $this->engine  = new PointsEngine($this->db, $clock, $seasons);
        $this->rules   = new RuleService($this->db, $clock);
        $scope           = new \WBS\Shared\Support\GroupScopeResolver($this->db);
        $this->catalog   = new ActivityCatalogService($this->db, $clock, $scope);
        $this->followUps = new FollowUpService($this->db, $clock, $this->engine, $scope);
        $this->config  = new ConfigService($this->db, $clock);

        $this->orgId  = Uuid::v7();
        $this->userId = Uuid::v7();
        $now          = $clock->nowUtcString();

        $this->db->table('gamification_seasons')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'season_year' => (int) date('Y'), 'status' => 'active',
            'starts_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'ends_at'   => date('Y-m-d H:i:s', strtotime('+80 days')),
            'created_at' => $now,
        ]);
    }

    public function testFixedActivityAwardsConfiguredPoints(): void
    {
        $this->rules->create($this->orgId, [
            'code' => 'event.attended', 'event_type' => 'event.attended',
            'points' => 50, 'phase' => 'build', 'activity_name' => 'Event Attendance',
        ]);
        $res = $this->engine->award($this->orgId, 'event.attended', $this->userId, 'evt:1');
        $this->assertTrue($res->ok);
        $this->assertSame(50, $res->data['points']);
    }

    public function testVariableActivityUsesSuppliedValueClampedToBand(): void
    {
        $this->rules->create($this->orgId, [
            'code' => 'leadership.serve', 'event_type' => 'leadership.served',
            'points' => 40, 'point_mode' => 'variable', 'min_points' => 20, 'max_points' => 100,
        ]);
        // Supplied value within band.
        $a = $this->engine->award($this->orgId, 'leadership.serve', $this->userId, 'lead:1', ['data' => ['value' => 65]]);
        $this->assertSame(65, $a->data['points']);
        // Supplied value above max -> clamped to 100.
        $b = $this->engine->award($this->orgId, 'leadership.serve', $this->userId, 'lead:2', ['data' => ['value' => 999]]);
        $this->assertSame(100, $b->data['points']);
    }

    public function testFormulaActivityComputesSafelyAndClamps(): void
    {
        $this->rules->create($this->orgId, [
            'code' => 'contribution.verified', 'event_type' => 'contribution.verified',
            'points' => 10, 'point_mode' => 'formula',
            'point_formula' => 'floor(amount / 10) * base_points',
            'min_points' => 5, 'max_points' => 1000,
        ]);
        // amount 95 -> floor(9.5)=9 * 10 = 90
        $r = $this->engine->award($this->orgId, 'contribution.verified', $this->userId, 'give:1', ['data' => ['amount' => 95]]);
        $this->assertSame(90, $r->data['points']);
        // amount 5 -> floor(0.5)=0 * 10 = 0, clamped up to min 5
        $r2 = $this->engine->award($this->orgId, 'contribution.verified', $this->userId, 'give:2', ['data' => ['amount' => 5]]);
        $this->assertSame(5, $r2->data['points']);
    }

    public function testInvalidFormulaIsRejectedAtSaveTime(): void
    {
        $bad = $this->rules->create($this->orgId, [
            'code' => 'bad.formula', 'event_type' => 'x', 'points' => 10,
            'point_mode' => 'formula', 'point_formula' => '2 ** amount',
        ]);
        $this->assertFalse($bad->ok);
        $this->assertSame('BAD_FORMULA', $bad->code);
    }

    public function testDailyLimitBlocksFurtherAwards(): void
    {
        $this->rules->create($this->orgId, [
            'code' => 'outreach.contact', 'event_type' => 'outreach.logged',
            'points' => 20, 'daily_limit' => 2,
        ]);
        $this->assertTrue($this->engine->award($this->orgId, 'outreach.contact', $this->userId, 'o:1')->ok);
        $this->assertTrue($this->engine->award($this->orgId, 'outreach.contact', $this->userId, 'o:2')->ok);
        $third = $this->engine->award($this->orgId, 'outreach.contact', $this->userId, 'o:3');
        $this->assertFalse($third->ok);
        $this->assertSame('LIMIT_REACHED', $third->code);
        $this->assertSame('day', $third->data['window']);
    }

    public function testApprovalRoleIsRoutedOntoReviewRow(): void
    {
        $this->rules->create($this->orgId, [
            'code' => 'recruit.newmember', 'event_type' => 'member.recruited',
            'points' => 100, 'requires_review' => 1, 'approval_role_code' => 'group_leader',
        ]);
        $res = $this->engine->award($this->orgId, 'recruit.newmember', $this->userId, 'rec:1');
        $this->assertTrue($res->ok);
        $this->assertSame('held', $res->data['state']);
        $review = $this->db->table('fraud_reviews')->where('ledger_id', $res->data['ledger_id'])->get()->getRowArray();
        $this->assertNotNull($review);
        $this->assertSame('group_leader', $review['assigned_role']);
    }

    public function testCatalogGroupsActivitiesByPhase(): void
    {
        $this->catalog->define($this->orgId, ['code' => 'GIVING', 'name' => 'Giving', 'phase' => 'send']);
        $this->rules->create($this->orgId, [
            'code' => 'contribution.verified', 'event_type' => 'contribution.verified',
            'points' => 10, 'phase' => 'send', 'activity_name' => 'Financial Giving',
            'category_id' => $this->db->table('activity_categories')->where('code', 'GIVING')->get()->getRowArray()['id'],
        ]);
        $cat = $this->catalog->catalog($this->orgId);
        $send = null;
        foreach ($cat['phases'] as $p) {
            if ($p['phase'] === 'send') {
                $send = $p;
            }
        }
        $this->assertNotNull($send);
        $this->assertNotEmpty($send['categories']);
        $this->assertSame('GIVING', $send['categories'][0]['category']['code']);
        $this->assertSame('contribution.verified', $send['categories'][0]['activities'][0]['code']);
    }

    public function testRecordingFollowUpAwardsPointsWithMethodMultiplier(): void
    {
        // Award rule: base 20, a visit is 1.5x.
        $this->rules->create($this->orgId, [
            'code' => 'followup.member', 'event_type' => 'followup.logged', 'points' => 20,
            'multipliers' => [['factor' => 1.5, 'when' => ['field' => 'method', 'op' => '==', 'value' => 'visit']]],
        ]);
        $this->followUps->defineMethod($this->orgId, ['code' => 'visit', 'name' => 'Visit', 'multiplier_key' => 'visit']);
        $this->followUps->defineType($this->orgId, [
            'code' => 'absent_member', 'name' => 'Absent Member', 'phase' => 'build',
            'award_rule_code' => 'followup.member', 'default_next_days' => 7,
        ]);

        $subject = Uuid::v7();
        $res = $this->followUps->record($this->orgId, $this->userId, [
            'type_code' => 'absent_member', 'method_code' => 'visit',
            'subject_user_id' => $subject, 'summary' => 'Checked in',
        ]);
        $this->assertTrue($res->ok);
        // 20 * 1.5 = 30 points awarded to the follower.
        $this->assertSame(30, $res->data['award']['points']);
        // Next follow-up auto-scheduled from the type default (7 days).
        $this->assertNotNull($res->data['next_follow_up_at']);

        // The follow-up row links to the ledger entry.
        $row = $this->db->table('follow_ups')->where('id', $res->data['id'])->get()->getRowArray();
        $this->assertNotNull($row['award_ledger_id']);

        // And it shows up as due work when the schedule elapses.
        $due = $this->followUps->dueFollowUps($this->orgId, $this->userId, date('Y-m-d H:i:s', strtotime('+8 days')));
        $this->assertCount(1, $due);
    }

    public function testUnknownFollowUpTypeIsRejected(): void
    {
        $this->followUps->defineMethod($this->orgId, ['code' => 'call', 'name' => 'Call']);
        $res = $this->followUps->record($this->orgId, $this->userId, [
            'type_code' => 'nope', 'method_code' => 'call', 'subject_user_id' => Uuid::v7(),
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('UNKNOWN_TYPE', $res->code);
    }

    public function testTypedConfigRoundTripsAndReadOnlyIsProtected(): void
    {
        $this->config->set($this->orgId, 'leaderboard_cache_ttl', 300, ['type' => 'integer']);
        $this->assertSame(300, $this->config->get($this->orgId, 'leaderboard_cache_ttl'));

        $this->config->set($this->orgId, 'followups_award_enabled', true, ['type' => 'boolean']);
        $this->assertTrue($this->config->get($this->orgId, 'followups_award_enabled'));

        // A read-only key refuses further writes.
        $this->config->set($this->orgId, 'engine_version', '1', ['type' => 'string', 'is_editable' => false]);
        $blocked = $this->config->set($this->orgId, 'engine_version', '2');
        $this->assertFalse($blocked->ok);
        $this->assertSame('CONFIG_READONLY', $blocked->code);
        $this->assertSame('1', $this->config->get($this->orgId, 'engine_version'));
    }

    // ---- CRUD gap-fills ----------------------------------------------------

    public function testRuleShowReturnsCurrentAndVersionHistory(): void
    {
        $this->rules->create($this->orgId, ['code' => 'event.attended', 'event_type' => 'event.attended', 'points' => 50]);
        $this->rules->update($this->orgId, 'event.attended', ['points' => 60]); // supersede -> v2

        $res = $this->rules->show($this->orgId, 'event.attended');
        $this->assertTrue($res->ok);
        $this->assertSame(60, (int) $res->data['current']['points']);
        $this->assertSame(2, (int) $res->data['current']['version']);
        $this->assertCount(2, $res->data['versions']);

        $missing = $this->rules->show($this->orgId, 'nope');
        $this->assertFalse($missing->ok);
        $this->assertSame('RULE_NOT_FOUND', $missing->code);
    }

    public function testCategoryShowIncludesActivityCount(): void
    {
        $this->catalog->define($this->orgId, ['code' => 'GIVING', 'name' => 'Giving', 'phase' => 'send']);
        $catId = $this->db->table('activity_categories')->where('code', 'GIVING')->get()->getRowArray()['id'];
        $this->rules->create($this->orgId, ['code' => 'contribution.verified', 'event_type' => 'contribution.verified', 'points' => 10, 'category_id' => $catId]);

        $res = $this->catalog->showCategory($this->orgId, 'GIVING');
        $this->assertTrue($res->ok);
        $this->assertSame('Giving', $res->data['name']);
        $this->assertSame(1, (int) $res->data['activity_count']);
    }

    public function testConfigShowAndDeleteRespectingReadOnly(): void
    {
        $this->config->set($this->orgId, 'points_cache_ttl', 3600, ['type' => 'integer']);
        $show = $this->config->show($this->orgId, 'points_cache_ttl');
        $this->assertTrue($show->ok);
        $this->assertSame(3600, $show->data['value']);
        $this->assertSame('integer', $show->data['type']);

        // Delete an editable key.
        $del = $this->config->delete($this->orgId, 'points_cache_ttl');
        $this->assertTrue($del->ok);
        $this->assertNull($this->config->get($this->orgId, 'points_cache_ttl'));

        // A read-only key refuses deletion.
        $this->config->set($this->orgId, 'engine_version', '1', ['type' => 'string', 'is_editable' => false]);
        $blocked = $this->config->delete($this->orgId, 'engine_version');
        $this->assertFalse($blocked->ok);
        $this->assertSame('CONFIG_READONLY', $blocked->code);
    }

    public function testFollowUpTypeAndMethodShowAndDisable(): void
    {
        $this->followUps->defineType($this->orgId, ['code' => 'absent_member', 'name' => 'Absent Member', 'phase' => 'build']);
        $this->followUps->defineMethod($this->orgId, ['code' => 'call', 'name' => 'Call']);

        $t = $this->followUps->showType($this->orgId, 'absent_member');
        $this->assertTrue($t->ok);
        $this->assertSame('Absent Member', $t->data['name']);

        $dt = $this->followUps->disableType($this->orgId, 'absent_member');
        $this->assertTrue($dt->ok);
        $this->assertSame('inactive', $this->db->table('follow_up_types')->where('code', 'absent_member')->get()->getRowArray()['status']);

        $m = $this->followUps->showMethod($this->orgId, 'call');
        $this->assertTrue($m->ok);
        $dm = $this->followUps->disableMethod($this->orgId, 'call');
        $this->assertTrue($dm->ok);
        $this->assertSame('inactive', $this->db->table('follow_up_methods')->where('code', 'call')->get()->getRowArray()['status']);
    }

    public function testFollowUpRecordLifecycleUpdateAwardsOnCompletionThenCancel(): void
    {
        $this->rules->create($this->orgId, ['code' => 'followup.member', 'event_type' => 'followup.logged', 'points' => 20]);
        $this->followUps->defineMethod($this->orgId, ['code' => 'call', 'name' => 'Call', 'multiplier_key' => 'call']);
        $this->followUps->defineType($this->orgId, ['code' => 'absent_member', 'name' => 'Absent Member', 'award_rule_code' => 'followup.member']);

        $subject = Uuid::v7();
        // Record it PENDING -> no award yet (status doesn't count).
        $rec = $this->followUps->record($this->orgId, $this->userId, [
            'type_code' => 'absent_member', 'method_code' => 'call',
            'subject_user_id' => $subject, 'status' => 'pending',
        ]);
        $this->assertTrue($rec->ok);
        $this->assertNull($rec->data['award']);
        $fid = $rec->data['id'];

        // Read it back.
        $show = $this->followUps->showRecord($this->orgId, $fid);
        $this->assertTrue($show->ok);
        $this->assertSame('pending', $show->data['status']);

        // Transition to completed -> awards now, idempotently.
        $upd = $this->followUps->updateRecord($this->orgId, $fid, ['status' => 'completed', 'outcome' => 'Reached them']);
        $this->assertTrue($upd->ok);
        $this->assertSame(20, $upd->data['award']['points']);
        $this->assertNotNull($this->db->table('follow_ups')->where('id', $fid)->get()->getRowArray()['award_ledger_id']);

        // A second update does not double-award (already has a ledger id).
        $again = $this->followUps->updateRecord($this->orgId, $fid, ['summary' => 'note']);
        $this->assertTrue($again->ok);
        $this->assertNull($again->data['award']);

        // Cancel it.
        $cancel = $this->followUps->cancelRecord($this->orgId, $fid);
        $this->assertTrue($cancel->ok);
        $this->assertSame('cancelled', $this->db->table('follow_ups')->where('id', $fid)->get()->getRowArray()['status']);

        // A cancelled record refuses further updates.
        $blocked = $this->followUps->updateRecord($this->orgId, $fid, ['status' => 'completed']);
        $this->assertFalse($blocked->ok);
        $this->assertSame('FOLLOWUP_CANCELLED', $blocked->code);
    }

    public function testUpdateRecordRequiresOutcomeWhenTypeDemandsIt(): void
    {
        $this->followUps->defineMethod($this->orgId, ['code' => 'call', 'name' => 'Call']);
        $this->followUps->defineType($this->orgId, ['code' => 'new_visitor', 'name' => 'New Visitor', 'requires_outcome' => 1]);

        $rec = $this->followUps->record($this->orgId, $this->userId, [
            'type_code' => 'new_visitor', 'method_code' => 'call',
            'subject_user_id' => Uuid::v7(), 'status' => 'in_progress',
        ]);
        $this->assertTrue($rec->ok);

        // Completing without an outcome is rejected.
        $bad = $this->followUps->updateRecord($this->orgId, $rec->data['id'], ['status' => 'completed']);
        $this->assertFalse($bad->ok);
        $this->assertSame('OUTCOME_REQUIRED', $bad->code);
    }
}
