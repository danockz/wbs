<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group classification taxonomy — the "kind" axis (leadership-responsibility
 * model, option B: PLACEMENT vs KIND).
 *
 * The group hierarchy already answers WHERE a group sits (parent tree +
 * group_closure) and cross-cut links answer WHICH branches an orthogonal group
 * spans. Neither says WHAT KIND of group it is. `groups.type` was a free-form
 * string; this migration introduces a small, org-configurable taxonomy so a
 * group can be classified as a department, an activity team, a ministry, a
 * committee, etc. — independently of where it is placed. A department can then be
 * either nested OR cross-cutting without overloading a single field.
 *
 * `group_kinds` is the configurable catalog (org-scoped, most-specific catalogs
 * pattern). `groups.kind_code` references a kind's code (nullable, so existing
 * rows are unaffected). Internal roles like management / staff / volunteer are
 * NOT modelled here — they remain the existing group_members.role /
 * membership_type fields, by decision.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + guarded ADD COLUMN.
 */
final class CreateGroupKinds extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_kinds (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(50)  NOT NULL,
                name             VARCHAR(100) NOT NULL,
                description      VARCHAR(255) NULL,
                -- Advisory default placement for this kind: does it usually sit in
                -- the tree, cut across it, or either? Purely descriptive — it does
                -- NOT change scope evaluation (scope stays uniform per decision).
                default_placement VARCHAR(16) NOT NULL DEFAULT "either", -- nested|crosscut|either
                icon             VARCHAR(120) NULL,
                color            VARCHAR(20)  NULL,
                sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|inactive
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gk_code_uq (organization_id, code),
                KEY gk_status_idx (organization_id, status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        if ($this->db->tableExists('groups') && ! $this->db->fieldExists('kind_code', 'groups')) {
            $this->db->query('ALTER TABLE `groups` ADD COLUMN kind_code VARCHAR(50) NULL AFTER type');
            $this->db->query('CREATE INDEX groups_kind_idx ON `groups` (organization_id, kind_code)');
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if ($this->db->tableExists('groups') && $this->db->fieldExists('kind_code', 'groups')) {
            try {
                $this->db->query('DROP INDEX groups_kind_idx ON `groups`');
            } catch (\Throwable) {
                // index may already be gone
            }
            $this->db->query('ALTER TABLE `groups` DROP COLUMN kind_code');
        }
        $this->db->query('DROP TABLE IF EXISTS group_kinds');
    }
}
