<?php

declare(strict_types=1);

namespace WBS\Notifications\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Notification templates gain an audience dimension so a row can target a
 * platform role or a group_members.role (or neither = base body). Combined with
 * the existing nullable group_id + locale, resolution is:
 *   group+role → group base → org+role → org base, then recipient locale → org
 *   default locale → English.
 *
 * Uniqueness of a lineage is enforced in NotificationTemplateService (MySQL
 * UNIQUE treats NULL as distinct, so a DB unique on nullable group_id/audience
 * would not protect org-level rows).
 */
final class NotificationTemplateAudience extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if (! $this->db->tableExists('notification_templates')) {
            return;
        }

        if (! $this->db->fieldExists('audience_kind', 'notification_templates')) {
            $this->db->query(
                'ALTER TABLE notification_templates
                    ADD COLUMN audience_kind VARCHAR(16) NOT NULL DEFAULT \'\' AFTER locale,
                    ADD COLUMN audience_role VARCHAR(40) NOT NULL DEFAULT \'\' AFTER audience_kind',
            );
        }

        $this->db->resetDataCache();
        try {
            $this->db->query(
                'ALTER TABLE notification_templates
                    ADD KEY nt_resolve_idx (organization_id, group_id, key_name, channel, locale, audience_kind, audience_role, status, version)',
            );
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->tableExists('notification_templates')) {
            return;
        }
        try {
            $this->db->query('ALTER TABLE notification_templates DROP INDEX nt_resolve_idx');
        } catch (\Throwable) {
        }
        if ($this->db->fieldExists('audience_kind', 'notification_templates')) {
            $this->db->query(
                'ALTER TABLE notification_templates
                    DROP COLUMN audience_kind,
                    DROP COLUMN audience_role',
            );
        }
    }
}
