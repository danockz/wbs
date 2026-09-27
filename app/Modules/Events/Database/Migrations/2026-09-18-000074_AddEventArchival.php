<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Event soft-ARCHIVE (gap L4).
 *
 * A terminal event (cancelled / completed / completed_no_attendance) — or a
 * mistaken draft — can be archived so it drops out of the active index, calendar
 * feeds and analytics WITHOUT being deleted: attendance, orders, certificates
 * and the audit trail all stay intact (history integrity). Archival is a
 * soft-delete FLAG orthogonal to `status`, so the terminal status is preserved
 * (an archived event is still "cancelled" / "completed", just filed away) and
 * unarchive is a clean, reversible clear of the flag.
 *
 * Nullable + additive — existing rows are untouched (archived_at IS NULL = live).
 *
 * `ev_active_idx (organization_id, archived_at, starts_at)` lets the active-list
 * reads filter `archived_at IS NULL` on an index rather than table-scanning, and
 * mirrors the shape of the existing `ev_org_idx`.
 */
final class AddEventArchival extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE events
                ADD COLUMN archived_at DATETIME NULL AFTER completed_at,
                ADD COLUMN archived_by CHAR(36) NULL AFTER archived_at,
                ADD KEY ev_active_idx (organization_id, archived_at, starts_at)
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE events
                DROP KEY ev_active_idx,
                DROP COLUMN archived_by,
                DROP COLUMN archived_at
        ');
    }
}
