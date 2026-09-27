<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M6 — track verify-reminder nudges so the expiry sweep can nudge, then expire,
 * a `pending_verification` account without spamming it.
 *
 * `pending_verification` is a real starting state (self-registration) but nothing
 * nudged or expired it. The sweep needs a small watermark:
 *   - `verify_reminded_at`   — when the last verify reminder was sent (so a
 *     reminder round fires at most once per configured cadence);
 *   - `verify_reminder_count`— how many reminders have gone out (for the cadence
 *     ladder and observability).
 * Indexed by (status, created_at) so the sweep's scan for stale pending accounts
 * is bounded. Idempotent.
 */
final class AddVerifyReminderWatermark extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('verify_reminded_at', 'users')) {
            $this->db->query(
                'ALTER TABLE users ADD COLUMN verify_reminded_at DATETIME NULL AFTER status_changed_by'
            );
        }
        if (! $this->db->fieldExists('verify_reminder_count', 'users')) {
            $this->db->query(
                'ALTER TABLE users ADD COLUMN verify_reminder_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER verify_reminded_at'
            );
        }
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('users'),
        );
        if (! in_array('users_status_created_idx', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE users ADD KEY users_status_created_idx (status, created_at)'
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
            $this->db->getIndexData('users'),
        );
        if (in_array('users_status_created_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE users DROP KEY users_status_created_idx');
        }
        if ($this->db->fieldExists('verify_reminder_count', 'users')) {
            $this->db->query('ALTER TABLE users DROP COLUMN verify_reminder_count');
        }
        if ($this->db->fieldExists('verify_reminded_at', 'users')) {
            $this->db->query('ALTER TABLE users DROP COLUMN verify_reminded_at');
        }
    }
}
