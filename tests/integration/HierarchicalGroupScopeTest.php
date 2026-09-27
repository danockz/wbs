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
use WBS\Gamification\Services\ActivityCatalogService;
use WBS\Gamification\Services\FollowUpService;
use WBS\Gamification\Services\PointsEngine;
use WBS\Gamification\Services\SeasonService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Uuid;

/**
 * Proves the configurable W-B-S configuration surfaces are HIERARCHICAL-GROUP
 * PERMISSION COMPLIANT on both axes:
 *
 *  A) DATA-SCOPE RESOLUTION — a subgroup inherits its ancestors' activity
 *     categories and follow-up types most-specific-wins, but ONLY when the
 *     ancestor row opts in via include_descendants; a subgroup override hides
 *     the inherited row; org-wide (NULL) rows are always visible.
 *
 *  B) AUTHORIZATION — the PDP honours role_assignments.scope_group_id +
 *     include_descendants: a grant scoped to a parent (with descendants) covers
 *     a child action, an unrelated-branch grant does not, and a purely
 *     group-scoped grant cannot act org-wide.
 *
 * Self-skips when no test DB is reachable so the suite still passes bare.
 *
 * @internal
 */
final class HierarchicalGroupScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private GroupScopeResolver $scope;
    private ActivityCatalogService $catalog;
    private FollowUpService $followUps;
    private AuthorizationService $pdp;

    private string $orgId;
    private string $regionId;   // parent
    private string $districtId; // child of region
    private string $otherId;    // unrelated branch

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

        $clock           = new Clock();
        $this->scope     = new GroupScopeResolver($this->db);
        $seasons         = new SeasonService($this->db, $clock);
        $engine          = new PointsEngine($this->db, $clock, $seasons);
        $this->catalog   = new ActivityCatalogService($this->db, $clock, $this->scope);
        $this->followUps = new FollowUpService($this->db, $clock, $engine, $this->scope);
        $this->pdp       = new AuthorizationService(
            $this->db,
            new AbacConditionEvaluator(),
            new SegregationOfDutiesCombinator(),
            $this->scope,
        );

        $this->orgId      = Uuid::v7();
        $this->regionId   = Uuid::v7();
        $this->districtId = Uuid::v7();
        $this->otherId    = Uuid::v7();
        $now              = $clock->nowUtcString();

        // region  ->  district   |   other (separate root)
        foreach ([$this->regionId, $this->districtId, $this->otherId] as $gid) {
            $this->db->table('group_closure')->insert(
                ['ancestor_id' => $gid, 'descendant_id' => $gid, 'distance' => 0],
            );
        }
        $this->db->table('group_closure')->insert(
            ['ancestor_id' => $this->regionId, 'descendant_id' => $this->districtId, 'distance' => 1],
        );

        $this->db->table('gamification_seasons')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'season_year' => (int) date('Y'), 'status' => 'active',
            'starts_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'ends_at'   => date('Y-m-d H:i:s', strtotime('+80 days')),
            'created_at' => $now,
        ]);
    }

    // ---- A) DATA-SCOPE RESOLUTION -----------------------------------------

    public function testDistrictInheritsRegionCategoryWhenDescendantsIncluded(): void
    {
        // Region category opts in to descendants; org-wide category always shows.
        $this->catalog->define($this->orgId, [
            'code' => 'discipleship', 'name' => 'Discipleship', 'phase' => 'build',
            'group_id' => $this->regionId, 'include_descendants' => 1,
        ]);
        $this->catalog->define($this->orgId, [
            'code' => 'giving', 'name' => 'Giving', 'phase' => 'send',
            'group_id' => null,
        ]);

        $codes = array_column($this->catalog->listCategories($this->orgId, null, $this->districtId), 'code');
        sort($codes);
        $this->assertSame(['discipleship', 'giving'], $codes, 'district should inherit region + org-wide');
    }

    public function testAncestorCategoryHiddenWhenDescendantsNotIncluded(): void
    {
        $this->catalog->define($this->orgId, [
            'code' => 'region_only', 'name' => 'Region Only', 'phase' => 'build',
            'group_id' => $this->regionId, 'include_descendants' => 0,
        ]);

        $codes = array_column($this->catalog->listCategories($this->orgId, null, $this->districtId), 'code');
        $this->assertNotContains('region_only', $codes, 'non-inheritable ancestor row must not leak down');
    }

    public function testSubgroupOverrideWinsOverInheritedCategory(): void
    {
        $this->catalog->define($this->orgId, [
            'code' => 'care', 'name' => 'Region Care', 'phase' => 'build',
            'group_id' => $this->regionId, 'include_descendants' => 1,
        ]);
        $this->catalog->define($this->orgId, [
            'code' => 'care', 'name' => 'District Care', 'phase' => 'build',
            'group_id' => $this->districtId,
        ]);

        $rows = $this->catalog->listCategories($this->orgId, null, $this->districtId);
        $care = array_values(array_filter($rows, static fn ($r) => $r['code'] === 'care'));
        $this->assertCount(1, $care, 'most-specific-wins collapses to one row per code');
        $this->assertSame('District Care', $care[0]['name']);
        $this->assertSame($this->districtId, $care[0]['group_id']);
    }

    public function testFollowUpTypeResolvesFromAncestorChain(): void
    {
        // Type defined at the region, inheritable; district records against it.
        $this->followUps->defineType($this->orgId, [
            'code' => 'new_visitor', 'name' => 'New Visitor', 'phase' => 'win',
            'group_id' => $this->regionId, 'include_descendants' => 1,
        ]);
        $this->followUps->defineMethod($this->orgId, [
            'code' => 'visit', 'name' => 'Visit', 'multiplier_key' => 'visit',
        ]);

        $res = $this->followUps->record($this->orgId, Uuid::v7(), [
            'type_code' => 'new_visitor', 'method_code' => 'visit',
            'subject_user_id' => Uuid::v7(), 'status' => 'completed',
            'group_id' => $this->districtId,
        ]);
        $this->assertTrue($res->ok, 'district should resolve the region-inherited follow-up type');
    }

    public function testFollowUpTypeNotInheritedWhenAncestorOptsOut(): void
    {
        $this->followUps->defineType($this->orgId, [
            'code' => 'region_type', 'name' => 'Region Type', 'phase' => 'build',
            'group_id' => $this->regionId, 'include_descendants' => 0,
        ]);
        $this->followUps->defineMethod($this->orgId, [
            'code' => 'call', 'name' => 'Call', 'multiplier_key' => 'call',
        ]);

        $res = $this->followUps->record($this->orgId, Uuid::v7(), [
            'type_code' => 'region_type', 'method_code' => 'call',
            'subject_user_id' => Uuid::v7(), 'status' => 'completed',
            'group_id' => $this->districtId,
        ]);
        $this->assertFalse($res->ok);
        $this->assertSame('UNKNOWN_TYPE', $res->code);
    }

    // ---- B) AUTHORIZATION (scope_group_id + include_descendants) -----------

    public function testParentScopedGrantCoversChildAction(): void
    {
        $this->grantManage($this->regionId, includeDescendants: true);

        $this->assertTrue(
            $this->pdp->isAllowed($this->req($this->districtId)),
            'a region grant with descendants must cover a district-targeted action',
        );
    }

    public function testParentScopedGrantWithoutDescendantsDoesNotCoverChild(): void
    {
        $this->grantManage($this->regionId, includeDescendants: false);

        $this->assertFalse(
            $this->pdp->isAllowed($this->req($this->districtId)),
            'without include_descendants the grant is confined to the exact group',
        );
    }

    public function testUnrelatedBranchGrantDeniesTarget(): void
    {
        $this->grantManage($this->otherId, includeDescendants: true);

        $this->assertFalse(
            $this->pdp->isAllowed($this->req($this->districtId)),
            'a grant on a different branch must not authorize this target',
        );
    }

    public function testGroupScopedGrantCannotActOrgWide(): void
    {
        $this->grantManage($this->regionId, includeDescendants: true);

        $this->assertFalse(
            $this->pdp->isAllowed($this->req(null)),
            'an org-wide (no target group) action needs an org-wide grant',
        );
    }

    public function testExactGroupGrantCoversSameGroup(): void
    {
        $this->grantManage($this->districtId, includeDescendants: false);

        $this->assertTrue($this->pdp->isAllowed($this->req($this->districtId)));
    }

    public function testCoarseAnyScopeGateAdmitsGroupScopedAdmin(): void
    {
        // A purely district-scoped grant should PASS the coarse `any` gate even
        // for a null target (the route filter cannot know the resource group).
        $this->grantManage($this->districtId, includeDescendants: false);

        $anyReq = new AccessRequest(
            organizationId: $this->orgId,
            subjectId: $this->subjectId,
            action: 'gamification.manage',
            attributes: ['scope_check' => 'any'],
        );
        $this->assertTrue($this->pdp->isAllowed($anyReq));
    }

    // ---- helpers -----------------------------------------------------------

    private string $subjectId = '';

    private function grantManage(string $scopeGroupId, bool $includeDescendants): void
    {
        $now       = (new Clock())->nowUtcString();
        $roleId    = Uuid::v7();
        $permId    = Uuid::v7();
        $this->subjectId = $this->subjectId !== '' ? $this->subjectId : Uuid::v7();

        $this->db->table('roles')->insert([
            'id' => $roleId, 'organization_id' => $this->orgId,
            'code' => 'group_admin_' . substr($roleId, 0, 6),
            'name' => 'GroupAdmin-' . substr($roleId, 0, 6), 'created_at' => $now,
        ]);
        $this->db->table('permissions')->insert([
            'id' => $permId, 'code' => 'gamification.manage',
            'description' => 'Manage gamification', 'created_at' => $now,
        ]);
        $this->db->table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permId,
        ]);
        $this->db->table('role_assignments')->insert([
            'id' => Uuid::v7(), 'organization_id' => $this->orgId,
            'subject_id' => $this->subjectId, 'role_id' => $roleId,
            'scope_group_id' => $scopeGroupId,
            'include_descendants' => $includeDescendants ? 1 : 0,
            'status' => 'active', 'created_at' => $now,
        ]);
    }

    private function req(?string $targetGroupId): AccessRequest
    {
        return new AccessRequest(
            organizationId: $this->orgId,
            subjectId: $this->subjectId,
            action: 'gamification.manage',
            attributes: ['group_id' => $targetGroupId],
        );
    }
}
