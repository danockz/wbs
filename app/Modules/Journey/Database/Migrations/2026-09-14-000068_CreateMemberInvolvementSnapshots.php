<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Materialized per-member INVOLVEMENT snapshot — the resource-light backbone of
 * involvement-based journey triage (see docs/TODO_INVOLVEMENT_BASED_TRIAGE.md).
 *
 * Instead of fanning out across Events + Courses + Referrals + Contributions +
 * point_ledger for every member on every pipeline render, the involvement of a
 * member (per journey context) is aggregated ONCE into this table — refreshed on
 * a journey transition (the InvolvementService transition listener) or by a
 * batch recompute — and the pipeline / roster hot path then reads pre-computed
 * bands + figures with plain grouped aggregate queries.
 *
 * ONE row per (organization, user, group context); group_id NULL = the member's
 * org-wide primary journey, matching member_journeys' own (org,user,group)
 * uniqueness so a snapshot joins 1:1 to a journey row.
 *
 * The `band` (hot|warm|cold) is the classification produced by the rule bands;
 * the raw figures are ALSO persisted so the leader-facing UI can surface the
 * quantum-of-work (sponsorships, giving, points) alongside the band, and so the
 * bands can be recomputed after a threshold/config change without re-reading the
 * source modules.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
final class CreateMemberInvolvementSnapshots extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS member_involvement_snapshots (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                user_id             CHAR(36)     NOT NULL,
                group_id            CHAR(36)     NULL,      -- NULL = org-wide primary journey context

                -- Current ladder stage of the member in this context, denormalised
                -- from member_journeys at refresh time so the pipeline board can
                -- split stage x band in ONE grouped query (no join, no per-member
                -- fan-out). Kept fresh by refreshing the snapshot on every
                -- transition (the stage is already current when the listener runs).
                stage_code          VARCHAR(60)  NOT NULL DEFAULT "",
                stage_phase         VARCHAR(16)  NOT NULL DEFAULT "",

                -- Input 1: last activity (recency of most recent participation).
                last_activity_at    DATETIME     NULL,

                -- Input 2: participation rate = activities in window / configured
                -- target, expressed in basis points (10000 = 100%), capped.
                activity_count      INT UNSIGNED NOT NULL DEFAULT 0,
                activity_target     INT UNSIGNED NOT NULL DEFAULT 0,
                participation_bps   INT UNSIGNED NOT NULL DEFAULT 0,

                -- Input 3: quantum of work — the raw components AND the composite.
                sponsorship_count   INT UNSIGNED NOT NULL DEFAULT 0,
                giving_minor        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                points              INT          NOT NULL DEFAULT 0,
                own_quantum         INT          NOT NULL DEFAULT 0,  -- weighted composite of the members direct work
                downline_quantum    INT          NOT NULL DEFAULT 0,  -- rolled-up disciple/mentee effort
                quantum             INT          NOT NULL DEFAULT 0,  -- effective = own + downline

                -- The classification + the window it was computed against.
                band                VARCHAR(8)   NOT NULL DEFAULT "cold", -- hot|warm|cold
                window_days         INT UNSIGNED NOT NULL DEFAULT 90,
                computed_at         DATETIME     NOT NULL,

                PRIMARY KEY (id),
                UNIQUE KEY mis_ctx_uq (organization_id, user_id, group_id),
                -- Pipeline board reads: members of a context by stage + band.
                KEY mis_board_idx (organization_id, group_id, stage_code, band),
                KEY mis_quantum_idx (organization_id, group_id, quantum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS member_involvement_snapshots');
    }
}
