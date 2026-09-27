<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Achievements, ranks, streak-freeze, and declarative multipliers (adaptation of
 * awardlib.md into WBS\Gamification).
 *
 * Additive + reversible. Preserves every WBS invariant:
 *  - Achievement unlock BONUS points are posted to the immutable point_ledger
 *    (source_ref 'achievement:{code}'), never to a separate mutable XP column
 *    that could drift from the ledger.
 *  - Multipliers are a DECLARATIVE JSON spec (factor + when-condition), evaluated
 *    with whitelisted integer math — NEVER eval()'d code (the source spec's
 *    @eval formula is explicitly rejected).
 *  - All ids are CHAR(36) UUIDs; org-scoped for the single-tenant-per-org model.
 */
final class CreateAchievementsAndRanks extends Migration
{
    public function up(): void
    {
        // --- G1 achievements --------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS achievement_definitions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,
                name             VARCHAR(150) NOT NULL,
                description      VARCHAR(255) NULL,
                category         VARCHAR(80)  NULL,
                icon             VARCHAR(120) NULL,
                color            VARCHAR(20)  NULL,
                trigger_type     VARCHAR(32)  NOT NULL,   -- points|count|streak|combo|first_time|cumulative_points|rank_reached|custom
                trigger_config   JSON         NULL,        -- {threshold|count, activity_code, streak_code, rank_code, ...}
                xp               INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_points     INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_rule_code  VARCHAR(80)  NULL,         -- optional rule used to post bonus to the ledger
                secret           TINYINT(1)   NOT NULL DEFAULT 0,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|inactive
                sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ad_code_uq (organization_id, code),
                KEY ad_trigger_idx (organization_id, trigger_type, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_achievements (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                achievement_id   CHAR(36)     NOT NULL,
                achievement_code VARCHAR(80)  NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                season_id        CHAR(36)     NULL,
                xp_awarded       INT UNSIGNED NOT NULL DEFAULT 0,
                bonus_points_awarded INT UNSIGNED NOT NULL DEFAULT 0,
                trigger_source_ref VARCHAR(191) NULL,       -- award/event that triggered the unlock
                granted_by       CHAR(36)     NULL,         -- set for manual unlocks
                metadata         JSON         NULL,
                unlocked_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ua_idem_uq (achievement_id, subject_id),
                KEY ua_subject_idx (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_achievement_progress (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                achievement_id   CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                current_value    DECIMAL(14,2) NOT NULL DEFAULT 0,
                required_value   DECIMAL(14,2) NOT NULL DEFAULT 0,
                percentage       DECIMAL(5,2)  NOT NULL DEFAULT 0,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uap_uq (achievement_id, subject_id),
                KEY uap_subject_idx (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- G4 ranks ---------------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS rank_definitions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,
                name             VARCHAR(150) NOT NULL,
                min_points       INT          NOT NULL DEFAULT 0,
                sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
                icon             VARCHAR(120) NULL,
                color            VARCHAR(20)  NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rd_code_uq (organization_id, code),
                KEY rd_points_idx (organization_id, status, min_points)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- G2 streak freeze/grace (existing table) --------------------------
        $this->db->query('
            ALTER TABLE user_streaks
                ADD COLUMN freeze_until DATE         NULL,
                ADD COLUMN grace_days   TINYINT UNSIGNED NOT NULL DEFAULT 0
        ');

        // --- G5 declarative multipliers on rules ------------------------------
        $this->db->query('
            ALTER TABLE gamification_rules
                ADD COLUMN multipliers JSON NULL
        ');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE gamification_rules DROP COLUMN multipliers');
        $this->db->query('ALTER TABLE user_streaks DROP COLUMN freeze_until, DROP COLUMN grace_days');
        foreach (['user_achievement_progress', 'user_achievements', 'achievement_definitions', 'rank_definitions'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
