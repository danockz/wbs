<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Referrals\Services\SponsorReassignmentService;

/**
 * Locks the pure decision core of the sponsor-reassignment maker-checker
 * workflow (SRS FR-MEM-002): the eligibility guard that prevents illegal
 * re-parents, and the projected-upline computation the checker reviews before
 * approving.
 *
 * These are the safety-critical invariants of re-parenting:
 *  - a member can never sponsor themselves,
 *  - a re-parent that would create a CYCLE (new sponsor sits inside the
 *    member's own downline) is blocked, and
 *  - the projected upline is self-/loop-guarded so a corrupt graph can never
 *    produce an infinite or self-referential chain.
 *
 * Pure logic — no DB, no live sponsorship graph — so the graph is passed in.
 *
 * @internal
 */
final class SponsorReassignmentEligibilityTest extends CIUnitTestCase
{
    public function testMemberCannotSponsorThemselves(): void
    {
        [$status, $reason] = SponsorReassignmentService::evaluateEligibility('X', 'X', []);
        $this->assertSame('blocked', $status);
        $this->assertNotNull($reason);
    }

    public function testCycleIsBlockedWhenNewSponsorIsInMembersDownline(): void
    {
        // The new sponsor 'Y' has member 'X' in its upline => X is an ancestor
        // of Y, so re-parenting X under Y would form a cycle.
        [$status, $reason] = SponsorReassignmentService::evaluateEligibility('X', 'Y', ['X', 'A', 'B']);
        $this->assertSame('blocked', $status);
        $this->assertNotNull($reason);
    }

    public function testLegalReassignmentIsAllowed(): void
    {
        // Moving 'N' under 'A' whose upline is [B] — N is nowhere in that path.
        [$status, $reason] = SponsorReassignmentService::evaluateEligibility('N', 'A', ['B']);
        $this->assertSame('ok', $status);
        $this->assertNull($reason);
    }

    public function testProjectedUplineIsNewSponsorThenItsUpline(): void
    {
        $path = SponsorReassignmentService::projectUpline('N', 'A', ['B', 'C']);
        $this->assertSame(['A', 'B', 'C'], $path);
    }

    public function testProjectedUplineStopsBeforeIncludingTheMember(): void
    {
        // Defensive: even if a corrupt graph reports the member inside the new
        // sponsor's upline, the projection must not include it or loop past it.
        $path = SponsorReassignmentService::projectUpline('N', 'A', ['B', 'N', 'C']);
        $this->assertSame(['A', 'B'], $path);
        $this->assertNotContains('N', $path);
    }

    public function testProjectedUplineBreaksOnDuplicateToAvoidLoops(): void
    {
        $path = SponsorReassignmentService::projectUpline('N', 'A', ['B', 'A', 'C']);
        $this->assertSame(['A', 'B'], $path);
    }
}
