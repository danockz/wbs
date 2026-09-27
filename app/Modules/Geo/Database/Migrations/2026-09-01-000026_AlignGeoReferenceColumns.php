<?php

declare(strict_types=1);

namespace WBS\Geo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Align the reference-hierarchy tables with the full public dataset shape
 * (dr5hn countries-states-cities / GeoDB) so the importer can ingest it
 * LOSSLESSLY (SRS §8.1).
 *
 * The source assigns stable integer ids that cross-reference between files
 * (a city.country_id points at a country.id); the importer preserves those ids
 * as our primary keys, so no id remapping is needed and FKs line up.
 *
 * Adds the descriptive columns present in the source but absent from the initial
 * subset schema. Idempotent: each ADD COLUMN is guarded so re-running is safe.
 */
final class AlignGeoReferenceColumns extends Migration
{
    /** @var array<string, list<string>> table => list of "ADD COLUMN ..." clauses */
    private array $additions = [];

    public function __construct()
    {
        parent::__construct();

        $this->additions = [
            'countries' => [
                'ADD COLUMN tld VARCHAR(16) NULL AFTER currency_symbol',
                'ADD COLUMN native VARCHAR(255) NULL AFTER tld',
                'ADD COLUMN region VARCHAR(255) NULL AFTER native',
                'ADD COLUMN subregion VARCHAR(255) NULL AFTER region',
                'ADD COLUMN nationality VARCHAR(255) NULL AFTER subregion',
                'ADD COLUMN population BIGINT UNSIGNED NULL AFTER nationality',
                'ADD COLUMN gdp BIGINT UNSIGNED NULL AFTER population',
                'ADD COLUMN emoji VARCHAR(191) NULL AFTER gdp',
                'ADD COLUMN emojiU VARCHAR(191) NULL AFTER emoji',
            ],
            'cities' => [
                'ADD COLUMN native VARCHAR(255) NULL AFTER country_code',
            ],
            'towns_villages' => [
                "ADD COLUMN type ENUM('town','village') NOT NULL DEFAULT 'town' AFTER name",
                'ADD COLUMN population INT UNSIGNED NULL AFTER longitude',
                'ADD COLUMN translations JSON NULL AFTER population',
                'ADD COLUMN wikiDataId VARCHAR(255) NULL AFTER translations',
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->additions as $table => $clauses) {
            foreach ($clauses as $clause) {
                try {
                    $this->db->query("ALTER TABLE {$table} {$clause}");
                } catch (\Throwable $e) {
                    // Column already exists — safe to ignore.
                }
            }
        }

        // The source town/village shape has no country_code (only country_id);
        // relax our NOT NULL so town imports don't fail. cities keep it NOT NULL.
        try {
            $this->db->query('ALTER TABLE towns_villages MODIFY COLUMN country_code CHAR(2) NULL');
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        $drops = [
            'countries'      => ['tld', 'native', 'region', 'subregion', 'nationality', 'population', 'gdp', 'emoji', 'emojiU'],
            'cities'         => ['native'],
            'towns_villages' => ['type', 'population', 'translations', 'wikiDataId'],
        ];
        foreach ($drops as $table => $cols) {
            foreach ($cols as $col) {
                try {
                    $this->db->query("ALTER TABLE {$table} DROP COLUMN {$col}");
                } catch (\Throwable $e) {
                }
            }
        }
    }
}
