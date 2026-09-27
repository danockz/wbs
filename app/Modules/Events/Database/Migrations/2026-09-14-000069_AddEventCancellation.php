<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Safe-cancel audit fields (lifecycle-guards workstream, gap G7).
 *
 * `cancel()` was previously a silent, unguarded status write. It now records WHY
 * an event was cancelled and WHEN, so a published→cancelled transition is
 * auditable (and, later, drives the registrant notification in G3). Nullable and
 * additive — existing rows are untouched.
 */
final class AddEventCancellation extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE events
                ADD COLUMN cancellation_reason TEXT     NULL AFTER status,
                ADD COLUMN cancelled_at        DATETIME NULL AFTER cancellation_reason,
                ADD COLUMN cancelled_by        CHAR(36) NULL AFTER cancelled_at
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE events
                DROP COLUMN cancellation_reason,
                DROP COLUMN cancelled_at,
                DROP COLUMN cancelled_by
        ');
    }
}
