<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-hierarchical-group calendar settings (display label, timezone display,
 * visible event kinds). One row per (organization, group); missing row → inherit
 * from the nearest ancestor, then platform defaults. Reference data only — the
 * events themselves stay on `events.group_id`.
 */
final class CreateGroupCalendarSettings extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('group_calendar_settings')) {
            return;
        }

        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_calendar_settings (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                display_label    VARCHAR(200) NULL,
                timezone         VARCHAR(64)  NULL,
                visible_kinds    TEXT         NULL,
                updated_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gcs_org_group_uq (organization_id, group_id),
                KEY gcs_group_idx (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('group_calendar_settings')) {
            $this->db->query('DROP TABLE group_calendar_settings');
        }
    }
}
