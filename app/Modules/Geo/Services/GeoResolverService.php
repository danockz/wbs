<?php

declare(strict_types=1);

namespace WBS\Geo\Services;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Read-side geographic resolution engine (SRS §8). Pure reference-data queries:
 * no writes, no personal data, no consent surface — that lives in
 * {@see LocationService}. This service turns identifiers or coordinates into a
 * fully-populated hierarchy (town → city → state → country → subregion → region)
 * and answers spatial questions against the reference tables.
 *
 * Design notes:
 *  - Distance uses the Haversine great-circle formula (EARTH_RADIUS_KM), matching
 *    what the reference LocationService library specifies.
 *  - Every spatial query is bounded first by a lat/lng bounding box (so an index
 *    on (latitude, longitude) can be used) and only THEN refined by the exact
 *    great-circle distance in a HAVING clause — no function-wrapped indexed
 *    columns in the WHERE.
 *  - All SQL is parameterized. Per-request static caches avoid repeat lookups
 *    within a single request; they are NOT a persistent cache.
 */
final class GeoResolverService
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const EARTH_RADIUS_MI = 3959.0;

    /** Two-tier reverse-geocode radii (km). */
    private const TOWN_RADIUS_KM = 20.0;
    private const CITY_RADIUS_KM = 50.0;

    private const MAX_RADIUS_KM = 500.0;

    /** @var array<string, mixed> per-request query cache */
    private array $queryCache = [];

    /** @var array<string, array<string,mixed>|null> per-request entity cache */
    private array $entityCache = [];

    public function __construct(
        private readonly BaseConnection $db,
    ) {
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    /**
     * Cascading resolution: town_village_id → city_id → coordinates. Returns a
     * fully-populated hierarchy with a `valid` flag, `resolution_method`, and
     * `errors` (never throws for bad *data* — only for out-of-range coordinates
     * passed directly to the spatial helpers).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function resolve(array $data): array
    {
        $hasTown   = isset($data['town_village_id']) && is_numeric($data['town_village_id']);
        $hasCity   = isset($data['city_id']) && is_numeric($data['city_id']);
        $hasCoords = isset($data['latitude'], $data['longitude'])
            && is_numeric($data['latitude']) && is_numeric($data['longitude']);

        if (! $hasTown && ! $hasCity && ! $hasCoords) {
            return $this->failure(['location' => 'Provide one of town_village_id, city_id, or latitude+longitude.']);
        }

        if ($hasCoords) {
            $lat = (float) $data['latitude'];
            $lng = (float) $data['longitude'];
            if ($lat < -90 || $lat > 90) {
                return $this->failure(['latitude' => 'Latitude must be between -90 and 90.']);
            }
            if ($lng < -180 || $lng > 180) {
                return $this->failure(['longitude' => 'Longitude must be between -180 and 180.']);
            }
        }

        if ($hasTown) {
            $result = $this->resolveFromTown((int) $data['town_village_id']);
            if ($result['valid']) {
                $result['resolution_method'] = 'exact_town';

                return $result;
            }
            if (! $hasCoords) {
                return $result;
            }
        }

        if ($hasCity) {
            $result = $this->resolveFromCity((int) $data['city_id']);
            if ($result['valid']) {
                $result['resolution_method'] = 'exact_city';

                return $result;
            }
            if (! $hasCoords) {
                return $result;
            }
        }

        if ($hasCoords) {
            $result = $this->resolveFromCoordinates((float) $data['latitude'], (float) $data['longitude']);
            if ($result['valid']) {
                $result['resolution_method'] = 'reverse_geocode';
                $result['distance_km']       = $this->calculateDistance(
                    (float) $data['latitude'],
                    (float) $data['longitude'],
                    (float) ($result['latitude'] ?? 0),
                    (float) ($result['longitude'] ?? 0),
                );
            }

            return $result;
        }

        return $this->failure(['location' => 'Unable to resolve location from provided data.']);
    }

    /**
     * Reverse-geocode to the nearest named place: towns within TOWN_RADIUS_KM
     * first, then cities within CITY_RADIUS_KM. Null when nothing is close.
     *
     * @return array<string,mixed>|null
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $this->assertCoordRange($latitude, $longitude);

        $key = sprintf('revgeo_%.3f_%.3f', $latitude, $longitude);
        if (array_key_exists($key, $this->queryCache)) {
            return $this->queryCache[$key];
        }

        $town = $this->findNearest('towns_villages', $latitude, $longitude, self::TOWN_RADIUS_KM);
        if ($town !== null) {
            $out = $this->enrichTown($town);
            $out['distance_km'] = round((float) $town['distance'], 2);

            return $this->queryCache[$key] = $out;
        }

        $city = $this->findNearest('cities', $latitude, $longitude, self::CITY_RADIUS_KM);
        if ($city !== null) {
            $out = $this->enrichCity($city);
            $out['distance_km'] = round((float) $city['distance'], 2);

            return $this->queryCache[$key] = $out;
        }

        return $this->queryCache[$key] = null;
    }

    /**
     * All towns + cities within radius, nearest first.
     *
     * @return list<array<string,mixed>>
     */
    public function findPlacesInRadius(float $latitude, float $longitude, float $radiusKm, int $limit = 50): array
    {
        $this->assertCoordRange($latitude, $longitude);
        $radiusKm = min(max($radiusKm, 1.0), self::MAX_RADIUS_KM);
        $limit    = max(1, min($limit, 200));

        $places = [];
        foreach ($this->findInRadius('towns_villages', $latitude, $longitude, $radiusKm, $limit) as $t) {
            $t['type']  = 'town_village';
            $places[]   = $t;
        }
        $remaining = $limit - count($places);
        if ($remaining > 0) {
            foreach ($this->findInRadius('cities', $latitude, $longitude, $radiusKm, $remaining) as $c) {
                $c['type']  = 'city';
                $places[]   = $c;
            }
        }

        usort($places, static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return array_slice($places, 0, $limit);
    }

    /**
     * Preferred BCP-47 language for a place, walking town → city → country
     * `translations` JSON, falling back to the country's implied default and
     * finally 'en'. Returns the bare language subtag (e.g. 'fr').
     *
     * @param array<string,mixed> $ids  any of town_village_id|city_id|country_id
     */
    public function detectPreferredLanguage(array $ids, string $default = 'en'): string
    {
        $sources = [];
        if (! empty($ids['town_village_id'])) {
            $sources[] = ['towns_villages', (int) $ids['town_village_id']];
        }
        if (! empty($ids['city_id'])) {
            $sources[] = ['cities', (int) $ids['city_id']];
        }
        if (! empty($ids['country_id'])) {
            $sources[] = ['countries', (int) $ids['country_id']];
        }

        foreach ($sources as [$table, $id]) {
            $row = $this->entity($table, $id, ['translations']);
            $tr  = $this->parseTranslations($row['translations'] ?? null);
            if ($tr !== []) {
                return $this->normalizeLocale((string) array_key_first($tr));
            }
        }

        return $this->normalizeLocale($default);
    }

    // =========================================================================
    // Stats + distance (public utilities)
    // =========================================================================

    /**
     * Great-circle distance between two points (Haversine).
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2, string $unit = 'km'): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c    = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $r    = $unit === 'mi' ? self::EARTH_RADIUS_MI : self::EARTH_RADIUS_KM;

        return round($r * $c, 3);
    }

    /**
     * Aggregate reference-data stats grouped by a hierarchy level.
     *
     * @return array<string,mixed>
     */
    public function geographicStats(string $groupBy): array
    {
        $table = match ($groupBy) {
            'region'  => 'regions',
            'country' => 'countries',
            'state'   => 'states',
            'city'    => 'cities',
            default   => null,
        };
        if ($table === null) {
            return [];
        }

        // regions/subregions have no coordinate columns; guard the select.
        $hasCoords = in_array($table, ['countries', 'states', 'cities'], true);
        $select    = 'COUNT(*) AS total_count';
        if ($hasCoords) {
            $select .= ', SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) AS has_coordinates'
                . ', MIN(latitude) AS min_lat, MAX(latitude) AS max_lat'
                . ', MIN(longitude) AS min_lng, MAX(longitude) AS max_lng';
        }

        $row = $this->db->query("SELECT {$select} FROM " . $this->db->protectIdentifiers($table))
            ->getRowArray() ?: [];

        $row['group_by']    = $groupBy;
        $row['total_count'] = (int) ($row['total_count'] ?? 0);
        if ($hasCoords) {
            $row['has_coordinates'] = (int) ($row['has_coordinates'] ?? 0);
            foreach (['min_lat', 'max_lat', 'min_lng', 'max_lng'] as $k) {
                $row[$k] = isset($row[$k]) && $row[$k] !== null ? (float) $row[$k] : null;
            }
        }

        return $row;
    }

    // =========================================================================
    // Internals — cascade
    // =========================================================================

    /** @return array<string,mixed> */
    private function resolveFromTown(int $townId): array
    {
        $town = $this->entity('towns_villages', $townId, ['id', 'name', 'city_id', 'state_id', 'country_id', 'latitude', 'longitude', 'flag']);
        if ($town === null) {
            return $this->failure(['town_village_id' => "No town/village with id {$townId}."]);
        }
        $out          = $this->enrichTown($town);
        $out['valid'] = true;
        $out['errors'] = [];

        return $out;
    }

    /** @return array<string,mixed> */
    private function resolveFromCity(int $cityId): array
    {
        $city = $this->entity('cities', $cityId, ['id', 'name', 'state_id', 'country_id', 'latitude', 'longitude', 'flag']);
        if ($city === null) {
            return $this->failure(['city_id' => "No city with id {$cityId}."]);
        }
        $out          = $this->enrichCity($city);
        $out['valid'] = true;
        $out['errors'] = [];

        return $out;
    }

    /** @return array<string,mixed> */
    private function resolveFromCoordinates(float $lat, float $lng): array
    {
        $loc = $this->reverseGeocode($lat, $lng);
        if ($loc === null) {
            return $this->failure([
                'location' => sprintf(
                    'No town/village or city found within %dkm of (%.4f, %.4f).',
                    (int) self::CITY_RADIUS_KM,
                    $lat,
                    $lng,
                ),
            ]);
        }
        $loc['input_latitude']  = $lat;
        $loc['input_longitude'] = $lng;
        $loc['valid']           = true;
        $loc['errors']          = [];

        return $loc;
    }

    // =========================================================================
    // Internals — spatial
    // =========================================================================

    /**
     * Nearest single row in $table (towns_villages|cities) within $maxRadiusKm.
     *
     * @return array<string,mixed>|null
     */
    private function findNearest(string $table, float $lat, float $lng, float $maxRadiusKm): ?array
    {
        $rows = $this->findInRadius($table, $lat, $lng, $maxRadiusKm, 1);

        return $rows[0] ?? null;
    }

    /**
     * Bounding-box-then-Haversine radius query. Only 'towns_villages' and
     * 'cities' are permitted tables (guards against SQL injection via $table).
     *
     * @return list<array<string,mixed>>
     */
    private function findInRadius(string $table, float $lat, float $lng, float $radiusKm, int $limit): array
    {
        $cols = match ($table) {
            'towns_villages' => 'id, name, city_id, state_id, country_id, latitude, longitude',
            'cities'         => 'id, name, state_id, country_id, latitude, longitude',
            default          => throw new InvalidArgumentException("Unsupported spatial table {$table}"),
        };

        $bbox = $this->boundingBox($lat, $lng, $radiusKm);
        $sql  = "
            SELECT {$cols},
                (6371 * acos(
                    LEAST(1.0, GREATEST(-1.0,
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    ))
                )) AS distance
            FROM " . $this->db->protectIdentifiers($table) . "
            WHERE flag = 1
                AND latitude  BETWEEN ? AND ?
                AND longitude BETWEEN ? AND ?
            HAVING distance <= ?
            ORDER BY distance ASC
            LIMIT ?
        ";

        $rows = $this->db->query($sql, [
            $lat, $lng, $lat,
            $bbox['min_lat'], $bbox['max_lat'],
            $bbox['min_lng'], $bbox['max_lng'],
            $radiusKm, $limit,
        ])->getResultArray();

        foreach ($rows as &$r) {
            $r['distance'] = round((float) $r['distance'], 3);
        }
        unset($r);

        return $rows;
    }

    /** @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float} */
    private function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.32;
        $cos      = cos(deg2rad($lat));
        $lngDelta = abs($cos) < 1e-9 ? 180.0 : $radiusKm / (111.32 * $cos);

        return [
            'min_lat' => $lat - $latDelta,
            'max_lat' => $lat + $latDelta,
            'min_lng' => $lng - $lngDelta,
            'max_lng' => $lng + $lngDelta,
        ];
    }

    // =========================================================================
    // Internals — enrichment
    // =========================================================================

    /**
     * @param array<string,mixed> $town
     * @return array<string,mixed>
     */
    private function enrichTown(array $town): array
    {
        $out = $this->blank();
        $out['town_village_id'] = (int) $town['id'];
        $out['town_name']       = $town['name'] ?? null;
        $out['latitude']        = isset($town['latitude']) ? (float) $town['latitude'] : null;
        $out['longitude']       = isset($town['longitude']) ? (float) $town['longitude'] : null;

        if (! empty($town['city_id'])) {
            $city = $this->entity('cities', (int) $town['city_id'], ['id', 'name']);
            $out['city_id']   = (int) $town['city_id'];
            $out['city_name'] = $city['name'] ?? null;
        }

        $this->applyStateCountry($out, $town['state_id'] ?? null, $town['country_id'] ?? null);
        $out['valid'] = true;

        return $out;
    }

    /**
     * @param array<string,mixed> $city
     * @return array<string,mixed>
     */
    private function enrichCity(array $city): array
    {
        $out = $this->blank();
        $out['city_id']   = (int) $city['id'];
        $out['city_name'] = $city['name'] ?? null;
        $out['latitude']  = isset($city['latitude']) ? (float) $city['latitude'] : null;
        $out['longitude'] = isset($city['longitude']) ? (float) $city['longitude'] : null;

        $this->applyStateCountry($out, $city['state_id'] ?? null, $city['country_id'] ?? null);
        $out['valid'] = true;

        return $out;
    }

    /**
     * Fill state/country/subregion/region fields into a result array.
     *
     * @param array<string,mixed> $out
     */
    private function applyStateCountry(array &$out, mixed $stateId, mixed $countryId): void
    {
        if (! empty($stateId)) {
            $state = $this->entity('states', (int) $stateId, ['id', 'name', 'country_id']);
            if ($state !== null) {
                $out['state_id']   = (int) $state['id'];
                $out['state_name'] = $state['name'] ?? null;
                $countryId         = $countryId ?: ($state['country_id'] ?? null);
            }
        }
        if (! empty($countryId)) {
            $country = $this->entity('countries', (int) $countryId, ['id', 'name', 'iso2', 'region_id', 'subregion_id']);
            if ($country !== null) {
                $out['country_id']   = (int) $country['id'];
                $out['country_name'] = $country['name'] ?? null;
                $out['country_code'] = $country['iso2'] ?? null;
                $rr = $this->regionForCountry((int) $country['id']);
                if ($rr !== null) {
                    $out['region_id']      = $rr['region_id'] ?? null;
                    $out['region_name']    = $rr['region_name'] ?? null;
                    $out['subregion_id']   = $rr['subregion_id'] ?? null;
                    $out['subregion_name'] = $rr['subregion_name'] ?? null;
                }
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function regionForCountry(int $countryId): ?array
    {
        $key = "country_region_{$countryId}";
        if (array_key_exists($key, $this->queryCache)) {
            return $this->queryCache[$key];
        }

        $country = $this->entity('countries', $countryId, ['region_id', 'subregion_id']);
        if ($country === null) {
            return $this->queryCache[$key] = null;
        }

        $result = ['region_id' => null, 'region_name' => null, 'subregion_id' => null, 'subregion_name' => null];

        if (! empty($country['subregion_id'])) {
            $sub = $this->entity('subregions', (int) $country['subregion_id'], ['id', 'name', 'region_id']);
            if ($sub !== null) {
                $result['subregion_id']   = (int) $sub['id'];
                $result['subregion_name'] = $sub['name'] ?? null;
                if (! empty($sub['region_id'])) {
                    $country['region_id'] = $sub['region_id'];
                }
            }
        }
        if (! empty($country['region_id'])) {
            $reg = $this->entity('regions', (int) $country['region_id'], ['id', 'name']);
            if ($reg !== null) {
                $result['region_id']   = (int) $reg['id'];
                $result['region_name'] = $reg['name'] ?? null;
            }
        }

        return $this->queryCache[$key] = $result;
    }

    // =========================================================================
    // Internals — helpers
    // =========================================================================

    /**
     * Fetch a reference row by id, cached per request.
     *
     * @param list<string> $columns
     * @return array<string,mixed>|null
     */
    private function entity(string $table, int $id, array $columns = ['*']): ?array
    {
        $key = $table . '_' . $id . '_' . implode(',', $columns);
        if (array_key_exists($key, $this->entityCache)) {
            return $this->entityCache[$key];
        }

        // $columns are internal, fixed identifier lists (never user input); CI4's
        // select() escapes them. Default '*' selects all.
        $row = $this->db->table($table)
            ->select(implode(', ', $columns))
            ->where('id', $id)
            ->get()->getRowArray();

        return $this->entityCache[$key] = ($row ?: null);
    }

    private function assertCoordRange(float $lat, float $lng): void
    {
        if ($lat < -90 || $lat > 90) {
            throw new InvalidArgumentException("Latitude {$lat} out of range (-90..90).");
        }
        if ($lng < -180 || $lng > 180) {
            throw new InvalidArgumentException("Longitude {$lng} out of range (-180..180).");
        }
    }

    /** @return array<string,mixed> */
    private function parseTranslations(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeLocale(string $locale): string
    {
        return explode('-', strtolower(trim($locale)))[0] ?: 'en';
    }

    /** @return array<string,mixed> */
    private function blank(): array
    {
        return [
            'valid'             => false,
            'errors'            => [],
            'town_village_id'   => null,
            'town_name'         => null,
            'city_id'           => null,
            'city_name'         => null,
            'state_id'          => null,
            'state_name'        => null,
            'country_id'        => null,
            'country_name'      => null,
            'country_code'      => null,
            'region_id'         => null,
            'region_name'       => null,
            'subregion_id'      => null,
            'subregion_name'    => null,
            'latitude'          => null,
            'longitude'         => null,
            'resolution_method' => null,
            'distance_km'       => null,
        ];
    }

    /**
     * @param array<string,string> $errors
     * @return array<string,mixed>
     */
    private function failure(array $errors): array
    {
        $out           = $this->blank();
        $out['errors'] = $errors;

        return $out;
    }
}
