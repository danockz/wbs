<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Annual, organization-wide gamification season lifecycle (SRS FR-GAM-006/007).
 *
 *  - Exactly ONE active season per org (active_key UNIQUE guard).
 *  - Rollover happens at one universal configurable date/time in the ORG time
 *    zone (default 1 Jan 00:00), never per-member local time.
 *  - Rollover is IDEMPOTENT and lock-protected by a UNIQUE transition key, so a
 *    retried/duplicate scheduled run cannot double-close a season.
 *  - Closing snapshots balances/ranks and marks the closed ledger archived
 *    (read-only). Archive is never deletion. New season starts balances at zero.
 */
final class SeasonService
{
    /**
     * How long a `running` transition row may go without a heartbeat before it is
     * considered abandoned (a crashed worker) and eligible for reclaim. 30 min is
     * comfortably longer than any real rollover yet short enough that a wedged
     * annual operation self-heals on the next sweep pass rather than waiting a
     * year.
     */
    private const RECLAIM_LEASE_SECONDS = 1800;

    /**
     * G4 — how OPEN HELD entries in a closing season are handled at rollover.
     * Config key `held_rollover_policy` (per-org), default `carry_forward`:
     *   - carry_forward       : re-point held entries (and their still-open fraud
     *                           reviews) to the NEW season, so a later approve/
     *                           clear rolls up into the active season instead of
     *                           mutating the frozen, snapshotted closed season;
     *   - reject_on_close     : reverse held entries and reject their reviews on
     *                           close (points never become spendable);
     *   - resolve_before_close: refuse to roll over while open held entries exist,
     *                           forcing a human to clear the queue first.
     */
    private const HELD_POLICY_DEFAULT = 'carry_forward';
    private const HELD_POLICIES       = ['carry_forward', 'reject_on_close', 'resolve_before_close'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?ConfigService $config = null,
    ) {
    }

    /** Ensure an active season exists for the org, creating the first if needed. */
    public function ensureCurrentSeason(string $organizationId, string $orgTimezone = 'UTC'): array
    {
        $active = $this->activeSeason($organizationId);
        if ($active !== null) {
            return $active;
        }

        $tz   = new DateTimeZone($orgTimezone);
        $now  = $this->clock->now()->setTimezone($tz);
        $year = (int) $now->format('Y');

        return $this->openSeason($organizationId, $year, $tz);
    }

    /** @return array<string,mixed>|null */
    public function activeSeason(string $organizationId): ?array
    {
        return $this->db->table('gamification_seasons')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()->getRowArray() ?: null;
    }

