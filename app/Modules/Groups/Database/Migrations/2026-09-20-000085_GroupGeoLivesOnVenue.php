<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * UN-REPLICATION: a group's geo (GPS + place) lives on its VENUE.
 *
 * Correction to the Groups+Venues+Geo unification. The first cut denormalized
 * resolved geo onto `groups` (000064 added region_label/city_label/
 * location_group_key/latitude/longitude; 000078 added geo_country_id/
 * geo_state_id/geo_city_id/country_label) so the directory could read one table.
 * That replicated location across all three entities — group, venue and the Geo
 * reference hierarchy — which makes `venues` and `groups` two competing sources
 * of truth for the same fact, and every venue move a two-table write.
 *
 * The model is instead a single chain:
 *
 *     groups.primary_venue_id  →  venues  →  Geo reference (countries/states/cities)
 *                                     └── venues.latitude/longitude  (GPS)
 *                                     └── venues.{country,state,city,town_village}_id
 *
 * A GROUP has no coordinates and no place ids of its own: it meets somewhere,
 * and "somewhere" is the venue. `LocationSyncService::stampVenue()` stays the
 * single writer of geo, and now writes ONE table. Readers (public + admin
 * directory) join the group's primary venue and take the geo from it; a group
 * with no venue is simply UNLOCATED rather than "located by its own copy".
 *
 * Kept on `groups`:
 *   • primary_venue_id — the link that carries geo (000077);
 *   • address_id       — postal/contact address, not a geo source;
 *   • location_text    — free-text human description ("Behind the market"),
 *                        not replicated geo.
 *
 * Dropped here: every resolved-geo id, place label, directory bucket key and
 * GPS pair that duplicated the venue. Idempotent (drop-if-present) so a partial
 * run resumes; `down()` re-adds them nullable (values are re-derivable by
 * re-running `geo:backfill-location`, which now stamps venues only).
 */
final class GroupGeoLivesOnVenue extends Migration
{
    /** Indexes to drop before their columns disappear. */
    private const INDEXES = ['groups_geo_idx', 'groups_locbucket_idx'];

    /** Replicated geo columns removed from `groups` (venue is the source). */
    private const COLUMNS = [
        // from 000078 (AddGroupGeoResolution)
        'geo_country_id',
        'geo_state_id',
        'geo_city_id',
        'country_label',
        // from 000064 (AddGroupLocation)
        'location_group_key',
        'region_label',
        'city_label',
        'latitude',
        'longitude',
    ];

    /** DDL used by down() to restore the columns (nullable, no data loss risk). */
    private const RESTORE_DDL = [
        'geo_country_id'     => 'MEDIUMINT UNSIGNED NULL',
        'geo_state_id'       => 'MEDIUMINT UNSIGNED NULL',
        'geo_city_id'        => 'MEDIUMINT UNSIGNED NULL',
        'country_label'      => 'VARCHAR(120) NULL',
        'location_group_key' => 'VARCHAR(160) NULL',
        'region_label'       => 'VARCHAR(120) NULL',
        'city_label'         => 'VARCHAR(120) NULL',
        'latitude'           => 'DECIMAL(10,8) NULL',
        'longitude'          => 'DECIMAL(11,8) NULL',
    ];

    public function up(): void
    {
        // Raw ALTERs don't invalidate CI4's connection-lifetime schema cache, so
        // fieldExists()/getIndexData() can read a stale list (see 000064/000076).
        $this->db->resetDataCache();

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        foreach (self::INDEXES as $index) {
            if (in_array($index, $existing, true)) {
                $this->db->query("ALTER TABLE `groups` DROP INDEX `{$index}`");
            }
        }

        foreach (self::COLUMNS as $name) {
            if ($this->db->fieldExists($name, 'groups')) {
                $this->db->query("ALTER TABLE `groups` DROP COLUMN `{$name}`");
            }
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        foreach (self::RESTORE_DDL as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'groups')) {
                $this->db->query("ALTER TABLE `groups` ADD COLUMN `{$name}` {$ddl}");
            }
        }

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('groups'));
        if (! in_array('groups_locbucket_idx', $existing, true)) {
            $this->db->query('ALTER TABLE `groups` ADD KEY groups_locbucket_idx (organization_id, location_group_key)');
        }

        $this->db->resetDataCache();
    }
}
