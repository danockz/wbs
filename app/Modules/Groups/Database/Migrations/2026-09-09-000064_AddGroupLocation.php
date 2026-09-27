<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Location fields for groups so the public directory (/g) can be presented as a
 * GROUP DIRECTORY BY LOCATION with each group's location + contact details.
 *
 * `address_id` links a group to a Geo `addresses` row (the authoritative,
 * geocodable location with lat/long/geo_point). The denormalized `*_label`
 * columns and `location_group_key` give the directory a cheap, stable grouping
 * key (e.g. "Greater Accra") without a join on every render; they are refreshed
 * whenever the address changes. contact_email/contact_phone already exist from
 * AddGroupPublicProfile (000047) and are reused for the directory contact block.
 *
 * All columns nullable/defaulted; idempotent (add-if-absent).
 */
final class AddGroupLocation extends Migration
{
    /** @var array<string,string> column => DDL type/clause */
    private array $columns = [
        'address_id'         => 'CHAR(36) NULL',           // -> Geo addresses
        'location_group_key' => 'VARCHAR(160) NULL',       // directory bucket, e.g. state/region label
        'region_label'       => 'VARCHAR(120) NULL',
        'city_label'         => 'VARCHAR(120) NULL',
        'latitude'           => 'DECIMAL(10,8) NULL',
        'longitude'          => 'DECIMAL(11,8) NULL',
    ];

    /** @var array<string,string> index name => DDL */
    private array $indexes = [
        'groups_locbucket_idx' => 'ADD KEY groups_locbucket_idx (organization_id, location_group_key)',
        'groups_address_idx'   => 'ADD KEY groups_address_idx (address_id)',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach ($this->columns as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'groups')) {
                $this->db->query('ALTER TABLE `groups` ADD COLUMN `' . $name . '` ' . $ddl);
            }
        }

        $existing = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('groups'),
        );
        foreach ($this->indexes as $idx => $ddl) {
            if (! in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE `groups` ' . $ddl);
            }
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $existing = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('groups'),
        );
        foreach (array_keys($this->indexes) as $idx) {
            if (in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE `groups` DROP INDEX `' . $idx . '`');
            }
        }
        foreach (array_keys($this->columns) as $name) {
            if ($this->db->fieldExists($name, 'groups')) {
                $this->db->query('ALTER TABLE `groups` DROP COLUMN `' . $name . '`');
            }
        }
    }
}
