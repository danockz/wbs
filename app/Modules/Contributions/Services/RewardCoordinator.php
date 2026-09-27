<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Gamification\Services\PointsEngine;
use WBS\Shared\Messaging\IdempotencyStore;
use WBS\Shared\Support\Result;

/**
 * Bridges verified contribution events to gamification AND the VBCS metrics
 * (SRS FR-VBCS-007 / FR-GAM-003). Runs as an idempotent queue consumer of
 * contribution.succeeded / contribution.refunded.
 *
 *  - Points are awarded ONLY on verified success, keyed by
 *    source_ref = "contribution:{id}" so a redelivered event cannot double-award
 *    (PointsEngine also enforces this via UNIQUE(rule,subject,source_ref)).
 *  - A refund reverses the associated non-final points with a compensating
 *    ledger adjustment.
 *  - PGV/GGV are refreshed for the giver and cascaded up their sponsor chain,
 *    then the giver's partnership tier is recomputed. These are derived caches,
 *    so recomputing on a redelivered event is harmless (idempotent upsert).
 */
final class RewardCoordinator
{
    private const CONSUMER = 'contributions.reward';
    private const RULE     = 'contribution.verified';

    public function __construct(
        private readonly PointsEngine $points,
        private readonly IdempotencyStore $idem,
        private readonly MetricsService $metrics,
        private readonly PartnershipService $partnership,
        private readonly ?BaseConnection $db = null,
    ) {
    }

    /**
     * Handle a contribution.succeeded event.
     *
     * @param array<string,mixed> $event organization_id, user_id, source_ref
     */
    public function onSucceeded(array $event): Result
    {
        $orgId     = (string) ($event['organization_id'] ?? '');
        $userId    = (string) ($event['user_id'] ?? '');
        $sourceRef = (string) ($event['source_ref'] ?? '');
        if ($orgId === '' || $userId === '' || $sourceRef === '') {
            return Result::fail('BAD_EVENT', 'reward.bad_event', 422);
        }

        // Consumer-side idempotency (belt and braces with ledger UNIQUE).
        if (! $this->idem->markProcessed(self::CONSUMER, 'succeeded:' . $sourceRef)) {
            return Result::ok(['status' => 'already_processed'], 200, ['deduplicated' => true]);
        }

        // Refresh derived VBCS metrics for the giver + their upline, then their
        // partnership tier. Derived caches -> safe on redelivery.
        $this->metrics->cascadeToUpline($orgId, $userId);
        $this->partnership->recomputeStatus($orgId, $userId);

        // Attribute the credit to the RECEIVING (cause) group so the ledger entry
        // rolls up to that group and every ancestor (design doc Part B). When the
        // cause has no owning group, PointsEngine falls back to the giver's own
        // group (membership fallback), else org-level. The cause id also tags the
        // entry's project dimension for project boards.
        $amountMinor    = (int) ($event['amount_minor'] ?? 0);
        $causeId        = (string) ($event['cause_id'] ?? '');
        $receivingGroup = $causeId !== '' ? $this->causeGroupId($orgId, $causeId) : null;

        // Pass amount_minor through `data` so amount-metric group campaigns
        // (e.g. a giving "project") are fed the contribution value, while
        // points/count campaigns use the awarded points / event count. The
        // campaign feed runs inside award() for FINAL awards only.
        return $this->points->award($orgId, self::RULE, $userId, $sourceRef, [
            'subject_type'       => 'user',
            'receiving_group_id' => $receivingGroup,
            'project_code'       => $causeId !== '' ? $causeId : null,
            'amount_minor'       => $amountMinor,
            'data'               => ['amount_minor' => $amountMinor],
        ]);
    }

    /**
     * Handle a contribution.refunded event — reverse non-final points.
     *
     * @param array<string,mixed> $event organization_id, source_ref
     */
    public function onRefunded(array $event): Result
    {
        $orgId     = (string) ($event['organization_id'] ?? '');
        $sourceRef = (string) ($event['source_ref'] ?? '');
        if ($orgId === '' || $sourceRef === '') {
            return Result::fail('BAD_EVENT', 'reward.bad_event', 422);
        }

        if (! $this->idem->markProcessed(self::CONSUMER, 'refunded:' . $sourceRef)) {
            return Result::ok(['status' => 'already_processed'], 200, ['deduplicated' => true]);
        }

        // Refresh derived VBCS metrics for the giver + upline (a refund lowers
        // PGV/GGV), then their partnership tier. Safe on redelivery.
        $userId = (string) ($event['user_id'] ?? '');
        if ($userId !== '') {
            $this->metrics->cascadeToUpline($orgId, $userId);
            $this->partnership->recomputeStatus($orgId, $userId);
        }

        return $this->points->reverse($orgId, $sourceRef, 'contribution refunded');
    }

    /**
     * The owning group of a cause (the RECEIVING group for attribution), or null
     * when the cause is org-wide / unknown / no DB handle is wired.
     */
    private function causeGroupId(string $organizationId, string $causeId): ?string
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->table('causes')
            ->select('group_id')
            ->where('organization_id', $organizationId)
            ->where('id', $causeId)
            ->get()->getRowArray();

        return $row !== null && $row['group_id'] !== null && $row['group_id'] !== ''
            ? (string) $row['group_id']
            : null;
    }
}
