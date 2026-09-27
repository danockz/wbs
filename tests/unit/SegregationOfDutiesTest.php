<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Policy\Combinators\SegregationOfDutiesCombinator;

/**
 * Locks maker-checker segregation of duties (SRS: financial/privileged
 * approvals). Self-approval on any approval action MUST be denied inside the
 * PDP, and the submitter identity must come from the persisted record's
 * attribute, never be inferable from a non-approval action.
 *
 * These paths gate refunds and privileged approvals, so a regression is a
 * direct financial-control failure. Pure logic — no DB.
 *
 * @internal
 */
final class SegregationOfDutiesTest extends CIUnitTestCase
{
    private SegregationOfDutiesCombinator $sod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sod = new SegregationOfDutiesCombinator();
    }

    private function req(string $action, array $attrs): AccessRequest
    {
        return new AccessRequest('org-1', 'user-approver', $action, null, null, $attrs);
    }

    public function testSelfApprovalIsDeniedForEveryApprovalAction(): void
    {
        foreach (SegregationOfDutiesCombinator::APPROVAL_ACTIONS as $action) {
            $decision = $this->sod->evaluate($this->req($action, ['submitted_by' => 'user-approver']));
            $this->assertNotNull($decision, "{$action} self-approval should be decided");
            $this->assertFalse($decision->isPermitted());
            $this->assertSame('SOD_SELF_APPROVAL', $decision->reason);
            $this->assertSame('sod', $decision->stage);
        }
    }

    public function testDifferentMakerAndCheckerIsAllowedThrough(): void
    {
        // maker != checker → no objection; the combinator returns null so the
        // pipeline continues to RBAC.
        $decision = $this->sod->evaluate($this->req('contribution.refund.approve', ['submitted_by' => 'user-maker']));
        $this->assertNull($decision);
    }

    public function testRequestedByIsAlsoHonouredAsSubmitter(): void
    {
        $decision = $this->sod->evaluate($this->req('access.request.approve', ['requested_by' => 'user-approver']));
        $this->assertNotNull($decision);
        $this->assertFalse($decision->isPermitted());
    }

    public function testNonApprovalActionIsIgnoredEvenWhenSelfSubmitted(): void
    {
        // A non-approval action is out of scope for SoD regardless of attributes.
        $decision = $this->sod->evaluate($this->req('contribution.create', ['submitted_by' => 'user-approver']));
        $this->assertNull($decision);
    }

    public function testMissingSubmitterDoesNotBlock(): void
    {
        // Without a known submitter, SoD cannot assert self-approval; it defers
        // (RBAC still governs). It must NOT hard-deny on missing data.
        $decision = $this->sod->evaluate($this->req('group.change.approve', []));
        $this->assertNull($decision);
    }
}
