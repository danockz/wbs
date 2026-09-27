<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Policy\Combinators\SegregationOfDutiesCombinator;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\AccessControl\Services\BreakGlassService;
use WBS\Audit\Services\AuditLogger;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves FR-ACL-006 break-glass honours every clause:
 *   - reason + strong MFA required to open;
 *   - narrow, bounded, hard-capped TTL;
 *   - does NOT bypass immutable financial/audit controls (deny-list);
 *   - a live session CONFERS the permission through the PDP, within scope;
 *   - automatic expiry ends access and leaves the session pending review;
 *   - mandatory post-use review is maker-checker (opener cannot self-review).
 *
 * Mirrors the other AccessControl integration tests: real DB via
 * DatabaseTestTrait, self-skips when no test database is reachable.
 *
 * @internal
 */
final class BreakGlassTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private BreakGlassService $svc;
    private AuthorizationService $pdp;
    private Clock $clock;

    private string $orgId;
    private string $regionId;
    private string $districtId;
    private string $otherId;

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

        $this->clock = new Clock();
        $scope       = new GroupScopeResolver($this->db);
        $this->svc   = new BreakGlassService(
            $this->db,
            $this->clock,
            new AuditLogger($this->db, $this->clock),
            NotificationServices::notifications(),
        );
        $this->pdp = new AuthorizationService($this->db, new AbacConditionEvaluator(), new SegregationOfDutiesCombinator(), $scope);

        $this->orgId      = Uuid::v7();
        $this->regionId   = Uuid::v7();
        $this->districtId = Uuid::v7();
        $this->otherId    = Uuid::v7();

        foreach ([$this->regionId, $this->districtId, $this->otherId] as $gid) {
            $this->db->table('group_closure')->insert(['ancestor_id' => $gid, 'descendant_id' => $gid, 'distance' => 0]);
        }
        $this->db->table('group_closure')->insert(['ancestor_id' => $this->regionId, 'descendant_id' => $this->districtId, 'distance' => 1]);
    }

    public function testOpenRequiresReason(): void
    {
        $res = $this->svc->open($this->orgId, Uuid::v7(), 'high', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => '   ', 'ttl_minutes' => 30,
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('REASON_REQUIRED', $res->code);
    }

    public function testOpenRequiresStrongMfa(): void
    {
        $res = $this->svc->open($this->orgId, Uuid::v7(), 'low', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => 'server on fire', 'ttl_minutes' => 30,
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('MFA_REQUIRED', $res->code);
    }

    public function testTtlIsMandatoryAndCapped(): void
    {
        $missing = $this->svc->open($this->orgId, Uuid::v7(), 'high', [
            'permission_code' => 'event.create', 'reason' => 'x', 'ttl_minutes' => 0,
        ]);
        $this->assertSame('TTL_REQUIRED', $missing->code);

        $tooLong = $this->svc->open($this->orgId, Uuid::v7(), 'high', [
            'permission_code' => 'event.create', 'reason' => 'x', 'ttl_minutes' => 99999,
        ]);
        $this->assertSame('TTL_TOO_LONG', $tooLong->code);
    }

    public function testCannotBreakGlassIntoFinanceControls(): void
    {
        foreach (['contribution.refund.approve', 'contribution.manage', 'event.expense.approve'] as $perm) {
            $res = $this->svc->open($this->orgId, Uuid::v7(), 'high', [
                'permission_code' => $perm, 'scope_group_id' => $this->districtId,
                'reason' => 'trying to bypass', 'ttl_minutes' => 30,
            ]);
            $this->assertFalse($res->ok, "{$perm} must not be obtainable via break-glass");
            $this->assertSame('BREAK_GLASS_FORBIDDEN', $res->code);
        }
    }

    public function testActiveSessionConfersPermissionThroughPdpWithinScope(): void
    {
        $subject = Uuid::v7();
        $res = $this->svc->open($this->orgId, $subject, 'high', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => 'urgent event fix', 'ttl_minutes' => 30,
        ]);
        $this->assertTrue($res->ok);
        $this->assertSame('pending', $res->data['review_state']);

        // Granted in the district...
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['group_id' => $this->districtId],
        )));
        // ...but not in an unrelated branch.
        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['group_id' => $this->otherId],
        )));
    }

    public function testAutoExpiryEndsAccessAndLeavesReviewPending(): void
    {
        $subject = Uuid::v7();
        $open = $this->svc->open($this->orgId, $subject, 'high', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => 'urgent', 'ttl_minutes' => 30,
        ]);
        $sessionId = $open->data['session_id'];

        // Force the window into the past, then run the expiry pass.
        $this->db->table('break_glass_sessions')->where('id', $sessionId)->update([
            'effective_to' => $this->clock->now()->modify('-1 minute')->format('Y-m-d H:i:s.u'),
        ]);
        $expired = $this->svc->expireLapsed($this->orgId);
        $this->assertGreaterThanOrEqual(1, $expired->data['expired']);

        $row = $this->db->table('break_glass_sessions')->where('id', $sessionId)->get()->getRowArray();
        $this->assertSame('expired', $row['status']);
        $this->assertSame('pending', $row['review_state'], 'expiry must still require post-use review');

        // Access is gone.
        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['group_id' => $this->districtId],
        )));
    }

    public function testReviewIsMakerCheckerAndClosesTheLoop(): void
    {
        $opener  = Uuid::v7();
        $open = $this->svc->open($this->orgId, $opener, 'high', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => 'urgent', 'ttl_minutes' => 30, 'subject_id' => $opener,
        ]);
        $sessionId = $open->data['session_id'];

        // Cannot review while still active.
        $tooEarly = $this->svc->review($this->orgId, Uuid::v7(), $sessionId, ['outcome' => 'justified']);
        $this->assertSame('SESSION_STILL_ACTIVE', $tooEarly->code);

        // Close it.
        $this->assertTrue($this->svc->close($this->orgId, $opener, $sessionId)->ok);

        // Opener cannot self-review (maker-checker).
        $self = $this->svc->review($this->orgId, $opener, $sessionId, ['outcome' => 'justified']);
        $this->assertFalse($self->ok);
        $this->assertSame('BREAK_GLASS_SELF_REVIEW', $self->code);

        // A different reviewer can, and it sticks.
        $reviewer = Uuid::v7();
        $ok = $this->svc->review($this->orgId, $reviewer, $sessionId, ['outcome' => 'unjustified', 'notes' => 'not warranted']);
        $this->assertTrue($ok->ok);

        $row = $this->db->table('break_glass_sessions')->where('id', $sessionId)->get()->getRowArray();
        $this->assertSame('reviewed', $row['review_state']);

        // No double review.
        $again = $this->svc->review($this->orgId, Uuid::v7(), $sessionId, ['outcome' => 'justified']);
        $this->assertSame('ALREADY_REVIEWED', $again->code);
    }

    public function testPendingReviewsListsClosedButUnreviewedSessions(): void
    {
        $opener = Uuid::v7();
        $open = $this->svc->open($this->orgId, $opener, 'high', [
            'permission_code' => 'event.create', 'scope_group_id' => $this->districtId,
            'reason' => 'urgent', 'ttl_minutes' => 30,
        ]);
        $this->svc->close($this->orgId, $opener, $open->data['session_id']);

        $pending = $this->svc->pendingReviews($this->orgId);
        $ids = array_column($pending, 'id');
        $this->assertContains($open->data['session_id'], $ids);
    }
}
