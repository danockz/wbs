<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Cause lifecycle (SRS FR-VBCS-001).
 *
 * A cause is group-owned; a child cause cannot silently route funds to a parent
 * (routing is explicit and policy-gated elsewhere). Targets are integer minor
 * units to stay consistent with the ledger.
 */
final class CauseService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, array $data): Result
    {
        if (empty($data['name'])) {
            return Result::fail('NAME_REQUIRED', 'cause.name_required', 422);
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'cause.bad_currency', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('causes')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $data['group_id'] ?? null,
            'name'            => $data['name'],
            'purpose'         => $data['purpose'] ?? null,
            'visibility'      => $data['visibility'] ?? 'group',
            'currency'        => $currency,
            'target_minor'    => isset($data['target_minor']) ? (int) $data['target_minor'] : null,
            'target_count'    => isset($data['target_count']) ? (int) $data['target_count'] : null,
            'show_target'     => array_key_exists('show_target', $data) ? (! empty($data['show_target']) ? 1 : 0) : 1,
            'starts_at'       => $data['starts_at'] ?? null,
            'ends_at'         => $data['ends_at'] ?? null,
            'status'          => 'draft',
            'created_at'      => $now,
        ]);

        return Result::created(['cause_id' => $id, 'status' => 'draft']);
    }

    public function activate(string $causeId): Result
    {
        $this->db->table('causes')->where('id', $causeId)->update([
            'status'     => 'active',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['cause_id' => $causeId, 'status' => 'active']);
    }

    /**
     * Paginated, filterable cause listing for an organization.
     *
     * @param array<string,mixed> $filters status|visibility|group_id|q|limit|offset
     */
    public function list(string $organizationId, array $filters = []): Result
    {
        $limit  = max(1, min((int) ($filters['limit'] ?? 20), 200));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $b = $this->db->table('causes')->where('organization_id', $organizationId);
        if (! empty($filters['status'])) {
            $b->where('status', (string) $filters['status']);
        }
        if (! empty($filters['visibility'])) {
            $b->where('visibility', (string) $filters['visibility']);
        }
        if (! empty($filters['group_id'])) {
            $b->where('group_id', (string) $filters['group_id']);
        }
        if (! empty($filters['q'])) {
            $b->like('name', (string) $filters['q']);
        }

        $total = (int) (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('created_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return Result::ok([
            'causes' => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /** Read a single cause (org-scoped). */
    public function show(string $organizationId, string $causeId): Result
    {
        $cause = $this->find($causeId);
        if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
            return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
        }

        return Result::ok($cause);
    }

    /**
     * Update a cause's editable fields (org-scoped). Money target stays integer
     * minor units; currency is normalized/validated. Status is NOT changed here
     * (use setStatus); created ledger integrity is untouched.
     *
     * @param array<string,mixed> $data
     */
    public function update(string $organizationId, string $causeId, array $data): Result
    {
        $cause = $this->find($causeId);
        if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
            return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
        }
        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            return Result::fail('NAME_REQUIRED', 'cause.name_required', 422);
        }

        $fields = ['updated_at' => $this->clock->nowUtcString()];
        foreach (['name', 'purpose', 'visibility', 'starts_at', 'ends_at'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $data[$k] !== '' ? $data[$k] : null;
            }
        }
        if (array_key_exists('currency', $data)) {
            $currency = strtoupper((string) $data['currency']);
            if (strlen($currency) !== 3) {
                return Result::fail('BAD_CURRENCY', 'cause.bad_currency', 422);
            }
            $fields['currency'] = $currency;
        }
        foreach (['target_minor', 'target_count'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = ($data[$k] === '' || $data[$k] === null) ? null : (int) $data[$k];
            }
        }
        if (array_key_exists('show_target', $data)) {
            $fields['show_target'] = ! empty($data['show_target']) ? 1 : 0;
        }

        $this->db->table('causes')->where('id', $causeId)->update($fields);

        return Result::ok(['cause_id' => $causeId]);
    }

    /**
     * Transition a cause's lifecycle status (draft|active|closed). Org-scoped.
     */
    public function setStatus(string $organizationId, string $causeId, string $status): Result
    {
        $allowed = ['draft', 'active', 'closed'];
        if (! in_array($status, $allowed, true)) {
            return Result::fail('BAD_STATUS', 'cause.bad_status', 422, ['allowed' => $allowed]);
        }
        $cause = $this->find($causeId);
        if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
            return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
        }

        $this->db->table('causes')->where('id', $causeId)->update([
            'status'     => $status,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['cause_id' => $causeId, 'status' => $status]);
    }

    /**
     * Delete a cause (org-scoped). SAFE: a cause that has ANY contribution or
     * intent is never hard-deleted (ledger integrity) — it is closed instead and
     * the caller is told. Only an untouched cause is actually removed.
     */
    public function delete(string $organizationId, string $causeId): Result
    {
        $cause = $this->find($causeId);
        if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
            return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
        }

        $hasContrib = (int) $this->db->table('contributions')->where('cause_id', $causeId)->countAllResults() > 0
            || (int) $this->db->table('contribution_intents')->where('cause_id', $causeId)->countAllResults() > 0;

        if ($hasContrib) {
            $this->db->table('causes')->where('id', $causeId)->update([
                'status'     => 'closed',
                'updated_at' => $this->clock->nowUtcString(),
            ]);

            return Result::ok(['cause_id' => $causeId, 'deleted' => false, 'status' => 'closed']);
        }

        $this->db->table('causes')->where('id', $causeId)->delete();

        return Result::ok(['cause_id' => $causeId, 'deleted' => true]);
    }

    /** Raised total (succeeded contributions) in minor units. */
    public function raisedMinor(string $causeId): int
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(amount_minor),0) AS total FROM contributions WHERE cause_id = ? AND state = "succeeded"',
            [$causeId],
        )->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function find(string $causeId): ?array
    {
        return $this->db->table('causes')->where('id', $causeId)->get()->getRowArray() ?: null;
    }

    // --- C6: group-teardown attribution repair -------------------------------

    /**
     * Terminal / hidden group states. A cause owned by one of these can no longer
     * attribute contributions to a live branch, so it must be re-homed. Mirrors
     * GroupScopeResolver::withoutDeadGroups so "dead" means the same everywhere.
     */
    private const DEAD_STATUSES = ['dissolved', 'merged', 'archived'];

    /**
     * C6 — repair contribution ATTRIBUTION when a receiving group dies.
     *
     * A cause is group-owned (`causes.group_id`), and every succeeded
     * contribution attributes to that group AND accumulates to its ancestors. If
     * the owning group is dissolved or merged away, those causes would keep
     * pointing at a dead node — funds attributed to a group that no longer
     * resolves. This re-homes the dead group's causes so attribution follows the
     * organisation's stated fallback rule:
     *
     *   - MERGE   (survivor given & live): re-point the loser's causes to the
     *     SURVIVOR group. The survivor inherits the giving history of the group it
     *     absorbed — the receiving context genuinely continues under it.
     *
     *   - DISSOLVE (no survivor): roll the causes UP to the nearest SURVIVING
     *     ancestor (walking `groups.parent_id`, skipping any dead/archived link).
     *     If no live ancestor exists, `group_id` becomes NULL — the cause survives
     *     at ORG level rather than attributing to a phantom group. This matches
     *     the membership-fallback → ancestor-rollup rule for attribution; the
     *     contributions themselves (amounts, donors, ledger) are never rewritten,
     *     only the group the cause hangs on.
     *
     * The contribution rows are deliberately untouched: they reference the cause,
     * and the cause now names a live group, so all rollups recompute correctly
     * from the repaired ownership without mutating financial records.
     *
     * SYSTEM authority, fault-isolated caller (JobRouter). Idempotent: once the
     * causes name a live group they no longer match the dead group, so a
     * redelivered event re-points 0.
     *
     * @return Result data: reattributed (int), target_group_id (?string),
     *                mode ('merge'|'dissolve')
     */
    public function reattributeGroupCauses(
        string $organizationId,
        string $groupId,
        string $reasonCode,
        ?string $survivorId = null,
    ): Result {
        if ($organizationId === '' || $groupId === '') {
            return Result::fail('REATTR_BAD_INPUT', 'cause.reattr_bad_input', 422);
        }

        // Resolve the target the causes should hang on after the teardown.
        $survivor = $survivorId !== null ? trim($survivorId) : '';
        $isMerge  = $survivor !== '' && $survivor !== $groupId && $this->isLiveGroup($organizationId, $survivor);

        $target = $isMerge
            ? $survivor
            : $this->nearestLiveAncestor($organizationId, $groupId); // null => org level

        // Re-point only THIS org's causes owned by the dead group. A cause already
        // moved (or never owned by the dead group) is skipped, keeping this
        // idempotent.
        $b = $this->db->table('causes')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId);
        $count = (int) $b->countAllResults(false);

        if ($count > 0) {
            $b->update([
                'group_id'   => $target, // may be null => org-level cause
                'updated_at' => $this->clock->nowUtcString(),
            ]);
        }

        return Result::ok([
            'reattributed'    => $count,
            'target_group_id' => $target,
            'mode'            => $isMerge ? 'merge' : 'dissolve',
            'reason_code'     => $reasonCode,
        ]);
    }

    /** True when the group exists in the org and is NOT in a dead/hidden state. */
    private function isLiveGroup(string $organizationId, string $groupId): bool
    {
        $row = $this->db->table('groups')
            ->select('status')
            ->where('id', $groupId)
            ->where('organization_id', $organizationId)
            ->get()->getRowArray();

        return $row !== null && ! in_array((string) ($row['status'] ?? ''), self::DEAD_STATUSES, true);
    }

    /**
     * Walk `groups.parent_id` upward from the dead group and return the id of the
     * nearest ancestor that is still live, or NULL if the chain reaches the root
     * (or a broken/dead link) without finding one. A visited-set guards against a
     * cyclic parent chain so this always terminates.
     */
    private function nearestLiveAncestor(string $organizationId, string $groupId): ?string
    {
        $seen    = [$groupId => true];
        $current = $this->db->table('groups')
            ->select('parent_id')
            ->where('id', $groupId)
            ->where('organization_id', $organizationId)
            ->get()->getRowArray();

        $parentId = $current !== null ? (string) ($current['parent_id'] ?? '') : '';

        while ($parentId !== '' && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $row = $this->db->table('groups')
                ->select('status, parent_id')
                ->where('id', $parentId)
                ->where('organization_id', $organizationId)
                ->get()->getRowArray();

            if ($row === null) {
                return null; // broken link => attribute at org level
            }
            if (! in_array((string) ($row['status'] ?? ''), self::DEAD_STATUSES, true)) {
                return $parentId; // nearest live ancestor
            }
            $parentId = (string) ($row['parent_id'] ?? '');
        }

        return null;
    }

    // --- VBCS reporting reads (adaptation of GivingsLibrary) ------------------

    /**
     * Cause progress: raised vs. target (amount + count), with a 0-100 percent.
     */
    public function progress(string $organizationId, string $causeId, bool $revealTarget = true): Result
    {
        $cause = $this->find($causeId);
        if ($cause === null || (string) $cause['organization_id'] !== $organizationId) {
            return Result::notFound('cause.not_found', 'CAUSE_NOT_FOUND');
        }

        $raised = $this->raisedMinor($causeId);
        $count  = (int) ($this->db->table('contributions')
            ->where('cause_id', $causeId)->where('state', 'succeeded')
            ->countAllResults());
        $show   = $revealTarget || self::targetsArePublic($cause);
        $target = $show && $cause['target_minor'] !== null ? (int) $cause['target_minor'] : null;
        $pct    = ($target !== null && $target > 0) ? min(100, (int) floor($raised * 100 / $target)) : null;

        return Result::ok([
            'cause_id'       => $causeId,
            'currency'       => $cause['currency'],
            'raised_minor'   => $raised,
            'target_minor'   => $target,
            'percent'        => $pct,
            'donor_count'    => $count,
            'target_count'   => $show && $cause['target_count'] !== null ? (int) $cause['target_count'] : null,
            'show_target'    => self::targetsArePublic($cause) ? 1 : 0,
            'status'         => $cause['status'],
        ]);
    }

    /** Goal numbers are visible on anonymous/public surfaces. */
    public static function targetsArePublic(array $cause): bool
    {
        return (int) ($cause['show_target'] ?? 1) === 1;
    }

    /**
     * Strip amount/count targets (and any percent) for a public payload.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function hideGoalsForPublic(array $row): array
    {
        if (self::targetsArePublic($row)) {
            return $row;
        }
        $row['target_minor'] = null;
        $row['target_count'] = null;
        $row['percent']      = null;
        $row['show_target']  = 0;

        return $row;
    }

    /**
     * Public donor list for a cause. Respects recognition: 'anonymous' and
     * 'none' contributions are aggregated as "Anonymous" and never expose a
     * subject id or name; email is never returned.
     *
     * @return list<array<string,mixed>>
     */
    public function donors(string $organizationId, string $causeId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->table('contributions c')
            ->select('c.user_id, c.amount_minor, c.currency, c.recognition, c.verified_at, u.display_name', false)
            ->join('users u', 'u.id = c.user_id', 'left')
            ->where('c.organization_id', $organizationId)
            ->where('c.cause_id', $causeId)
            ->where('c.state', 'succeeded')
            ->orderBy('c.verified_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $anon = in_array($r['recognition'], ['anonymous', 'none'], true) || empty($r['user_id']);
            $out[] = [
                'subject_id'   => $anon ? null : $r['user_id'],
                'display_name' => $anon ? 'Anonymous' : ($r['display_name'] ?: 'Member'),
                'amount_minor' => (int) $r['amount_minor'],
                'currency'     => $r['currency'],
                'given_at'     => $r['verified_at'],
            ];
        }

        return $out;
    }

    /**
     * Leader-facing group giving report: totals by state, top causes, and a
     * monthly trend for the trailing $days window. All money in minor units.
     */
    public function groupGivingReport(string $organizationId, string $groupId, int $days = 30): Result
    {
        if ($groupId === '') {
            return Result::fail('BAD_GROUP', 'cause.group_required', 422);
        }
        $days  = max(1, min(366, $days));
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days", strtotime($this->clock->nowUtcString())));

        // Contributions are joined to their cause to scope by owning group.
        $totals = $this->db->table('contributions c')
            ->select('COUNT(*) AS cnt', false)
            ->select('COALESCE(SUM(CASE WHEN c.state = "succeeded" THEN c.amount_minor ELSE 0 END),0) AS verified_minor', false)
            ->select('COALESCE(SUM(CASE WHEN c.state = "pending" THEN c.amount_minor ELSE 0 END),0) AS pending_minor', false)
            ->select('COALESCE(SUM(CASE WHEN c.source = "manual" AND c.state = "succeeded" THEN c.amount_minor ELSE 0 END),0) AS manual_minor', false)
            ->join('causes cz', 'cz.id = c.cause_id')
            ->where('c.organization_id', $organizationId)
            ->where('cz.group_id', $groupId)
            ->where('c.created_at >=', $since)
            ->get()->getRowArray();

        $byCause = $this->db->table('contributions c')
            ->select('cz.id AS cause_id, cz.name AS cause_name, COALESCE(SUM(c.amount_minor),0) AS total_minor, COUNT(*) AS cnt', false)
            ->join('causes cz', 'cz.id = c.cause_id')
            ->where('c.organization_id', $organizationId)
            ->where('cz.group_id', $groupId)
            ->where('c.state', 'succeeded')
            ->where('c.created_at >=', $since)
            ->groupBy('cz.id, cz.name')
            ->orderBy('total_minor', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        $monthly = $this->db->table('contributions c')
            ->select('DATE_FORMAT(c.verified_at, "%Y-%m") AS month, COALESCE(SUM(c.amount_minor),0) AS total_minor', false)
            ->join('causes cz', 'cz.id = c.cause_id')
            ->where('c.organization_id', $organizationId)
            ->where('cz.group_id', $groupId)
            ->where('c.state', 'succeeded')
            ->where('c.verified_at >=', $since)
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->get()->getResultArray();

        return Result::ok([
            'group_id' => $groupId,
            'days'     => $days,
            'totals'   => [
                'count'          => (int) ($totals['cnt'] ?? 0),
                'verified_minor' => (int) ($totals['verified_minor'] ?? 0),
                'pending_minor'  => (int) ($totals['pending_minor'] ?? 0),
                'manual_minor'   => (int) ($totals['manual_minor'] ?? 0),
            ],
            'by_cause' => array_map(static fn ($r): array => [
                'cause_id'    => $r['cause_id'],
                'cause_name'  => $r['cause_name'],
                'total_minor' => (int) $r['total_minor'],
                'count'       => (int) $r['cnt'],
            ], $byCause),
            'monthly' => array_map(static fn ($r): array => [
                'month'       => $r['month'],
                'total_minor' => (int) $r['total_minor'],
            ], $monthly),
        ]);
    }
}
