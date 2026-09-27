<?php

declare(strict_types=1);

namespace Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use WBS\Gamification\Services\PointsEngine;
use WBS\Gamification\Services\RollupService;
use WBS\Gamification\Services\RuleService;
use WBS\Gamification\Services\SeasonService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * End-to-end (DB-backed) proof of the group-attribution RANKING engine
 * (design doc Part B.3/B.4): a credited group's points roll UP to every
 * ancestor, reversals are symmetric, and a multi-group check-in fans a single
 * action across every credited group.
 *
 * Hierarchy used throughout (a 3-level chain plus a sibling):
 *
 *     Region
 *      ├── District
 *      │     └── Cell            (members live here)
 *      └── District2             (sibling — must NOT receive Cell's credit)
 *
 * Covered:
 *  - leaf → ancestor roll-up deltas (Cell credit lands on Cell, District,
 *    Region; the sibling District2 stays zero),
 *  - the org-wide individual total is the SUM over ledger entries and is
 *    UNCHANGED by how it is attributed to groups,
 *  - reversal symmetry (a reversal subtracts the same delta from every level),
 *  - single-award-per-threshold is already covered by CampaignTeamMilestoneTest;
 *    here we assert the ranking rollup counterpart (idempotent per group),
 *  - multi-group check-in: per_group writes one entry per credited group (the
 *    member's total counts once per group; each group ranks independently), and
 *    individual_once writes one individual entry + rollup-only deltas,
 *  - "any member, any group's event": a member NOT in the credited group is
 *    still credited to that group and its ancestors.
 *
 * Self-skips when no test database is reachable, so the suite still passes in a
 * bare sandbox.
 *
 * @internal
 */
final class RankingRollupAndMultiGroupTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private PointsEngine $engine;
    private RollupService $rollup;
    private RuleService $rules;
    private SeasonService $seasons;

    private string $orgId;
    private string $regionId;
    private string $districtId;
    private string $district2Id;
    private string $cellId;
    private string $seasonId;
    /** @var array<string,string> short-name => user id */
    private array $users = [];

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
        $this->seasons = new SeasonService($this->db, $clock);
        $this->rollup  = new RollupService($this->db, $clock);
        $this->engine  = new PointsEngine($this->db, $clock, $this->seasons, null, null, $this->rollup);
        $this->rules   = new RuleService($this->db, $clock);

        $this->seedFixtures();
    }

    // ---------------------------------------------------------------------
    // Leaf → ancestor roll-up
    // ---------------------------------------------------------------------

    public function testLeafCreditRollsUpToEveryAncestorButNotSiblings(): void
    {
        $this->defineRule('event.attended', 50);

        // Ama (a Cell member) earns, attributed to the Cell (receiving group).
        $res = $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'evt:1', [
            'subject_type'       => 'user',
            'receiving_group_id' => $this->cellId,
        ]);
        $this->assertTrue($res->ok, (string) $res->code);

        // The exact ledger entry carries the Cell as its group_id.
        $entry = $this->db->table('point_ledger')->where('source_ref', 'evt:1')->get()->getRowArray();
        $this->assertSame($this->cellId, (string) $entry['group_id']);

        // Rollup: Cell, District, Region each hold 50; District2 (sibling) holds 0.
        $this->assertSame(50, $this->rollupPoints($this->cellId));
        $this->assertSame(50, $this->rollupPoints($this->districtId));
        $this->assertSame(50, $this->rollupPoints($this->regionId));
        $this->assertSame(0, $this->rollupPoints($this->district2Id));
    }

    public function testTwoMembersAggregateAtSharedAncestors(): void
    {
        $this->defineRule('event.attended', 50);

        // Ama credited to the Cell; Kofi credited to District2 (sibling branch).
        $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'e:a', [
            'subject_type' => 'user', 'receiving_group_id' => $this->cellId,
        ]);
        $this->engine->award($this->orgId, 'event.attended', $this->users['kofi'], 'e:k', [
            'subject_type' => 'user', 'receiving_group_id' => $this->district2Id,
        ]);

        // District only sees Ama's (via Cell); District2 only sees Kofi's.
        $this->assertSame(50, $this->rollupPoints($this->districtId));
        $this->assertSame(50, $this->rollupPoints($this->district2Id));
        // Region is the common ancestor of BOTH -> 100.
        $this->assertSame(100, $this->rollupPoints($this->regionId));
    }

    public function testOrgWideIndividualTotalIsUnchangedByGroupAttribution(): void
    {
        $this->defineRule('event.attended', 50);

        // Same member earns twice, attributed to two different groups.
        $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'x:1', [
            'subject_type' => 'user', 'receiving_group_id' => $this->cellId,
        ]);
        $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'x:2', [
            'subject_type' => 'user', 'receiving_group_id' => $this->district2Id,
        ]);

        // The individual's org-wide balance is the SUM of ledger entries (100),
        // independent of which groups the credit was attributed to.
        $this->assertSame(100, $this->engine->balance($this->orgId, $this->users['ama']));
    }

    // ---------------------------------------------------------------------
    // Reversal symmetry
    // ---------------------------------------------------------------------

    public function testReversalSubtractsTheSameDeltaFromEveryLevel(): void
    {
        $this->defineRule('event.attended', 50);

        $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'rev:1', [
            'subject_type' => 'user', 'receiving_group_id' => $this->cellId,
        ]);
        $this->assertSame(50, $this->rollupPoints($this->regionId));

        $rv = $this->engine->reverse($this->orgId, 'rev:1', 'test reversal');
        $this->assertTrue($rv->ok);

        // Every ancestor level is back to zero; the individual balance too.
        $this->assertSame(0, $this->rollupPoints($this->cellId));
        $this->assertSame(0, $this->rollupPoints($this->districtId));
        $this->assertSame(0, $this->rollupPoints($this->regionId));
        $this->assertSame(0, $this->engine->balance($this->orgId, $this->users['ama']));
    }

    public function testRebuildReproducesIncrementalRollupExactly(): void
    {
        $this->defineRule('event.attended', 50);

        $this->engine->award($this->orgId, 'event.attended', $this->users['ama'], 'rb:1', [
            'subject_type' => 'user', 'receiving_group_id' => $this->cellId,
        ]);
        $this->engine->award($this->orgId, 'event.attended', $this->users['kofi'], 'rb:2', [
            'subject_type' => 'user', 'receiving_group_id' => $this->districtId,
        ]);

        $before = [
            $this->rollupPoints($this->cellId),
            $this->rollupPoints($this->districtId),
            $this->rollupPoints($this->regionId),
        ];

        // A full rebuild from the immutable ledger must reproduce the same cells.
        $this->rollup->rebuild($this->orgId, $this->seasonId);

        $this->assertSame($before, [
            $this->rollupPoints($this->cellId),
            $this->rollupPoints($this->districtId),
            $this->rollupPoints($this->regionId),
        ]);
        // Cell=50, District=50+50=100, Region=100.
        $this->assertSame(50, $this->rollupPoints($this->cellId));
        $this->assertSame(100, $this->rollupPoints($this->districtId));
        $this->assertSame(100, $this->rollupPoints($this->regionId));
    }

    // ---------------------------------------------------------------------
    // Multi-group check-in (per_group / individual_once) + "any member, any event"
    // ---------------------------------------------------------------------

    public function testPerGroupWritesOneEntryPerCreditedGroup(): void
    {
        $this->defineRule('event.attended', 50, 'per_group');

        // One check-in credits the Cell AND District2 (two independent branches).
        $res = $this->engine->awardMultiGroup(
            $this->orgId,
            'event.attended',
            $this->users['ama'],
            'attendance:1',
            [$this->cellId, $this->district2Id],
        );
        $this->assertTrue($res->ok, (string) $res->code);
        $this->assertSame('per_group', $res->data['mode']);
        $this->assertSame(2, (int) $res->data['groups_count']);

        // Two ledger rows, one per group, sharing the source_ref.
        $rows = $this->db->table('point_ledger')->where('source_ref', 'attendance:1')
            ->where('entry_type', 'award')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $groups = array_map(static fn ($r): string => (string) $r['group_id'], $rows);
        sort($groups);
        $expected = [$this->cellId, $this->district2Id];
        sort($expected);
        $this->assertSame($expected, $groups);

        // The individual counts the activity once PER credited group -> 100.
        $this->assertSame(100, $this->engine->balance($this->orgId, $this->users['ama']));

        // Each group ranks independently; Region (ancestor of both) sees 100.
        $this->assertSame(50, $this->rollupPoints($this->cellId));
        $this->assertSame(50, $this->rollupPoints($this->district2Id));
        $this->assertSame(50, $this->rollupPoints($this->districtId)); // via Cell only
        $this->assertSame(100, $this->rollupPoints($this->regionId));
    }

    public function testPerGroupIsIdempotentPerGroupOnReplay(): void
    {
        $this->defineRule('event.attended', 50, 'per_group');

        $first  = $this->engine->awardMultiGroup($this->orgId, 'event.attended', $this->users['ama'], 'attendance:2', [$this->cellId, $this->district2Id]);
        $this->assertTrue($first->ok);

        // Replay the SAME check-in — no new rows, no inflation.
        $again = $this->engine->awardMultiGroup($this->orgId, 'event.attended', $this->users['ama'], 'attendance:2', [$this->cellId, $this->district2Id]);
        $this->assertTrue($again->ok);

        $this->assertSame(2, $this->db->table('point_ledger')
            ->where('source_ref', 'attendance:2')->where('entry_type', 'award')->countAllResults());
        $this->assertSame(100, $this->engine->balance($this->orgId, $this->users['ama']));
    }

    public function testIndividualOnceWritesOneEntryPlusRollupOnlyDeltas(): void
    {
        $this->defineRule('event.attended', 50, 'individual_once');

        $res = $this->engine->awardMultiGroup(
            $this->orgId,
            'event.attended',
            $this->users['ama'],
            'attendance:3',
            [$this->cellId, $this->district2Id],
        );
        $this->assertTrue($res->ok, (string) $res->code);
        $this->assertSame('individual_once', $res->data['mode']);
        $this->assertSame($this->cellId, (string) $res->data['primary_group']);

        // Exactly ONE ledger entry (against the primary group).
        $rows = $this->db->table('point_ledger')->where('source_ref', 'attendance:3')
            ->where('entry_type', 'award')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($this->cellId, (string) $rows[0]['group_id']);

        // The individual is NOT multiplied -> 50 (one entry only).
        $this->assertSame(50, $this->engine->balance($this->orgId, $this->users['ama']));

        // Both groups' standings still rose (rollup-only for the secondary).
        $this->assertSame(50, $this->rollupPoints($this->cellId));
        $this->assertSame(50, $this->rollupPoints($this->district2Id));
        $this->assertSame(100, $this->rollupPoints($this->regionId));
    }

    public function testAnyMemberIsCreditedToAnyGroupsEvent(): void
    {
        $this->defineRule('event.attended', 50, 'per_group');

        // "zoe" belongs to NO group at all, yet attends the Cell's event.
        $res = $this->engine->awardMultiGroup(
            $this->orgId,
            'event.attended',
            $this->users['zoe'],
            'attendance:4',
            [$this->cellId],
        );
        $this->assertTrue($res->ok, (string) $res->code);

        // Credit lands on the Cell (the event's group) and rolls to its ancestors,
        // even though zoe is not a member of any of them.
        $entry = $this->db->table('point_ledger')->where('source_ref', 'attendance:4')->get()->getRowArray();
        $this->assertSame($this->cellId, (string) $entry['group_id']);
        $this->assertSame(50, $this->rollupPoints($this->cellId));
        $this->assertSame(50, $this->rollupPoints($this->regionId));
        $this->assertSame(50, $this->engine->balance($this->orgId, $this->users['zoe']));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function defineRule(string $code, int $points, string $creditMode = 'per_group'): void
    {
        $res = $this->rules->create($this->orgId, [
            'code'              => $code,
            'event_type'       => $code,
            'points'            => $points,
            'phase'             => 'build',
            'group_credit_mode' => $creditMode,
        ]);
        $this->assertTrue($res->ok, 'rule create should succeed: ' . (string) $res->code);
    }

    /** Points held in the fully-combined ('*','*','*') rollup cell for a group. */
    private function rollupPoints(string $groupId): int
    {
        $row = $this->db->table('group_point_rollup')
            ->where('organization_id', $this->orgId)
            ->where('season_id', $this->seasonId)
            ->where('group_id', $groupId)
            ->where('category_code', '*')
            ->where('project_code', '*')
            ->where('phase', '*')
            ->get()->getRowArray();

        return (int) ($row['points'] ?? 0);
    }

    private function seedFixtures(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->orgId = Uuid::v7();
        $this->db->table('organizations')->insert([
            'id' => $this->orgId, 'name' => 'Rollup Org', 'slug' => 'rollup-' . substr($this->orgId, 0, 8),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Region (root) -> District -> Cell, plus sibling District2.
        $this->regionId    = $this->addGroup('Region', null, 1, 'region');
        $this->districtId  = $this->addGroup('District', $this->regionId, 2, 'district');
        $this->district2Id = $this->addGroup('District2', $this->regionId, 2, 'district');
        $this->cellId      = $this->addGroup('Cell', $this->districtId, 3, 'cell');

        // Active season.
        $this->seasonId = Uuid::v7();
        $this->db->table('gamification_seasons')->insert([
            'id' => $this->seasonId, 'organization_id' => $this->orgId,
            'season_year' => (int) date('Y'), 'status' => 'active',
            'starts_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'ends_at'   => date('Y-m-d H:i:s', strtotime('+80 days')),
            'created_at' => $now,
        ]);

        // Members: ama+kofi live in the Cell; zoe belongs to NO group.
        foreach (['ama', 'kofi'] as $key) {
            $this->users[$key] = $this->addUser($key);
            $this->db->table('group_members')->insert([
                'id' => Uuid::v7(), 'organization_id' => $this->orgId, 'group_id' => $this->cellId,
                'user_id' => $this->users[$key], 'role' => 'member', 'membership_type' => 'member',
                'status' => 'active', 'joined_at' => $now,
            ]);
        }
        $this->users['zoe'] = $this->addUser('zoe'); // deliberately group-less
    }

    private function addGroup(string $name, ?string $parentId, int $depth, string $type): string
    {
        $now = date('Y-m-d H:i:s');
        $id  = Uuid::v7();

        // Build the materialized path from the parent's path.
        $path = '/' . $id . '/';
        if ($parentId !== null) {
            $parent = $this->db->table('groups')->where('id', $parentId)->get()->getRowArray();
            $path   = (string) $parent['path'] . $id . '/';
        }

        $this->db->table('groups')->insert([
            'id' => $id, 'organization_id' => $this->orgId, 'parent_id' => $parentId,
            'name' => $name, 'slug' => strtolower($name) . '-' . substr($id, 0, 8), 'type' => $type,
            'depth' => $depth, 'path' => $path, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Closure: self (distance 0) + every ancestor of the parent + 1.
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $id, 'descendant_id' => $id, 'distance' => 0,
        ]);
        if ($parentId !== null) {
            $ancestors = $this->db->table('group_closure')
                ->select('ancestor_id, distance')
                ->where('descendant_id', $parentId)
                ->get()->getResultArray();
            foreach ($ancestors as $a) {
                $this->db->table('group_closure')->insert([
                    'ancestor_id'   => (string) $a['ancestor_id'],
                    'descendant_id' => $id,
                    'distance'      => (int) $a['distance'] + 1,
                ]);
            }
        }

        return $id;
    }

    private function addUser(string $key): string
    {
        $now = date('Y-m-d H:i:s');
        $id  = Uuid::v7();
        $this->db->table('users')->insert([
            'id' => $id, 'organization_id' => $this->orgId,
            'email' => $key . '.' . substr($id, 0, 8) . '@rollup.test',
            'email_verified' => 1, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'display_name' => ucfirst($key), 'status' => 'active', 'locale' => 'en',
            'timezone' => 'UTC', 'mfa_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }
}
