<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gamification: seasons, rules, immutable point ledger, badges (SRS FR-GAM-*).
 *
 *  - Points are NON-REDEEMABLE and kept entirely separate from the money ledger.
 *  - `point_ledger` is append-only and prevents duplicate awards with a UNIQUE
 *    (rule_id, subject_id, source_ref) key; reversals are compensating entries.
 *  - Exactly one active organization-wide season; rollover is idempotent and
 *    lock-protected via a UNIQUE season transition key.
 */
final class CreateGamification extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS gamification_seasons (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                season_year      SMALLINT UNSIGNED NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|closed
                starts_at        DATETIME     NOT NULL,   -- org timezone boundary, stored UTC
                ends_at          DATETIME     NULL,
                closed_at        DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gs_year_uq (organization_id, season_year),
                KEY gs_active_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // At most one active season per org (app-maintained active_key + UNIQUE).
        $this->db->query('ALTER TABLE gamification_seasons ADD COLUMN active_key CHAR(36) NULL');
        $this->db->query('ALTER TABLE gamification_seasons ADD UNIQUE KEY gs_active_uq (active_key)');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS gamification_rules (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                code             VARCHAR(80)  NOT NULL,   -- e.g. referral.conversion, event.attendance
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                points           INT          NOT NULL,   -- may be negative for penalties (rare)
                event_type       VARCHAR(80)  NOT NULL,   -- domain event that triggers evaluation
                per_period_cap   INT UNSIGNED NULL,       -- anti-gaming cap
                period           VARCHAR(16)  NULL,        -- day|week|month|season
                cooldown_seconds INT UNSIGNED NULL,
                requires_review  TINYINT(1)   NOT NULL DEFAULT 0,
                effective_from   DATETIME     NOT NULL,
                effective_to     DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                explanation      VARCHAR(255) NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gr_ver_uq (organization_id, code, version),
                KEY gr_event_idx (organization_id, event_type, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS point_ledger (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                season_id        CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- user or group id
                subject_type     VARCHAR(16)  NOT NULL DEFAULT "user", -- user|group
                rule_id          CHAR(36)     NOT NULL,
                rule_version     INT UNSIGNED NOT NULL,
                entry_type       VARCHAR(16)  NOT NULL DEFAULT "award", -- award|reversal|adjustment
                points           INT          NOT NULL,
                source_ref       VARCHAR(191) NOT NULL,   -- domain event/entity key
                state            VARCHAR(16)  NOT NULL DEFAULT "final", -- final|held|reversed
                explanation      VARCHAR(255) NULL,
                archived         TINYINT(1)   NOT NULL DEFAULT 0,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pl_idem_uq (rule_id, subject_id, source_ref, entry_type),
                KEY pl_balance_idx (organization_id, season_id, subject_id),
                KEY pl_source_idx (source_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS badges (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                code             VARCHAR(80)  NOT NULL,
                name             VARCHAR(150) NOT NULL,
                criteria         JSON         NULL,
                visibility       VARCHAR(16)  NOT NULL DEFAULT "public", -- public|group|private
                permanent        TINYINT(1)   NOT NULL DEFAULT 1,       -- else season-scoped
                expiry_policy    VARCHAR(20)  NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY badge_code_uq (organization_id, code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS badge_awards (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                badge_id         CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                season_id        CHAR(36)     NULL,
                source_ref       VARCHAR(191) NULL,
                state            VARCHAR(16)  NOT NULL DEFAULT "awarded", -- awarded|revoked
                visibility       VARCHAR(16)  NOT NULL DEFAULT "public",
                awarded_at       DATETIME     NOT NULL,
                revoked_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ba_idem_uq (badge_id, subject_id, season_id),
                KEY ba_subject_idx (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS season_balance_snapshots (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                season_id        CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL DEFAULT "user",
                total_points     INT          NOT NULL DEFAULT 0,
                rank_position    INT UNSIGNED NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY sbs_uq (season_id, subject_id, subject_type),
                KEY sbs_season_idx (season_id, total_points)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Idempotent, lock-protected rollover job records — a transition can
        // never run twice (UNIQUE transition key).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS season_transitions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                transition_key   VARCHAR(191) NOT NULL,   -- org:from_year:to_year
                from_season_id   CHAR(36)     NULL,
                to_season_id     CHAR(36)     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "running", -- running|completed|failed
                created_at       DATETIME     NOT NULL,
                completed_at     DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY st_key_uq (transition_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_streaks (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                streak_code      VARCHAR(80)  NOT NULL,
                season_id        CHAR(36)     NULL,
                current_count    INT UNSIGNED NOT NULL DEFAULT 0,
                best_count       INT UNSIGNED NOT NULL DEFAULT 0,
                last_event_date  DATE         NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY us_uq (organization_id, subject_id, streak_code, season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS fraud_reviews (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                source_ref       VARCHAR(191) NOT NULL,
                reason           VARCHAR(120) NOT NULL,
                ledger_id        CHAR(36)     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "open", -- open|cleared|rejected
                created_at       DATETIME     NOT NULL,
                resolved_at      DATETIME     NULL,
                PRIMARY KEY (id),
                KEY fr_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'fraud_reviews', 'user_streaks', 'season_transitions', 'season_balance_snapshots',
            'badge_awards', 'badges', 'point_ledger', 'gamification_rules', 'gamification_seasons',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
