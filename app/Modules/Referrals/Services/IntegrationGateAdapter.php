<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Journey\Services\IntegrationGatePort;

/**
 * Journey's {@see IntegrationGatePort}, backed by {@see IntegrationService}.
 * A thin, config-aware wrapper: when the feature is off for the journey's group
 * (or the move is not into a gated stage), it answers null (allowed) and the
 * journey proceeds untouched.
 */
final class IntegrationGateAdapter implements IntegrationGatePort
{
    public function __construct(private readonly ?IntegrationService $integration = null)
    {
    }

    public function gate(string $organizationId, string $userId, string $toStageCode, ?string $groupId): ?array
    {
        $svc = $this->integration ?? \WBS\Referrals\Config\Services::integration(false);

        return $svc->gate($organizationId, $userId, $toStageCode, $groupId);
    }
}
