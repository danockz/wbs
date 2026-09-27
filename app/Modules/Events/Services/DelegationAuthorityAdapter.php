<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use Throwable;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\AccessControl\Services\DelegationService;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;

/**
 * Production {@see CommitteeAuthorityPort}: committee authority IS delegation.
 *
 * Every appointment hands the member a row in `delegations` through the ACL
 * module's own service, so the platform's delegation rules apply unchanged:
 * the delegator must actually hold the capability, the delegated scope must be
 * contained in the delegator's (`scopeContains` over the full scope model), the
 * duration is mandatory and bounded, the chain depth is capped, the purpose is
 * recorded, and revoking a delegation cascades to everything sub-delegated from
 * it. Nothing here re-implements or relaxes any of that — it maps the committee's
 * vocabulary onto the delegation call.
 *
 * Failures are returned as-is (they are already localized `Result`s with codes
 * like DELEGATION_EXCEEDS_AUTHORITY), except an unexpected throw, which becomes a
 * denial: a committee must never gain authority because the ACL layer errored.
 */
final class DelegationAuthorityAdapter implements CommitteeAuthorityPort
{
    public function __construct(
        private readonly DelegationService $delegations,
        private readonly ?AuthorizationService $authorization = null,
    ) {
    }

    public function holds(string $organizationId, string $subjectId, string $permission, ?string $groupId): bool
    {
        if ($subjectId === '' || $permission === '' || $this->authorization === null) {
            // No decision point wired: refuse. A committee never gains authority
            // because the ACL layer is absent.
            return false;
        }

        try {
            // group_id makes this the AUTHORITATIVE per-group decision (the PDP
            // checks the grant's scope covers the target); without it only an
            // org-wide grant would satisfy the question.
            return $this->authorization->isAllowed(new AccessRequest(
                organizationId: $organizationId,
                subjectId: $subjectId,
                action: $permission,
                objectType: 'event_committee',
                attributes: $groupId !== null && $groupId !== '' ? ['group_id' => $groupId] : [],
            ));
        } catch (Throwable) {
            return false;
        }
    }

    public function delegate(string $organizationId, string $delegatorId, string $delegateId, string $permission, array $opts): Result
    {
        $scopeGroup = isset($opts['scope_group_id']) && $opts['scope_group_id'] !== ''
            ? (string) $opts['scope_group_id']
            : null;

        try {
            return $this->delegations->delegate($organizationId, $delegatorId, [
                'delegate_id'      => $delegateId,
                'permission_code'  => $permission,
                'purpose'          => (string) ($opts['purpose'] ?? ''),
                'duration_days'    => max(1, (int) ($opts['duration_days'] ?? 1)),
                'scope_group_id'   => $scopeGroup,
                // No scope group ⇒ SELF (org-wide delegations are the delegator's
                // own business; the committee never widens reach on its own).
                'scope_mode'       => ScopeMode::normalize($opts['scope_mode'] ?? null, null),
                'include_crosscut' => ! empty($opts['include_crosscut']),
            ]);
        } catch (Throwable $e) {
            return Result::fail('AUTHORITY_UNAVAILABLE', 'Events.committee.errAuthorityUnavailable', 503, [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    public function revoke(string $organizationId, string $actorId, string $delegationId, string $reason): Result
    {
        if ($delegationId === '') {
            return Result::ok(['delegation_id' => null, 'revoked' => false], 200, ['noop' => true]);
        }

        try {
            return $this->delegations->revoke($organizationId, $actorId, $delegationId, $reason);
        } catch (Throwable $e) {
            return Result::fail('AUTHORITY_UNAVAILABLE', 'Events.committee.errAuthorityUnavailable', 503, [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
