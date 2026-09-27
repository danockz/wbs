<?php

declare(strict_types=1);

namespace WBS\AccessControl\Policy\Combinators;

use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Policy\Decision;

/**
 * Maker-checker segregation of duties (SRS: financial/privileged approvals).
 *
 * For approval actions, the acting subject must NOT be the same principal who
 * submitted/requested the item being approved. This is enforced in the PDP so
 * every approval path inherits it, and financial services (e.g. RefundService,
 * ConnectionService payment approval) mirror it at their boundary as
 * defence-in-depth.
 *
 * The submitter identity is supplied as a request attribute (submitted_by /
 * requested_by) taken from the persisted record, never from client input.
 */
final class SegregationOfDutiesCombinator
{
    /** Actions that require maker != checker. */
    public const APPROVAL_ACTIONS = [
        'contribution.refund.approve',
        'integration.connection.approve',
        'group.change.approve',
        'sponsor.reassign.approve',
        'access.request.approve',
    ];

    /**
     * Returns a DENY decision when self-approval is detected, or null when this
     * combinator has no objection (the caller continues its decision pipeline).
     */
    public function evaluate(AccessRequest $req): ?Decision
    {
        if (! in_array($req->action, self::APPROVAL_ACTIONS, true)) {
            return null;
        }

        $submitter = $req->attr('submitted_by') ?? $req->attr('requested_by');
        if ($submitter !== null && (string) $submitter === $req->subjectId) {
            return Decision::deny('SOD_SELF_APPROVAL', 'sod');
        }

        return null;
    }
}
