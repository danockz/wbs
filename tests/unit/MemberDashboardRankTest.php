<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Reporting\Services\MemberDashboardService;

/**
 * Locks in the member dashboard's rank-ladder resolution.
 *
 * `MemberDashboardService::resolveRank()` maps a member's point total onto the
 * organization's ascending rank ladder, returning the current rank, the next
 * rank up, and the points still needed to reach it. It is pure (no DB), so the
 * ladder logic is tested in isolation.
 *
 * @internal
 */
final class MemberDashboardRankTest extends CIUnitTestCase
{
    /** @return list<array<string,mixed>> ascending by min_points */
    private function ladder(): array
    {
        return [
            ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 0],
            ['code' => 'silver', 'name' => 'Silver', 'min_points' => 100],
            ['code' => 'gold', 'name' => 'Gold', 'min_points' => 500],
        ];
    }

    public function testEntryLevelResolvesToLowestRank(): void
    {
        $r = MemberDashboardService::resolveRank(0, $this->ladder());

        $this->assertSame('Bronze', $r['current']['name']);
        $this->assertSame('Silver', $r['next']['name']);
        $this->assertSame(100, $r['points_to_next']);
    }

    public function testMidLadderPicksHighestRankAtOrBelowPoints(): void
    {
        $r = MemberDashboardService::resolveRank(250, $this->ladder());

        $this->assertSame('Silver', $r['current']['name']);
        $this->assertSame('Gold', $r['next']['name']);
        $this->assertSame(250, $r['points_to_next']); // 500 - 250
    }

    public function testExactBoundaryCountsAsThatRank(): void
    {
        $r = MemberDashboardService::resolveRank(100, $this->ladder());

        $this->assertSame('Silver', $r['current']['name']);
        $this->assertSame('Gold', $r['next']['name']);
        $this->assertSame(400, $r['points_to_next']);
    }

    public function testTopRankHasNoNext(): void
    {
        $r = MemberDashboardService::resolveRank(999, $this->ladder());

        $this->assertSame('Gold', $r['current']['name']);
        $this->assertNull($r['next']);
        $this->assertNull($r['points_to_next']);
    }

    public function testEmptyLadderIsUnranked(): void
    {
        $r = MemberDashboardService::resolveRank(50, []);

        $this->assertNull($r['current']);
        $this->assertNull($r['next']);
        $this->assertNull($r['points_to_next']);
    }

    // -- rankProgressPercent --------------------------------------------------

    public function testProgressPercentMidwayBetweenTiers(): void
    {
        $current = ['min_points' => 100];
        $next    = ['min_points' => 200];

        // 150 pts is halfway from 100 → 200.
        $this->assertSame(50, MemberDashboardService::rankProgressPercent(150, $current, $next));
    }

    public function testProgressPercentAtTierFloorIsZero(): void
    {
        $this->assertSame(0, MemberDashboardService::rankProgressPercent(
            100,
            ['min_points' => 100],
            ['min_points' => 300],
        ));
    }

    public function testProgressPercentTopRankIsFull(): void
    {
        // No next rank → treated as complete.
        $this->assertSame(100, MemberDashboardService::rankProgressPercent(500, ['min_points' => 400], null));
    }

    public function testProgressPercentClampsAndRounds(): void
    {
        // Points beyond the next floor clamp to 100.
        $this->assertSame(100, MemberDashboardService::rankProgressPercent(
            999,
            ['min_points' => 100],
            ['min_points' => 200],
        ));
    }

    public function testProgressPercentUnconfiguredRanksIsFull(): void
    {
        $this->assertSame(100, MemberDashboardService::rankProgressPercent(50, null, null));
    }

    // -- milestoneForTrack ----------------------------------------------------

    /** @return callable(int):string */
    private function eventsLabel(): callable
    {
        return static fn (int $t): string => "Attended {$t} events";
    }

    public function testMilestoneBelowFirstTierHasNoAchievementButHasNext(): void
    {
        $m = MemberDashboardService::milestoneForTrack(0, [1, 5, 10], $this->eventsLabel());

        $this->assertNull($m['achieved']);
        $this->assertNotNull($m['next']);
        $this->assertSame(1, $m['next']['tier']);
        $this->assertSame(1, $m['next']['remaining']);
    }

    public function testMilestonePicksHighestTierReached(): void
    {
        $m = MemberDashboardService::milestoneForTrack(7, [1, 5, 10, 25], $this->eventsLabel());

        $this->assertSame(5, $m['achieved']['tier']);
        $this->assertSame('Attended 5 events', $m['achieved']['label']);
        $this->assertSame(10, $m['next']['tier']);
        $this->assertSame(3, $m['next']['remaining']); // 10 - 7
    }

    public function testMilestoneExactTierBoundaryCounts(): void
    {
        $m = MemberDashboardService::milestoneForTrack(5, [1, 5, 10], $this->eventsLabel());

        $this->assertSame(5, $m['achieved']['tier']);
        $this->assertSame(10, $m['next']['tier']);
        $this->assertSame(5, $m['next']['remaining']);
    }

    public function testMilestoneTopTierHasNoNext(): void
    {
        $m = MemberDashboardService::milestoneForTrack(99, [1, 5, 10], $this->eventsLabel());

        $this->assertSame(10, $m['achieved']['tier']);
        $this->assertNull($m['next']);
    }

    // --- Hierarchical group path on "My groups" -----------------------------
    //
    // The dashboard shows each membership's full group path. Ancestry comes from
    // the materialized groups.path (never recomputed); these pure helpers parse
    // it and stitch names, so the resolution is O(memberships) + O(1) queries.

    public function testParsePathIdsSplitsMaterializedPath(): void
    {
        $this->assertSame(
            ['region', 'district', 'chapter'],
            MemberDashboardService::parsePathIds('/region/district/chapter/'),
        );
        $this->assertSame([], MemberDashboardService::parsePathIds('/'));
        $this->assertSame([], MemberDashboardService::parsePathIds(''));
    }

    public function testGroupPathNamesMapsChainRootToLeaf(): void
    {
        $names = ['g1' => 'Greater Accra Region', 'g2' => 'Accra Metro District'];
        $this->assertSame(
            ['Greater Accra Region', 'Accra Metro District'],
            MemberDashboardService::groupPathNames(['g1', 'g2'], $names),
        );
    }

    public function testGroupPathNamesSkipsUnknownIds(): void
    {
        $names = ['g1' => 'Region', 'g3' => 'Chapter'];
        $this->assertSame(
            ['Region', 'Chapter'],
            MemberDashboardService::groupPathNames(['g1', 'g2', 'g3'], $names),
        );
    }
}
