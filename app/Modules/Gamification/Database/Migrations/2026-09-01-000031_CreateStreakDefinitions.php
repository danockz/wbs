<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Admin-managed catalog of streak types (adaptation follow-up: streaks must be
 * addable by an admin, like ranks/achievements/rules).
 *
 * user_streaks stores per-subject progress against a streak_code; this table is
 * the org-scoped DEFINITION of each streak_code (display metadata + default
 * grace policy). StreakService.record() may validate a code against an active
 * definition when strict mode is on, but progress rows remain the source of
 * truth for counts.
 */
final class CreateStreakDefinitions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS streak_definitions (
                id                CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                code              VARCHAR(80)  NOT NULL,
                name              VARCHAR(150) NOT NULL,
                description       VARCHAR(255) NULL,
                cadence           VARCHAR(16)  NOT NULL DEFAULT "daily", -- daily|weekly
                default_grace_days TINYINT UNSIGNED NOT NULL DEFAULT 0,
                icon              VARCHAR(120) NULL,
                color             VARCHAR(20)  NULL,
                status            VARCHAR(16)  NOT NULL DEFAULT "active", -- active|inactive
                sort_order        INT UNSIGNED NOT NULL DEFAULT 0,
                created_at        DATETIME     NOT NULL,
                updated_at        DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY sd_code_uq (organization_id, code),
                KEY sd_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS streak_definitions');
    }
}
