<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Groups\Services\GroupMembershipService;
use WBS\Shared\Support\Result;

/**
 * Production GroupMembershipPort: forwards a decision-driven membership to the
 * platform's own Groups\GroupMembershipService::add(), so the join policy
 * (open/approval), conflict rules and idempotency apply uniformly. Reuses the
 * Groups module wholesale — no forked membership path.
 */
final class GroupMembershipAdapter implements GroupMembershipPort
{
    public function __construct(private readonly GroupMembershipService $memberships)
    {
    }

    public function join(string $organizationId, string $groupId, string $userId, array $opts = []): Result
    {
        return $this->memberships->add($organizationId, $groupId, [
            'user_id'           => $userId,
            'membership_type'   => (string) ($opts['membership_type'] ?? 'member'),
            'role'              => (string) ($opts['role'] ?? 'member'),
            'source'            => (string) ($opts['source'] ?? 'referral'),
            'requires_approval' => ! empty($opts['requires_approval']),
            'added_by'          => $opts['added_by'] ?? null,
            'actor_id'          => $opts['actor_id'] ?? ($opts['added_by'] ?? null),
        ]);
    }

    public function ensureBelonging(string $organizationId, string $userId, ?string $groupId, array $opts = []): Result
    {
        return $this->memberships->ensureBelonging($organizationId, $userId, $groupId, $opts);
    }
}
