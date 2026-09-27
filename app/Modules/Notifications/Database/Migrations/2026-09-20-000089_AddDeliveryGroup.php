<?php

declare(strict_types=1);

namespace WBS\Notifications\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Which body a delivery belongs to (per-group notification credentials).
 *
 * Credentials are per hierarchical group, so a delivery has to carry the group
 * whose credentials it sends on — otherwise the dispatcher can only reach for
 * one org-wide identity. `notification_deliveries` had `campaign_id` (campaigns
 * have a group) but nothing for the transactional majority of sends.
 *
 * `group_id` is stamped at send() time and never rewritten afterwards, exactly
 * like `recipient_snapshot`: the body that pays for and brands a message is
 * decided when the message is composed, not inferred later when the queue gets
 * to it. Resolution order in `NotificationService::send()`:
 *
 *   1. an explicit `group_id` in the send options (a cell texting its own
 *      members, a leader acting for a specific body);
 *   2. the campaign's group, for campaign sends;
 *   3. the recipient's own primary membership group
 *      (`GroupScopeResolver::primaryMembershipGroup`) — the same membership
 *      fallback contribution attribution uses, so a person's messages belong to
 *      the body they actually sit in;
 *   4. NULL, which the credential resolver reads as "no body provided
 *      credentials for this" and refuses to send (fail-closed).
 *
 * Idempotent: add-if-absent column, safe to re-run.
 */
final class AddDeliveryGroup extends Migration
{
    public function up(): void
    {
        // Raw DDL doesn't invalidate CI4's connection-lifetime schema cache.
        $this->db->resetDataCache();

        if ($this->db->tableExists('notification_deliveries')
            && ! $this->db->fieldExists('group_id', 'notification_deliveries')) {
            $this->db->query('ALTER TABLE `notification_deliveries` ADD COLUMN `group_id` CHAR(36) NULL AFTER `organization_id`');
            $this->db->query('ALTER TABLE `notification_deliveries` ADD KEY `nd_group_idx` (`organization_id`, `group_id`, `channel`, `status`)');
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('notification_deliveries')) {
            $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('notification_deliveries'));
            if (in_array('nd_group_idx', $existing, true)) {
                $this->db->query('ALTER TABLE `notification_deliveries` DROP INDEX `nd_group_idx`');
            }
            if ($this->db->fieldExists('group_id', 'notification_deliveries')) {
                $this->db->query('ALTER TABLE `notification_deliveries` DROP COLUMN `group_id`');
            }
        }

        $this->db->resetDataCache();
    }
}
