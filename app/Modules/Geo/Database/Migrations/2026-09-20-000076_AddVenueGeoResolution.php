<?php

declare(strict_types=1);

namespace WBS\Geo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Venue geo-resolution denormalization (Groups+Venues+Geo unification).
 *
 * Venues previously carried only `address_id` + raw lat/long, so bucketing or
 * drilling venues by place required a join through `addresses` on every read.
 * This adds each venue its OWN resolved geo hierarchy (country/state/city/town
 * ids + display labels) plus a `location_group_key` bucket — mirroring what
 * `groups` already got in AddGroupLocation (000064) — so the global→local
 * directory can read one indexed table with no per-row join.
 *
 * `LocationSyncService` is the single writer of these columns: it stamps them
 * from the venue's address (or resolves from lat/long via GeoResolverService
 * when the address lacks ids), so the denormalized values never drift.
 *
 * Geo id columns are MEDIUMINT UNSIGNED to match the reference tables EXACTLY
 * (type/collation parity, SRS §8.2). All columns nullable/additive; idempotent
 * (add-if-absent), so a partially-applied run resumes safely.
 */
final class AddVenueGeoResolution extends Migration
{
    /** @var array<string,string> column => DDL type/clause */
    private array $columns = [
        'country_id'         => 'MEDIUMINT UNSIGNED NULL',
        'state_id'           => 'MEDIUMINT UNSIGNED NULL',
        'city_id'            => 'MEDIUMINT UNSIGNED NULL',
        'town_village_id'    => 'MEDIUMINT UNSIGNED NULL',
        'country_label'      => 'VARCHAR(120) NULL',
        'state_label'        => 'VARCHAR(120) NULL',
        'city_label'         => 'VARCHAR(120) NULL',
        'location_group_key' => 'VARCHAR(160) NULL',
    ];

    /** @var array<string,string> index name => DDL */
    private array $indexes = [
        'venues_geo_idx'       => 'ADD KEY venues_geo_idx (organization_id, country_id, state_id, city_id)',
        'venues_locbucket_idx' => 'ADD KEY venues_locbucket_idx (organization_id, location_group_key)',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so fieldExists()/getIndexData() can read a
        // stale list and silently skip an ADD.
        $this->db->resetDataCache();

        foreach ($this->columns as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'venues')) {
                $this->db->query('ALTER TABLE venues ADD COLUMN `' . $name . '` ' . $ddl);
            }
        }

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('venues'));
        foreach ($this->indexes as $idx => $ddl) {
            if (! in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE venues ' . $ddl);
            }
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('venues'));
        foreach (array_keys($this->indexes) as $idx) {
            if (in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE venues DROP INDEX `' . $idx . '`');
            }
        }
        foreach (array_keys($this->columns) as $name) {
            if ($this->db->fieldExists($name, 'venues')) {
                $this->db->query('ALTER TABLE venues DROP COLUMN `' . $name . '`');
            }
        }
    }
}
