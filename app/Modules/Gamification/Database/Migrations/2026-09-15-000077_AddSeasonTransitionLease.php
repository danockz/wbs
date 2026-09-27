<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G1 — make a failed/crashed season rollover RECOVERABLE.
 *
 * `season_transitions` claims the annual transition by inserting a `running` row
 * and flips it to `completed`/`failed`. But nothing ever reclaimed a `failed`
 * row or a `running` row abandoned by a crashed worker, so the UNIQUE
 * `transition_key` wedged that year boundary permanently — every later attempt hit
 * `ROLLOVER_IN_PROGRESS`. Retry is SAFE (the rollover transaction is atomic;
 * nothing partial commits on failure), the guard just refused it.
 *
 * This adds the bookkeeping a reclaim needs:
 *   - `updated_at` — heartbeat stamped each time the row transitions, so a stale
 *     `running` (older than the lease) can be detected and self-healed;
 *   - `attempts`  — how many times the transition has been (re)claimed, for
 *     observability and to cap runaway retries if ever desired.
 * Idempotent.
 */
final class AddSeasonTransitionLease extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('updated_at', 'season_transitions')) {
            $this->db->query(
                'ALTER TABLE season_transitions ADD COLUMN updated_at DATETIME NULL AFTER completed_at'
            );
        }
        if (! $this->db->fieldExists('attempts', 'season_transitions')) {
            $this->db->query(
                'ALTER TABLE season_transitions ADD COLUMN attempts INT NOT NULL DEFAULT 1 AFTER status'
            );
        }
        // Indexed scan for the reclaim sweep: stuck rows by (status, updated_at).
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('season_transitions'),
        );
        if (! in_array('st_reclaim_idx', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE season_transitions ADD KEY st_reclaim_idx (status, updated_at)'
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('season_transitions'),
        );
        if (in_array('st_reclaim_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE season_transitions DROP KEY st_reclaim_idx');
        }
        if ($this->db->fieldExists('attempts', 'season_transitions')) {
            $this->db->query('ALTER TABLE season_transitions DROP COLUMN attempts');
        }
        if ($this->db->fieldExists('updated_at', 'season_transitions')) {
            $this->db->query('ALTER TABLE season_transitions DROP COLUMN updated_at');
        }
    }
}
