<?php

declare(strict_types=1);

namespace WBS\Notifications\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * N1 — persist WHEN a deferred delivery becomes sendable.
 *
 * `notification_deliveries.status` has always supported `deferred` (quiet-hours /
 * frequency-cap), and the gate computed a `defer_until`, but that timestamp was
 * NEVER stored — so nothing could ever know when a deferred message was due, and
 * deferred rows sat forever. This adds the missing `defer_until` column (+ an
 * index over (status, defer_until)) so the `notifications.release-deferred` sweep
 * can pick up due rows in a bounded, indexed scan. Idempotent.
 */
final class AddDeliveryDeferUntil extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('defer_until', 'notification_deliveries')) {
            $this->db->query(
                'ALTER TABLE notification_deliveries ADD COLUMN defer_until DATETIME(6) NULL AFTER status'
            );
        }
        // Indexed lookup for the release sweep: due deferred rows.
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('notification_deliveries'),
        );
        if (! in_array('nd_defer_idx', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE notification_deliveries ADD KEY nd_defer_idx (status, defer_until)'
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
            $this->db->getIndexData('notification_deliveries'),
        );
        if (in_array('nd_defer_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE notification_deliveries DROP KEY nd_defer_idx');
        }
        if ($this->db->fieldExists('defer_until', 'notification_deliveries')) {
            $this->db->query('ALTER TABLE notification_deliveries DROP COLUMN defer_until');
        }
    }
}
