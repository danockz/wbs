<?php

declare(strict_types=1);

namespace WBS\AccessControl\Sweep;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C): expire lapsed access grants/requests + break-glass
 * sessions past their TTL. Wraps the ALREADY-idempotent
 * AccessRequestService::expireLapsed() + BreakGlassService::expireLapsed() (the
 * same operations the standalone `acl:expire` command runs) behind the unified
 * SweepContract so they schedule through the one runner. Findings AC1/AC4/AC5.
 */
final class AccessExpirySweep implements SweepContract
{
    public function key(): string
    {
        return 'acl.expire';
    }

    public function description(): string
    {
        return 'Expire lapsed access grants/requests and break-glass sessions past their TTL.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $grants = AccessControlServices::accessRequests()->expireLapsed($organizationId);
        if ($grants->failed()) {
            return SweepResult::fail('access expiry failed: ' . (string) $grants->message);
        }
        $bg = AccessControlServices::breakGlass()->expireLapsed($organizationId);
        if ($bg->failed()) {
            return SweepResult::fail('break-glass expiry failed: ' . (string) $bg->message);
        }

        $delegationsCascaded = (int) ($grants->data['delegations_cascaded'] ?? 0);
        $bgExpired           = (int) ($bg->data['expired'] ?? 0);
        $assignmentsExpired  = ! empty($grants->data['expired_assignments']) ? 1 : 0;
        $requestsExpired     = ! empty($grants->data['expired_requests']) ? 1 : 0;

        $swept = $delegationsCascaded + $bgExpired + $assignmentsExpired + $requestsExpired;

        return SweepResult::ok($swept, [
            'expired_assignments'  => $assignmentsExpired,
            'expired_requests'     => $requestsExpired,
            'delegations_cascaded' => $delegationsCascaded,
            'break_glass_expired'  => $bgExpired,
        ]);
    }
}
