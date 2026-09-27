<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Opt-in cross-cut coverage on grants + rules (leadership-responsibility model).
 *
 * Cross-cutting groups are linked to the hierarchy via `group_crosscut_links`
 * (Groups migration 000055). Coverage flows DOWN-ONLY and is OPT-IN per grant:
 * a grant/rule only pulls in the cross-cut groups attached to the hierarchy
 * nodes it covers when `include_crosscut = 1`. Default 0 => existing grants are
 * unchanged.
 *
 * Idempotent: guarded ADD COLUMN.
 */
final class AddCrosscutScope extends Migration
{
    /** Grant tables + rules that gain the opt-in flag. */
    private const TABLES = [
        'role_assignments',
        'access_requests',
        'delegations',
        'break_glass_sessions',
        'rules',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('include_crosscut', $table)) {
                $this->db->query(
                    "ALTER TABLE {$table} ADD COLUMN include_crosscut TINYINT(1) NOT NULL DEFAULT 0 "
                    . 'AFTER scope_mode',
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
        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('include_crosscut', $table)) {
                $this->db->query("ALTER TABLE {$table} DROP COLUMN include_crosscut");
            }
        }
    }
}
