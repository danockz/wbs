<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Single-organization tenancy anchor (SRS: single organization, no multi-tenant).
 * Every scoped record carries organization_id for uniform queries and future
 * portability, even though only one active organization exists.
 */
final class CreateOrganizations extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS organizations (
                id               CHAR(36)     NOT NULL,
                name             VARCHAR(200) NOT NULL,
                slug             VARCHAR(200) NOT NULL,
                timezone         VARCHAR(64)  NOT NULL DEFAULT "UTC",
                default_locale   VARCHAR(12)  NOT NULL DEFAULT "en",
                max_group_depth  TINYINT UNSIGNED NOT NULL DEFAULT 9,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY org_slug_uq (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS organizations');
    }
}
