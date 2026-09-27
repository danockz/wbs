<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Configurable Win–Build–Send activities.
 *
 * Makes every earning ACTIVITY, ACHIEVEMENT (with triggers), and FOLLOW-UP
 * fully data-configured and manageable at runtime, extending the EXISTING
 * versioned-rules + immutable-ledger engine (not a parallel one).
 *
 * See docs/configurable-wbs-activities.md for the full rationale + gap analysis
 * against the reference schema.
 *
 * All changes are additive/backward-compatible: new columns are nullable or
 * defaulted (phase → 'general', point_mode → 'fixed'), existing rows keep
 * working, and the idempotency/versioning guarantees of the ledger are
 * untouched.
 */
final class ConfigurableWbsActivities extends Migration
{
    public function up(): void
    {
        // ---- 1. Activity categories (the admin-facing grouping) ------------
        // Org + optional group scoped, most-specific-wins like the other
        // configurable catalogs. Carries the Win/Build/Send `phase`.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS activity_categories (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                group_id             CHAR(36)     NULL,
                include_descendants  TINYINT(1)   NOT NULL DEFAULT 1,
                code                 VARCHAR(50)  NOT NULL,
                name                 VARCHAR(100) NOT NULL,
                phase                VARCHAR(16)  NOT NULL DEFAULT "general", -- win|build|send|general
                description          VARCHAR(255) NULL,
                icon                 VARCHAR(120) NULL,
                color                VARCHAR(20)  NULL,
                sort_order           INT UNSIGNED NOT NULL DEFAULT 0,
                status               VARCHAR(16)  NOT NULL DEFAULT "active", -- active|inactive
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ac_code_uq (organization_id, group_id, code),
                KEY ac_phase_idx (organization_id, phase, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- 2. gamification_rules become configurable "activities" --------
        // A rule already describes how an event earns points; these columns
        // turn it into a first-class, admin-manageable activity with a phase,
        // richer point computation and multi-period limits.
        $this->db->query('
            ALTER TABLE gamification_rules
                ADD COLUMN category_id        CHAR(36)      NULL       AFTER group_id,
                ADD COLUMN phase              VARCHAR(16)   NOT NULL DEFAULT "general" AFTER category_id,
                ADD COLUMN activity_name      VARCHAR(150)  NULL       AFTER phase,
                ADD COLUMN icon               VARCHAR(120)  NULL       AFTER activity_name,
                ADD COLUMN color              VARCHAR(20)   NULL       AFTER icon,
                ADD COLUMN sort_order         INT UNSIGNED  NOT NULL DEFAULT 0 AFTER color,
                ADD COLUMN point_mode         VARCHAR(16)   NOT NULL DEFAULT "fixed" AFTER points, -- fixed|variable|formula
                ADD COLUMN point_formula      VARCHAR(255)  NULL       AFTER point_mode,
                ADD COLUMN min_points         INT           NULL       AFTER point_formula,
                ADD COLUMN max_points         INT           NULL       AFTER min_points,
                ADD COLUMN daily_limit        INT UNSIGNED  NULL       AFTER max_points,
                ADD COLUMN weekly_limit       INT UNSIGNED  NULL       AFTER daily_limit,
                ADD COLUMN monthly_limit      INT UNSIGNED  NULL       AFTER weekly_limit,
                ADD COLUMN approval_role_code VARCHAR(80)   NULL       AFTER requires_review
        ');
        $this->db->query('ALTER TABLE gamification_rules ADD KEY gr_phase_idx (organization_id, phase, status)');

        // ---- 3. Achievements gain a phase ---------------------------------
        $this->db->query('ALTER TABLE achievement_definitions ADD COLUMN phase VARCHAR(16) NOT NULL DEFAULT "general" AFTER category');

        // ---- 4. Approval routing on the review queue ----------------------
        $this->db->query('ALTER TABLE fraud_reviews ADD COLUMN assigned_role VARCHAR(80) NULL AFTER reason');

        // ---- 5. Follow-up types (configurable) ----------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS follow_up_types (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                group_id             CHAR(36)     NULL,
                include_descendants  TINYINT(1)   NOT NULL DEFAULT 1,
                code                 VARCHAR(50)  NOT NULL,
                name                 VARCHAR(100) NOT NULL,
                phase                VARCHAR(16)  NOT NULL DEFAULT "build",
                description          VARCHAR(255) NULL,
                requires_outcome     TINYINT(1)   NOT NULL DEFAULT 0,
                default_next_days    INT UNSIGNED NULL,       -- auto next-follow-up offset
                award_rule_code      VARCHAR(80)  NULL,       -- rule that awards points on record
                icon                 VARCHAR(120) NULL,
                color                VARCHAR(20)  NULL,
                sort_order           INT UNSIGNED NOT NULL DEFAULT 0,
                status               VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY fut_code_uq (organization_id, group_id, code),
                KEY fut_phase_idx (organization_id, phase, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- 6. Follow-up methods (configurable channels + multiplier key) -
        $this->db->query('
            CREATE TABLE IF NOT EXISTS follow_up_methods (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                code                 VARCHAR(50)  NOT NULL,   -- call|visit|sms|whatsapp|kingschat|email|other
                name                 VARCHAR(100) NOT NULL,
                multiplier_key       VARCHAR(50)  NULL,       -- fed to rule multiplier when.field=method
                sort_order           INT UNSIGNED NOT NULL DEFAULT 0,
                status               VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY fum_code_uq (organization_id, code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- 7. Follow-up records -----------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS follow_ups (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                group_id             CHAR(36)     NULL,
                subject_user_id      CHAR(36)     NOT NULL,   -- who was followed up
                follower_user_id     CHAR(36)     NOT NULL,   -- who performed it (earns points)
                type_code            VARCHAR(50)  NOT NULL,
                method_code          VARCHAR(50)  NOT NULL,
                status               VARCHAR(16)  NOT NULL DEFAULT "completed", -- pending|in_progress|completed|no_response|cancelled
                performed_at         DATETIME     NULL,
                summary              VARCHAR(500) NULL,
                outcome              VARCHAR(500) NULL,
                spiritual_health     VARCHAR(20)  NULL,       -- excellent|good|okay|challenged|very_challenged
                needs                JSON         NULL,
                next_follow_up_at    DATETIME     NULL,
                next_notes           VARCHAR(500) NULL,
                award_ledger_id      CHAR(36)     NULL,       -- point_ledger entry created on record
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NULL,
                PRIMARY KEY (id),
                KEY fu_follower_idx (organization_id, follower_user_id, performed_at),
                KEY fu_subject_idx (organization_id, subject_user_id),
                KEY fu_due_idx (organization_id, next_follow_up_at),
                KEY fu_type_status_idx (organization_id, type_code, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- 8. Typed runtime config store --------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS gamification_config (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                config_key           VARCHAR(100) NOT NULL,
                config_value         TEXT         NULL,
                config_type          VARCHAR(16)  NOT NULL DEFAULT "string", -- string|integer|float|boolean|json
                description          VARCHAR(255) NULL,
                is_editable          TINYINT(1)   NOT NULL DEFAULT 1,
                updated_by           CHAR(36)     NULL,
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gc_key_uq (organization_id, config_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS gamification_config');
        $this->db->query('DROP TABLE IF EXISTS follow_ups');
        $this->db->query('DROP TABLE IF EXISTS follow_up_methods');
        $this->db->query('DROP TABLE IF EXISTS follow_up_types');

        $this->db->query('ALTER TABLE fraud_reviews DROP COLUMN assigned_role');
        $this->db->query('ALTER TABLE achievement_definitions DROP COLUMN phase');

        $this->db->query('ALTER TABLE gamification_rules DROP INDEX gr_phase_idx');
        $this->db->query('
            ALTER TABLE gamification_rules
                DROP COLUMN approval_role_code,
                DROP COLUMN monthly_limit,
                DROP COLUMN weekly_limit,
                DROP COLUMN daily_limit,
                DROP COLUMN max_points,
                DROP COLUMN min_points,
                DROP COLUMN point_formula,
                DROP COLUMN point_mode,
                DROP COLUMN sort_order,
                DROP COLUMN color,
                DROP COLUMN icon,
                DROP COLUMN activity_name,
                DROP COLUMN phase,
                DROP COLUMN category_id
        ');

        $this->db->query('DROP TABLE IF EXISTS activity_categories');
    }
}
