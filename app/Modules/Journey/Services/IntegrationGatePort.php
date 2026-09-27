<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * The integration gate the Journey module consults before a stage move lands.
 *
 * The Journey module knows nothing about decisions or the contact book; it owns
 * only this contract. The Referrals module supplies the adapter (wired through
 * DI), which answers from the integration-decisions feature. When no adapter is
 * wired (null), no gate exists and every transition behaves exactly as before.
 */
interface IntegrationGatePort
{
    /**
     * Should a member be blocked from entering `$toStageCode` because they are
     * not yet integrated?
     *
     * @return array<string,mixed>|null null = allowed; otherwise a payload with
     *                                `blocked` => true and `outstanding` => the
     *                                decision groups still missing.
     */
    public function gate(string $organizationId, string $userId, string $toStageCode, ?string $groupId): ?array;
}
