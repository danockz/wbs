<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Event completion audit + close-automation sweep index (gap L3).
 *
 * `complete()` (manual button or the `events:close-due` sweep) now stamps
 * `completed_at` so the moment an event was closed is auditable and available to
 * the reports/certificate flows. Nullable and additive — existing rows are
 * untouched.
 *
 * `ev_close_idx (status, ends_at, starts_at)` supports the close sweep's bounded,
 * indexed range read over PUBLISHED events whose scheduled finish is far enough
 * in the past — mirroring the reminder watermark index (gap G3), so the sweep
 * never table-scans.
 */
final class AddEventCompletionAudit extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE events
                ADD COLUMN completed_at DATETIME NULL AFTER last_reminded_at,
                ADD KEY ev_close_idx (status, ends_at, starts_at)
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE events
                DROP KEY ev_close_idx,
                DROP COLUMN completed_at
        ');
    }
}
