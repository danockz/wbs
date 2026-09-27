<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Public self-join policy for groups.
 *
 * Split out from 000047 (which was already applied): a group's `/g/{slug}/join`
 * flow admits members immediately when join_policy = 'open', otherwise the
 * membership starts pending until a leader approves it (join_policy = 'approval',
 * the safe default). Idempotent so it is safe to re-run.
 */
final class AddGroupJoinPolicy extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('join_policy', 'groups')) {
            $this->db->query(
                "ALTER TABLE `groups` ADD COLUMN `join_policy` VARCHAR(16) NOT NULL DEFAULT 'approval'"
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if ($this->db->fieldExists('join_policy', 'groups')) {
            $this->db->query('ALTER TABLE `groups` DROP COLUMN `join_policy`');
        }
    }
}
