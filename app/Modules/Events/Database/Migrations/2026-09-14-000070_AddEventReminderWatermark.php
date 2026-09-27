<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pre-event reminder watermark (roster-notifications workstream, gap G3).
 *
 * The reminder sweep (`php spark events:reminders-due`) stamps this column when
 * it has staged reminders for an event, so a re-run of the sweep never
 * double-notifies. Nullable and additive — existing rows are untouched and are
 * simply eligible for their first reminder.
 */
final class AddEventReminderWatermark extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE events
                ADD COLUMN last_reminded_at DATETIME NULL AFTER updated_at,
                ADD KEY ev_reminder_idx (status, starts_at, last_reminded_at)
        ');
    }

    public function down(): void
    {
        $this->db->query('
            ALTER TABLE events
                DROP KEY ev_reminder_idx,
                DROP COLUMN last_reminded_at
        ');
    }
}
