<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group resolved-geo IDs (Groups+Venues+Geo unification).
 *
 * ⚠ SUPERSEDED by 000085_GroupGeoLivesOnVenue, which DROPS these columns again.
 * Kept in the chain (not deleted) so an already-migrated database and a fresh
 * `migrate:refresh` both replay correctly: this adds, 000085 removes. A group's
 * geo is read through `groups.primary_venue_id → venues`, never stored on the
 * group — see 000085 for the reasoning.
 *
 * 000064 added the group's denormalized location LABELS (region_label,
 * city_label, location_group_key, lat/long). To render the public/admin
 * global→local drill-down (Country ▸ State ▸ City ▸ Venue ▸ groups) purely off
 * the `groups` table — with no per-row `addresses` join — the reader also needs
 * the resolved geo IDs and a distinct COUNTRY label (region_label collapses
 * state-or-country and is lossy for a Country/State split).
 *
 * `LocationSyncService::stampGroup()` is the single writer of these columns
 * (from the group's primary venue, else its address fallback). Matching the
 * venue columns added in 000076 so both tables bucket by the same scheme.
 *
 * MEDIUMINT UNSIGNED matches the Geo reference PKs (countries/states/cities).
 * Nullable/additive, no hard cross-module FK (logical-ref + service-guard
 * style). Idempotent.
 */
final class AddGroupGeoResolution extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache (see 000064).
        $this->db->resetDataCache();

        $cols = [
            'geo_country_id'  => 'MEDIUMINT UNSIGNED NULL AFTER `location_group_key`',
            'geo_state_id'    => 'MEDIUMINT UNSIGNED NULL AFTER `geo_country_id`',
            'geo_city_id'     => 'MEDIUMINT UNSIGNED NULL AFTER `geo_state_id`',
            'country_label'   => 'VARCHAR(120) NULL AFTER `geo_city_id`',
        ];
        foreach ($cols as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'groups')) {
                $this->db->query("ALTER TABLE `groups` ADD COLUMN `{$name}` {$ddl}");
            }
        }

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        if (! in_array('groups_geo_idx', $existing, true)) {
            $this->db->query('ALTER TABLE `groups` ADD KEY groups_geo_idx (geo_country_id, geo_state_id, geo_city_id)');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        if (in_array('groups_geo_idx', $existing, true)) {
            $this->db->query('ALTER TABLE `groups` DROP INDEX `groups_geo_idx`');
        }
        foreach (['country_label', 'geo_city_id', 'geo_state_id', 'geo_country_id'] as $name) {
            if ($this->db->fieldExists($name, 'groups')) {
                $this->db->query("ALTER TABLE `groups` DROP COLUMN `{$name}`");
            }
        }
    }
}
