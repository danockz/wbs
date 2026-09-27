<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * UPAF: adapter catalogue, connector profiles, connections (SRS FR-INT-*).
 *
 *  - provider_adapter_catalog: code-owned adapters (versioned) with declared
 *    capabilities. connector_profiles: NO-CODE declarative profiles that are
 *    validated configuration data — never executable code.
 *  - integration_connections: DB-stored instances (group-scoped) with encrypted
 *    credential references; lifecycle draft->tested->pending_approval->active->
 *    disabled/revoked; payment/notification actions require approval (SoD).
 *  - capability_grants: ancestor->descendant time-bound, capability-limited
 *    reuse without exposing secrets.
 *  - provider_events: inbound provider callbacks, globally unique (provider,
 *    event_id) for idempotent processing.
 */
final class CreateIntegrations extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS provider_adapter_catalog (
                id               CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,   -- e.g. stripe_v1
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                category         VARCHAR(40)  NOT NULL,   -- payment|notification|social_oidc|stream|meeting|learning|geocoding
                family           VARCHAR(40)  NOT NULL,   -- UPAF protocol family
                display_name     VARCHAR(150) NOT NULL,
                capabilities     JSON         NOT NULL,   -- declared canonical ops
                credential_fields JSON        NULL,
                config_schema    JSON         NULL,
                countries        JSON         NULL,
                currencies       JSON         NULL,
                channels         JSON         NULL,
                webhook_verify   VARCHAR(40)  NULL,       -- hmac_sha256|jws|none
                type             VARCHAR(16)  NOT NULL DEFAULT "adapter", -- adapter|profile
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|deprecated|revoked
                docs_ref         VARCHAR(255) NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pac_code_ver_uq (code, version),
                KEY pac_category_idx (category, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS connector_profiles (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                family           VARCHAR(40)  NOT NULL,   -- must be an approved UPAF family
                canonical_op     VARCHAR(40)  NOT NULL,   -- one canonical operation
                http_method      VARCHAR(8)   NULL,       -- GET|POST (allowlisted)
                approved_host    VARCHAR(191) NOT NULL,   -- TLS-only pre-approved host
                request_mapping  JSON         NULL,
                response_mapping JSON         NULL,        -- bounded JSONPath-style
                status_mapping   JSON         NULL,
                signature_algo   VARCHAR(40)  NULL,        -- finite reviewed set
                owner_id         CHAR(36)     NULL,        -- named integration owner
                status           VARCHAR(20)  NOT NULL DEFAULT "draft", -- draft|sandbox_verified|security_review|finance_review|approved|active|deprecated|revoked
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cp_code_ver_uq (organization_id, code, version),
                KEY cp_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS integration_connections (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                adapter_code     VARCHAR(80)  NOT NULL,
                adapter_version  INT UNSIGNED NOT NULL,
                category         VARCHAR(40)  NOT NULL,
                display_name     VARCHAR(150) NULL,
                allowed_capabilities JSON     NULL,
                countries        JSON         NULL,
                currencies       JSON         NULL,
                channels         JSON         NULL,
                settings         JSON         NULL,        -- NON-secret config only
                sender_identity  VARCHAR(191) NULL,
                status           VARCHAR(20)  NOT NULL DEFAULT "draft", -- draft|tested|pending_approval|active|disabled|revoked|failed|expired
                requested_by     CHAR(36)     NULL,
                approved_by      CHAR(36)     NULL,        -- != requested_by for payment/notification
                tested_at        DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY ic_group_idx (organization_id, group_id, status),
                KEY ic_adapter_idx (adapter_code, adapter_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Encrypted, WRITE-ONLY credential slots. Never returned to UI/API.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS connection_credentials (
                id               CHAR(36)     NOT NULL,
                connection_id    CHAR(36)     NOT NULL,
                slot             VARCHAR(60)  NOT NULL,   -- api_key|secret|merchant_id|...
                cipher           TEXT         NOT NULL,   -- SecretBox "keyId:base64", aad-bound to connection+slot
                fingerprint      CHAR(16)     NULL,        -- non-reversible display hint
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cc_slot_uq (connection_id, slot, version),
                KEY cc_conn_idx (connection_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS capability_grants (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                connection_id    CHAR(36)     NOT NULL,   -- ancestor-owned connection
                grantee_group_id CHAR(36)     NOT NULL,   -- descendant
                capability       VARCHAR(60)  NOT NULL,   -- e.g. event_reminder.send
                constraints      JSON         NULL,        -- cause/currency/monthly cap
                starts_at        DATETIME     NOT NULL,
                expires_at       DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|revoked|expired
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY cg_grantee_idx (grantee_group_id, capability, status),
                KEY cg_conn_idx (connection_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS provider_connection_tests (
                id               CHAR(36)     NOT NULL,
                connection_id    CHAR(36)     NOT NULL,
                operation        VARCHAR(40)  NOT NULL,
                sandbox          TINYINT(1)   NOT NULL DEFAULT 1,
                outcome          VARCHAR(16)  NOT NULL,   -- pass|fail
                detail           JSON         NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY pct_conn_idx (connection_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Inbound provider callbacks — globally unique per (provider, event_id).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS provider_events (
                id               CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,
                event_id         VARCHAR(191) NOT NULL,
                connection_id    CHAR(36)     NULL,
                event_type       VARCHAR(80)  NULL,
                verified         TINYINT(1)   NOT NULL DEFAULT 0,
                status           VARCHAR(20)  NOT NULL DEFAULT "received",
                received_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pe_event_uq (provider, event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'provider_events', 'provider_connection_tests', 'capability_grants',
            'connection_credentials', 'integration_connections', 'connector_profiles',
            'provider_adapter_catalog',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
