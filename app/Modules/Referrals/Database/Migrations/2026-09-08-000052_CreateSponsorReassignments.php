<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sponsor-reassignment maker-checker workflow (SRS FR-MEM-002).
 *
 * Sponsor re-parenting is no longer a bare `assign()`: it is a reviewed request
 * carrying a reason, effective date, the computed before/after upline paths, a
 * descendant-impact assessment, and (on approval) recalculation of only the
 * AFFECTED, NON-FINALIZED metrics. History is never rewritten — approval closes
 * the old sponsorship edge and opens a new one exactly as `assign()` always did.
 *
 * Two tables, mirroring the access-request maker-checker model:
 *  - `sponsor_reassignments`      — one row per request (pending→approved|…).
 *  - `sponsor_reassignment_reviews` — append-only decision trail.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, safe to re-run.
 */
final class CreateSponsorReassignments extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS sponsor_reassignments (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                member_id          CHAR(36)     NOT NULL,   -- whose sponsor changes
                current_sponsor_id CHAR(36)     NULL,        -- before (null if none)
                new_sponsor_id     CHAR(36)     NOT NULL,    -- after
                requested_by       CHAR(36)     NOT NULL,    -- maker
                approver_id        CHAR(36)     NULL,        -- nominated checker
                reason             VARCHAR(500) NOT NULL,     -- business justification
                effective_at       DATETIME     NULL,         -- when the change takes effect (default: on approval)
                before_path        JSON         NULL,         -- upline chain before (ids, nearest-first)
                after_path         JSON         NULL,         -- upline chain after
                impact             JSON         NULL,         -- descendant-impact assessment snapshot
                status             VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|approved|rejected|cancelled
                eligibility_state  VARCHAR(16)  NOT NULL DEFAULT "ok",      -- ok|blocked (cycle/self/etc at submit)
                eligibility_detail VARCHAR(500) NULL,
                decided_by         CHAR(36)     NULL,
                decided_at         DATETIME(6)  NULL,
                sponsorship_id     CHAR(36)     NULL,         -- new sponsorships row created on approval
                recalc             JSON         NULL,         -- what was recalculated on approval
                created_at         DATETIME(6)  NOT NULL,
                updated_at         DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY srp_member_idx (organization_id, member_id, status),
                KEY srp_approver_idx (organization_id, approver_id, status),
                KEY srp_status_idx (organization_id, status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS sponsor_reassignment_reviews (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                request_id       CHAR(36)     NOT NULL,
                action           VARCHAR(16)  NOT NULL,   -- submit|approve|reject|cancel
                actor_id         CHAR(36)     NULL,
                note             VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY srr_request_idx (request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS sponsor_reassignment_reviews');
        $this->db->query('DROP TABLE IF EXISTS sponsor_reassignments');
    }
}
