<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Certificate templates become per-hierarchical-group: nullable `group_id`
 * (NULL = org-level default). The old unique (org, event_type, version) would
 * block two groups from owning version 1 of the same type, so it is replaced
 * with a lookup index; uniqueness of a lineage is enforced in CertificateService.
 */
final class CertificateTemplatesPerGroup extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if (! $this->db->tableExists('certificate_templates')) {
            return;
        }

        if (! $this->db->fieldExists('group_id', 'certificate_templates')) {
            $this->db->query(
                'ALTER TABLE certificate_templates
                    ADD COLUMN group_id CHAR(36) NULL AFTER organization_id',
            );
        }

        // Drop the org-wide unique so two groups can share an event_type+version.
        try {
            $this->db->query('ALTER TABLE certificate_templates DROP INDEX ct_type_ver_uq');
        } catch (\Throwable) {
            // Index already gone (re-run) — fine.
        }

        $this->db->resetDataCache();
        try {
            $this->db->query(
                'ALTER TABLE certificate_templates
                    ADD KEY ct_group_idx (organization_id, group_id, event_type, status, version)',
            );
        } catch (\Throwable) {
            // Index already present.
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->tableExists('certificate_templates')) {
            return;
        }
        try {
            $this->db->query('ALTER TABLE certificate_templates DROP INDEX ct_group_idx');
        } catch (\Throwable) {
        }
        if ($this->db->fieldExists('group_id', 'certificate_templates')) {
            $this->db->query('ALTER TABLE certificate_templates DROP COLUMN group_id');
        }
        try {
            $this->db->query(
                'ALTER TABLE certificate_templates
                    ADD UNIQUE KEY ct_type_ver_uq (organization_id, event_type, version)',
            );
        } catch (\Throwable) {
        }
    }
}
