<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\AccessControl\Services\RoleService;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves the RBAC role-catalogue CRUD is group-hierarchy correct and safe
 * (SRS FR-ACL-002/003):
 *
 *  - managing the catalogue requires an ORG-WIDE grant (a merely group-scoped
 *    manager is denied — a role definition is org-wide in effect);
 *  - SYSTEM roles cannot be deleted or have their permission set rewritten;
 *  - the privilege-escalation guard stops an issuer granting a role permissions
 *    the issuer does not themselves hold (unless they hold admin.manage);
 *  - permission codes must exist in the central catalogue;
 *  - a role still referenced by an assignment cannot be deleted.
 *
 * Real DB via DatabaseTestTrait; self-skips when no test database is reachable.
 *
 * @internal
 */
final class RoleManagementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private RoleService $svc;
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
        $this->svc   = new RoleService(
            $this->db,
            $this->clock,
            new GroupScopeResolver($this->db),
            new AuditLogger($this->db, $this->clock),
        );

        $this->orgId    = Uuid::v7();
        $this->regionId = Uuid::v7();
        $this->db->table('group_closure')->insert(['ancestor_id' => $this->regionId, 'descendant_id' => $this->regionId, 'distance' => 0]);
    }

    public function testOrgWideManagerCanCreateAndReadRole(): void
    {
        $issuer = $this->makeManager(null, ['event.create']);

        $res = $this->svc->create($this->orgId, $issuer, ['code' => 'Helper', 'name' => 'Helper Role']);
        $this->assertTrue($res->ok, 'org-wide manager may create a role');
        $this->assertSame('helper', $res->data['code'], 'code is normalized lowercase');

        $show = $this->svc->show($this->orgId, $res->data['role_id']);
        $this->assertTrue($show->ok);
        $this->assertSame([], $show->data['permissions']);
    }

    public function testGroupScopedManagerCannotManageCatalogue(): void
    {
        $issuer = $this->makeManager($this->regionId, ['event.create'], includeDescendants: true);

        $res = $this->svc->create($this->orgId, $issuer, ['code' => 'helper', 'name' => 'Helper']);
        $this->assertFalse($res->ok, 'a group-scoped grant does not cover the org-wide catalogue');
        $this->assertSame('ACCESS_OUT_OF_SCOPE', $res->code);
    }

    public function testSetPermissionsHonoursCatalogueAndEscalationGuard(): void
    {
        // Issuer holds event.create only (org-wide manager, no admin.manage).
        $issuer = $this->makeManager(null, ['event.create']);
        $role   = $this->svc->create($this->orgId, $issuer, ['code' => 'helper', 'name' => 'Helper'])->data['role_id'];

        // Unknown permission code is rejected.
        $unknown = $this->svc->setPermissions($this->orgId, $issuer, $role, ['event.create', 'does.not.exist']);
        $this->assertSame('UNKNOWN_PERMISSION', $unknown->code);

        // Escalation: granting a permission the issuer lacks is denied.
        $this->ensurePermission('contribution.manage');
        $escalate = $this->svc->setPermissions($this->orgId, $issuer, $role, ['event.create', 'contribution.manage']);
        $this->assertSame('PRIVILEGE_ESCALATION', $escalate->code);

        // Granting only permissions the issuer holds succeeds.
        $ok = $this->svc->setPermissions($this->orgId, $issuer, $role, ['event.create']);
        $this->assertTrue($ok->ok);
        $this->assertSame(['event.create'], $this->svc->show($this->orgId, $role)->data['permissions']);
    }

    public function testAdminManageBypassesEscalationGuard(): void
    {
        $issuer = $this->makeManager(null, ['admin.manage']);
        $role   = $this->svc->create($this->orgId, $issuer, ['code' => 'powerful', 'name' => 'Powerful'])->data['role_id'];
        $this->ensurePermission('contribution.manage');

        $ok = $this->svc->setPermissions($this->orgId, $issuer, $role, ['contribution.manage']);
        $this->assertTrue($ok->ok, 'admin.manage holder may grant any catalogued permission');
    }

    public function testSystemRolesAreProtected(): void
    {
        $issuer = $this->makeManager(null, ['admin.manage']);

        $sysRoleId = Uuid::v7();
        $this->db->table('roles')->insert([
            'id' => $sysRoleId, 'organization_id' => $this->orgId,
            'code' => 'org_admin', 'name' => 'Org Admin', 'is_system' => 1,
            'created_at' => $this->clock->nowUtcString(),
        ]);

        $this->assertSame('ROLE_IS_SYSTEM', $this->svc->delete($this->orgId, $issuer, $sysRoleId)->code);
        $this->assertSame('ROLE_IS_SYSTEM', $this->svc->setPermissions($this->orgId, $issuer, $sysRoleId, [])->code);
    }

    public function testRoleInUseCannotBeDeleted(): void
    {
        $issuer = $this->makeManager(null, ['admin.manage']);
        $roleId = $this->svc->create($this->orgId, $issuer, ['code' => 'temp', 'name' => 'Temp'])->data['role_id'];

        // Reference it from an assignment.
        $this->db->table('role_assignments')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'subject_id' => Uuid::v7(), 'role_id' => $roleId, 'scope_group_id' => null,
            'status' => 'active', 'created_at' => $this->clock->nowUtcString(),
        ]);

        $inUse = $this->svc->delete($this->orgId, $issuer, $roleId);
        $this->assertSame('ROLE_IN_USE', $inUse->code);

        // Remove the reference, then delete succeeds.
        $this->db->table('role_assignments')->where('role_id', $roleId)->delete();
        $this->assertTrue($this->svc->delete($this->orgId, $issuer, $roleId)->ok);
    }

    // ---------------------------------------------------------------------

    /**
     * Build an issuer holding access.role.manage at the given scope plus the
     * listed extra permission codes, all in one role.
     *
     * @param list<string> $extraPermissions
     */
    private function makeManager(?string $scopeGroupId, array $extraPermissions = [], bool $includeDescendants = false): string
    {
        $now     = $this->clock->nowUtcString();
        $subject = Uuid::v7();
        $roleId  = Uuid::v7();

        $this->db->table('roles')->insert([
            'id' => $roleId, 'organization_id' => $this->orgId,
            'code' => 'mgr_' . substr($roleId, 0, 6), 'name' => 'Manager',
            'created_at' => $now,
        ]);

        foreach (array_merge([RoleService::MANAGE_PERMISSION], $extraPermissions) as $code) {
            $permId = $this->ensurePermission($code);
            $link   = $this->db->table('role_permissions')
                ->where('role_id', $roleId)->where('permission_id', $permId)->get()->getRowArray();
            if ($link === null) {
                $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        }

        $this->db->table('role_assignments')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'subject_id' => $subject, 'role_id' => $roleId,
            'scope_group_id' => $scopeGroupId,
            'include_descendants' => $includeDescendants ? 1 : 0,
            'status' => 'active', 'created_at' => $now,
        ]);

        return $subject;
    }

    private function ensurePermission(string $code): string
    {
        $row = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        if ($row !== null) {
            return (string) $row['id'];
        }
        $id = Uuid::v7();
        $this->db->table('permissions')->insert([
            'id' => $id, 'code' => $code, 'description' => null,
            'created_at' => $this->clock->nowUtcString(),
        ]);

        return $id;
    }
}
