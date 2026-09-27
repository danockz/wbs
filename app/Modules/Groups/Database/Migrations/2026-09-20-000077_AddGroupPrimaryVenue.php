<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group primary-venue link (Groups+Venues+Geo unification).
 *
 * A group "meets somewhere". `primary_venue_id` points a group at its home
 * venue and becomes the SOURCE OF TRUTH for the group's physical location: when
 * set, `LocationSyncService` stamps the group's denormalized location fields
 * (region_label/city_label/location_group_key/lat/long — added in 000064) from
 * that venue's resolved geo. When NOT set, the group falls back to its own
 * `address_id`. The pointer is kept consistent with the
 * `venue_group_assignments` row whose `assignment_type='primary'` (that join
 * table stays the full relationship + audit; this column is the fast pointer).
 *
 * Nullable/additive; no hard cross-module FK (matches the codebase's logical-ref
 * + service-guard style, so a venue soft-delete never cascade-breaks a group —
 * the sync service clears/repoints the pointer instead). Idempotent.
 */
final class AddGroupPrimaryVenue extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache (see 000064).
        $this->db->resetDataCache();

        if (! $this->db->fieldExists('primary_venue_id', 'groups')) {
            $this->db->query('ALTER TABLE `groups` ADD COLUMN `primary_venue_id` CHAR(36) NULL AFTER `address_id`');
        }

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        if (! in_array('groups_primary_venue_idx', $existing, true)) {
            $this->db->query('ALTER TABLE `groups` ADD KEY groups_primary_venue_idx (primary_venue_id)');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        if (in_array('groups_primary_venue_idx', $existing, true)) {
            $this->db->query('ALTER TABLE `groups` DROP INDEX `groups_primary_venue_idx`');
        }
        if ($this->db->fieldExists('primary_venue_id', 'groups')) {
            $this->db->query('ALTER TABLE `groups` DROP COLUMN `primary_venue_id`');
        }
    }
}
