<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add the missing `include_descendants` flag to `gamification_rules`.
 *
 * The three configurable activity catalogs are meant to share ONE group-scope
 * model — a group's own row wins, then an ANCESTOR row that opts in with
 * `include_descendants = 1`, then the org-wide (NULL group) default. Migration
 * 000043 (ConfigurableWbsActivities) added `include_descendants` to
 * `activity_categories` and `follow_up_types`, but `gamification_rules` — the
 * earning-activity catalog — was left without it.
 *
 * That gap is a latent crash: JourneyRecommendationService (and any reader that
 * inherits ancestor rows) filters `gamification_rules` with
 * `WHERE include_descendants = 1` for a member whose group has ancestors, which
 * fails with "Unknown column" on MySQL. This adds the column so earning
 * activities inherit down the hierarchy exactly like categories and follow-up
 * types.
 *
 * Additive and backward-compatible: NULLable-with-default, existing rows keep
 * working (default 1 = inheritable, matching the sibling catalogs' default).
 * Idempotent (add-if-absent); MySQL has no ADD COLUMN IF NOT EXISTS.
 */
final class AddIncludeDescendantsToGamificationRules extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('gamification_rules')) {
            return;
        }
        if (! $this->db->fieldExists('include_descendants', 'gamification_rules')) {
            // Mirror the sibling catalogs (activity_categories / follow_up_types):
            // TINYINT(1) NOT NULL DEFAULT 1. Placed after group_id where possible.
            $anchor = $this->db->fieldExists('group_id', 'gamification_rules') ? ' AFTER group_id' : '';
            $this->db->query(
                'ALTER TABLE gamification_rules ADD COLUMN include_descendants TINYINT(1) NOT NULL DEFAULT 1' . $anchor,
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if ($this->db->tableExists('gamification_rules')
            && $this->db->fieldExists('include_descendants', 'gamification_rules')) {
            $this->db->query('ALTER TABLE gamification_rules DROP COLUMN include_descendants');
        }
    }
}
