<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Groups\Services\GroupLifecycleService;

/**
 * Locks the group-lifecycle state machine (SRS FR-GRP-005).
 *
 * `GroupLifecycleService::canTransition()` is the pure transition rule behind
 * archive / reactivate / dissolve / merge. Terminal states permit nothing;
 * archive is reversible; an unknown legacy status permits any known forward move
 * but never resurrects a terminal state.
 *
 * @internal
 */
final class GroupLifecycleTransitionTest extends CIUnitTestCase
{
    public function testActiveMayArchiveDissolveOrMerge(): void
    {
        $this->assertTrue(GroupLifecycleService::canTransition('active', 'archived'));
        $this->assertTrue(GroupLifecycleService::canTransition('active', 'dissolved'));
        $this->assertTrue(GroupLifecycleService::canTransition('active', 'merged'));
    }

    public function testArchivedIsReversibleAndTerminable(): void
    {
        $this->assertTrue(GroupLifecycleService::canTransition('archived', 'active'));
        $this->assertTrue(GroupLifecycleService::canTransition('archived', 'dissolved'));
        $this->assertTrue(GroupLifecycleService::canTransition('archived', 'merged'));
    }

    public function testDissolvedIsTerminal(): void
    {
        foreach (GroupLifecycleService::STATES as $to) {
            $this->assertFalse(GroupLifecycleService::canTransition('dissolved', $to));
        }
    }

    public function testMergedIsTerminal(): void
    {
        foreach (GroupLifecycleService::STATES as $to) {
            $this->assertFalse(GroupLifecycleService::canTransition('merged', $to));
        }
    }

    public function testNoOpTransitionIsRejected(): void
    {
        $this->assertFalse(GroupLifecycleService::canTransition('active', 'active'));
        $this->assertFalse(GroupLifecycleService::canTransition('archived', 'archived'));
    }

    public function testUnknownTargetStateIsRejected(): void
    {
        $this->assertFalse(GroupLifecycleService::canTransition('active', 'suspended'));
        $this->assertFalse(GroupLifecycleService::canTransition('active', ''));
    }

    public function testUnknownLegacyStatusPermitsForwardButNotTerminalResurrection(): void
    {
        // A legacy free-string status can still move to a known state...
        $this->assertTrue(GroupLifecycleService::canTransition('inactive', 'active'));
        $this->assertTrue(GroupLifecycleService::canTransition('inactive', 'archived'));
        // ...but an unknown TARGET is still rejected.
        $this->assertFalse(GroupLifecycleService::canTransition('inactive', 'zombie'));
    }
}
