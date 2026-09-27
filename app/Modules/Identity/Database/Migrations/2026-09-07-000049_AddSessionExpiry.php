<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Absolute session expiry (SRS FR-ID session lifetime).
 *
 * Server-side sessions previously had NO lifetime — `SessionService::active()`
 * checked only `revoked_at`, so a leaked session id was valid forever. This adds
 * an `expires_at` (absolute cap); combined with the idle check now enforced in
 * `active()` (via `last_seen_at`) a session dies at whichever comes first:
 * absolute TTL, idle timeout, or explicit revocation.
 *
 * Idempotent: the column is added only if missing, and existing rows are
 * backfilled to created_at + 14 days so no live session is invalidated abruptly.
 */
final class AddSessionExpiry extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('expires_at', 'sessions')) {
            $this->db->query('ALTER TABLE `sessions` ADD COLUMN `expires_at` DATETIME NULL AFTER `last_seen_at`');
            // Backfill existing rows to a sane absolute cap (created_at + 14d).
            $this->db->query('UPDATE `sessions` SET `expires_at` = DATE_ADD(`created_at`, INTERVAL 14 DAY) WHERE `expires_at` IS NULL');
        }

        // Index the reaper / active-lookup predicate (revoked_at, expires_at).
        if (! $this->indexExists('sessions', 'sessions_expiry_idx')) {
            $this->db->query('CREATE INDEX sessions_expiry_idx ON `sessions` (revoked_at, expires_at)');
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if ($this->indexExists('sessions', 'sessions_expiry_idx')) {
            $this->db->query('DROP INDEX sessions_expiry_idx ON `sessions`');
        }
        if ($this->db->fieldExists('expires_at', 'sessions')) {
            $this->db->query('ALTER TABLE `sessions` DROP COLUMN `expires_at`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach ($this->db->getIndexData($table) as $idx) {
            if ($idx->name === $index) {
                return true;
            }
        }

        return false;
    }
}
