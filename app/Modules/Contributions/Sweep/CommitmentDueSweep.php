<?php

declare(strict_types=1);

namespace WBS\Contributions\Sweep;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C): remind subjects of giving commitments that have come
 * due and advance each to its next due date (or complete one-time commitments).
 * Wraps the already-idempotent CommitmentService::processDue() (the same
 * operation as the `contributions:commitment-due` command); idempotent within a
 * day via the reminder dedupe key + last_reminded_at watermark. Finding C8-area.
 */
final class CommitmentDueSweep implements SweepContract
{
    public function key(): string
    {
        return 'contributions.commitment-due';
    }

    public function description(): string
    {
        return 'Remind subjects of due giving commitments and roll each to its next due date.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $processed = ContributionServices::commitments()->processDue($organizationId, $limit);

        return SweepResult::ok((int) $processed, ['processed' => (int) $processed]);
    }
}
