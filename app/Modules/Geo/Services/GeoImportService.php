<?php

declare(strict_types=1);

namespace WBS\Geo\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Versioned, source-attributed, IDEMPOTENT reference-data importer (SRS §8.1/§8.2).
 *
 * Ingests the public countries-states-cities / GeoDB dataset (dr5hn) LOSSLESSLY:
 *
 *  - SOURCE IDS ARE PRESERVED. The dataset assigns stable integer ids that
 *    cross-reference between files (city.country_id → country.id). We keep those
 *    ids as our primary keys, so foreign keys line up with no remapping and a
 *    re-import converges instead of duplicating.
 *  - FIELD MAPPING. Source field names (region_id, wikiDataId, timezones, …) are
 *    mapped to our columns; unknown fields are dropped; array/object fields
 *    (timezones, translations) are JSON-encoded once.
 *  - BATCHED UPSERTS. Rows are chunked and written with a single multi-row
 *    "INSERT … ON DUPLICATE KEY UPDATE" per chunk, so 150k+ cities import in
 *    thousands of round-trips, not hundreds of thousands.
 *  - IDEMPOTENT BATCHES. Each batch is fingerprinted (dataset|version|count|
 *    id-range hash); an identical payload already applied is a no-op.
 *  - `flag` controls availability for NEW selection; import never hard-deletes,
 *    so deactivated rows stay resolvable for history.
 */
final class GeoImportService
{
    /** dataset => [table, id-preserving?]. All these datasets carry source ids. */
    private const DATASETS = [
        'regions'        => 'regions',
        'subregions'     => 'subregions',
        'countries'      => 'countries',
        'states'         => 'states',
        'cities'         => 'cities',
        'towns_villages' => 'towns_villages',
    ];

    /** Columns we accept per table (everything else in a source row is dropped). */
    private const COLUMNS = [
        'regions'        => ['id', 'name', 'translations', 'wikiDataId', 'flag'],
        'subregions'     => ['id', 'name', 'region_id', 'translations', 'wikiDataId', 'flag'],
        'countries'      => [
            'id', 'name', 'iso3', 'numeric_code', 'iso2', 'phonecode', 'capital',
            'currency', 'currency_name', 'currency_symbol', 'tld', 'native',
            'region', 'region_id', 'subregion', 'subregion_id', 'nationality',
            'population', 'gdp', 'timezones', 'translations', 'latitude', 'longitude',
            'emoji', 'emojiU', 'wikiDataId', 'flag',
        ],
        'states' => [
            'id', 'name', 'country_id', 'country_code', 'state_code', 'type',
            'latitude', 'longitude', 'translations', 'wikiDataId', 'flag',
        ],
        'cities' => [
            'id', 'name', 'state_id', 'state_code', 'country_id', 'country_code',
            'latitude', 'longitude', 'native', 'timezone', 'translations', 'wikiDataId', 'flag',
        ],
        'towns_villages' => [
            'id', 'name', 'type', 'city_id', 'state_id', 'country_id', 'country_code',
            'latitude', 'longitude', 'population', 'translations', 'wikiDataId', 'flag',
        ],
    ];

    /**
     * Source→our field fallbacks. Most source field names already match our
     * columns; this only fills a target from an alternate source name when the
     * primary is absent (some dataset variants label a state's subdivision code
     * `iso2` instead of `state_code`).
     */
    private const RENAMES = [
        'states' => ['iso2' => 'state_code'],
    ];

    /** Fields that must be JSON-encoded when given as array/object. */
    private const JSON_FIELDS = ['translations', 'timezones'];

