<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use WBS\Journey\Services\JourneySignalService;
use WBS\Shared\Support\Result;

/**
 * Production JourneySignalPort: forwards an account-creation journey signal to the
 * platform's own Journey\JourneySignalService::ingest(), so a membership-facet
 * entry rule opens the new member's journey. Reuses the Journey module wholesale
 * — no forked signal path. Mirrors Referrals/Groups JourneySignalAdapter.
 */
final class JourneySignalAdapter implements JourneySignalPort
{
    public function __construct(private readonly JourneySignalService $signals)
    {
    }

    public function ingest(string $organizationId, array $data): Result
    {
        return $this->signals->ingest($organizationId, $data);
    }
}
