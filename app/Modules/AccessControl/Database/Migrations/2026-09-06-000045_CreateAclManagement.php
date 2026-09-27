<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group-aware AccessControl management schema (SRS FR-ACL-003/005/006).
 *
 *  - FR-ACL-003: role_assignments gains a `conditions` JSON column so an
 *    assignment can carry ABAC-style conditions ("...start/end dates, issuer,
 *    approval, and conditions"). The other required fields (org, scope,
 *    descendant scope, effective window, issuer, approval linkage, status)
 *    already exist from migrations 000006 + 000035.
 *
 *  - FR-ACL-005: `delegations` records a leader delegating permissions they
 *    themselves hold to a named delegate, at an equal/narrower scope, for no
 *    longer than their own authorization. Chains are traceable via parent_id +
 *    depth, and every row is revocable.
 *
 *  - FR-ACL-006: `break_glass_sessions` records emergency access — reason + MFA
 *    required, narrow TTL, auto-expiry, and a mandatory post-use review captured
 *    in `break_glass_reviews`. Enhanced audit/alerting is emitted by the service
 *    via the hash-chained AuditLogger; this schema is the durable record.
 */
final class CreateAclManagement extends Migration
{
    public function up(): void
    {
        // FR-ACL-003 — assignment conditions (ABAC predicate tree, nullable).
        $this->db->query('ALTER TABLE role_assignments
            ADD COLUMN conditions JSON NULL AFTER include_descendants');

        // FR-ACL-005 — delegations.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS delegations (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                delegator_id        CHAR(36)     NOT NULL,   -- the leader granting
                delegate_id         CHAR(36)     NOT NULL,   -- the recipient
                permission_code     VARCHAR(120) NOT NULL,   -- a permission the delegator HOLDS
                scope_group_id      CHAR(36)     NULL,        -- null = org-wide (only if delegator is org-wide)
                include_descendants TINYINT(1)   NOT NULL DEFAULT 0,
                purpose             VARCHAR(500) NOT NULL,    -- required justification
                parent_id           CHAR(36)     NULL,        -- delegation this one was sub-delegated from
                depth               INT UNSIGNED NOT NULL DEFAULT 1, -- 1 = delegated directly by a role holder
                status              VARCHAR(16)  NOT NULL DEFAULT "active", -- active|revoked|expired
                effective_from      DATETIME     NOT NULL,
                effective_to        DATETIME     NOT NULL,    -- ALWAYS bounded (max duration)
                revoked_at          DATETIME     NULL,
                revoked_by          CHAR(36)     NULL,
                created_at          DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY dl_delegate_idx (organization_id, delegate_id, status),
                KEY dl_delegator_idx (organization_id, delegator_id, status),
                KEY dl_expiry_idx (status, effective_to),
                KEY dl_parent_idx (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // FR-ACL-006 — break-glass emergency sessions.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS break_glass_sessions (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                subject_id          CHAR(36)     NOT NULL,   -- who is being granted emergency access
                opened_by           CHAR(36)     NOT NULL,   -- who invoked it
                permission_code     VARCHAR(120) NOT NULL,   -- the emergency capability
                scope_group_id      CHAR(36)     NULL,
                include_descendants TINYINT(1)   NOT NULL DEFAULT 0,
                reason              VARCHAR(500) NOT NULL,    -- mandatory
                mfa_level           VARCHAR(16)  NOT NULL,    -- assurance at open time (must be strong)
                status              VARCHAR(16)  NOT NULL DEFAULT "active", -- active|expired|closed
                effective_from      DATETIME(6)  NOT NULL,
                effective_to        DATETIME(6)  NOT NULL,    -- narrow, always bounded
                closed_at           DATETIME(6)  NULL,
                review_state        VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|reviewed
                created_at          DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY bg_subject_idx (organization_id, subject_id, status),
                KEY bg_expiry_idx (status, effective_to),
                KEY bg_review_idx (organization_id, review_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // FR-ACL-006 — mandatory post-use review of a break-glass session.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS break_glass_reviews (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                session_id          CHAR(36)     NOT NULL,
                reviewer_id         CHAR(36)     NOT NULL,   -- must differ from opener (maker-checker)
                outcome             VARCHAR(16)  NOT NULL,    -- justified|unjustified
                notes               VARCHAR(1000) NULL,
                created_at          DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY bgr_session_idx (session_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS break_glass_reviews');
        $this->db->query('DROP TABLE IF EXISTS break_glass_sessions');
        $this->db->query('DROP TABLE IF EXISTS delegations');
        $this->db->query('ALTER TABLE role_assignments DROP COLUMN conditions');
    }
}
