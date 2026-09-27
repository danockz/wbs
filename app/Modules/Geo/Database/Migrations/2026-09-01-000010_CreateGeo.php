<?php

declare(strict_types=1);

namespace WBS\Geo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Geographic reference hierarchy + location records (SRS §8).
 *
 * Source-of-truth hierarchy: regions > subregions > countries > states >
 * cities / towns_villages. Reference data is versioned, source-attributed and
 * imported idempotently (see GeoImportService). `flag` toggles availability for
 * NEW selection while keeping deactivated rows resolvable for historical
 * records.
 *
 * Location on people/venues carries precision + consent metadata so exact GPS
 * is never inferred from reference centroids and always requires consent.
 */
final class CreateGeo extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        // --- Reference hierarchy -------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS regions (
                id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                translations JSON NULL,
                flag         TINYINT(1) NOT NULL DEFAULT 1,
                wikiDataId   VARCHAR(255) NULL,
                created_at   TIMESTAMP NULL DEFAULT NULL,
                updated_at   TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY regions_name_uq (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS subregions (
                id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                region_id    MEDIUMINT UNSIGNED NOT NULL,
                translations JSON NULL,
                flag         TINYINT(1) NOT NULL DEFAULT 1,
                wikiDataId   VARCHAR(255) NULL,
                created_at   TIMESTAMP NULL DEFAULT NULL,
                updated_at   TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY subregions_region_idx (region_id),
                UNIQUE KEY subregions_region_name_uq (region_id, name),
                CONSTRAINT subregions_region_fk FOREIGN KEY (region_id) REFERENCES regions(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS countries (
                id            MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(255) NOT NULL,
                iso2          CHAR(2) NULL,
                iso3          CHAR(3) NULL,
                numeric_code  CHAR(3) NULL,
                phonecode     VARCHAR(255) NULL,
                capital       VARCHAR(255) NULL,
                currency      VARCHAR(255) NULL,
                currency_name VARCHAR(255) NULL,
                currency_symbol VARCHAR(255) NULL,
                region_id     MEDIUMINT UNSIGNED NULL,
                subregion_id  MEDIUMINT UNSIGNED NULL,
                timezones     JSON NULL,
                translations  JSON NULL,
                latitude      DECIMAL(10,8) NULL,
                longitude     DECIMAL(11,8) NULL,
                flag          TINYINT(1) NOT NULL DEFAULT 1,
                wikiDataId    VARCHAR(255) NULL,
                created_at    TIMESTAMP NULL DEFAULT NULL,
                updated_at    TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY countries_iso2_uq (iso2),
                UNIQUE KEY countries_iso3_uq (iso3),
                UNIQUE KEY countries_name_uq (name),
                KEY countries_region_idx (region_id),
                KEY countries_subregion_idx (subregion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // states (per SRS §8.1 DDL).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS states (
                id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                country_id   MEDIUMINT UNSIGNED NOT NULL,
                country_code CHAR(2) NOT NULL,
                state_code   VARCHAR(255) NULL,
                type         VARCHAR(100) NULL,
                latitude     DECIMAL(10,8) NULL,
                longitude    DECIMAL(11,8) NULL,
                translations JSON NULL,
                created_at   TIMESTAMP NULL DEFAULT NULL,
                updated_at   TIMESTAMP NULL DEFAULT NULL,
                flag         TINYINT(1) NOT NULL DEFAULT 1,
                wikiDataId   VARCHAR(255) NULL,
                PRIMARY KEY (id),
                KEY states_country_idx (country_id),
                UNIQUE KEY states_country_code_uq (country_id, state_code),
                CONSTRAINT states_country_fk FOREIGN KEY (country_id) REFERENCES countries(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS cities (
                id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                state_id     MEDIUMINT UNSIGNED NOT NULL,
                state_code   VARCHAR(255) NULL,
                country_id   MEDIUMINT UNSIGNED NOT NULL,
                country_code CHAR(2) NOT NULL,
                latitude     DECIMAL(10,8) NULL,
                longitude    DECIMAL(11,8) NULL,
                timezone     VARCHAR(64) NULL,
                translations JSON NULL,
                flag         TINYINT(1) NOT NULL DEFAULT 1,
                wikiDataId   VARCHAR(255) NULL,
                created_at   TIMESTAMP NULL DEFAULT NULL,
                updated_at   TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY cities_lookup_idx (country_id, state_id, name),
                KEY cities_state_idx (state_id),
                CONSTRAINT cities_state_fk FOREIGN KEY (state_id) REFERENCES states(id),
                CONSTRAINT cities_country_fk FOREIGN KEY (country_id) REFERENCES countries(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS towns_villages (
                id           MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(255) NOT NULL,
                city_id      MEDIUMINT UNSIGNED NULL,
                state_id     MEDIUMINT UNSIGNED NOT NULL,
                country_id   MEDIUMINT UNSIGNED NOT NULL,
                country_code CHAR(2) NOT NULL,
                latitude     DECIMAL(10,8) NULL,
                longitude    DECIMAL(11,8) NULL,
                timezone     VARCHAR(64) NULL,
                flag         TINYINT(1) NOT NULL DEFAULT 1,
                created_at   TIMESTAMP NULL DEFAULT NULL,
                updated_at   TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY tv_lookup_idx (country_id, state_id, name),
                KEY tv_city_idx (city_id),
                CONSTRAINT tv_state_fk FOREIGN KEY (state_id) REFERENCES states(id),
                CONSTRAINT tv_country_fk FOREIGN KEY (country_id) REFERENCES countries(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- Import provenance / versioning -------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS geo_import_batches (
                id            CHAR(36)     NOT NULL,
                dataset       VARCHAR(40)  NOT NULL,   -- regions|countries|states|cities|...
                version       VARCHAR(60)  NOT NULL,   -- source version/tag
                source        VARCHAR(255) NOT NULL,   -- attribution / license
                checksum      CHAR(64)     NOT NULL,   -- sha256 of payload (idempotency)
                row_count     INT UNSIGNED NOT NULL DEFAULT 0,
                inserted      INT UNSIGNED NOT NULL DEFAULT 0,
                updated       INT UNSIGNED NOT NULL DEFAULT 0,
                skipped       INT UNSIGNED NOT NULL DEFAULT 0,
                status        VARCHAR(20)  NOT NULL DEFAULT "completed", -- staged|completed|failed|rolled_back
                report        JSON NULL,
                created_at    DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gib_idem_uq (dataset, checksum),
                KEY gib_dataset_ver_idx (dataset, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // --- Location records ----------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS addresses (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                region_id        MEDIUMINT UNSIGNED NULL,
                subregion_id     MEDIUMINT UNSIGNED NULL,
                country_id       MEDIUMINT UNSIGNED NULL,
                state_id         MEDIUMINT UNSIGNED NULL,
                city_id          MEDIUMINT UNSIGNED NULL,
                town_village_id  MEDIUMINT UNSIGNED NULL,
                line1            VARCHAR(255) NULL,
                line2            VARCHAR(255) NULL,
                postal_code      VARCHAR(40)  NULL,
                latitude         DECIMAL(10,8) NULL,
                longitude        DECIMAL(11,8) NULL,
                timezone         VARCHAR(64)  NULL,
                location_precision VARCHAR(16) NOT NULL DEFAULT "unknown", -- exact|neighborhood|city|state|country|unknown
                location_source  VARCHAR(40)  NULL,
                location_consent_at DATETIME NULL,
                geocoded_at      DATETIME NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME NULL,
                PRIMARY KEY (id),
                KEY addr_org_idx (organization_id),
                KEY addr_country_idx (country_id, state_id, city_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // POINT SRID 4326 (lon, lat), maintained by LocationService::syncPoint().
        // NULLABLE by design: an address may have no coordinates (precision=unknown)
        // and the service sets geo_point = NULL when a location is cleared. MySQL
        // forbids a SPATIAL INDEX on a nullable column, so proximity queries use
        // ST_Distance_Sphere(...) guarded by `geo_point IS NOT NULL` instead of an
        // R-tree index (adequate at single-org scale). Added idempotently so a
        // partially-applied earlier run can be resumed safely.
        $this->addGeoPoint('addresses');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS venues (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                address_id       CHAR(36)     NULL,
                name             VARCHAR(200) NOT NULL,
                mode             VARCHAR(20)  NOT NULL DEFAULT "physical", -- physical|virtual|hybrid
                capacity         INT UNSIGNED NULL,
                accessibility    JSON NULL,
                latitude         DECIMAL(10,8) NULL,
                longitude        DECIMAL(11,8) NULL,
                timezone         VARCHAR(64)  NULL,
                discovery_status VARCHAR(20)  NOT NULL DEFAULT "private", -- public|private|unlisted
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME NULL,
                PRIMARY KEY (id),
                KEY venues_org_idx (organization_id),
                KEY venues_addr_idx (address_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $this->addGeoPoint('venues');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS location_consents (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- user id
                purpose          VARCHAR(60)  NOT NULL,
                precision_granted VARCHAR(16) NOT NULL DEFAULT "city",
                granted_at       DATETIME     NOT NULL,
                revoked_at       DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY lc_subject_idx (subject_id, purpose)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS geocode_jobs (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                address_id       CHAR(36)     NULL,
                query_hash       CHAR(64)     NOT NULL,   -- hashed query for cache/dedupe
                provider         VARCHAR(40)  NULL,
                status           VARCHAR(20)  NOT NULL DEFAULT "queued", -- queued|running|done|failed
                attempts         INT UNSIGNED NOT NULL DEFAULT 0,
                result           JSON NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME NULL,
                PRIMARY KEY (id),
                KEY gj_status_idx (status),
                KEY gj_query_idx (query_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    /**
     * Add the nullable POINT SRID 4326 `geo_point` column to $table, idempotently.
     *
     * Safe to re-run against a partially-migrated database: the column is only
     * added when absent, and any leftover SPATIAL INDEX from an older revision of
     * this migration is dropped (a spatial index is invalid on a nullable column
     * and must not exist here).
     */
    private function addGeoPoint(string $table): void
    {
        if (! $this->db->fieldExists('geo_point', $table)) {
            $this->db->query(
                'ALTER TABLE ' . $this->db->protectIdentifiers($table)
                . ' ADD COLUMN `geo_point` POINT SRID 4326 NULL'
            );
        }

        // Remove a stale SPATIAL index if an earlier revision of this migration
        // created one (invalid on a nullable column; must not exist here).
        foreach ($this->db->getIndexData($table) as $index) {
            if (($index->type ?? '') === 'SPATIAL') {
                $this->db->query(
                    'ALTER TABLE ' . $this->db->protectIdentifiers($table)
                    . ' DROP INDEX ' . $this->db->protectIdentifiers($index->name)
                );
            }
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach ([
            'geocode_jobs', 'location_consents', 'venues', 'addresses',
            'geo_import_batches', 'towns_villages', 'cities', 'states',
            'countries', 'subregions', 'regions',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
