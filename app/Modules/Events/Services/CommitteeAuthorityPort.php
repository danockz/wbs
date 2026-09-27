<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow port for the authority a committee member holds.
 *
 * A committee gets NO new permission bits. Its authority is a TIME-BOUNDED
 * DELEGATION of an existing `event.*` capability — leader → chair, chair →
 * member — created through the platform's ACL layer so containment
 * (`GroupScopeResolver::scopeContains`), chain depth and "you cannot delegate
 * what you do not hold" are all enforced there, once, rather than re-derived in
 * the Events module. This port is the two operations the committee lifecycle
 * needs, so Events depends on the idea rather than on AccessControl's service:
 *
 *  - holds(): ask the platform's decision point whether a subject actually holds
 *    a capability over a group — the authoritative check, because scope coverage
 *    alone is not authority (a cell leader scoped to a group may hold only
 *    check-in, not the right to form a committee there);
 *  - delegate(): grant one capability to one user, bounded in time and scope;
 *  - revoke(): take it back — which also cascades to anything sub-delegated from
 *    it, because a derived authority cannot outlive its source.
 *
 * The production adapter wraps `DelegationService`; tests supply a spy.
 */
interface CommitteeAuthorityPort
{
    /**
     * Create a bounded delegation of an existing capability.
     *
     * @param array<string,mixed> $opts purpose (required), duration_days (required,
     *                                  1..365), scope_group_id, scope_mode,
     *                                  include_crosscut
     *
     * @return Result on success data carries `delegation_id` and `effective_to`
     */
    public function delegate(string $organizationId, string $delegatorId, string $delegateId, string $permission, array $opts): Result;

    /** Revoke a delegation (and everything sub-delegated from it). */
    public function revoke(string $organizationId, string $actorId, string $delegationId, string $reason): Result;

    /**
     * Does this subject hold $permission over $groupId RIGHT NOW?
     *
     * Delegates to the platform's PDP, so MAC, segregation of duties, RuBAC denies
     * and the scope model all apply exactly as they do everywhere else — and an
     * active, unexpired delegation counts, which is how a chair or member holds
     * authority at all. A NULL group asks the org-wide question.
     */
    public function holds(string $organizationId, string $subjectId, string $permission, ?string $groupId): bool;
}
