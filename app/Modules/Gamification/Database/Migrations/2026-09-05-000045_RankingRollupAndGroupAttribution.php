<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ranking / group-attribution / ancestor roll-up model (design doc Part B.3).
 *
 * Today `point_ledger` records only WHO earned points (subject) and under WHICH
 * rule — it carries no notion of WHICH GROUP the credit belongs to, so nothing
 * downstream can rank a group, a group's ancestors, or cross-cutting activity /
 * project / phase dimensions. This migration adds that attribution dimension and
 * a derived, rebuildable roll-up cache for fast ranking. It is EXTENSIVE of the
 * existing engine (no parallel award tables).
 *
 * All ledger changes are additive and backward compatible — every new column is
 * nullable, so historical rows keep working (they read as org-level / untagged)
 * until backfilled by `gamification:rebuild-rollup`.
 *
 *   1. point_ledger gains group_id (EXACT credited group — receiving group, else
 *      membership fallback, else NULL org-level), plus cross-cutting
 *      category_code / project_code / phase, and matching indexes. The idempotency
 *      UNIQUE key is widened to include group_id so a multi-group check-in can
 *      post one entry per credited group (group_credit_mode=per_group).
 *   2. group_point_rollup — a pure function of (point_ledger + group_closure):
 *      one row per (org, season, group, category, project, phase). A contribution
 *      tagged group L rolls up to L AND every ancestor of L. category/project/
 *      phase use a '*' sentinel for the "all-combined" rows (a NULLable column
 *      cannot sit in a composite PRIMARY KEY under MySQL).
 *   3. Config flags for the settled decisions: group_campaigns.rollup_awards
 *      (ancestor award minting is OPT-IN) and gamification_rules.group_credit_mode
 *      (per_group | individual_once for multi-group check-ins).
 */
final class RankingRollupAndGroupAttribution extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        // ---- 1. Attribute each ledger entry to the acted-on group -----------
        // group_id      = EXACT credited group (event/cause group), or the
        //                 member's own group via fallback, or NULL (org-level).
        // category_code / project_code / phase = cross-cutting dimensions that
        //                 power activity / project / phase boards without joins.
        // amount_minor carries the raw economic volume of the activity (e.g. a
        // contribution amount in minor units) so the "volume" ranking measure is
        // derivable from the ledger alone — keeping group_point_rollup a pure,
        // rebuildable function of (point_ledger + group_closure).
        $this->db->query('
            ALTER TABLE point_ledger
                ADD COLUMN group_id      CHAR(36)    NULL AFTER subject_type,
                ADD COLUMN category_code VARCHAR(64) NULL AFTER group_id,
                ADD COLUMN project_code  VARCHAR(64) NULL AFTER category_code,
                ADD COLUMN phase         VARCHAR(8)  NULL AFTER project_code,
                ADD COLUMN amount_minor  BIGINT      NOT NULL DEFAULT 0 AFTER points
        ');
        $this->db->query('ALTER TABLE point_ledger ADD KEY pl_group_idx    (organization_id, season_id, group_id)');
        $this->db->query('ALTER TABLE point_ledger ADD KEY pl_category_idx (organization_id, season_id, category_code)');
        $this->db->query('ALTER TABLE point_ledger ADD KEY pl_project_idx  (organization_id, season_id, project_code)');

        // Widen the idempotency guard so multi-group check-ins (one ledger entry
        // per credited group) do not collide. The old key was
        // (rule_id, subject_id, source_ref, entry_type).
        $this->db->query('ALTER TABLE point_ledger DROP INDEX pl_idem_uq');
        $this->db->query('
            ALTER TABLE point_ledger
                ADD UNIQUE KEY pl_idem_uq (rule_id, subject_id, source_ref, entry_type, group_id)
        ');

        // ---- 2. Derived roll-up cache (rebuildable) -------------------------
        // One row per (org, season, group, category, project, phase). Rows where
        // a dimension = '*' are the "all combined" totals for that dimension.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_point_rollup (
                organization_id CHAR(36)    NOT NULL,
                season_id       CHAR(36)    NOT NULL,
                group_id        CHAR(36)    NOT NULL,   -- ancestor-or-self of some ledger group_id
                category_code   VARCHAR(64) NOT NULL DEFAULT "*",  -- "*" = all categories
                project_code    VARCHAR(64) NOT NULL DEFAULT "*",  -- "*" = all projects
                phase           VARCHAR(8)  NOT NULL DEFAULT "*",  -- "*" = all phases
                points          BIGINT      NOT NULL DEFAULT 0,    -- measure: points
                volume_minor    BIGINT      NOT NULL DEFAULT 0,    -- measure: raw amount/volume (minor units)
                contributions   INT         NOT NULL DEFAULT 0,    -- measure: event/contribution count
                updated_at      DATETIME(6) NOT NULL,
                PRIMARY KEY (organization_id, season_id, group_id, category_code, project_code, phase),
                KEY gpr_rank_idx (organization_id, season_id, category_code, project_code, phase, points)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- 3. Config flags for the settled decisions ----------------------
        // (iii) ancestor award minting is OPT-IN. rollup_to_general (already
        // present) is a different axis (counting to the general season).
        if (! $this->columnExists('group_campaigns', 'rollup_awards')) {
            $this->db->query('
                ALTER TABLE group_campaigns
                    ADD COLUMN rollup_awards TINYINT(1) NOT NULL DEFAULT 0 AFTER rollup_to_general
            ');
        }

        // (iv) multi-group check-in credit mode: per_group | individual_once.
        if (! $this->columnExists('gamification_rules', 'group_credit_mode')) {
            $this->db->query('
                ALTER TABLE gamification_rules
                    ADD COLUMN group_credit_mode VARCHAR(16) NOT NULL DEFAULT "per_group" AFTER group_id
            ');
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $this->db->query('DROP TABLE IF EXISTS group_point_rollup');

        if ($this->columnExists('gamification_rules', 'group_credit_mode')) {
            $this->db->query('ALTER TABLE gamification_rules DROP COLUMN group_credit_mode');
        }
        if ($this->columnExists('group_campaigns', 'rollup_awards')) {
            $this->db->query('ALTER TABLE group_campaigns DROP COLUMN rollup_awards');
        }

        // Restore the original idempotency key, then drop the attribution columns.
        $this->db->query('ALTER TABLE point_ledger DROP INDEX pl_idem_uq');
        $this->db->query('ALTER TABLE point_ledger ADD UNIQUE KEY pl_idem_uq (rule_id, subject_id, source_ref, entry_type)');

        foreach (['pl_group_idx', 'pl_category_idx', 'pl_project_idx'] as $idx) {
            $this->db->query("ALTER TABLE point_ledger DROP INDEX {$idx}");
        }
        $this->db->query('
            ALTER TABLE point_ledger
                DROP COLUMN amount_minor,
                DROP COLUMN phase,
                DROP COLUMN project_code,
                DROP COLUMN category_code,
                DROP COLUMN group_id
        ');
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->db->fieldExists($column, $table);
    }
}
