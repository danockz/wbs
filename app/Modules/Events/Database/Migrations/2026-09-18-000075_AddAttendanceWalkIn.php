<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Attendance WALK-IN flag (gap L6).
 *
 * A `manual` (or `streaming`) check-in is allowed with NO active registration —
 * that is a walk-in, and it is a legitimate, common case. Previously such an
 * attendance was indistinguishable from a matched one, so attendance and
 * registration silently drifted apart: a walk-in had no confirmed seat and never
 * appeared in registration-derived figures, and there was no way to reconcile
 * "who attended" against "who registered / who paid".
 *
 * This adds a boolean `walk_in` flag that CheckinService stamps at record time
 * (true iff there was no `registered` row for the person when they were checked
 * in). It is a FLAG, not a data fork — we do NOT synthesize a fake registration
 * row (which would corrupt capacity/waitlist accounting and the audit trail).
 * The post-event reconciliation report reads this flag to split attendance into
 * matched / walk-in and to surface no-shows and paid-but-absent, all
 * aggregate-only and non-destructive (history integrity).
 *
 * Nullable-safe additive column (default 0) — existing rows become `walk_in = 0`
 * (matched), the correct back-fill since every pre-L6 attendance either matched a
 * registration or was a QR scan (which requires one).
 *
 * `ea_walkin_idx (event_id, walk_in)` lets the reconciliation split read the
 * walk-in / matched counts on an index rather than scanning attendance.
 */
final class AddAttendanceWalkIn extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE event_attendance
                ADD COLUMN walk_in TINYINT(1) NOT NULL DEFAULT 0 AFTER manual_reason,
                ADD KEY ea_walkin_idx (event_id, walk_in)
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE event_attendance
                DROP KEY ea_walkin_idx,
                DROP COLUMN walk_in
        ');
    }
}
