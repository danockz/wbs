<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use WBS\Journey\Services\JourneySignalService;
use WBS\Shared\Support\Result;

/**
 * Production JourneySignalPort: forwards a belonging-change journey signal to the
 * platform's own Journey\JourneySignalService::ingest(), so membership-facet
 * rules advance (or propose) the person's stage. Reuses the Journey module
 * wholesale — no forked signal path. Mirrors Referrals\JourneySignalAdapter.
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
