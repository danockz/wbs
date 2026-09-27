<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Badge catalog CRUD support.
 *
 * The original `badges` table (migration 000012) could only be SEEDED — it had
 * no lifecycle columns, so it could not participate in the platform's
 * soft-delete-via-`status` convention, nor in hierarchical group inheritance the
 * way ranks / achievements / streaks do (those gained group_id +
 * include_descendants in 000042; badges already had group_id but nothing else).
 *
 * This migration makes badges a first-class, runtime-manageable catalog:
 *   - `status` (active|inactive) for soft-delete/disable,
 *   - `include_descendants` so an ancestor group's badge is inheritable,
 *   - `description`, `icon`, `sort_order` for admin/console display,
 *   - `updated_at` to track edits,
 *   - the UNIQUE key becomes (organization_id, group_id, code) so a subgroup can
 *     override an org-wide badge code — matching rank/achievement/streak scoping
 *     (MySQL treats multiple NULL group_ids as distinct, so the org-wide row
 *     stays unique per code).
 *
 * All changes are additive/backward-compatible: new columns are nullable or
 * defaulted, and existing seeded badges keep working (status defaults active).
 */
final class BadgeCatalogCrud extends Migration
{
    public function up(): void
    {
        $this->db->query('
            ALTER TABLE badges
                ADD COLUMN include_descendants TINYINT(1)   NOT NULL DEFAULT 1   AFTER group_id,
                ADD COLUMN description         VARCHAR(255)  NULL                 AFTER name,
                ADD COLUMN icon                VARCHAR(120)  NULL                 AFTER description,
                ADD COLUMN sort_order          INT           NOT NULL DEFAULT 0   AFTER icon,
                ADD COLUMN status              VARCHAR(16)   NOT NULL DEFAULT "active" AFTER permanent,
                ADD COLUMN updated_at          DATETIME      NULL                 AFTER created_at
        ');

        // Make the badge code unique PER GROUP (org-wide row keeps its own slot).
        $this->db->query('ALTER TABLE badges DROP INDEX badge_code_uq, ADD UNIQUE KEY badge_code_uq (organization_id, group_id, code)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE badges DROP INDEX badge_code_uq, ADD UNIQUE KEY badge_code_uq (organization_id, code)');
        $this->db->query('
            ALTER TABLE badges
                DROP COLUMN include_descendants,
                DROP COLUMN description,
                DROP COLUMN icon,
                DROP COLUMN sort_order,
                DROP COLUMN status,
                DROP COLUMN updated_at
        ');
    }
}
