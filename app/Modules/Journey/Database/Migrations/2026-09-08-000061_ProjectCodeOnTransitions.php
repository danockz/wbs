<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Project attribution on journey moves.
 *
 * Adds a nullable `project_code` to the two journey-move tables so that a
 * transition (and a pending proposal for one) can carry the "project" the
 * originating activity belonged to — the SAME first-class, cross-cutting
 * `project_code` dimension the gamification ledger already uses (e.g. a giving
 * cause, an outreach project). This lets:
 *
 *   - membership rules gate on project (already exposed as a signal attribute),
 *   - the resulting Option-D disciple-making award be tagged with the project so
 *     it appears on project boards / rollups, and
 *   - the immutable transition trail record which project drove the advance.
 *
 * project_code is intrinsic for CONTRIBUTIONS (= cause_id) and otherwise flows
 * only when a caller explicitly supplies one — there is no automatic campaign
 * resolution here. NULL = not project-attributed (the existing default);
 * behaviour is unchanged until a code is supplied. Advisory metadata only: it
 * never gates a move.
 *
 * Idempotent: guarded ADD COLUMN (MySQL has no ADD COLUMN IF NOT EXISTS).
 */
final class ProjectCodeOnTransitions extends Migration
{
    /** table => column position anchor */
    private const TARGETS = [
        'member_journey_transitions' => 'evidence_ref',
        'journey_stage_proposals'    => 'evidence_ref',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach (self::TARGETS as $table => $after) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('project_code', $table)) {
                $anchor = $this->db->fieldExists($after, $table) ? " AFTER {$after}" : '';
                $this->db->query(
                    "ALTER TABLE {$table} ADD COLUMN project_code VARCHAR(64) NULL{$anchor}",
                );
            }
            // A light index so project-scoped reads of the trail stay cheap.
            if ($table === 'member_journey_transitions' && ! $this->indexExists($table, 'mjt_project_idx')) {
                $this->db->query(
                    "ALTER TABLE {$table} ADD KEY mjt_project_idx (organization_id, project_code, created_at)",
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
        foreach (array_keys(self::TARGETS) as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if ($table === 'member_journey_transitions' && $this->indexExists($table, 'mjt_project_idx')) {
                $this->db->query("ALTER TABLE {$table} DROP KEY mjt_project_idx");
            }
            if ($this->db->fieldExists('project_code', $table)) {
                $this->db->query("ALTER TABLE {$table} DROP COLUMN project_code");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach ($this->db->getIndexData($table) as $meta) {
            if (($meta->name ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
}
