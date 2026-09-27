<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Custom-adapter SDK registration + certification (SRS FR-INT-013).
 *
 * When a provider's behaviour cannot be expressed as a no-code connector profile,
 * it is onboarded as a VERSIONED, SIGNED, REVIEWED custom-adapter module. This
 * table is the reviewable registration record for such a module: its declared
 * manifest, the SDK contract-test report (conformance evidence), the manifest
 * signature, and a certification lifecycle mirroring connector_profiles.
 *
 * Registering/certifying an adapter NEVER changes core business modules or any
 * group's configuration workflow — on activation the manifest is published into
 * the existing `provider_adapter_catalog` (type='custom_adapter'), so the rest of
 * the platform (catalogue UI, fallback matrix, connections) consumes it exactly
 * like a built-in adapter.
 *
 * Append-only decision trail in `custom_adapter_reviews`. Idempotent.
 */
final class CreateCustomAdapters extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS custom_adapters (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                code               VARCHAR(80)  NOT NULL,   -- adapter machine code, e.g. acme_pay_v1
                version            INT UNSIGNED NOT NULL DEFAULT 1,
                category           VARCHAR(40)  NOT NULL,
                family             VARCHAR(40)  NOT NULL,
                display_name       VARCHAR(150) NOT NULL,
                impl_class         VARCHAR(255) NULL,        -- FQCN of the shipped, reviewed adapter class
                manifest           JSON         NOT NULL,    -- the signed manifest (declared capabilities, hosts, config)
                capabilities       JSON         NOT NULL,    -- denormalized for querying
                signature          VARCHAR(255) NULL,        -- ManifestSigner output (v1:keyId:mac)
                contract_report    JSON         NULL,        -- ContractTestSuite evidence
                certified          TINYINT(1)   NOT NULL DEFAULT 0,
                status             VARCHAR(24)  NOT NULL DEFAULT "draft", -- draft|contract_tested|security_review|approved|active|deprecated|revoked
                submitted_by       CHAR(36)     NULL,
                decided_by         CHAR(36)     NULL,
                decided_at         DATETIME(6)  NULL,
                catalog_id         CHAR(36)     NULL,        -- provider_adapter_catalog row published on activation
                created_at         DATETIME(6)  NOT NULL,
                updated_at         DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ca_code_ver_uq (organization_id, code, version),
                KEY ca_status_idx (organization_id, status),
                KEY ca_category_idx (organization_id, category, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS custom_adapter_reviews (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                adapter_id       CHAR(36)     NOT NULL,
                action           VARCHAR(24)  NOT NULL,   -- register|contract_test|advance|approve|activate|revoke
                from_status      VARCHAR(24)  NULL,
                to_status        VARCHAR(24)  NULL,
                actor_id         CHAR(36)     NULL,
                note             VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY car_adapter_idx (adapter_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS custom_adapter_reviews');
        $this->db->query('DROP TABLE IF EXISTS custom_adapters');
    }
}
