<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam the ContactBookService uses to turn a `join_group` decision into a
 * real belonging, so the outreach module depends on this one method rather than
 * the whole Groups module. The production adapter wraps
 * Groups\GroupMembershipService::add() — which honours the group's join policy
 * (open → active immediately, approval → pending until a leader approves),
 * conflict rules and idempotency — so a decision-driven membership can never
 * bypass the membership lifecycle. Tests supply a trivial in-memory implementation.
 *
 * @see \WBS\Groups\Services\GroupMembershipService::add()
 */
interface GroupMembershipPort
{
    /**
     * @param array<string,mixed> $opts membership_type, role, source,
     *   requires_approval(bool), added_by, actor_id
     */
    public function join(string $organizationId, string $groupId, string $userId, array $opts = []): Result;

    /**
     * Guarantee the "every system user belongs to a group" invariant: no-op when
     * the user already holds an active membership anywhere, otherwise attach them
     * to $groupId. Never MOVES an existing belonging.
     *
     * @param array<string,mixed> $opts source, added_by, actor_id, requires_approval
     */
    public function ensureBelonging(string $organizationId, string $userId, ?string $groupId, array $opts = []): Result;
}
