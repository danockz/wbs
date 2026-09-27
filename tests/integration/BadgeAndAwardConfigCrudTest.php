<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\Gamification\Services\AchievementService;
use WBS\Gamification\Services\BadgeService;
use WBS\Gamification\Services\PointsEngine;
use WBS\Gamification\Services\RankService;
use WBS\Gamification\Services\SeasonService;
use WBS\Gamification\Services\StreakService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * CRUD coverage for BADGES and the read-one getters added to the other award
 * config surfaces (ranks / achievements / streaks). Proves:
 *   - badge define (create + update upsert), list, show, disable (soft),
 *   - manual badge grant (idempotent), revoke (soft, no hard-delete), reinstate,
 *   - rank/achievement/streak show() read-one getters + NOT_FOUND behaviour.
 *
 * Self-skips when no test DB is reachable so the suite still passes bare.
 *
 * @internal
 */
final class BadgeAndAwardConfigCrudTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private BadgeService $badges;
    private RankService $ranks;
    private AchievementService $achievements;
    private StreakService $streaks;
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

        $clock   = new Clock();
        $scope   = new GroupScopeResolver($this->db);
        $seasons = new SeasonService($this->db, $clock);
        $engine  = new PointsEngine($this->db, $clock, $seasons);

        $this->badges       = new BadgeService($this->db, $clock, $scope);
        $this->ranks        = new RankService($this->db, $clock, $scope);
        $this->streaks      = new StreakService($this->db, $clock, $scope);
        $this->achievements = new AchievementService($this->db, $clock, $engine, $seasons, $this->streaks, $this->ranks, $scope);

        $this->orgId  = Uuid::v7();
        $this->userId = Uuid::v7();
    }

    // ---- Badges ------------------------------------------------------------

    public function testBadgeDefineCreatesThenUpdates(): void
    {
        $c = $this->badges->define($this->orgId, ['code' => 'champion', 'name' => 'Champion', 'description' => 'Top performer']);
        $this->assertTrue($c->ok);
        $this->assertArrayNotHasKey('updated', $c->data);

        $u = $this->badges->define($this->orgId, ['code' => 'champion', 'name' => 'Champion (v2)']);
        $this->assertTrue($u->ok);
        $this->assertTrue($u->data['updated']);

        $show = $this->badges->show($this->orgId, 'champion');
        $this->assertTrue($show->ok);
        $this->assertSame('Champion (v2)', $show->data['name']);
    }

    public function testBadgeListAndShowNotFound(): void
    {
        $this->badges->define($this->orgId, ['code' => 'a', 'name' => 'A', 'sort_order' => 2]);
        $this->badges->define($this->orgId, ['code' => 'b', 'name' => 'B', 'sort_order' => 1]);

        $list = $this->badges->list($this->orgId);
        $this->assertCount(2, $list);
        $this->assertSame('b', $list[0]['code'], 'ordered by sort_order');

        $missing = $this->badges->show($this->orgId, 'nope');
        $this->assertFalse($missing->ok);
        $this->assertSame('BADGE_NOT_FOUND', $missing->code);
    }

    public function testBadgeDisableSoftDeletes(): void
    {
        $this->badges->define($this->orgId, ['code' => 'temp', 'name' => 'Temp']);
        $res = $this->badges->disable($this->orgId, 'temp');
        $this->assertTrue($res->ok);
        $this->assertSame('inactive', $res->data['status']);

        // Still returned unfiltered, but excluded from active-only list.
        $this->assertCount(0, $this->badges->list($this->orgId, true));
        $this->assertCount(1, $this->badges->list($this->orgId, false));
    }

    public function testBadgeGrantIsIdempotentAndRevocable(): void
    {
        $this->badges->define($this->orgId, ['code' => 'streaker', 'name' => 'Streaker']);

        $g1 = $this->badges->grant($this->orgId, 'streaker', $this->userId);
        $this->assertTrue($g1->ok);
        $this->assertArrayHasKey('badge_award_id', $g1->data);

        // Second grant (same subject, same null season) is idempotent.
        $g2 = $this->badges->grant($this->orgId, 'streaker', $this->userId);
        $this->assertTrue($g2->ok);
        $this->assertTrue($g2->data['already_awarded'] ?? false);

        $this->assertCount(1, $this->badges->awardsForSubject($this->orgId, $this->userId));

        // Revoke is soft: the award row remains but drops out of active awards.
        $rev = $this->badges->revoke($this->orgId, 'streaker', $this->userId);
        $this->assertTrue($rev->ok);
        $this->assertSame('revoked', $rev->data['state']);
        $this->assertCount(0, $this->badges->awardsForSubject($this->orgId, $this->userId));
        $this->assertCount(1, $this->badges->awardsForSubject($this->orgId, $this->userId, true));

        // Re-granting reinstates the same row.
        $g3 = $this->badges->grant($this->orgId, 'streaker', $this->userId);
        $this->assertTrue($g3->ok);
        $this->assertTrue($g3->data['reinstated'] ?? false);
    }

    public function testGrantOnInactiveBadgeIsRejected(): void
    {
        $this->badges->define($this->orgId, ['code' => 'gone', 'name' => 'Gone', 'status' => 'inactive']);
        $res = $this->badges->grant($this->orgId, 'gone', $this->userId);
        $this->assertFalse($res->ok);
        $this->assertSame('BADGE_INACTIVE', $res->code);
    }

    // ---- Read-one getters on the other award surfaces ----------------------

    public function testRankShowReadOne(): void
    {
        $this->ranks->define($this->orgId, ['code' => 'gold', 'name' => 'Gold', 'min_points' => 1000]);
        $show = $this->ranks->show($this->orgId, 'gold');
        $this->assertTrue($show->ok);
        $this->assertSame(1000, (int) $show->data['min_points']);

        $missing = $this->ranks->show($this->orgId, 'platinum');
        $this->assertSame('RANK_NOT_FOUND', $missing->code);
    }

    public function testAchievementShowReadOne(): void
    {
        $this->achievements->define($this->orgId, [
            'code' => 'first_win', 'name' => 'First Win',
            'trigger_type' => 'points', 'trigger_config' => ['threshold' => 100],
        ]);
        $show = $this->achievements->show($this->orgId, 'first_win');
        $this->assertTrue($show->ok);
        $this->assertSame('First Win', $show->data['name']);
        $this->assertIsArray($show->data['trigger_config']);

        $this->assertSame('ACHIEVEMENT_NOT_FOUND', $this->achievements->show($this->orgId, 'nope')->code);
    }

    public function testStreakShowReadOne(): void
    {
        $this->streaks->define($this->orgId, ['code' => 'daily', 'name' => 'Daily Devotion']);
        $show = $this->streaks->show($this->orgId, 'daily');
        $this->assertTrue($show->ok);
        $this->assertSame('Daily Devotion', $show->data['name']);

        $this->assertSame('STREAK_NOT_FOUND', $this->streaks->show($this->orgId, 'weekly')->code);
    }
}
