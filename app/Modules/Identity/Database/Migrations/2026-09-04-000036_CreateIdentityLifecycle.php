<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;
use Throwable;

/**
 * Identity uniqueness policy + account lifecycle state machine
 * (SRS FR-ID-002, FR-ID-009).
 *
 * FR-ID-002 — uniqueness:
 *  - ALTER users: add E.164-normalized `phone` (+ verification flag), the raw
 *    `phone_input`/`phone_region` used to normalize it, `date_of_birth`,
 *    derived `is_minor`, and `country_code` (jurisdiction for the age/uniqueness
 *    policy). Phone is unique per organization when the policy requires it — a
 *    partial unique index cannot express "only when policy says so", so we make
 *    the column unique (NULLs are exempt in MySQL) and additionally guard in the
 *    service so the constraint can be relaxed by config without a schema change.
 *  - `identity_policies`: per-jurisdiction (country_code, '*' = default) minimum
 *    age, phone default region, and uniqueness switches.
 *  - `identity_merge_requests` + `identity_merge_reviews`: a justified, audited
 *    merge REVIEW workflow (maker-checker) — never a silent merge.
 *
 * FR-ID-009 — lifecycle:
 *  - ALTER users: widen `status` to hold every lifecycle state and add
 *    reason/actor/timestamp + `merged_into_id`/`anonymized_at` evidence columns.
 *  - `account_state_transitions`: append-only evidence of every state change
 *    (from/to, reason, actor, optional approval reference, evidence JSON).
 *
 * ALTER TABLE precedent exists in Geo/Referrals/Gamification/AccessControl
 * migrations. down() is best-effort (try/catch drop cols/index, drop tables).
 */
final class CreateIdentityLifecycle extends Migration
{
    public function up(): void
    {
        // --- FR-ID-009: widen status + lifecycle evidence columns ------------
        // 'pending_verification' is 20 chars; the old VARCHAR(16) cannot hold it.
        $this->db->query('ALTER TABLE users
            MODIFY COLUMN status VARCHAR(24) NOT NULL DEFAULT "active",
            ADD COLUMN phone            VARCHAR(20)  NULL AFTER email_verified,
            ADD COLUMN phone_verified   TINYINT(1)   NOT NULL DEFAULT 0 AFTER phone,
            ADD COLUMN phone_input      VARCHAR(40)  NULL AFTER phone_verified,
            ADD COLUMN phone_region     VARCHAR(4)   NULL AFTER phone_input,
            ADD COLUMN date_of_birth    DATE         NULL AFTER phone_region,
            ADD COLUMN is_minor         TINYINT(1)   NOT NULL DEFAULT 0 AFTER date_of_birth,
            ADD COLUMN country_code     VARCHAR(2)   NULL AFTER is_minor,
            ADD COLUMN status_reason    VARCHAR(255) NULL AFTER status,
            ADD COLUMN status_changed_at DATETIME    NULL AFTER status_reason,
            ADD COLUMN status_changed_by CHAR(36)    NULL AFTER status_changed_at,
            ADD COLUMN merged_into_id   CHAR(36)     NULL AFTER status_changed_by,
            ADD COLUMN anonymized_at    DATETIME     NULL AFTER merged_into_id
        ');

        // Phone unique per organization (MySQL exempts NULLs, so unverified /
        // phone-less accounts are unaffected). The service enforces the policy
        // switch; the index guarantees no accidental duplicate slips through.
        $this->db->query('CREATE UNIQUE INDEX users_org_phone_uq ON users (organization_id, phone)');
        $this->db->query('CREATE INDEX users_merged_into_idx ON users (merged_into_id)');

        // --- FR-ID-002: per-jurisdiction identity policy ---------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS identity_policies (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                country_code         VARCHAR(2)   NOT NULL DEFAULT "*",   -- "*" = default
                min_age              INT          NOT NULL DEFAULT 13,
                phone_default_region VARCHAR(4)   NULL,                    -- ISO region for national numbers
                require_email_unique TINYINT(1)   NOT NULL DEFAULT 1,
                require_phone_unique TINYINT(1)   NOT NULL DEFAULT 1,
                allow_minor          TINYINT(1)   NOT NULL DEFAULT 0,
                notes                VARCHAR(255) NULL,
                created_at           DATETIME     NOT NULL,
                updated_at           DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idp_org_country_uq (organization_id, country_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- FR-ID-009: append-only lifecycle transition evidence -----------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS account_state_transitions (
                id                CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                user_id           CHAR(36)     NOT NULL,
                from_status       VARCHAR(24)  NULL,
                to_status         VARCHAR(24)  NOT NULL,
                reason            VARCHAR(255) NOT NULL,
                actor_id          CHAR(36)     NULL,
                approval_ref      CHAR(36)     NULL,     -- e.g. an access_request / merge_request id
                evidence          JSON         NULL,
                created_at        DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ast_user_idx (user_id, created_at),
                KEY ast_org_to_idx (organization_id, to_status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- FR-ID-002: audited merge REVIEW workflow (maker-checker) --------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS identity_merge_requests (
                id                CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                primary_user_id   CHAR(36)     NOT NULL,  -- the account kept
                duplicate_user_id CHAR(36)     NOT NULL,  -- the account merged away
                reason            VARCHAR(255) NOT NULL,
                requested_by      CHAR(36)     NOT NULL,
                status            VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|approved|rejected|cancelled
                conflict_detail   JSON         NULL,
                decided_by        CHAR(36)     NULL,
                decided_at        DATETIME(6)  NULL,
                created_at        DATETIME(6)  NOT NULL,
                updated_at        DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY imr_status_idx (organization_id, status, created_at),
                KEY imr_primary_idx (primary_user_id),
                KEY imr_duplicate_idx (duplicate_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS identity_merge_reviews (
                id                CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                merge_request_id  CHAR(36)     NOT NULL,
                action            VARCHAR(16)  NOT NULL,   -- submit|approve|reject|cancel
                actor_id          CHAR(36)     NULL,
                note              VARCHAR(255) NULL,
                created_at        DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY imrv_req_idx (merge_request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['users_org_phone_uq', 'users_merged_into_idx'] as $idx) {
            try {
                $this->db->query("DROP INDEX {$idx} ON users");
            } catch (Throwable) {
                // index may not exist
            }
        }
        try {
            $this->db->query('ALTER TABLE users
                DROP COLUMN phone,
                DROP COLUMN phone_verified,
                DROP COLUMN phone_input,
                DROP COLUMN phone_region,
                DROP COLUMN date_of_birth,
                DROP COLUMN is_minor,
                DROP COLUMN country_code,
                DROP COLUMN status_reason,
                DROP COLUMN status_changed_at,
                DROP COLUMN status_changed_by,
                DROP COLUMN merged_into_id,
                DROP COLUMN anonymized_at
            ');
            $this->db->query('ALTER TABLE users MODIFY COLUMN status VARCHAR(16) NOT NULL DEFAULT "active"');
        } catch (Throwable) {
            // columns may not exist
        }

        foreach ([
            'identity_merge_reviews',
            'identity_merge_requests',
            'account_state_transitions',
            'identity_policies',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
