<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group lifecycle: archive / merge / dissolve with evidence (SRS FR-GRP-005).
 *
 * Mirrors the identity account-lifecycle model (FR-ID-009): the `groups.status`
 * column becomes a real state machine (active | archived | dissolved | merged)
 * and every transition demands a stated reason + optional evidence, recorded
 * append-only in `group_lifecycle_transitions` alongside an immutable audit-log
 * entry. `dissolved` and `merged` are TERMINAL. A merge additionally records the
 * surviving group in `merged_into_id`.
 *
 * Idempotent: widens the pre-existing `status` column and only adds columns /
 * the table if absent, so it is safe to re-run against a partially-applied DB.
 */
final class CreateGroupLifecycle extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        // Widen status (was VARCHAR(16)) and add the lifecycle bookkeeping the
        // state machine writes. Keep the default 'active' so existing rows are
        // untouched semantically.
        $this->db->query('ALTER TABLE `groups` MODIFY COLUMN status VARCHAR(24) NOT NULL DEFAULT "active"');

        foreach ([
            "ADD COLUMN status_reason     VARCHAR(255) NULL AFTER status",
            "ADD COLUMN status_changed_at DATETIME     NULL AFTER status_reason",
            "ADD COLUMN status_changed_by CHAR(36)     NULL AFTER status_changed_at",
            "ADD COLUMN archived_at       DATETIME     NULL AFTER status_changed_by",
            "ADD COLUMN dissolved_at      DATETIME     NULL AFTER archived_at",
            "ADD COLUMN merged_into_id    CHAR(36)     NULL AFTER dissolved_at",
        ] as $clause) {
            // Extract the column name (3rd token) to guard each add independently.
            $col = explode(' ', trim($clause))[2];
            if (! $this->columnExists('groups', $col)) {
                $this->db->query("ALTER TABLE `groups` {$clause}");
            }
        }

        // Append-only evidence trail: one row per lifecycle transition.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_lifecycle_transitions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                from_status      VARCHAR(24)  NULL,
                to_status        VARCHAR(24)  NOT NULL,
                reason           VARCHAR(255) NOT NULL,
                actor_id         CHAR(36)     NULL,
                approval_ref     VARCHAR(191) NULL,
                merged_into_id   CHAR(36)     NULL,   -- surviving group on a merge
                evidence         JSON         NULL,   -- arbitrary supporting evidence
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY glt_group_idx (organization_id, group_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $this->db->query('DROP TABLE IF EXISTS group_lifecycle_transitions');

        foreach (['merged_into_id', 'dissolved_at', 'archived_at', 'status_changed_by', 'status_changed_at', 'status_reason'] as $col) {
            if ($this->columnExists('groups', $col)) {
                $this->db->query("ALTER TABLE `groups` DROP COLUMN {$col}");
            }
        }

        $this->db->query('ALTER TABLE `groups` MODIFY COLUMN status VARCHAR(16) NOT NULL DEFAULT "active"');
    }

    private function columnExists(string $table, string $column): bool
    {
        return $this->db->fieldExists($column, $table);
    }
}