    /**
     * Roll over to the next season. Idempotent: a second call for the same
     * transition returns the already-completed result.
     *
     * RECLAIM (G1): if a prior attempt left the transition row `failed`, or a
     * crashed worker left it `running` past the stale lease
     * ({@see self::RECLAIM_LEASE_SECONDS}), this re-claims that row and retries —
     * the rollover transaction is atomic, so nothing partial was committed and a
     * retry is safe. A `running` row still within its lease is a genuine
     * concurrent run and yields `ROLLOVER_IN_PROGRESS`. Pass `$force` to reclaim a
     * `running` row regardless of lease age (operator override).
     */
    public function rollover(string $organizationId, string $orgTimezone = 'UTC', bool $force = false): Result
    {
        $current = $this->activeSeason($organizationId);
        if ($current === null) {
            return Result::fail('NO_ACTIVE_SEASON', 'gamification.no_active_season', 409);
        }

        $fromYear      = (int) $current['season_year'];
        $toYear        = $fromYear + 1;
        $transitionKey = $organizationId . ':' . $fromYear . ':' . $toYear;
        $now           = $this->clock->nowUtcString();

        // Lock-protected idempotency: claim the transition or detect a prior run.
        try {
            $this->db->table('season_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'transition_key'  => $transitionKey,
                'from_season_id'  => $current['id'],
                'status'          => 'running',
                'attempts'        => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } catch (Throwable) {
            $existing = $this->db->table('season_transitions')->where('transition_key', $transitionKey)->get()->getRowArray();
            if ($existing === null) {
                // Row vanished between the failed insert and the read — treat as
                // contended; a later attempt will re-claim cleanly.
                return Result::fail('ROLLOVER_IN_PROGRESS', 'gamification.rollover_in_progress', 409);
            }
            if ($existing['status'] === 'completed') {
                return Result::ok(['transition' => $transitionKey, 'status' => 'already_completed'], 200, ['deduplicated' => true]);
            }

            // G1 reclaim: retry a failed row, or a running row past its lease.
            if (! $this->canReclaim($existing, $force)) {
                return Result::fail('ROLLOVER_IN_PROGRESS', 'gamification.rollover_in_progress', 409);
            }

            // Re-claim the SAME row: flip back to running, bump attempts, restamp
            // the lease. Guard on the observed status so two racing reclaimers
            // don't both win (only one update matches the prior status).
            $this->db->table('season_transitions')
                ->where('transition_key', $transitionKey)
                ->where('status', $existing['status'])
                ->update([
                    'status'         => 'running',
                    'attempts'       => (int) ($existing['attempts'] ?? 1) + 1,
                    'from_season_id' => $current['id'],
                    'to_season_id'   => null,
                    'updated_at'     => $now,
                ]);
            if ((int) $this->db->affectedRows() !== 1) {
                // Another worker reclaimed first.
                return Result::fail('ROLLOVER_IN_PROGRESS', 'gamification.rollover_in_progress', 409);
            }
        }

        return $this->runTransition($organizationId, $current, $toYear, $transitionKey, $orgTimezone);
    }

    /**
     * Whether a non-completed transition row may be re-claimed for retry:
     *   - `failed` is always retryable;
     *   - `running` is retryable only once its lease has expired (a crashed
     *     worker), or when the operator forces it.
     *
     * @param array<string,mixed> $existing
     */
    private function canReclaim(array $existing, bool $force): bool
    {
        $status = (string) ($existing['status'] ?? '');
        if ($status === 'failed') {
            return true;
        }
        if ($status !== 'running') {
            return false;
        }
        if ($force) {
            return true;
        }

        // Stale-lease check: reclaim a `running` row whose last heartbeat is
        // older than the lease. Fall back to created_at for legacy rows with no
        // updated_at yet.
        $stamp = (string) ($existing['updated_at'] ?? $existing['created_at'] ?? '');
        if ($stamp === '') {
            return true; // no heartbeat at all — treat as abandoned.
        }
        $age = $this->clock->now()->getTimestamp() - strtotime($stamp . ' UTC');

        return $age >= self::RECLAIM_LEASE_SECONDS;
    }

    /**
     * The actual close-and-open work, shared by a fresh claim and a reclaim. The
     * caller has already secured the `running` transition row.
     *
     * @param array<string,mixed> $current the closing (active) season row
     */
    private function runTransition(string $organizationId, array $current, int $toYear, string $transitionKey, string $orgTimezone): Result
    {
        $tz  = new DateTimeZone($orgTimezone);
        $now = $this->clock->nowUtcString();

        // G4: resolve open held entries in the closing season according to the
        // configured policy BEFORE snapshotting/closing, so a held award can never
        // (a) sit in an archived closed season whose ranks are frozen, nor (b) be
        // approved later and mutate that closed season's rollup. Done outside the
        // main transaction as a pre-gate for `resolve_before_close`; the mutating
        // policies run inside it below.
        $policy = $this->heldRolloverPolicy($organizationId);
        if ($policy === 'resolve_before_close' && $this->openHeldCount($organizationId, (string) $current['id']) > 0) {
            // Release the claim so a later attempt (after the queue is cleared)
            // can retry cleanly, and surface a distinct, actionable error.
            $this->db->table('season_transitions')->where('transition_key', $transitionKey)->update([
                'status'     => 'failed',
                'updated_at' => $this->clock->nowUtcString(),
            ]);

            return Result::fail('HELD_ENTRIES_OPEN', 'gamification.held_entries_open', 409);
        }

        $this->db->transStart();

        // 1. Snapshot final balances + ranks for the closing season (FINAL entries
        //    only, so held/carried-forward entries never affect frozen standings).
        $this->snapshotBalances($organizationId, $current['id']);

        // 2. Open next season at zero balances (needed before carry-forward so held
        //    entries can be re-pointed to it).
        $next = $this->openSeason($organizationId, $toYear, $tz);

        // G4: dispose of open held entries per policy while we still hold the txn.
        //    carry_forward re-points them (and open reviews) to the NEW season so a
        //    later approve/clear rolls up into the active season, never the frozen
        //    closed one; reject_on_close reverses them here.
        $heldMoved = $this->disposeHeldEntries($organizationId, (string) $current['id'], (string) $next['id'], $policy, $now);

        // 3. Mark the closing season's REMAINING ledger archived (read-only) and
        //    close the season. Carried-forward held rows now bear the next
        //    season_id, so they are correctly excluded from the archive.
        $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('season_id', $current['id'])
            ->update(['archived' => 1]);

        $this->db->table('gamification_seasons')->where('id', $current['id'])->update([
            'status'     => 'closed',
            'active_key' => null,
            'closed_at'  => $now,
            'ends_at'    => $now,
        ]);

        $this->db->table('season_transitions')->where('transition_key', $transitionKey)->update([
            'to_season_id' => $next['id'],
            'status'       => 'completed',
            'completed_at' => $now,
            'updated_at'   => $now,
        ]);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            $this->db->table('season_transitions')->where('transition_key', $transitionKey)->update([
                'status'     => 'failed',
                'updated_at' => $this->clock->nowUtcString(),
            ]);

            return Result::fail('ROLLOVER_FAILED', 'gamification.rollover_failed', 500);
        }

        return Result::ok([
            'from_season_id' => $current['id'],
            'to_season_id'   => $next['id'],
            'transition'     => $transitionKey,
            'status'         => 'completed',
            'held_policy'    => $policy,
            'held_disposed'  => $heldMoved,
        ]);
    }

