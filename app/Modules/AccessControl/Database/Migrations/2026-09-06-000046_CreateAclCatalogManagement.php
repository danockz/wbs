<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Management metadata for the RBAC role catalogue and ABAC policy catalogue
 * (SRS FR-ACL-002/003 administrative CRUD).
 *
 *  - roles gains `description`, `is_system` and `updated_at`. `is_system` marks
 *    the seeded/built-in roles (org_admin, finance, …) so the management CRUD
 *    can refuse to delete or re-code them — a safety rail equivalent to the
 *    "wildcards need extra controls" stance in FR-ACL-002.
 *
 *  - abac_policies gains `description` and `updated_at`. The condition tree,
 *    effect, action pattern, priority and enabled flag already exist from
 *    migration 000006; these two fields make the row self-documenting and let
 *    the CRUD surface show when a policy last changed.
 *
 * All additions are nullable or defaulted so existing rows and the PDP keep
 * resolving unchanged.
 */
final class CreateAclCatalogManagement extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE roles
            ADD COLUMN description VARCHAR(255) NULL AFTER name,
            ADD COLUMN is_system   TINYINT(1)   NOT NULL DEFAULT 0 AFTER description,
            ADD COLUMN updated_at  DATETIME     NULL AFTER created_at');

        $this->db->query('ALTER TABLE abac_policies
            ADD COLUMN description VARCHAR(255) NULL AFTER code,
            ADD COLUMN updated_at  DATETIME     NULL AFTER created_at');
    }

    public function down(): void
    {
        try {
            $this->db->query('ALTER TABLE roles
                DROP COLUMN description,
                DROP COLUMN is_system,
                DROP COLUMN updated_at');
        } catch (\Throwable) {
            // best-effort down; column set may already be absent
        }

        try {
            $this->db->query('ALTER TABLE abac_policies
                DROP COLUMN description,
                DROP COLUMN updated_at');
        } catch (\Throwable) {
            // best-effort down
        }
    }
}
