<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Membership Journey — the first-class, configurable discipleship spine
 * (assessment §4, Option B).
 *
 * The journey is the record of a person's MATURITY / ROLE PROGRESSION
 * (prospect → first-timer → new believer → foundation → member → worker →
 * leader → sender). It deliberately does NOT duplicate:
 *   - the login-account lifecycle (Identity users.status), nor
 *   - group belonging (group_members), nor
 *   - the referral capture/conversion (prospects / referral_attributions).
 * It sits ABOVE them and references them.
 *
 * Three tables:
 *   - journey_stages            : org-configurable, ordered ladder. Each stage
 *                                 carries a Win/Build/Send `phase` so the journey
 *                                 shares one vocabulary with the activity model.
 *                                 org+group scoped, NULL group_id = org-wide,
 *                                 most-specific-wins (matches the other catalogs).
 *   - member_journeys           : ONE current-stage record per (person, context).
 *                                 group_id NULL = the person's org-wide primary
 *                                 journey; a non-null group_id = an optional
 *                                 per-group-context journey (hybrid granularity
 *                                 per the redefinition decision).
 *   - member_journey_transitions: append-only, immutable history of every stage
 *                                 change — from/to, reason, the actor AND the
 *                                 discipler (who moved them), evidence, source.
 *
 * Stage ladder is SEEDED with an editable default (see JourneySeeder). Nothing
 * here is hard-coded into behaviour: stages are data.
 */
final class CreateMembershipJourney extends Migration
{
    public function up(): void
    {
        // ---- journey_stages : the configurable ladder -----------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS journey_stages (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,      -- NULL = org-wide default catalog
                code             VARCHAR(60)  NOT NULL,  -- stable machine code, e.g. new_believer
                name             VARCHAR(100) NOT NULL,
                phase            VARCHAR(16)  NOT NULL DEFAULT "build",  -- win|build|send|general
                sort_order       INT          NOT NULL DEFAULT 0,       -- ladder position
                description      VARCHAR(255) NULL,
                is_terminal      TINYINT(1)   NOT NULL DEFAULT 0,       -- e.g. Sender/Multiplier
                is_entry         TINYINT(1)   NOT NULL DEFAULT 0,       -- default stage a new journey opens at
                icon             VARCHAR(120) NULL,
                color            VARCHAR(20)  NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY js_org_group_code_uq (organization_id, group_id, code),
                KEY js_org_order_idx (organization_id, sort_order),
                KEY js_phase_idx (organization_id, phase)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- member_journeys : current stage per (person, context) ----------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS member_journeys (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                user_id            CHAR(36)     NOT NULL,
                group_id           CHAR(36)     NULL,     -- NULL = org-wide primary journey
                stage_code         VARCHAR(60)  NOT NULL, -- current stage (resolved against journey_stages)
                stage_phase        VARCHAR(16)  NOT NULL DEFAULT "win", -- denormalised for fast pipeline reads
                stage_entered_at   DATETIME     NOT NULL,
                previous_stage     VARCHAR(60)  NULL,
                status             VARCHAR(16)  NOT NULL DEFAULT "active", -- active|paused|completed|archived
                source             VARCHAR(24)  NOT NULL DEFAULT "manual", -- manual|conversion|rule|import
                source_ref         VARCHAR(120) NULL,     -- e.g. prospect id / attribution id
                note               VARCHAR(255) NULL,
                created_at         DATETIME     NOT NULL,
                updated_at         DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mj_person_ctx_uq (organization_id, user_id, group_id),
                KEY mj_org_stage_idx (organization_id, stage_code),
                KEY mj_org_phase_idx (organization_id, stage_phase),
                KEY mj_group_idx (organization_id, group_id, stage_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- member_journey_transitions : append-only history ---------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS member_journey_transitions (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                journey_id         CHAR(36)     NOT NULL, -- FK-by-convention to member_journeys.id
                user_id            CHAR(36)     NOT NULL,
                group_id           CHAR(36)     NULL,     -- context copied from the journey at time of move
                from_stage         VARCHAR(60)  NULL,     -- NULL = journey opened
                to_stage           VARCHAR(60)  NOT NULL,
                direction          VARCHAR(12)  NOT NULL DEFAULT "advance", -- advance|regress|set|open
                reason             VARCHAR(255) NULL,
                actor_id           CHAR(36)     NULL,     -- who performed the change
                discipler_id       CHAR(36)     NULL,     -- who is credited with moving them (Build/Send)
                evidence_type      VARCHAR(40)  NULL,     -- course|event|follow_up|attestation|…
                evidence_ref       VARCHAR(120) NULL,
                source             VARCHAR(24)  NOT NULL DEFAULT "manual",
                created_at         DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY mjt_journey_idx (journey_id, created_at),
                KEY mjt_user_idx (organization_id, user_id, created_at),
                KEY mjt_discipler_idx (organization_id, discipler_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS member_journey_transitions');
        $this->db->query('DROP TABLE IF EXISTS member_journeys');
        $this->db->query('DROP TABLE IF EXISTS journey_stages');
    }
}
