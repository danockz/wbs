<?php

declare(strict_types=1);

namespace WBS\Contributions\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * VBCS gap adaptation (from GivingsLibrary): PGV/GGV metrics, partnership
 * tiers, and recurring giving commitments.
 *
 * Design stance vs. the reference library:
 *  - PGV/GGV are DERIVED and cached in giving_metrics (NOT mutable users.pgv/ggv
 *    columns) — same discipline as gamification's derived point balances. They
 *    are recomputed async on contribution.succeeded/refunded via the outbox.
 *  - Money stays BIGINT minor units platform-wide (never decimal/float).
 *  - Downline for GGV is resolved from the Referrals `sponsorships` graph
 *    (single source of truth), not a duplicated sponsor_id column.
 *  - partnership_level_definitions are admin-configurable, mirroring gamification
 *    rank_definitions.
 */
final class CreateVbcsMetricsAndPartnership extends Migration
{
    public function up(): void
    {
        // Derived PGV/GGV snapshot (one row per subject; refreshed async).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS giving_metrics (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- user id
                pgv_minor        BIGINT UNSIGNED NOT NULL DEFAULT 0, -- lifetime verified givings by subject
                ggv_minor        BIGINT UNSIGNED NOT NULL DEFAULT 0, -- downline total x multiplier
                ggv_raw_minor    BIGINT UNSIGNED NOT NULL DEFAULT 0, -- downline total before multiplier
                direct_recruits  INT UNSIGNED NOT NULL DEFAULT 0,
                downline_size    INT UNSIGNED NOT NULL DEFAULT 0,
                multiplier_bps   INT UNSIGNED NOT NULL DEFAULT 10000, -- 10000 = 1.00x; 13000 = 1.30x
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                computed_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gm_subject_uq (organization_id, subject_id),
                KEY gm_pgv_idx (organization_id, pgv_minor),
                KEY gm_ggv_idx (organization_id, ggv_minor)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Admin-configurable partnership tiers (mirrors rank_definitions).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS partnership_level_definitions (
                id                      CHAR(36)     NOT NULL,
                organization_id         CHAR(36)     NOT NULL,
                code                    VARCHAR(80)  NOT NULL,
                name                    VARCHAR(150) NOT NULL,
                description             VARCHAR(255) NULL,
                min_consecutive_months  INT UNSIGNED NOT NULL DEFAULT 0,
                min_pgv_minor           BIGINT UNSIGNED NOT NULL DEFAULT 0,
                icon                    VARCHAR(120) NULL,
                color                   VARCHAR(20)  NULL,
                sort_order              INT UNSIGNED NOT NULL DEFAULT 0,
                status                  VARCHAR(16)  NOT NULL DEFAULT "active", -- active|inactive
                created_at              DATETIME     NOT NULL,
                updated_at              DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pld_code_uq (organization_id, code),
                KEY pld_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Per-subject partnership status (derived; refreshed on giving events).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_partnership_status (
                id                        CHAR(36)     NOT NULL,
                organization_id           CHAR(36)     NOT NULL,
                subject_id                CHAR(36)     NOT NULL,
                current_level_code        VARCHAR(80)  NULL,
                consecutive_months_given  INT UNSIGNED NOT NULL DEFAULT 0,
                last_giving_at            DATETIME     NULL,
                computed_at               DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ups_subject_uq (organization_id, subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Recurring pledges — reminder-only (nothing auto-charges).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS giving_commitments (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- pledging user
                cause_id         CHAR(36)     NOT NULL,
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                frequency        VARCHAR(16)  NOT NULL DEFAULT "monthly", -- monthly|quarterly|annual|one_time
                status           VARCHAR(16)  NOT NULL DEFAULT "active",  -- active|cancelled|completed
                next_due_at      DATE         NULL,
                last_reminded_at DATETIME     NULL,
                started_at       DATETIME     NOT NULL,
                ended_at         DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY gc_due_idx (organization_id, status, next_due_at),
                KEY gc_subject_idx (organization_id, subject_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS giving_commitments');
        $this->db->query('DROP TABLE IF EXISTS user_partnership_status');
        $this->db->query('DROP TABLE IF EXISTS partnership_level_definitions');
        $this->db->query('DROP TABLE IF EXISTS giving_metrics');
    }
}
