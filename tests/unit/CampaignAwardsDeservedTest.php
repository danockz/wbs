<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Gamification\Services\CampaignService;

/**
 * Locks in the campaign target-award math.
 *
 * `CampaignService::awardsDeserved()` decides how many times a subject has hit a
 * group campaign's target given their cumulative metric value. It encodes the
 * repeatable / non-repeatable and max-awards-cap rules, and is pure (no DB), so
 * it is tested in isolation.
 *
 * @internal
 */
final class CampaignAwardsDeservedTest extends CIUnitTestCase
{
    public function testBelowTargetEarnsNothing(): void
    {
        $this->assertSame(0, CampaignService::awardsDeserved(99, 100, false, null));
        $this->assertSame(0, CampaignService::awardsDeserved(0, 100, true, null));
    }

    public function testExactTargetEarnsOne(): void
    {
        $this->assertSame(1, CampaignService::awardsDeserved(100, 100, false, null));
        $this->assertSame(1, CampaignService::awardsDeserved(100, 100, true, null));
    }

    public function testNonRepeatableCapsAtOneEvenWayOverTarget(): void
    {
        $this->assertSame(1, CampaignService::awardsDeserved(1000, 100, false, null));
    }

    public function testRepeatableEarnsOnePerTargetMultiple(): void
    {
        $this->assertSame(3, CampaignService::awardsDeserved(350, 100, true, null));
        $this->assertSame(5, CampaignService::awardsDeserved(500, 100, true, null));
    }

    public function testRepeatableHonoursMaxAwardsCap(): void
    {
        // 7 multiples earned but capped at 3.
        $this->assertSame(3, CampaignService::awardsDeserved(700, 100, true, 3));
        // Under the cap, the natural count wins.
        $this->assertSame(2, CampaignService::awardsDeserved(250, 100, true, 3));
    }

    public function testZeroTargetIsSafe(): void
    {
        $this->assertSame(0, CampaignService::awardsDeserved(500, 0, true, null));
    }

    // --- Tiered awards (silver/gold/diamond) --------------------------------

    /** @return list<array<string,mixed>> ascending by threshold */
    private function ladder(): array
    {
        return [
            ['tier_position' => 1, 'code' => 'silver', 'threshold_value' => 100],
            ['tier_position' => 2, 'code' => 'gold', 'threshold_value' => 500],
            ['tier_position' => 3, 'code' => 'diamond', 'threshold_value' => 1000],
        ];
    }

    public function testTieredBelowFirstThresholdReachesNothing(): void
    {
        $this->assertSame([], CampaignService::tiersReached(50, $this->ladder(), []));
    }

    public function testTieredReachesFirstTierOnly(): void
    {
        $reached = CampaignService::tiersReached(150, $this->ladder(), []);
        $this->assertCount(1, $reached);
        $this->assertSame('silver', $reached[0]['code']);
    }

    public function testTieredReachesMultipleTiersAtOnceFromCold(): void
    {
        // A big jump straight to 1200 earns all three tiers in one call.
        $reached = CampaignService::tiersReached(1200, $this->ladder(), []);
        $this->assertSame(['silver', 'gold', 'diamond'], array_column($reached, 'code'));
    }

    public function testTieredSkipsAlreadyGrantedTiers(): void
    {
        // Silver (pos 1) already granted; at 600 only gold (pos 2) is new.
        $reached = CampaignService::tiersReached(600, $this->ladder(), [1]);
        $this->assertCount(1, $reached);
        $this->assertSame('gold', $reached[0]['code']);
    }

    public function testTieredGrantsNothingWhenAllReachedTiersAlreadyGranted(): void
    {
        $this->assertSame([], CampaignService::tiersReached(600, $this->ladder(), [1, 2]));
    }

    // --- Optional team-challenge attribution (attributedTeams) --------------
    //
    // The campaign is individual-first; a team challenge is an optional overlay.
    // For a subtree team challenge, owner "region" has child teams
    // "north"/"south"; a member's contribution TALLIES into whichever child
    // sits on their group's ancestor path (their individual awards are separate).

    public function testUserRollsUpToTheirChildTeam(): void
    {
        // User is in "north-a", whose ancestors-or-self are: north-a, north, region.
        $teams = CampaignService::attributedTeams(
            ['north', 'south'],
            ['north-a', 'north', 'region'],
            'region',
        );
        $this->assertSame(['north'], $teams);
    }

    public function testUserDirectlyInAChildTeamCountsForIt(): void
    {
        $teams = CampaignService::attributedTeams(
            ['north', 'south'],
            ['south', 'region'],
            'region',
        );
        $this->assertSame(['south'], $teams);
    }

    public function testUserOutsideAnyChildTeamGetsNoAttribution(): void
    {
        // User only under "region" directly (not under a child) → no team.
        $teams = CampaignService::attributedTeams(
            ['north', 'south'],
            ['region'],
            'region',
        );
        $this->assertSame([], $teams);
    }

    public function testOwnerWithNoChildrenCompetesAsSingleAggregate(): void
    {
        // No child teams → the owner itself is the one aggregate subject, so
        // long as the user is within its subtree.
        $teams = CampaignService::attributedTeams(
            [],
            ['chapter', 'region'],
            'region',
        );
        $this->assertSame(['region'], $teams);
    }

    public function testOwnerWithNoChildrenAndUserOutsideSubtreeGetsNothing(): void
    {
        $teams = CampaignService::attributedTeams(
            [],
            ['other-branch'],
            'region',
        );
        $this->assertSame([], $teams);
    }

