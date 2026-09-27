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
use WBS\AccessControl\Services\DelegationService;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves FR-ACL-005 delegation enforces every invariant and that a valid
 * delegation actually CONFERS access through the PDP:
 *
 *  - possess-it: cannot delegate a permission you don't hold;
 *  - equal/narrower scope: can delegate a child scope from a region grant, but
 *    not a broader/unrelated scope;
 *  - duration ≤ your own authority and always bounded;
 *  - configurable max chain depth (env acl.maxDelegationDepth);
 *  - fully traceable (parent_id/depth + chain()) and revocable (cascades);
 *  - the PDP grants the delegate the permission within the delegated scope.
 *
 * Mirrors the other AccessControl integration tests: real DB via
 * DatabaseTestTrait, self-skips when no test database is reachable.
 *
 * @internal
 */
final class DelegationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private DelegationService $svc;
    private AuthorizationService $pdp;
    private Clock $clock;

    private string $orgId;
    private string $regionId;
    private string $districtId;
    private string $otherId;
    private string $permission = 'event.create';

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
        $this->svc   = new DelegationService($this->db, $this->clock, $scope, new AuditLogger($this->db, $this->clock));
        $this->pdp   = new AuthorizationService($this->db, new AbacConditionEvaluator(), new SegregationOfDutiesCombinator(), $scope);

        $this->orgId      = Uuid::v7();
        $this->regionId   = Uuid::v7();
        $this->districtId = Uuid::v7();
        $this->otherId    = Uuid::v7();

        foreach ([$this->regionId, $this->districtId, $this->otherId] as $gid) {
            $this->db->table('group_closure')->insert(['ancestor_id' => $gid, 'descendant_id' => $gid, 'distance' => 0]);
        }
        $this->db->table('group_closure')->insert(['ancestor_id' => $this->regionId, 'descendant_id' => $this->districtId, 'distance' => 1]);

        putenv('acl.maxDelegationDepth=2');
    }

    protected function tearDown(): void
    {
        putenv('acl.maxDelegationDepth');
        parent::tearDown();
    }

    public function testCannotDelegateAPermissionNotHeld(): void
    {
        $leader = Uuid::v7(); // holds nothing

        $res = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'help', 'duration_days' => 5,
        ]);

        $this->assertFalse($res->ok);
        $this->assertSame('NOT_AUTHORIZED_TO_DELEGATE', $res->code);
    }

    public function testDelegateNarrowerScopeFromRegionGrantAndPdpConfersIt(): void
    {
        $leader   = $this->giveRoleGrant($this->regionId, includeDescendants: true);
        $delegate = Uuid::v7();

        $res = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => $delegate, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'include_descendants' => 0,
            'purpose' => 'cover the district event', 'duration_days' => 7,
        ]);
        $this->assertTrue($res->ok, 'a region-with-descendants leader may delegate within a district');
        $this->assertSame(1, $res->data['depth']);

        // PDP now grants the delegate event.create in the district.
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $delegate, action: $this->permission,
            attributes: ['group_id' => $this->districtId],
        )));
        // ...but NOT in an unrelated branch.
        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $delegate, action: $this->permission,
            attributes: ['group_id' => $this->otherId],
        )));
    }

    public function testCannotDelegateBroaderScopeThanHeld(): void
    {
        // Leader only holds the district; cannot delegate the whole region.
        $leader = $this->giveRoleGrant($this->districtId, includeDescendants: false);

        $res = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->regionId, 'purpose' => 'overreach', 'duration_days' => 5,
        ]);

        $this->assertFalse($res->ok);
        $this->assertSame('DELEGATION_EXCEEDS_AUTHORITY', $res->code);
    }

    public function testDurationMustBeBoundedAndWithinLimit(): void
    {
        $leader = $this->giveRoleGrant(null, false);

        $missing = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'x', 'duration_days' => 0,
        ]);
        $this->assertFalse($missing->ok);
        $this->assertSame('DURATION_REQUIRED', $missing->code);

        $tooLong = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'x', 'duration_days' => 100000,
        ]);
        $this->assertFalse($tooLong->ok);
        $this->assertSame('DURATION_TOO_LONG', $tooLong->code);
    }

    public function testDurationCannotExceedAnUpstreamDelegationsEnd(): void
    {
        // Root leader (org-wide role) delegates to A for 3 days.
        $leader = $this->giveRoleGrant(null, false);
        $a      = Uuid::v7();
        $d1     = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => $a, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'first', 'duration_days' => 3,
        ]);
        $this->assertTrue($d1->ok);

        // A sub-delegates to B, but for LONGER than A's own 3 days → rejected.
        $tooLong = $this->svc->delegate($this->orgId, $a, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'second', 'duration_days' => 30,
        ]);
        $this->assertFalse($tooLong->ok);
        $this->assertSame('DELEGATION_EXCEEDS_AUTHORITY', $tooLong->code);
    }

    public function testChainDepthIsCappedByConfig(): void
    {
        // maxDelegationDepth=2 (set in setUp). Root role → A (depth1) → B (depth2) OK; B → C (depth3) rejected.
        $leader = $this->giveRoleGrant(null, false);
        $a      = Uuid::v7();
        $b      = Uuid::v7();

        $d1 = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => $a, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd1', 'duration_days' => 10,
        ]);
        $this->assertTrue($d1->ok);
        $this->assertSame(1, $d1->data['depth']);

        $d2 = $this->svc->delegate($this->orgId, $a, [
            'delegate_id' => $b, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd2', 'duration_days' => 5,
        ]);
        $this->assertTrue($d2->ok);
        $this->assertSame(2, $d2->data['depth']);

        $d3 = $this->svc->delegate($this->orgId, $b, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd3', 'duration_days' => 1,
        ]);
        $this->assertFalse($d3->ok, 'depth 3 exceeds the configured max of 2');
        $this->assertSame('DELEGATION_EXCEEDS_AUTHORITY', $d3->code);
    }

    public function testRevokeCascadesToSubDelegationsAndIsTraceable(): void
    {
        $leader = $this->giveRoleGrant(null, false);
        $a      = Uuid::v7();
        $b      = Uuid::v7();

        $d1 = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => $a, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd1', 'duration_days' => 10,
        ]);
        $d2 = $this->svc->delegate($this->orgId, $a, [
            'delegate_id' => $b, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd2', 'duration_days' => 5,
        ]);

        // Chain is traceable from the root.
        $chain = $this->svc->chain($this->orgId, $d1->data['delegation_id']);
        $this->assertCount(2, $chain);

        // Revoking the root cascades to the sub-delegation.
        $rev = $this->svc->revoke($this->orgId, $leader, $d1->data['delegation_id'], 'event over');
        $this->assertTrue($rev->ok);
        $this->assertSame(2, $rev->data['revoked_count']);

        // The PDP no longer grants B the permission.
        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $b, action: $this->permission,
            attributes: ['group_id' => $this->districtId],
        )));
    }

    public function testUnrelatedActorCannotRevoke(): void
    {
        $leader = $this->giveRoleGrant(null, false);
        $d1     = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => Uuid::v7(), 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'd1', 'duration_days' => 10,
        ]);

        $res = $this->svc->revoke($this->orgId, Uuid::v7(), $d1->data['delegation_id'], 'nope');
        $this->assertFalse($res->ok);
        $this->assertSame('CANNOT_REVOKE_DELEGATION', $res->code);
    }

    public function testSelfDelegationRejected(): void
    {
        $leader = $this->giveRoleGrant(null, false);
        $res    = $this->svc->delegate($this->orgId, $leader, [
            'delegate_id' => $leader, 'permission_code' => $this->permission,
            'scope_group_id' => $this->districtId, 'purpose' => 'x', 'duration_days' => 5,
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('SELF_DELEGATION', $res->code);
    }

    // ---- helpers -----------------------------------------------------------

    /** Give a fresh subject a role granting $this->permission at a scope; return the subject id. */
    private function giveRoleGrant(?string $scopeGroupId, bool $includeDescendants): string
    {
        $now     = $this->clock->nowUtcString();
        $subject = Uuid::v7();
        $roleId  = Uuid::v7();

        $this->db->table('roles')->insert([
            'id' => $roleId, 'organization_id' => $this->orgId,
            'code' => 'leader_' . substr($roleId, 0, 6), 'name' => 'Leader', 'created_at' => $now,
        ]);

        $perm = $this->db->table('permissions')->where('code', $this->permission)->get()->getRowArray();
        $permId = $perm['id'] ?? Uuid::v7();
        if ($perm === null) {
            $this->db->table('permissions')->insert([
                'id' => $permId, 'code' => $this->permission, 'description' => 'Create events', 'created_at' => $now,
            ]);
        }
        $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);

        $this->db->table('role_assignments')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'subject_id' => $subject, 'role_id' => $roleId,
            'scope_group_id' => $scopeGroupId,
            'include_descendants' => $includeDescendants ? 1 : 0,
            'status' => 'active', 'created_at' => $now,
        ]);

        return $subject;
    }
}
