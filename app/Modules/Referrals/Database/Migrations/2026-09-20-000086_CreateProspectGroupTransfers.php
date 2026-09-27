<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Provenance for a prospect's group placement (onboarding model).
 *
 * A prospect does NOT choose a group: they are placed in the group of the
 * mentor/sponsor who owns them, and they MOVE when a different mentor follows
 * them up after a configurable period of inactivity. Both the initial placement
 * and every move are recorded here so the birds-eye downline can answer "why is
 * this person in this group?" without replaying follow-up history.
 *
 * Append-only: a transfer never rewrites the previous row, it adds one. The
 * live pointers stay on `prospects.owner_user_id` / `prospects.assigned_group_id`
 * and (once the prospect has a platform account) on `group_members`; this table
 * is the audit trail tying those two states together with the trigger that
 * caused the change and the inactivity threshold in force at the time.
 *
 * No hard cross-module FKs (logical-ref + service-guard style, as elsewhere).
 */
final class CreateProspectGroupTransfers extends Migration
{
    public function up(): void
    {
        // Raw CREATE doesn't invalidate CI4's connection-lifetime schema cache.
        $this->db->resetDataCache();

        if ($this->db->tableExists('prospect_group_transfers')) {
            return;
        }

        $this->db->query(<<<'SQL'
            CREATE TABLE `prospect_group_transfers` (
                `id`                 CHAR(36)     NOT NULL,
                `organization_id`    CHAR(36)     NOT NULL,
                `prospect_id`        CHAR(36)     NOT NULL,
                `linked_user_id`     CHAR(36)     NULL,

                `from_group_id`      CHAR(36)     NULL,
                `to_group_id`        CHAR(36)     NULL,
                `from_owner_user_id` CHAR(36)     NULL,
                `to_owner_user_id`   CHAR(36)     NOT NULL,

                `reason`             VARCHAR(40)  NOT NULL DEFAULT 'inactivity_transfer',
                `trigger_type`       VARCHAR(24)  NULL,
                `trigger_id`         CHAR(36)     NULL,

                `threshold_weeks`    INT UNSIGNED NULL,
                `days_inactive`      INT UNSIGNED NULL,

                `previous_membership_id` CHAR(36) NULL,
                `membership_id`          CHAR(36) NULL,
                `sponsorship_id`         CHAR(36) NULL,

                `note`               VARCHAR(255) NULL,
                `created_by`         CHAR(36)     NULL,
                `created_at`         DATETIME     NOT NULL,

                PRIMARY KEY (`id`),
                KEY `pgt_org_prospect_idx` (`organization_id`, `prospect_id`, `created_at`),
                KEY `pgt_org_to_group_idx` (`organization_id`, `to_group_id`),
                KEY `pgt_org_from_owner_idx` (`organization_id`, `from_owner_user_id`),
                KEY `pgt_org_created_idx` (`organization_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            SQL);

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        $this->db->query('DROP TABLE IF EXISTS `prospect_group_transfers`');
        $this->db->resetDataCache();
    }
}