    // --- Leaf-inclusive milestone roll-up chain (rollup_awards=1) -----------
    //
    // milestoneChain returns every group on the MEMBER's own ancestor-or-self
    // path (nearest-first) that sits inside the owner's subtree, EXCLUDING the
    // direct-child team (already tallied by the caller). Tree:
    //   region > north > north-a > north-a-1 ; region > south
    // owner = region, direct-child team = north.

    public function testMilestoneChainIncludesLeafUpToOwnerExcludingTeam(): void
    {
        // Member leaf = north-a-1; leaf path nearest-first:
        //   north-a-1, north-a, north, region.
        // Exclude the direct-child team (north). Result: leaf, north-a, region.
        $chain = CampaignService::milestoneChain(
            ['north-a-1', 'north-a', 'north', 'region'],
            ['region', 'north', 'south', 'north-a', 'north-a-1'], // owner subtree
            'north',
        );
        $this->assertSame(['north-a-1', 'north-a', 'region'], $chain);
    }

    public function testMilestoneChainStopsAtOwnerExcludesGroupsAboveCampaign(): void
    {
        // Groups above the owner (e.g. "org") are outside the owner subtree and
        // must never mint, even though they are on the member's leaf path.
        $chain = CampaignService::milestoneChain(
            ['north-a', 'north', 'region', 'org'],
            ['region', 'north', 'south', 'north-a', 'north-a-1'],
            'north',
        );
        $this->assertSame(['north-a', 'region'], $chain);
        $this->assertNotContains('org', $chain);
    }

    public function testMilestoneChainMemberDirectlyInTeamMintsOwnerOnly(): void
    {
        // Member leaf IS the direct-child team (north): path north, region.
        // north is excluded (already tallied) → only the owner mints extra.
        $chain = CampaignService::milestoneChain(
            ['north', 'region'],
            ['region', 'north', 'south', 'north-a', 'north-a-1'],
            'north',
        );
        $this->assertSame(['region'], $chain);
    }

    public function testMilestoneChainMemberDirectlyInOwnerMintsOwnerOnly(): void
    {
        // Owner with children, member sits directly under the owner (leaf=region).
        // Direct-child team resolution would be empty in practice, but if a team
        // ref is passed that isn't on the path, the whole in-subtree path mints.
        $chain = CampaignService::milestoneChain(
            ['region'],
            ['region', 'north', 'south'],
            'north',
        );
        $this->assertSame(['region'], $chain);
    }

    // --- Group/team milestone (single target, single award) ----------------
    //
    // Each competing group/team has its OWN milestone (parity with individuals):
    // it accumulates only the contributions DIRECTED AT IT and earns exactly one
    // award the first time that directed total reaches team_target_value.

    public function testTeamMilestoneNotReachedBelowTarget(): void
    {
        $this->assertFalse(CampaignService::teamMilestoneReached(189999, 190000, 0));
    }

    public function testTeamMilestoneReachedAtExactTarget(): void
    {
        $this->assertTrue(CampaignService::teamMilestoneReached(190000, 190000, 0));
    }

    public function testTeamMilestoneReachedWhenWellOverTarget(): void
    {
        $this->assertTrue(CampaignService::teamMilestoneReached(195000, 190000, 0));
    }

    public function testTeamMilestoneGrantsOnlyOnce(): void
    {
        // Already granted (prevAwards = 1) → never fires again, even higher.
        $this->assertFalse(CampaignService::teamMilestoneReached(300000, 190000, 1));
    }

    public function testTeamMilestoneDisabledWhenNoTargetConfigured(): void
    {
        // team_target_value NULL is normalised to 0 → scoreboard only, no award.
        $this->assertFalse(CampaignService::teamMilestoneReached(500000, 0, 0));
    }

    // --- Hierarchical group path stitching (leaderboard, O(1) reads) --------
    //
    // The leaderboard shows each member's SPECIFIC hierarchical group as a full
    // path. We never recompute ancestry per row: groups.path already holds the
    // ancestor UUID chain, and one bounded query maps ids→names. These pure
    // helpers do the parse + stitch, so the read stays O(rows)+O(1) queries.

    public function testParsePathIdsSplitsMaterializedPath(): void
    {
        $this->assertSame(
            ['region', 'district', 'chapter'],
            CampaignService::parsePathIds('/region/district/chapter/'),
        );
    }

    public function testParsePathIdsHandlesRootAndBlankSegments(): void
    {
        $this->assertSame([], CampaignService::parsePathIds('/'));
        $this->assertSame([], CampaignService::parsePathIds(''));
        $this->assertSame(['a', 'b'], CampaignService::parsePathIds('//a//b//'));
    }

    public function testGroupPathNamesMapsChainRootToLeaf(): void
    {
        $names = ['g1' => 'Greater Accra Region', 'g2' => 'Accra Metro District'];
        $this->assertSame(
            ['Greater Accra Region', 'Accra Metro District'],
            CampaignService::groupPathNames(['g1', 'g2'], $names),
        );
    }

    public function testGroupPathNamesSkipsUnknownIds(): void
    {
        // An id with no name (e.g. filtered by org scope) is skipped, not blanked.
        $names = ['g1' => 'Region', 'g3' => 'Chapter'];
        $this->assertSame(
            ['Region', 'Chapter'],
            CampaignService::groupPathNames(['g1', 'g2', 'g3'], $names),
        );
    }

    public function testGroupPathNamesEmptyChainIsEmpty(): void
    {
        $this->assertSame([], CampaignService::groupPathNames([], ['g1' => 'Region']));
    }
}