    /**
     * G4 — the org's held-entry rollover policy (config `held_rollover_policy`),
     * falling back to the safe default and rejecting unknown values.
     */
    private function heldRolloverPolicy(string $organizationId): string
    {
        if ($this->config === null) {
            return self::HELD_POLICY_DEFAULT;
        }
        $value = (string) $this->config->get($organizationId, 'held_rollover_policy', self::HELD_POLICY_DEFAULT);

        return in_array($value, self::HELD_POLICIES, true) ? $value : self::HELD_POLICY_DEFAULT;
    }

    /** Count open held award entries in a season (G4 pre-close gate). */
    private function openHeldCount(string $organizationId, string $seasonId): int
    {
        return $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('season_id', $seasonId)
            ->where('state', 'held')
            ->countAllResults();
    }

    /**
     * G4 — dispose of the closing season's open held entries per policy. Returns
     * the number of held entries affected. Runs inside the rollover transaction.
     *
     *   carry_forward   : re-point each held entry to $nextSeasonId (and reset its
     *                     archived flag) so approving/clearing it later rolls up
     *                     into the ACTIVE season, not the frozen closed one; the
     *                     still-open fraud_reviews travel with them untouched.
     *   reject_on_close : flip each held entry to `reversed` and reject its still-
     *                     open review (points never become spendable). Frozen
     *                     standings are unaffected because held points were never
     *                     counted.
     *   resolve_before_close is handled as a pre-gate (never reaches here with
     *   open held entries).
     */
    private function disposeHeldEntries(string $organizationId, string $seasonId, string $nextSeasonId, string $policy, string $now): int
    {
        if ($policy === 'resolve_before_close') {
            return 0; // pre-gate guarantees none remain.
        }

        $held = $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('season_id', $seasonId)
            ->where('state', 'held')
            ->get()->getResultArray();
        if ($held === []) {
            return 0;
        }
        $ids = array_map(static fn ($r) => (string) $r['id'], $held);

        if ($policy === 'reject_on_close') {
            $this->db->table('point_ledger')
                ->whereIn('id', $ids)
                ->where('state', 'held')
                ->update(['state' => 'reversed']);
            $this->db->table('fraud_reviews')
                ->whereIn('ledger_id', $ids)
                ->where('status', 'open')
                ->update([
                    'status'      => 'rejected',
                    'reason'      => 'rejected:season_rollover',
                    'resolved_at' => $now,
                ]);

            return count($ids);
        }

        // carry_forward (default): move the held rows into the new season so their
        // eventual finalization rolls up into the active season. Re-assert
        // state='held' so a row resolved between the read and here is not moved.
        $this->db->table('point_ledger')
            ->whereIn('id', $ids)
            ->where('state', 'held')
            ->update([
                'season_id' => $nextSeasonId,
                'archived'  => 0,
            ]);

        return count($ids);
    }

