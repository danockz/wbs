<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Partnership tiers (VBCS). A tier is earned by giving in N consecutive
 * calendar months AND reaching a lifetime PGV threshold.
 *
 * Adaptation notes vs. GivingsLibrary:
 *  - Tier DEFINITIONS are admin-configurable (define/disable/list) mirroring
 *    gamification RankService; thresholds are BIGINT minor units.
 *  - Per-subject status is DERIVED (recomputeStatus) and cached in
 *    user_partnership_status; a level change is emitted via the outbox, not an
 *    inline Events::trigger.
 */
final class PartnershipService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly MetricsService $metrics,
        private readonly OutboxService $outbox,
    ) {
    }

    // --- Admin config: tier definitions --------------------------------------

    /**
     * Create or update a partnership tier (admin). Upsert by code.
     *
     * @param array<string,mixed> $data code, name, min_consecutive_months,
     *          min_pgv_minor, description, icon, color, sort_order, status
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_TIER', 'partnership.code_name_required', 422);
        }

        $payload = [
            'name'                   => mb_substr($name, 0, 150),
            'description'            => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'min_consecutive_months' => max(0, (int) ($data['min_consecutive_months'] ?? 0)),
            'min_pgv_minor'          => max(0, (int) ($data['min_pgv_minor'] ?? 0)),
            'icon'                   => $data['icon'] ?? null,
            'color'                  => $data['color'] ?? null,
            'sort_order'             => (int) ($data['sort_order'] ?? 0),
            'status'                 => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
        ];

        $existing = $this->db->table('partnership_level_definitions')
            ->where('organization_id', $organizationId)->where('code', $code)->get()->getRowArray();

        if ($existing !== null) {
            $payload['updated_at'] = $this->clock->nowUtcString();
            $this->db->table('partnership_level_definitions')->where('id', $existing['id'])->update($payload);

            return Result::ok(['level_id' => $existing['id'], 'code' => $code, 'updated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('partnership_level_definitions')->insert($payload + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'code'            => $code,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['level_id' => $id, 'code' => $code]);
    }

    /** Deactivate a tier. */
    public function disable(string $organizationId, string $code): Result
    {
        $tier = $this->db->table('partnership_level_definitions')
            ->where('organization_id', $organizationId)->where('code', $code)->get()->getRowArray();
        if ($tier === null) {
            return Result::notFound('partnership.tier_not_found', 'TIER_NOT_FOUND');
        }
        $this->db->table('partnership_level_definitions')->where('id', $tier['id'])->update([
            'status'     => 'inactive',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    /** Active tiers, highest requirement first. @return list<array<string,mixed>> */
    public function tiers(string $organizationId): array
    {
        return $this->db->table('partnership_level_definitions')
            ->where('organization_id', $organizationId)->where('status', 'active')
            ->orderBy('sort_order', 'DESC')->orderBy('min_pgv_minor', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * ALL tiers for the admin catalog — includes INACTIVE (disabled) tiers so an
     * admin can see and edit them. Ordered like the member catalog (highest
     * requirement first) for a consistent reading order.
     *
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(string $organizationId): array
    {
        return $this->db->table('partnership_level_definitions')
            ->where('organization_id', $organizationId)
            ->orderBy('sort_order', 'DESC')->orderBy('min_pgv_minor', 'DESC')
            ->get()->getResultArray();
    }

    // --- Per-subject status --------------------------------------------------

    /**
     * Recompute the subject's consecutive-giving-month streak + achieved tier,
     * upsert user_partnership_status, and emit partnership.level_changed on a
     * change.
     */
    public function recomputeStatus(string $organizationId, string $subjectId): Result
    {
        $streak       = $this->consecutiveMonths($organizationId, $subjectId);
        $pgv          = (int) $this->metrics->forSubject($organizationId, $subjectId)['pgv_minor'];
        $lastGivingAt = $this->lastGivingAt($organizationId, $subjectId);

        $achieved = null;
        foreach ($this->tiers($organizationId) as $tier) {
            if ($streak >= (int) $tier['min_consecutive_months'] && $pgv >= (int) $tier['min_pgv_minor']) {
                $achieved = $tier['code'];
                break; // highest-first: first satisfied tier wins
            }
        }

        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('user_partnership_status')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)->get()->getRowArray();

        $payload = [
            'current_level_code'       => $achieved,
            'consecutive_months_given' => $streak,
            'last_giving_at'           => $lastGivingAt,
            'computed_at'              => $now,
        ];

        if ($existing !== null) {
            $previous = $existing['current_level_code'];
            $this->db->table('user_partnership_status')->where('id', $existing['id'])->update($payload);
            if ($achieved !== null && $achieved !== $previous) {
                $this->outbox->stage('partnership_status', $subjectId, 'partnership.level_changed', [
                    'subject_id' => $subjectId,
                    'old_level'  => $previous,
                    'new_level'  => $achieved,
                ], $organizationId);
            }
        } else {
            $this->db->table('user_partnership_status')->insert($payload + [
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'subject_id'      => $subjectId,
            ]);
            if ($achieved !== null) {
                $this->outbox->stage('partnership_status', $subjectId, 'partnership.level_changed', [
                    'subject_id' => $subjectId,
                    'old_level'  => null,
                    'new_level'  => $achieved,
                ], $organizationId);
            }
        }

        return Result::ok(['subject_id' => $subjectId, 'level' => $achieved, 'streak' => $streak]);
    }

    /** Read a subject's status merged with cached metrics. @return array<string,mixed> */
    public function status(string $organizationId, string $subjectId): array
    {
        $row     = $this->db->table('user_partnership_status')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)->get()->getRowArray();
        $metrics = $this->metrics->forSubject($organizationId, $subjectId);

        return [
            'subject_id'               => $subjectId,
            'pgv_minor'                => $metrics['pgv_minor'],
            'ggv_minor'                => $metrics['ggv_minor'],
            'direct_recruits'          => $metrics['direct_recruits'],
            'downline_size'            => $metrics['downline_size'],
            'current_level_code'       => $row['current_level_code'] ?? null,
            'consecutive_months_given' => (int) ($row['consecutive_months_given'] ?? 0),
            'last_giving_at'           => $row['last_giving_at'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Count consecutive calendar months (ending at the current month) that have
     * at least one succeeded contribution. Distinct months are pulled from SQL;
     * the run length is walked back in PHP against a UTC "this month" cursor.
     */
    private function consecutiveMonths(string $organizationId, string $subjectId): int
    {
        $rows = $this->db->table('contributions')
            ->distinct()
            ->select("DATE_FORMAT(verified_at, '%Y-%m') AS ym", false)
            ->where('organization_id', $organizationId)
            ->where('user_id', $subjectId)
            ->where('state', 'succeeded')
            ->where('verified_at IS NOT NULL')
            ->get()->getResultArray();

        $monthSet = [];
        foreach ($rows as $r) {
            if (! empty($r['ym'])) {
                $monthSet[$r['ym']] = true;
            }
        }
        if ($monthSet === []) {
            return 0;
        }

        $streak = 0;
        $cursor = new DateTimeImmutable($this->clock->nowUtcString());
        $cursor = $cursor->modify('first day of this month');
        while (isset($monthSet[$cursor->format('Y-m')])) {
            $streak++;
            $cursor = $cursor->modify('-1 month');
        }

        return $streak;
    }

    private function lastGivingAt(string $organizationId, string $subjectId): ?string
    {
        $row = $this->db->table('contributions')
            ->select('verified_at')
            ->where('organization_id', $organizationId)
            ->where('user_id', $subjectId)
            ->where('state', 'succeeded')
            ->where('verified_at IS NOT NULL')
            ->orderBy('verified_at', 'DESC')
            ->get()->getRowArray();

        return $row['verified_at'] ?? null;
    }
}
