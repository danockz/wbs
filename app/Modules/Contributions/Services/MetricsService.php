<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Referrals\Services\SponsorshipService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Derives and caches PGV / GGV (Value-Based Contribution System headline
 * metrics) into the giving_metrics snapshot.
 *
 *  - PGV (Personal Giving Value) = lifetime SUM of the subject's own verified
 *    contributions (state='succeeded').
 *  - GGV (Group Giving Value)    = SUM of verified contributions across the
 *    subject's downline (from the sponsorship graph, bounded depth), multiplied
 *    by 1.30x once the subject has >= 10 direct recruits.
 *
 * Adaptation notes vs. GivingsLibrary:
 *  - Values are DERIVED and cached here (never mutated onto users.pgv/ggv).
 *  - Money is BIGINT minor units; the multiplier is stored in basis points
 *    (10000 = 1.00x) so no float is persisted.
 *  - Downline comes from Referrals\SponsorshipService (single source of truth),
 *    not a duplicated integer sponsor_id column or raw recursive SQL.
 *  - refresh runs async off contribution.succeeded/refunded; a new giving
 *    changes every ancestor's GGV, so cascadeToUpline() refreshes the chain.
 */
final class MetricsService
{
    /** Direct recruits needed before the GGV multiplier applies. */
    private const MULTIPLIER_THRESHOLD = 10;

    /** GGV multiplier in basis points once the threshold is met (1.30x). */
    private const MULTIPLIER_BPS = 13000;

    private const BASE_BPS = 10000;

    /** Safety cap on sponsor-chain depth for both downline and upline walks. */
    private const MAX_LEVELS = 7;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SponsorshipService $sponsorship,
    ) {
    }

    /**
     * Recompute and cache one subject's PGV/GGV. Idempotent (upsert).
     */
    public function refreshForSubject(string $organizationId, string $subjectId): Result
    {
        if ($organizationId === '' || $subjectId === '') {
            return Result::fail('BAD_SUBJECT', 'metrics.bad_subject', 422);
        }

        $pgv = $this->verifiedTotalMinor($organizationId, [$subjectId]);

        $downline    = $this->sponsorship->downline($subjectId, self::MAX_LEVELS);
        $ggvRaw      = $downline === [] ? 0 : $this->verifiedTotalMinor($organizationId, $downline);
        $recruits    = count($this->sponsorship->directRecruits($subjectId));
        $multiplierBps = $recruits >= self::MULTIPLIER_THRESHOLD ? self::MULTIPLIER_BPS : self::BASE_BPS;
        // GGV = raw * multiplier, kept in integer minor units.
        $ggv = intdiv($ggvRaw * $multiplierBps, self::BASE_BPS);

        $now = $this->clock->nowUtcMicro();
        $existing = $this->db->table('giving_metrics')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->get()->getRowArray();

        $payload = [
            'pgv_minor'       => $pgv,
            'ggv_minor'       => $ggv,
            'ggv_raw_minor'   => $ggvRaw,
            'direct_recruits' => $recruits,
            'downline_size'   => count($downline),
            'multiplier_bps'  => $multiplierBps,
            'computed_at'     => $now,
        ];

        if ($existing !== null) {
            $this->db->table('giving_metrics')->where('id', $existing['id'])->update($payload);
        } else {
            $this->db->table('giving_metrics')->insert($payload + [
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'subject_id'      => $subjectId,
            ]);
        }

        return Result::ok([
            'subject_id' => $subjectId,
            'pgv_minor'  => $pgv,
            'ggv_minor'  => $ggv,
            'multiplier' => $multiplierBps / self::BASE_BPS,
        ]);
    }

    /**
     * Refresh the subject AND every ancestor, because a new giving changes each
     * ancestor's downline total. Bounded to MAX_LEVELS with cycle guard (upline
     * itself is cycle-safe).
     */
    public function cascadeToUpline(string $organizationId, string $subjectId): Result
    {
        $this->refreshForSubject($organizationId, $subjectId);
        $refreshed = 1;
        foreach ($this->sponsorship->upline($subjectId, self::MAX_LEVELS) as $ancestorId) {
            $this->refreshForSubject($organizationId, $ancestorId);
            $refreshed++;
        }

        return Result::ok(['refreshed' => $refreshed]);
    }

    /** Read a subject's cached metrics (zeros if never computed). @return array<string,mixed> */
    public function forSubject(string $organizationId, string $subjectId): array
    {
        $row = $this->db->table('giving_metrics')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->get()->getRowArray();

        if ($row === null) {
            return [
                'subject_id' => $subjectId, 'pgv_minor' => 0, 'ggv_minor' => 0,
                'ggv_raw_minor' => 0, 'direct_recruits' => 0, 'downline_size' => 0,
                'multiplier_bps' => self::BASE_BPS, 'computed_at' => null,
            ];
        }

        return [
            'subject_id'      => $subjectId,
            'pgv_minor'       => (int) $row['pgv_minor'],
            'ggv_minor'       => (int) $row['ggv_minor'],
            'ggv_raw_minor'   => (int) $row['ggv_raw_minor'],
            'direct_recruits' => (int) $row['direct_recruits'],
            'downline_size'   => (int) $row['downline_size'],
            'multiplier_bps'  => (int) $row['multiplier_bps'],
            'computed_at'     => $row['computed_at'],
        ];
    }

    /**
     * SUM(amount_minor) of succeeded contributions for the given subjects.
     * Aggregation is pushed into SQL (the DB is far better at this at scale).
     */
    private function verifiedTotalMinor(string $organizationId, array $subjectIds): int
    {
        if ($subjectIds === []) {
            return 0;
        }
        $row = $this->db->table('contributions')
            ->selectSum('amount_minor', 'total')
            ->where('organization_id', $organizationId)
            ->whereIn('user_id', $subjectIds)
            ->where('state', 'succeeded')
            ->get()->getRowArray();

        return (int) ($row['total'] ?? 0);
    }
}
