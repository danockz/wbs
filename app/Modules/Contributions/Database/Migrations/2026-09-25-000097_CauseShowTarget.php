<?php

declare(strict_types=1);

namespace WBS\Contributions\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-cause toggle: show or hide fundraising goal numbers (amount, donor count,
 * % thermometer) on anonymous/public surfaces. The cause stays listed and still
 * receives gifts. Signed-in members and contribution.manage always see the goal.
 * Default ON (visible) so existing causes do not change behaviour.
 */
final class CauseShowTarget extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('causes') && ! $this->db->fieldExists('show_target', 'causes')) {
            $this->db->query('ALTER TABLE causes ADD COLUMN show_target TINYINT(1) NOT NULL DEFAULT 1 AFTER target_count');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('causes') && $this->db->fieldExists('show_target', 'causes')) {
            $this->db->query('ALTER TABLE causes DROP COLUMN show_target');
        }
    }
}
