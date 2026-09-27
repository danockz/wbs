<?php

declare(strict_types=1);

namespace WBS\Contributions\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * VBCS: causes, contributions, append-only ledger, webhooks (SRS FR-VBCS-*).
 *
 *  - ALL money is stored as INTEGER minor units + currency (no floats, no PAN).
 *  - journal_entries/journal_lines are APPEND-ONLY double-entry records;
 *    corrections are compensating entries, never edits.
 *  - webhook_inbox is idempotent on UNIQUE(provider, provider_event_id) and
 *    quarantines unknown/unverified events.
 *  - refund_requests enforce maker-checker (approver != requester).
 */
final class CreateContributions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS causes (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,       -- owning group
                name             VARCHAR(200) NOT NULL,
                purpose          TEXT         NULL,
                visibility       VARCHAR(16)  NOT NULL DEFAULT "group", -- public|group|private
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                target_minor     BIGINT UNSIGNED NULL,     -- integer minor units
                target_count     INT UNSIGNED NULL,
                starts_at        DATETIME     NULL,
                ends_at          DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|active|closed
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY causes_org_idx (organization_id, status),
                KEY causes_group_idx (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS payment_provider_configs (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                provider         VARCHAR(40)  NOT NULL,   -- stripe|mtn_momo|...
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                display_name     VARCHAR(120) NULL,
                currencies       JSON         NULL,        -- eligible currencies
                methods          JSON         NULL,        -- card|momo|...
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|disabled
                metadata         JSON         NULL,        -- NON-secret adapter metadata + schema version
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ppc_ver_uq (organization_id, provider, version),
                KEY ppc_group_idx (group_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS contribution_intents (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                cause_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NULL,
                provider_config_id CHAR(36)   NULL,
                provider         VARCHAR(40)  NULL,
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                recurrence       VARCHAR(16)  NOT NULL DEFAULT "once", -- once|monthly|...
                recognition      VARCHAR(16)  NOT NULL DEFAULT "public", -- public|group|anonymous|none
                idempotency_key  VARCHAR(191) NULL,
                checkout_ref     VARCHAR(191) NULL,        -- provider checkout/session id
                status           VARCHAR(20)  NOT NULL DEFAULT "intended", -- intended|pending|succeeded|failed|cancelled
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ci_idem_uq (idempotency_key),
                KEY ci_cause_idx (cause_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS contributions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                cause_id         CHAR(36)     NOT NULL,
                intent_id        CHAR(36)     NULL,
                user_id          CHAR(36)     NULL,
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                fee_minor        BIGINT UNSIGNED NOT NULL DEFAULT 0,
                net_minor        BIGINT UNSIGNED NULL,
                source           VARCHAR(16)  NOT NULL DEFAULT "online", -- online|manual
                recognition      VARCHAR(16)  NOT NULL DEFAULT "public",
                state            VARCHAR(20)  NOT NULL DEFAULT "pending", -- pending|succeeded|failed|refunded|reversed|disputed
                verified_at      DATETIME     NULL,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY con_cause_idx (cause_id, state),
                KEY con_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                contribution_id  CHAR(36)     NULL,
                intent_id        CHAR(36)     NULL,
                provider         VARCHAR(40)  NOT NULL,
                provider_txn_id  VARCHAR(191) NULL,
                type             VARCHAR(20)  NOT NULL,   -- charge|refund|fee|adjustment
                amount_minor     BIGINT       NOT NULL,   -- signed
                currency         CHAR(3)      NOT NULL,
                status           VARCHAR(20)  NOT NULL,
                raw_ref          VARCHAR(191) NULL,        -- secure reference to encrypted raw payload
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pt_provider_txn_uq (provider, provider_txn_id),
                KEY pt_contribution_idx (contribution_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- Append-only double-entry ledger -------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS journal_entries (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                cause_id         CHAR(36)     NULL,
                contribution_id  CHAR(36)     NULL,
                state            VARCHAR(20)  NOT NULL,   -- intended|pending|succeeded|failed|reversed|refunded|disputed|fee|adjustment
                currency         CHAR(3)      NOT NULL,
                memo             VARCHAR(255) NULL,
                source_ref       VARCHAR(191) NULL,        -- idempotency for posting
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY je_source_uq (organization_id, source_ref),
                KEY je_contribution_idx (contribution_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS journal_lines (
                id               CHAR(36)     NOT NULL,
                entry_id         CHAR(36)     NOT NULL,
                account          VARCHAR(60)  NOT NULL,   -- e.g. cause_funds, provider_clearing, fees
                direction        VARCHAR(6)   NOT NULL,   -- debit|credit
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY jl_entry_idx (entry_id),
                KEY jl_account_idx (account)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS manual_contribution_records (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                cause_id         CHAR(36)     NOT NULL,
                contribution_id  CHAR(36)     NULL,
                type             VARCHAR(20)  NOT NULL,   -- cash|cheque|bank_transfer|in_kind
                stated_value_minor BIGINT UNSIGNED NULL,
                currency         CHAR(3)      NULL,
                valuation_method VARCHAR(120) NULL,
                received_date    DATE         NULL,
                custodian_id     CHAR(36)     NULL,
                evidence_ref     VARCHAR(191) NULL,
                submitted_by     CHAR(36)     NULL,
                approved_by      CHAR(36)     NULL,        -- maker-checker: != submitted_by
                status           VARCHAR(20)  NOT NULL DEFAULT "submitted", -- submitted|approved|cleared|posted|rejected
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY mcr_cause_idx (cause_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS refund_requests (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                contribution_id  CHAR(36)     NOT NULL,
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                reason           VARCHAR(255) NULL,
                requested_by     CHAR(36)     NOT NULL,
                approved_by      CHAR(36)     NULL,        -- must differ from requested_by
                status           VARCHAR(20)  NOT NULL DEFAULT "requested", -- requested|approved|executed|rejected|failed
                provider_refund_id VARCHAR(191) NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY rr_contribution_idx (contribution_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS webhook_inbox (
                id               CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,
                provider_event_id VARCHAR(191) NOT NULL,
                event_type       VARCHAR(80)  NULL,
                verified         TINYINT(1)   NOT NULL DEFAULT 0,
                status           VARCHAR(20)  NOT NULL DEFAULT "received", -- received|processed|quarantined|failed
                payload_ref      VARCHAR(191) NULL,        -- encrypted/secure reference
                received_at      DATETIME(6)  NOT NULL,
                processed_at     DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY wi_event_uq (provider, provider_event_id),
                KEY wi_status_idx (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS reconciliation_cases (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,
                contribution_id  CHAR(36)     NULL,
                kind             VARCHAR(40)  NOT NULL,   -- missing_ledger|amount_mismatch|orphan_webhook|...
                detail           JSON         NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "open", -- open|resolved
                created_at       DATETIME     NOT NULL,
                resolved_at      DATETIME     NULL,
                PRIMARY KEY (id),
                KEY rc_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'reconciliation_cases', 'webhook_inbox', 'refund_requests', 'manual_contribution_records',
            'journal_lines', 'journal_entries', 'payment_transactions', 'contributions',
            'contribution_intents', 'payment_provider_configs', 'causes',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
