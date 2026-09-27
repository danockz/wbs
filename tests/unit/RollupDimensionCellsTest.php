<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Gamification\Services\RollupService;

/**
 * Locks the ranking roll-up FAN-OUT math (design doc Part B.3–B.4).
 *
 * A single ledger entry tagged (category, project, phase) must update not only
 * its fully-specific `group_point_rollup` cell but every "combined" cell where
 * one or more of those three axes is rolled to the '*' sentinel — so the
 * all-categories / all-projects / all-phases boards stay consistent in one pass.
 * `RollupService::dimensionCells()` is that pure fan-out; a regression here
 * silently desynchronises the combined boards from the specific ones.
 *
 * @internal
 */
final class RollupDimensionCellsTest extends CIUnitTestCase
{
    private const ALL = RollupService::ALL;

    public function testFullySpecificTupleFansOutToEightDistinctCells(): void
    {
        $cells = RollupService::dimensionCells('giving', 'proj1', 'build');

        // 2^3 combinations, all distinct when every axis is specific.
        $this->assertCount(8, $cells);
        $this->assertContains(['giving', 'proj1', 'build'], $cells); // fully specific
        $this->assertContains([self::ALL, self::ALL, self::ALL], $cells); // grand total
        $this->assertContains(['giving', self::ALL, self::ALL], $cells); // category board
        $this->assertContains([self::ALL, 'proj1', self::ALL], $cells); // project board
        $this->assertContains([self::ALL, self::ALL, 'build'], $cells); // phase board
    }

    public function testEveryCellIsUnique(): void
    {
        $cells = RollupService::dimensionCells('giving', 'proj1', 'build');
        $keys  = array_map(static fn (array $c): string => implode('|', $c), $cells);

        $this->assertSame($keys, array_values(array_unique($keys)), 'cells must be de-duplicated');
    }

    public function testAllStarTupleCollapsesToSingleGrandTotalCell(): void
    {
        // When the entry itself carries no cross-cut dimensions, there is exactly
        // one cell: the grand total. No phantom duplicate '*' rows.
        $cells = RollupService::dimensionCells(self::ALL, self::ALL, self::ALL);

        $this->assertSame([[self::ALL, self::ALL, self::ALL]], $cells);
    }

    public function testOneSpecificAxisYieldsTwoCells(): void
    {
        // Only phase is specific → {phase} and {*}. Category/project already '*'.
        $cells = RollupService::dimensionCells(self::ALL, self::ALL, 'send');

        $this->assertCount(2, $cells);
        $this->assertContains([self::ALL, self::ALL, 'send'], $cells);
        $this->assertContains([self::ALL, self::ALL, self::ALL], $cells);
    }

    public function testTwoSpecificAxesYieldFourCells(): void
    {
        $cells = RollupService::dimensionCells('giving', self::ALL, 'build');

        $this->assertCount(4, $cells);
        $this->assertContains(['giving', self::ALL, 'build'], $cells);
        $this->assertContains(['giving', self::ALL, self::ALL], $cells);
        $this->assertContains([self::ALL, self::ALL, 'build'], $cells);
        $this->assertContains([self::ALL, self::ALL, self::ALL], $cells);
    }
}