    /**
     * Reclaim sweep (G1): scan for wedged transitions — `failed` rows and
     * `running` rows past the stale lease — and retry each. Bounded and
     * idempotent: a completed retry no longer matches, and a genuinely in-flight
     * (fresh-lease) `running` row is left alone. Returns the number reclaimed and
     * successfully completed.
     *
     * @param string|null $organizationId null = every org
     */
    public function reclaimStuckRollovers(?string $organizationId, int $limit = 100): int
    {
        $limit    = max(1, min(1000, $limit));
        $cutoff   = $this->clock->now()
            ->modify('-' . self::RECLAIM_LEASE_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        $q = $this->db->table('season_transitions')
            ->whereIn('status', ['failed', 'running'])
            ->orderBy('updated_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $rows = $q->get($limit)->getResultArray();

        $reclaimed = 0;
        foreach ($rows as $row) {
            // Only stuck rows: any failed, or a running row older than the lease.
            if ((string) $row['status'] === 'running') {
                $stamp = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
                if ($stamp !== '' && $stamp > $cutoff) {
                    continue; // fresh lease — a live worker owns it.
                }
            }

            $orgId = (string) $row['organization_id'];
            // The active season carries the org timezone boundary; rollover()
            // re-derives everything from the active season, so UTC is a safe
            // default here (the boundary was already computed at first open).
            $res = $this->rollover($orgId, 'UTC');
            if ($res->ok) {
                $reclaimed++;
            }
        }

        return $reclaimed;
    }

    /** @return array<string,mixed> */
    private function openSeason(string $organizationId, int $year, DateTimeZone $tz): array
    {
        // Season starts at org-timezone 1 Jan 00:00, stored as UTC.
        $startLocal = new DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year), $tz);
        $startUtc   = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $id = Uuid::v7();
        $this->db->table('gamification_seasons')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'season_year'     => $year,
            'status'          => 'active',
            'active_key'      => $organizationId, // one active per org
            'starts_at'       => $startUtc,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return $this->db->table('gamification_seasons')->where('id', $id)->get()->getRowArray();
    }

    private function snapshotBalances(string $organizationId, string $seasonId): void
    {
        $rows = $this->db->query(
            'SELECT subject_id, subject_type, SUM(points) AS total
             FROM point_ledger
             WHERE organization_id = ? AND season_id = ? AND state = "final"
             GROUP BY subject_id, subject_type
             ORDER BY total DESC',
            [$organizationId, $seasonId],
        )->getResultArray();

        $now  = $this->clock->nowUtcString();
        $rank = 0;
        foreach ($rows as $r) {
            $rank++;
            $this->db->table('season_balance_snapshots')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'season_id'       => $seasonId,
                'subject_id'      => $r['subject_id'],
                'subject_type'    => $r['subject_type'],
                'total_points'    => (int) $r['total'],
                'rank_position'   => $rank,
                'created_at'      => $now,
            ]);
        }
    }
}
