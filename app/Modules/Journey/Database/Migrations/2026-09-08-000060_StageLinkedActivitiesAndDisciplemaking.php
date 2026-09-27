<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Option D — stage-linked activities + disciple-making attribution.
 *
 * Two additive, backward-compatible changes. Nothing here changes existing
 * behaviour until an org opts in.
 *
 * 1. STAGE LINKING. Adds a nullable `stage_code` to the three configurable
 *    activity tables so an activity category, an earning rule, or a follow-up
 *    type can optionally declare which journey stage it belongs to. This lets a
 *    dashboard show "Build activities for a New Believer" and lets rules/reads
 *    filter by stage. NULL = not stage-specific (the existing default). The
 *    column is advisory metadata: it never gates awarding.
 *
 * 2. DISCIPLE-MAKING ATTRIBUTION uses the EXISTING gamification stack — no new
 *    ledger. When a journey transition ADVANCES a member and names a
 *    `discipler_id`, JourneyAttributionService awards the discipler through the
 *    normal PointsEngine using a configurable rule code. The gamification_config
 *    defaults (feature flag + rule code) and the award rule itself are created by
 *    JourneyGamificationSeeder via the normal ConfigService/RuleService paths, so
 *    they are fully editable like any other activity. This migration therefore
 *    only adds the stage_code columns.
 *
 * Idempotent: guarded ADD COLUMN (MySQL has no ADD COLUMN IF NOT EXISTS).
 */
final class StageLinkedActivitiesAndDisciplemaking extends Migration
{
    /** table => column position anchor */
    private const STAGE_TARGETS = [
        'activity_categories' => 'phase',
        'gamification_rules'  => 'phase',
        'follow_up_types'     => 'phase',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach (self::STAGE_TARGETS as $table => $after) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('stage_code', $table)) {
                $anchor = $this->db->fieldExists($after, $table) ? " AFTER {$after}" : '';
                $this->db->query(
                    "ALTER TABLE {$table} ADD COLUMN stage_code VARCHAR(60) NULL{$anchor}",
                );
            }
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach (array_keys(self::STAGE_TARGETS) as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('stage_code', $table)) {
                $this->db->query("ALTER TABLE {$table} DROP COLUMN stage_code");
            }
        }
    }
}
