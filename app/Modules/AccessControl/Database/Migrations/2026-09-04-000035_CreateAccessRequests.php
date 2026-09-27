<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Access-request / approval workflow (SRS FR-ACL-004) plus the grant-lifecycle
 * columns FR-ACL-003 requires on role assignments (expiring, documented,
 * audited, approval-linked).
 *
 * Design:
 *  - access_requests: a request for a role (or a direct permission) at a scope,
 *    with business reason, requested duration, and a nominated approver.
 *    Maker-checker + conflict detection run through the existing PDP; a requester
 *    can never approve their own request (SoD action `access.request.approve`).
 *  - access_request_reviews: append-only decision trail (approve|reject|
 *    revoke|renew) — who acted, when, why. Immutable evidence.
 *  - role_assignments gains status/effective dating/issuer/approval linkage so a
 *    granted assignment expires automatically and traces back to its request.
 *    (AuthorizationService already reads role_assignments; a follow-up filters
 *    on status/effective window — see the service, not the schema.)
 */
final class CreateAccessRequests extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS access_requests (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- who the access is FOR
                requested_by     CHAR(36)     NOT NULL,   -- who submitted (maker)
                grant_type       VARCHAR(16)  NOT NULL DEFAULT "role", -- role|permission
                role_id          CHAR(36)     NULL,        -- when grant_type=role
                permission_code  VARCHAR(120) NULL,        -- when grant_type=permission
                scope_group_id   CHAR(36)     NULL,        -- null = org-wide
                include_descendants TINYINT(1) NOT NULL DEFAULT 0,
                reason           VARCHAR(500) NOT NULL,     -- business justification
                duration_days    INT UNSIGNED NULL,        -- null = no auto-expiry requested
                approver_id      CHAR(36)     NULL,         -- nominated checker
                status           VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|approved|rejected|revoked|expired|cancelled
                conflict_state   VARCHAR(16)  NOT NULL DEFAULT "none",    -- none|flagged
                conflict_detail  VARCHAR(500) NULL,
                decided_by       CHAR(36)     NULL,
                decided_at       DATETIME(6)  NULL,
                assignment_id    CHAR(36)     NULL,         -- created role_assignment on approval
                effective_from   DATETIME     NULL,
                effective_to     DATETIME     NULL,         -- computed from duration on approval
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY ar_subject_idx (organization_id, subject_id, status),
                KEY ar_approver_idx (organization_id, approver_id, status),
                KEY ar_expiry_idx (status, effective_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS access_request_reviews (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                request_id       CHAR(36)     NOT NULL,
                action           VARCHAR(16)  NOT NULL,     -- approve|reject|revoke|renew|cancel
                actor_id         CHAR(36)     NOT NULL,
                note             VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY arr_request_idx (request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Grant-lifecycle columns on role_assignments (FR-ACL-003). Existing rows
        // default to an active, non-expiring, "seed" assignment so the PDP keeps
        // resolving them exactly as before.
        $this->db->query('ALTER TABLE role_assignments
            ADD COLUMN status         VARCHAR(16) NOT NULL DEFAULT "active" AFTER scope_group_id,
            ADD COLUMN include_descendants TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
            ADD COLUMN source         VARCHAR(16) NOT NULL DEFAULT "seed" AFTER include_descendants,
            ADD COLUMN issued_by      CHAR(36)    NULL AFTER source,
            ADD COLUMN request_id     CHAR(36)    NULL AFTER issued_by,
            ADD COLUMN effective_from DATETIME    NULL AFTER request_id,
            ADD COLUMN effective_to   DATETIME    NULL AFTER effective_from,
            ADD COLUMN revoked_at     DATETIME    NULL AFTER effective_to
        ');
        $this->db->query('CREATE INDEX ra_status_idx ON role_assignments (subject_id, status)');
    }

    public function down(): void
    {
        // Drop added index + columns first (best-effort), then the new tables.
        try {
            $this->db->query('DROP INDEX ra_status_idx ON role_assignments');
        } catch (\Throwable) {
            // index may not exist
        }
        try {
            $this->db->query('ALTER TABLE role_assignments
                DROP COLUMN status,
                DROP COLUMN include_descendants,
                DROP COLUMN source,
                DROP COLUMN issued_by,
                DROP COLUMN request_id,
                DROP COLUMN effective_from,
                DROP COLUMN effective_to,
                DROP COLUMN revoked_at
            ');
        } catch (\Throwable) {
            // columns may not exist
        }

        foreach (['access_request_reviews', 'access_requests'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