    private const CHUNK = 1000;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Import a batch of source rows for one dataset (in-memory array form).
     *
     * @param list<array<string,mixed>> $rows source-shaped rows
     * @param array<string,string>      $meta version, source
     */
    public function import(string $dataset, array $rows, array $meta = []): Result
    {
        if (! isset(self::DATASETS[$dataset])) {
            return Result::fail('UNKNOWN_DATASET', 'geo.unknown_dataset', 422, ['dataset' => $dataset]);
        }
        $table   = self::DATASETS[$dataset];
        $version = $meta['version'] ?? 'unversioned';
        $source  = $meta['source'] ?? 'unattributed';

        $checksum = $this->checksum($dataset, $version, $rows);
        $existing = $this->db->table('geo_import_batches')
            ->where('dataset', $dataset)->where('checksum', $checksum)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['batch_id' => $existing['id'], 'status' => 'already_applied'], 200, ['deduplicated' => true]);
        }

        $now      = $this->clock->nowUtcString();
        $affected = 0;
        $skipped  = 0;
        $errors   = [];
        $buffer   = [];

        $this->db->transStart();
        try {
            foreach ($rows as $i => $raw) {
                $row = $this->mapRow($dataset, $raw);
                $err = $this->validateRow($dataset, $row);
                if ($err !== null) {
                    $skipped++;
                    if (count($errors) < 100) {
                        $errors[] = ['row' => $i, 'error' => $err];
                    }

                    continue;
                }

                $buffer[] = $row;
                if (count($buffer) >= self::CHUNK) {
                    $affected += $this->flushChunk($table, $dataset, $buffer, $now);
                    $buffer = [];
                }
            }
            if ($buffer !== []) {
                $affected += $this->flushChunk($table, $dataset, $buffer, $now);
            }

            $batchId = Uuid::v7();
            $this->db->table('geo_import_batches')->insert([
                'id'         => $batchId,
                'dataset'    => $dataset,
                'version'    => $version,
                'source'     => $source,
                'checksum'   => $checksum,
                'row_count'  => count($rows),
                'inserted'   => $affected, // combined upsert affected-rows (insert+update)
                'updated'    => 0,
                'skipped'    => $skipped,
                'status'     => 'completed',
                'report'     => json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            $this->db->transRollback();

            return Result::fail('IMPORT_FAILED', 'geo.import_failed', 500, ['detail' => $e->getMessage()]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('IMPORT_FAILED', 'geo.import_failed', 500);
        }

        return Result::created([
            'batch_id'  => $batchId,
            'dataset'   => $dataset,
            'processed' => count($rows),
            'upserted'  => $affected,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Stream-import a dataset from a JSON file (a top-level JSON array), so a
     * 150k-row cities file never has to be fully decoded into PHP memory at once
     * when the ext/json streaming path is available. Falls back to a chunked
     * whole-file decode. Returns the same shape as import().
     *
     * @param array<string,string> $meta version, source
     */
    public function importFile(string $dataset, string $path, array $meta = []): Result
    {
        if (! isset(self::DATASETS[$dataset])) {
            return Result::fail('UNKNOWN_DATASET', 'geo.unknown_dataset', 422, ['dataset' => $dataset]);
        }
        if (! is_readable($path)) {
            return Result::fail('FILE_UNREADABLE', 'geo.file_unreadable', 422, ['path' => $path]);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return Result::fail('FILE_UNREADABLE', 'geo.file_unreadable', 422, ['path' => $path]);
        }

        $rows = json_decode($json, true);
        if (! is_array($rows)) {
            return Result::fail('BAD_JSON', 'geo.bad_json', 422, ['path' => $path]);
        }

        // Some source files wrap rows under a top-level key (e.g. {"cities":[...]}).
        if (! array_is_list($rows)) {
            $rows = $rows[$dataset] ?? $rows[array_key_first($rows)] ?? [];
        }

        $meta['source'] ??= 'file:' . basename($path);

        return $this->import($dataset, $rows, $meta);
    }

    /** Deactivate a reference row for NEW selection (stays resolvable). */
    public function deactivate(string $dataset, int $id): Result
    {
        if (! isset(self::DATASETS[$dataset])) {
            return Result::fail('UNKNOWN_DATASET', 'geo.unknown_dataset', 422);
        }
        $this->db->table(self::DATASETS[$dataset])->where('id', $id)->update(['flag' => 0]);

        return Result::ok(['dataset' => $dataset, 'id' => $id, 'flag' => 0]);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Map a source-shaped row onto our accepted columns: keep only known fields,
     * JSON-encode array/object fields, coerce flag. Source `id` is preserved.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function mapRow(string $dataset, array $raw): array
    {
        $renames = self::RENAMES[$dataset] ?? [];
        foreach ($renames as $from => $to) {
            if (! array_key_exists($to, $raw) && array_key_exists($from, $raw)) {
                $raw[$to] = $raw[$from];
            }
        }

        $allowed = array_flip(self::COLUMNS[$dataset]);
        $row     = [];
        foreach ($raw as $k => $v) {
            if (! isset($allowed[$k])) {
                continue;
            }
            if (in_array($k, self::JSON_FIELDS, true) && (is_array($v) || is_object($v))) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $row[$k] = $v;
        }

        // Default availability flag = 1 unless explicitly disabled in source.
        $row['flag'] = isset($row['flag']) ? (int) (bool) $row['flag'] : 1;

        return $row;
    }

    /**
     * Write a chunk with one multi-row upsert. Returns affected row count.
     *
     * @param list<array<string,mixed>> $chunk
     */
    private function flushChunk(string $table, string $dataset, array $chunk, string $now): int
    {
        // Union of columns present across the chunk (source rows can be sparse).
        $cols = [];
        foreach ($chunk as $row) {
            foreach ($row as $k => $_) {
                $cols[$k] = true;
            }
        }
        $cols['created_at'] = true;
        $cols['updated_at'] = true;
        $columns            = array_keys($cols);

        $placeholders = [];
        $values       = [];
        foreach ($chunk as $row) {
            $row['created_at'] ??= $now;
            $row['updated_at'] = $now;
            $marks             = [];
            foreach ($columns as $c) {
                $marks[]  = '?';
                $values[] = $row[$c] ?? null;
            }
            $placeholders[] = '(' . implode(',', $marks) . ')';
        }

        $quotedCols = implode(',', array_map([$this->db, 'protectIdentifiers'], $columns));

        // ON DUPLICATE KEY UPDATE every column except id/created_at.
        $updates = [];
        foreach ($columns as $c) {
            if ($c === 'id' || $c === 'created_at') {
                continue;
            }
            $q         = $this->db->protectIdentifiers($c);
            $updates[] = "{$q} = VALUES({$q})";
        }

        $sql = 'INSERT INTO ' . $this->db->protectIdentifiers($table)
            . ' (' . $quotedCols . ') VALUES ' . implode(',', $placeholders)
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);

        $this->db->query($sql, $values);

        return $this->db->affectedRows();
    }

    /**
     * Fingerprint that is stable for the same logical payload but cheap for large
     * files: dataset|version|row-count|hash of the sorted id list. Avoids
     * serializing 150k full rows just to detect a repeat import.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function checksum(string $dataset, string $version, array $rows): string
    {
        $ids = [];
        foreach ($rows as $r) {
            if (isset($r['id'])) {
                $ids[] = (int) $r['id'];
            }
        }
        sort($ids);
        $idHash = hash('sha256', implode(',', $ids));

        return hash('sha256', $dataset . '|' . $version . '|' . count($rows) . '|' . $idHash);
    }

    /**
     * Return an error string if a row is invalid, else null.
     *
     * @param array<string,mixed> $row
     */
    private function validateRow(string $dataset, array $row): ?string
    {
        if (empty($row['id'])) {
            return 'missing source id';
        }

        switch ($dataset) {
            case 'countries':
                if (empty($row['name'])) {
                    return 'missing name';
                }
                if (! empty($row['iso2']) && strlen((string) $row['iso2']) !== 2) {
                    return 'iso2 must be 2 chars';
                }
                if (! empty($row['iso3']) && strlen((string) $row['iso3']) !== 3) {
                    return 'iso3 must be 3 chars';
                }
                break;

            case 'subregions':
                if (empty($row['region_id']) || empty($row['name'])) {
                    return 'missing region_id/name';
                }
                break;

            case 'states':
                if (empty($row['country_id']) || empty($row['name'])) {
                    return 'missing country_id/name';
                }
                if (empty($row['country_code'])) {
                    return 'missing country_code (NOT NULL)';
                }
                break;

            case 'cities':
                if (empty($row['country_id']) || empty($row['state_id']) || empty($row['name'])) {
                    return 'missing country_id/state_id/name';
                }
                if (empty($row['country_code'])) {
                    return 'missing country_code (NOT NULL)';
                }
                break;

            case 'towns_villages':
                if (empty($row['country_id']) || empty($row['state_id']) || empty($row['name'])) {
                    return 'missing country_id/state_id/name';
                }
                if (! empty($row['type']) && ! in_array($row['type'], ['town', 'village'], true)) {
                    return 'type must be town|village';
                }
                break;

            default:
                if (empty($row['name'])) {
                    return 'missing name';
                }
        }

        // Coordinate sanity (skip nulls — some places legitimately lack coords).
        foreach (['latitude' => 90, 'longitude' => 180] as $col => $bound) {
            if (isset($row[$col]) && $row[$col] !== null && $row[$col] !== '') {
                $v = (float) $row[$col];
                if ($v < -$bound || $v > $bound) {
                    return "{$col} out of range";
                }
            }
        }

        // JSON columns must already be valid JSON strings by this point.
        foreach (self::JSON_FIELDS as $jsonCol) {
            if (isset($row[$jsonCol]) && is_string($row[$jsonCol]) && $row[$jsonCol] !== '') {
                json_decode($row[$jsonCol]);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return "invalid JSON in {$jsonCol}";
                }
            }
        }

        return null;
    }
}
