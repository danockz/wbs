<?php

declare(strict_types=1);

namespace WBS\Journey\Sweep;

use CodeIgniter\Database\BaseConnection;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C): recompute the member-involvement snapshots that back
 * the downline triage board, which otherwise go stale (finding J1 — the snapshot
 * is only refreshed on write, so a contact that quietly lapses never re-bands).
 *
 * Wraps the already-idempotent InvolvementService::recomputeContext(), which
 * needs a concrete org; when the runner passes null (all orgs) this resolves the
 * org id set from the `organizations` table and recomputes each. Bounded per org
 * by the service's own limit.
 */
final class InvolvementRecomputeSweep implements SweepContract
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function key(): string
    {
        return 'journey.involvement-recompute';
    }

    public function description(): string
    {
        return 'Recompute member-involvement snapshots so the downline triage board does not go stale.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 5000;
        $orgIds = $organizationId !== null ? [$organizationId] : $this->allOrgIds();

        $refreshed = 0;
        $orgs = 0;
        foreach ($orgIds as $orgId) {
            $r = JourneyServices::involvement()->recomputeContext($orgId, null, $limit);
            if ($r->failed()) {
                return SweepResult::fail('involvement recompute failed for org ' . $orgId . ': ' . (string) $r->message);
            }
            $refreshed += (int) ($r->data['refreshed'] ?? 0);
            $orgs++;
        }

        return SweepResult::ok($refreshed, ['orgs' => $orgs, 'refreshed' => $refreshed]);
    }

    /** @return list<string> */
    private function allOrgIds(): array
    {
        $rows = $this->db->table('organizations')->select('id')->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['id'], $rows));
    }
}
