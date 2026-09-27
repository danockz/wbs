<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * IN3 — retire + prune rotated credential versions.
 *
 * `CredentialVault::put()` versions each slot (v1, v2, …) and rotation kept ALL
 * historical versions decryptable forever — after a compromise-driven rotation
 * the leaked secret stayed live in the vault. There was also no "active version"
 * pointer, so a specific leaked version could not be pinned/rolled back/disabled.
 *
 * This adds a per-version lifecycle marker:
 *   - `status`     — active|retired. Exactly one active version per (conn,slot);
 *                    the vault decrypts the active one and retires the rest.
 *   - `retired_at` — when a version was superseded, so a background sweep can
 *                    hard-prune retired ciphertext once a grace window elapses.
 * Indexed by (status, retired_at) so the prune sweep's scan is bounded.
 *
 * Backfill: for every existing (connection_id, slot) keep the MAX version active
 * and mark every older version retired (retired_at = its created_at), so the new
 * one-active invariant holds immediately on already-rotated slots. Idempotent.
 */
final class AddCredentialVersionRetirement extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('status', 'connection_credentials')) {
            $this->db->query(
                'ALTER TABLE connection_credentials
                 ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT "active" AFTER version'
            );
        }
        if (! $this->db->fieldExists('retired_at', 'connection_credentials')) {
            $this->db->query(
                'ALTER TABLE connection_credentials
                 ADD COLUMN retired_at DATETIME NULL AFTER status'
            );
        }

        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('connection_credentials'),
        );
        if (! in_array('cc_status_retired_idx', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE connection_credentials
                 ADD KEY cc_status_retired_idx (status, retired_at)'
            );
        }

        // Backfill: keep only the newest version of each slot active; retire the
        // rest. Safe to re-run — it only touches rows that are still active but
        // are not the max version for their (connection_id, slot).
        $this->db->query(
            'UPDATE connection_credentials c
             JOIN (
                 SELECT connection_id, slot, MAX(version) AS mx
                 FROM connection_credentials
                 GROUP BY connection_id, slot
             ) m ON m.connection_id = c.connection_id AND m.slot = c.slot
             SET c.status = "retired",
                 c.retired_at = COALESCE(c.retired_at, c.created_at)
             WHERE c.version < m.mx AND c.status = "active"'
        );
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('connection_credentials'),
        );
        if (in_array('cc_status_retired_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE connection_credentials DROP KEY cc_status_retired_idx');
        }
        if ($this->db->fieldExists('retired_at', 'connection_credentials')) {
            $this->db->query('ALTER TABLE connection_credentials DROP COLUMN retired_at');
        }
        if ($this->db->fieldExists('status', 'connection_credentials')) {
            $this->db->query('ALTER TABLE connection_credentials DROP COLUMN status');
        }
    }
}
