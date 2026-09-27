<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Policy\Combinators\SegregationOfDutiesCombinator;
use WBS\AccessControl\Services\AbacPolicyService;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves the ABAC policy CRUD (SRS: ABAC in the MAC+RBAC+ABAC PDP):
 *
 *  - managing policies requires an ORG-WIDE grant;
 *  - a malformed condition tree is rejected at WRITE time (not silently at
 *    decision time), using the same evaluator the PDP decides with;
 *  - a created deny-policy actually takes effect in the PDP;
 *  - disabling a policy removes it from PDP consideration.
 *
 * Real DB via DatabaseTestTrait; self-skips when no test database is reachable.
 *
 * @internal
 */
final class AbacPolicyManagementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private AbacPolicyService $svc;
    private AuthorizationService $pdp;
    private Clock $clock;

    private string $orgId;
    private string $regionId;

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
        $this->svc   = new AbacPolicyService(
            $this->db,
            $this->clock,
            $scope,
            new AuditLogger($this->db, $this->clock),
            new AbacConditionEvaluator(),
        );
        $this->pdp = new AuthorizationService($this->db, new AbacConditionEvaluator(), new SegregationOfDutiesCombinator(), $scope);

        $this->orgId    = Uuid::v7();
        $this->regionId = Uuid::v7();
        $this->db->table('group_closure')->insert(['ancestor_id' => $this->regionId, 'descendant_id' => $this->regionId, 'distance' => 0]);
    }

    public function testGroupScopedManagerCannotManagePolicies(): void
    {
        $issuer = $this->makeManager($this->regionId, includeDescendants: true);

        $res = $this->svc->create($this->orgId, $issuer, [
            'code' => 'p1', 'action_pattern' => 'event.*', 'effect' => 'deny',
            'condition' => ['attr' => 'amount', 'op' => 'gt', 'value' => 100],
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $res->code);
    }

    public function testMalformedConditionRejectedAtWriteTime(): void
    {
        $issuer = $this->makeManager(null);

        $bad = $this->svc->create($this->orgId, $issuer, [
            'code' => 'bad', 'action_pattern' => 'event.create', 'effect' => 'deny',
            'condition' => ['attr' => 'role', 'op' => 'regex', 'value' => '.*'], // unknown op
        ]);
        $this->assertFalse($bad->ok);
        $this->assertSame('INVALID_CONDITION', $bad->code);

        $badEffect = $this->svc->create($this->orgId, $issuer, [
            'code' => 'bad2', 'action_pattern' => 'event.create', 'effect' => 'maybe',
            'condition' => [],
        ]);
        $this->assertSame('BAD_EFFECT', $badEffect->code);
    }

    public function testCreatedDenyPolicyTakesEffectInPdpAndDisableRemovesIt(): void
    {
        $issuer  = $this->makeManager(null);
        $subject = Uuid::v7();

        // Give the subject an org-wide grant for event.create so RBAC would allow.
        $this->grantSubject($subject, 'event.create');
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['amount' => 5000],
        )), 'RBAC alone should allow before any deny policy');

        // Deny event.create when amount > 1000.
        $res = $this->svc->create($this->orgId, $issuer, [
            'code' => 'no_big_events', 'action_pattern' => 'event.create', 'effect' => 'deny',
            'condition' => ['attr' => 'amount', 'op' => 'gt', 'value' => 1000],
        ]);
        $this->assertTrue($res->ok);
        $policyId = $res->data['policy_id'];

        // Now the PDP denies the high-amount request...
        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['amount' => 5000],
        )));
        // ...but a low-amount one still passes.
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['amount' => 500],
        )));

        // Disabling the policy restores access.
        $this->assertTrue($this->svc->setEnabled($this->orgId, $issuer, $policyId, false)->ok);
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['amount' => 5000],
        )));
    }

    public function testUnconditionalPolicyActuallyFiresInPdp(): void
    {
        $issuer  = $this->makeManager(null);
        $subject = Uuid::v7();
        $this->grantSubject($subject, 'event.create');

        // A deny policy with NO conditions must apply unconditionally to the
        // action — NOT save cleanly then silently never fire (the bare-{} trap).
        $res = $this->svc->create($this->orgId, $issuer, [
            'code' => 'block_all_event_create', 'action_pattern' => 'event.create',
            'effect' => 'deny', // condition omitted entirely
        ]);
        $this->assertTrue($res->ok);

        $this->assertFalse($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.create',
            attributes: ['amount' => 5],
        )), 'a condition-less deny policy must fire for every matching action');

        // A different action is unaffected.
        $this->grantSubject($subject, 'event.schedule.approve');
        $this->assertTrue($this->pdp->isAllowed(new AccessRequest(
            organizationId: $this->orgId, subjectId: $subject, action: 'event.schedule.approve',
        )));
    }

    public function testDuplicateCodeRejected(): void
    {
        $issuer = $this->makeManager(null);
        $data   = ['code' => 'dup', 'action_pattern' => 'event.create', 'effect' => 'deny', 'condition' => []];

        $this->assertTrue($this->svc->create($this->orgId, $issuer, $data)->ok);
        $this->assertSame('POLICY_EXISTS', $this->svc->create($this->orgId, $issuer, $data)->code);
    }

    // ---------------------------------------------------------------------

    private function makeManager(?string $scopeGroupId, bool $includeDescendants = false): string
    {
        return $this->grantSubjectAt(Uuid::v7(), AbacPolicyService::MANAGE_PERMISSION, $scopeGroupId, $includeDescendants);
    }

    private function grantSubject(string $subject, string $permission): string
    {
        return $this->grantSubjectAt($subject, $permission, null, false);
    }

    private function grantSubjectAt(string $subject, string $permission, ?string $scopeGroupId, bool $includeDescendants): string
    {
        $now    = $this->clock->nowUtcString();
        $roleId = Uuid::v7();

        $this->db->table('roles')->insert([
            'id' => $roleId, 'organization_id' => $this->orgId,
            'code' => 'r_' . substr($roleId, 0, 8), 'name' => 'R', 'created_at' => $now,
        ]);

        $perm   = $this->db->table('permissions')->where('code', $permission)->get()->getRowArray();
        $permId = $perm['id'] ?? Uuid::v7();
        if ($perm === null) {
            $this->db->table('permissions')->insert([
                'id' => $permId, 'code' => $permission, 'description' => null, 'created_at' => $now,
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
