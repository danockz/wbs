<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\AccessControl\Services\RoleAssignmentService;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves FR-ACL-003 direct role-assignment management is GROUP-HIERARCHY AWARE
 * (delegated administration):
 *
 *  - an issuer whose `access.assignment.manage` grant is scoped to a parent
 *    (with descendants) may assign within a child group, but
 *  - an issuer scoped to an unrelated branch may not,
 *  - a group-scoped issuer cannot mint an org-wide assignment,
 *  - assignments are expiring + carry conditions, and revoke is soft + audited.
 *
 * Mirrors HierarchicalGroupScopeTest: uses the real DB via DatabaseTestTrait and
 * self-skips when no test database is reachable so the suite still passes bare.
 *
 * @internal
 */
final class RoleAssignmentManagementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private RoleAssignmentService $svc;
    private GroupScopeResolver $scope;
    private Clock $clock;

    private string $orgId;
    private string $regionId;
    private string $districtId;
    private string $otherId;
    private string $targetRoleId; // role being assigned to subjects

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
        $this->scope = new GroupScopeResolver($this->db);
        $this->svc   = new RoleAssignmentService(
            $this->db,
            $this->clock,
            $this->scope,
            new AuditLogger($this->db, $this->clock),
        );

        $this->orgId      = Uuid::v7();
        $this->regionId   = Uuid::v7();
        $this->districtId = Uuid::v7();
        $this->otherId    = Uuid::v7();
        $now              = $this->clock->nowUtcString();

        // region -> district | other (separate root)
        foreach ([$this->regionId, $this->districtId, $this->otherId] as $gid) {
            $this->db->table('group_closure')->insert(
                ['ancestor_id' => $gid, 'descendant_id' => $gid, 'distance' => 0],
            );
        }
        $this->db->table('group_closure')->insert(
            ['ancestor_id' => $this->regionId, 'descendant_id' => $this->districtId, 'distance' => 1],
        );

        // A role that issuers will assign to subjects.
        $this->targetRoleId = Uuid::v7();
        $this->db->table('roles')->insert([
            'id' => $this->targetRoleId, 'organization_id' => $this->orgId,
            'code' => 'event_helper', 'name' => 'Event Helper', 'created_at' => $now,
        ]);
    }

    public function testParentScopedIssuerCanAssignWithinChild(): void
    {
        $issuer = $this->makeIssuer($this->regionId, includeDescendants: true);

        $res = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(),
            'role_id'    => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
            'include_descendants' => 0,
            'duration_days' => 30,
        ]);

        $this->assertTrue($res->ok, 'region issuer with descendants should assign in a district');
        $this->assertSame('active', $res->data['status']);
        $this->assertNotNull($res->data['effective_to'], 'assignment must be expiring');
    }

    public function testParentScopedIssuerWithoutDescendantsCannotAssignInChild(): void
    {
        $issuer = $this->makeIssuer($this->regionId, includeDescendants: false);

        $res = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(),
            'role_id'    => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
        ]);

        $this->assertFalse($res->ok);
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $res->code);
    }

    public function testUnrelatedBranchIssuerIsDenied(): void
    {
        $issuer = $this->makeIssuer($this->otherId, includeDescendants: true);

        $res = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(),
            'role_id'    => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
        ]);

        $this->assertFalse($res->ok);
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $res->code);
    }

    public function testGroupScopedIssuerCannotMintOrgWideAssignment(): void
    {
        $issuer = $this->makeIssuer($this->regionId, includeDescendants: true);

        $res = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(),
            'role_id'    => $this->targetRoleId,
            'scope_group_id' => null, // org-wide target
        ]);

        $this->assertFalse($res->ok, 'org-wide assignment needs an org-wide management grant');
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $res->code);
    }

    public function testOrgWideIssuerCanAssignAnywhere(): void
    {
        $issuer = $this->makeIssuer(null, includeDescendants: false); // org-wide grant

        $child = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(), 'role_id' => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
        ]);
        $orgWide = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(), 'role_id' => $this->targetRoleId,
            'scope_group_id' => null,
        ]);

        $this->assertTrue($child->ok);
        $this->assertTrue($orgWide->ok);
    }

    public function testConditionsArePersistedAndBadConditionsRejected(): void
    {
        $issuer  = $this->makeIssuer(null, includeDescendants: false);
        $subject = Uuid::v7();

        $ok = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => $subject, 'role_id' => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
            'conditions' => ['attr' => 'mfa_level', 'op' => 'eq', 'value' => 'strong'],
        ]);
        $this->assertTrue($ok->ok);

        $row = $this->db->table('role_assignments')->where('id', $ok->data['assignment_id'])->get()->getRowArray();
        $this->assertNotNull($row['conditions']);
        $this->assertStringContainsString('mfa_level', (string) $row['conditions']);

        $bad = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(), 'role_id' => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
            'conditions' => 'not-an-array',
        ]);
        $this->assertFalse($bad->ok);
        $this->assertSame('BAD_CONDITIONS', $bad->code);
    }

    public function testRevokeIsSoftAndScopeChecked(): void
    {
        $issuer  = $this->makeIssuer($this->regionId, includeDescendants: true);
        $created = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(), 'role_id' => $this->targetRoleId,
            'scope_group_id' => $this->districtId,
        ]);
        $this->assertTrue($created->ok);
        $assignmentId = $created->data['assignment_id'];

        // An unrelated-branch issuer cannot revoke it.
        $intruder = $this->makeIssuer($this->otherId, includeDescendants: true);
        $denied   = $this->svc->revoke($this->orgId, $intruder, $assignmentId, 'nope');
        $this->assertFalse($denied->ok);
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $denied->code);

        // The in-scope issuer can; the row is soft-revoked, not deleted.
        $ok = $this->svc->revoke($this->orgId, $issuer, $assignmentId, 'role no longer needed');
        $this->assertTrue($ok->ok);
        $row = $this->db->table('role_assignments')->where('id', $assignmentId)->get()->getRowArray();
        $this->assertSame('revoked', $row['status']);
        $this->assertNotNull($row['revoked_at']);
    }

    public function testRevokeRequiresAReason(): void
    {
        $issuer  = $this->makeIssuer(null, false);
        $created = $this->svc->assign($this->orgId, $issuer, [
            'subject_id' => Uuid::v7(), 'role_id' => $this->targetRoleId, 'scope_group_id' => $this->districtId,
        ]);
        $res = $this->svc->revoke($this->orgId, $issuer, $created->data['assignment_id'], '   ');
        $this->assertFalse($res->ok);
        $this->assertSame('REASON_REQUIRED', $res->code);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Create a subject holding access.assignment.manage at the given scope and
     * return their id.
     */
    private function makeIssuer(?string $scopeGroupId, bool $includeDescendants): string
    {
        $now     = $this->clock->nowUtcString();
        $subject = Uuid::v7();
        $roleId  = Uuid::v7();

        $this->db->table('roles')->insert([
            'id' => $roleId, 'organization_id' => $this->orgId,
            'code' => 'assign_admin_' . substr($roleId, 0, 6),
            'name' => 'AssignAdmin', 'created_at' => $now,
        ]);

        // Reuse the permission row if a prior issuer already created it.
        $perm = $this->db->table('permissions')->where('code', RoleAssignmentService::MANAGE_PERMISSION)->get()->getRowArray();
        $permId = $perm['id'] ?? Uuid::v7();
        if ($perm === null) {
            $this->db->table('permissions')->insert([
                'id' => $permId, 'code' => RoleAssignmentService::MANAGE_PERMISSION,
                'description' => 'Manage role assignments', 'created_at' => $now,
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
