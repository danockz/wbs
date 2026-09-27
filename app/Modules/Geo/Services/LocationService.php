<?php

declare(strict_types=1);

namespace WBS\Geo\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Location writes for addresses/venues (SRS §8.2/§8.3).
 *
 *  - Maintains the POINT SRID 4326 column from decimal lat/lng, always in
 *    (longitude, latitude) order per SRS.
 *  - Enforces consent-gated precision: 'exact'/'neighborhood' precision for a
 *    PERSON requires a recorded location consent; without consent the stored
 *    precision is coarsened (never store precise personal GPS without consent).
 *  - Venue (non-personal) data may be precise per its own discovery policy.
 */
final class LocationService
{
    private const PRECISION_RANK = [
        'exact'        => 5,
        'neighborhood' => 4,
        'city'         => 3,
        'state'        => 2,
        'country'      => 1,
        'unknown'      => 0,
    ];

    private const VENUE_TYPES = [
        'church', 'hall', 'conference_center', 'classroom', 'outdoor',
        'fellowship_hall', 'auditorium', 'training_center', 'other',
    ];

    private const VENUE_STATUSES = ['active', 'inactive', 'maintenance', 'closed'];

    private const DEFAULT_VENUE_LIMIT = 20;
    private const MAX_VENUE_LIMIT     = 200;
    private const MAX_RADIUS_KM       = 500.0;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        // The single writer of denormalized venue/group geo. Optional so unit
        // tests can construct LocationService without the sync wiring; when null,
        // CRUD still works and the stamping is simply skipped (the backfill
        // command can re-stamp later).
        private readonly ?LocationSyncService $sync = null,
    ) {
    }

    /**
     * Create/replace a venue address with geometry.
     *
     * @param array<string,mixed> $data
     */
    public function upsertAddress(string $organizationId, array $data): Result
    {
        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            return Result::fail('BAD_LATITUDE', 'geo.bad_latitude', 422);
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            return Result::fail('BAD_LONGITUDE', 'geo.bad_longitude', 422);
        }

        $now = $this->clock->nowUtcString();
        $id  = $data['id'] ?? Uuid::v7();

        $fields = [
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'region_id'          => $data['region_id'] ?? null,
            'subregion_id'       => $data['subregion_id'] ?? null,
            'country_id'         => $data['country_id'] ?? null,
            'state_id'           => $data['state_id'] ?? null,
            'city_id'            => $data['city_id'] ?? null,
            'town_village_id'    => $data['town_village_id'] ?? null,
            'line1'              => $data['line1'] ?? null,
            'line2'              => $data['line2'] ?? null,
            'postal_code'        => $data['postal_code'] ?? null,
            'latitude'           => $lat,
            'longitude'          => $lng,
            'timezone'           => $data['timezone'] ?? null,
            'location_precision' => $data['location_precision'] ?? 'unknown',
            'location_source'    => $data['location_source'] ?? null,
            'geocoded_at'        => $data['geocoded_at'] ?? null,
            'updated_at'         => $now,
        ];

        $exists = $this->db->table('addresses')->where('id', $id)->countAllResults() > 0;
        if ($exists) {
            $this->db->table('addresses')->where('id', $id)->update($fields);
        } else {
            $fields['created_at'] = $now;
            $this->db->table('addresses')->insert($fields);
        }

        $this->syncPoint('addresses', $id, $lat, $lng);

        return $exists
            ? Result::ok(['id' => $id])
            : Result::created(['id' => $id]);
    }

    /**
     * Resolve the precision at which a person's location may be stored/shown,
     * given consent. Coarsens to 'city' when precise precision lacks consent.
     */
    public function effectivePersonPrecision(string $subjectId, string $purpose, string $requested): string
    {
        $requestedRank = self::PRECISION_RANK[$requested] ?? 0;
        if ($requestedRank < self::PRECISION_RANK['neighborhood']) {
            return $requested; // city/state/country/unknown need no exact-GPS consent
        }

        $consent = $this->db->table('location_consents')
            ->where('subject_id', $subjectId)
            ->where('purpose', $purpose)
            ->where('revoked_at', null)
            ->orderBy('granted_at', 'DESC')
            ->get()->getRowArray();

        if ($consent === null) {
            return 'city'; // no consent -> coarsen precise personal location
        }

        $granted = self::PRECISION_RANK[$consent['precision_granted']] ?? self::PRECISION_RANK['city'];

        // Cannot exceed what was consented to.
        return $requestedRank <= $granted ? $requested : $consent['precision_granted'];
    }

    /** Record a location-use consent for a person. */
    public function grantConsent(string $organizationId, string $subjectId, string $purpose, string $precision = 'city'): Result
    {
        $now = $this->clock->nowUtcString();
        $id  = Uuid::v7();
        $this->db->table('location_consents')->insert([
            'id'                => $id,
            'organization_id'   => $organizationId,
            'subject_id'        => $subjectId,
            'purpose'           => $purpose,
            'precision_granted' => $precision,
            'granted_at'        => $now,
            'created_at'        => $now,
        ]);

        return Result::created(['id' => $id]);
    }

    // =========================================================================
    // VENUE CRUD (SRS §8.3) — non-personal facility data
    // =========================================================================

    /**
     * Create a venue. Requires either coordinates or a resolvable location id.
     * Coordinates are validated and the POINT geometry is synced.
     *
     * @param array<string,mixed> $data
     */
    public function createVenue(string $organizationId, array $data, ?string $createdBy = null): Result
    {
        if (empty($data['name'])) {
            return Result::fail('NAME_REQUIRED', 'venue.name_required', 422);
        }

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        if (($err = $this->coordError($lat, $lng)) !== null) {
            return $err;
        }
        $hasLocation = $lat !== null && $lng !== null;
        foreach (['town_village_id', 'city_id', 'state_id', 'country_id'] as $k) {
            if (! empty($data[$k])) {
                $hasLocation = true;
            }
        }
        if (! $hasLocation) {
            return Result::fail('LOCATION_REQUIRED', 'venue.location_required', 422);
        }

        $now = $this->clock->nowUtcString();
        $id  = Uuid::v7();
        $this->db->table('venues')->insert([
            'id'                     => $id,
            'organization_id'        => $organizationId,
            'address_id'             => $data['address_id'] ?? null,
            'name'                   => $data['name'],
            'venue_type'             => $this->validVenueType((string) ($data['venue_type'] ?? 'other')),
            'status'                 => $this->validVenueStatus((string) ($data['status'] ?? 'active')),
            'mode'                   => $data['mode'] ?? 'physical',
            'address_text'           => $data['address_text'] ?? null,
            'capacity'               => isset($data['capacity']) ? (int) $data['capacity'] : null,
            'contact_phone'          => $data['contact_phone'] ?? null,
            'contact_email'          => $data['contact_email'] ?? null,
            'website'                => $data['website'] ?? null,
            'operating_hours'        => $this->encodeJson($data['operating_hours'] ?? null),
            'accessibility_features' => $this->encodeJson($data['accessibility_features'] ?? null),
            'accessibility'          => $this->encodeJson($data['accessibility'] ?? null),
            'parking_info'           => $data['parking_info'] ?? null,
            'latitude'               => $lat,
            'longitude'              => $lng,
            'timezone'               => $data['timezone'] ?? null,
            'discovery_status'       => $data['discovery_status'] ?? 'private',
            'version'                => 1,
            'created_by'             => $createdBy,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        $this->syncPoint('venues', $id, $lat, $lng);
        // Stamp the venue's denormalized geo (country/state/city + bucket key) so
        // the unified directory can drill it without a per-row address join.
        $this->sync?->stampVenue($id);

        return Result::created(['id' => $id, 'version' => 1]);
    }

    /**
     * Update a venue with optimistic locking. When $expectedVersion is given and
     * does not match the stored version, the update is rejected as a conflict.
     *
     * @param array<string,mixed> $data
     */
    public function updateVenue(string $venueId, array $data, ?string $updatedBy = null, ?int $expectedVersion = null): Result
    {
        $venue = $this->db->table('venues')->where('id', $venueId)->where('deleted_at', null)->get()->getRowArray();
        if ($venue === null) {
            return Result::notFound('venue.not_found', 'VENUE_NOT_FOUND');
        }
        if ($expectedVersion !== null && (int) $venue['version'] !== $expectedVersion) {
            return Result::fail('VERSION_CONFLICT', 'venue.version_conflict', 409, [
                'expected' => $expectedVersion,
                'actual'   => (int) $venue['version'],
            ]);
        }

        $lat = array_key_exists('latitude', $data) ? ($data['latitude'] !== null ? (float) $data['latitude'] : null) : (isset($venue['latitude']) ? (float) $venue['latitude'] : null);
        $lng = array_key_exists('longitude', $data) ? ($data['longitude'] !== null ? (float) $data['longitude'] : null) : (isset($venue['longitude']) ? (float) $venue['longitude'] : null);
        if (($err = $this->coordError($lat, $lng)) !== null) {
            return $err;
        }

        $fields = ['updated_by' => $updatedBy, 'updated_at' => $this->clock->nowUtcString(), 'version' => (int) $venue['version'] + 1];
        $map    = [
            'name', 'mode', 'address_text', 'capacity', 'contact_phone', 'contact_email',
            'website', 'parking_info', 'timezone', 'discovery_status', 'address_id',
        ];
        foreach ($map as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $k === 'capacity' ? ($data[$k] !== null ? (int) $data[$k] : null) : $data[$k];
            }
        }
        if (array_key_exists('venue_type', $data)) {
            $fields['venue_type'] = $this->validVenueType((string) $data['venue_type']);
        }
        if (array_key_exists('status', $data)) {
            $fields['status'] = $this->validVenueStatus((string) $data['status']);
        }
        foreach (['operating_hours', 'accessibility_features', 'accessibility'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[$k] = $this->encodeJson($data[$k]);
            }
        }
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $fields['latitude']  = $lat;
            $fields['longitude'] = $lng;
        }

        $this->db->table('venues')->where('id', $venueId)->update($fields);
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $this->syncPoint('venues', $venueId, $lat, $lng);
        }
        // Re-stamp the venue's geo if its address/coords may have changed, then
        // Re-resolve this venue's geo. Groups are NOT re-stamped: a group holds
        // no copy of location, it reads geo through `primary_venue_id`, so every
        // group meeting here follows this write automatically.
        if ($this->sync !== null
            && (array_key_exists('address_id', $data)
                || array_key_exists('latitude', $data)
                || array_key_exists('longitude', $data))) {
            $this->sync->stampVenue($venueId);
        }

        return Result::ok(['id' => $venueId, 'version' => $fields['version']]);
    }

    /** Soft-delete (default) or hard-delete a venue. */
    public function deleteVenue(string $venueId, bool $hardDelete = false): Result
    {
        $venue = $this->db->table('venues')->where('id', $venueId)->get()->getRowArray();
        if ($venue === null) {
            return Result::notFound('venue.not_found', 'VENUE_NOT_FOUND');
        }

        if ($hardDelete) {
            $this->db->table('venue_group_assignments')->where('venue_id', $venueId)->delete();
            $this->db->table('venues')->where('id', $venueId)->delete();
            $this->detachPrimaryVenue($venueId);

            return Result::ok(['id' => $venueId, 'deleted' => 'hard']);
        }

        $this->db->table('venues')->where('id', $venueId)->update([
            'deleted_at' => $this->clock->nowUtcString(),
            'status'     => 'closed',
        ]);
        $this->detachPrimaryVenue($venueId);

        return Result::ok(['id' => $venueId, 'deleted' => 'soft']);
    }

    /**
     * A deleted venue must never leave a dangling `groups.primary_venue_id`. Null
     * the pointer on any group that referenced it, then re-stamp that group from
     * its address_id fallback so its directory location stays correct.
     */
    private function detachPrimaryVenue(string $venueId): void
    {
        $groups = $this->db->table('groups')->select('id')
            ->where('primary_venue_id', $venueId)->get()->getResultArray();
        if ($groups === []) {
            return;
        }
        // Clearing the pointer is the WHOLE update: those groups have no geo of
        // their own, so with no venue they simply read as Unlocated.
        $this->db->table('groups')->where('primary_venue_id', $venueId)
            ->update(['primary_venue_id' => null, 'updated_at' => $this->clock->nowUtcString()]);
    }

    /** Retrieve a single (non-deleted) venue. */
    public function getVenue(string $venueId): Result
    {
        $venue = $this->db->table('venues')->where('id', $venueId)->where('deleted_at', null)->get()->getRowArray();
        if ($venue === null) {
            return Result::notFound('venue.not_found', 'VENUE_NOT_FOUND');
        }

        return Result::ok($this->hydrateVenue($venue));
    }

    /**
     * Paginated, filterable venue listing for an organization.
     *
     * @param array<string,mixed> $filters  venue_type|status|discovery_status|q|limit|offset
     */
    public function listVenues(string $organizationId, array $filters = []): Result
    {
        $limit  = max(1, min((int) ($filters['limit'] ?? self::DEFAULT_VENUE_LIMIT), self::MAX_VENUE_LIMIT));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $b = $this->db->table('venues')->where('organization_id', $organizationId)->where('deleted_at', null);
        if (! empty($filters['venue_type'])) {
            $b->where('venue_type', $filters['venue_type']);
        }
        if (! empty($filters['status'])) {
            $b->where('status', $filters['status']);
        }
        if (! empty($filters['discovery_status'])) {
            $b->where('discovery_status', $filters['discovery_status']);
        }
        if (! empty($filters['q'])) {
            $b->like('name', (string) $filters['q']);
        }

        $total = (clone $b)->countAllResults(false);
        $rows  = $b->orderBy('name', 'ASC')->limit($limit, $offset)->get()->getResultArray();

        return Result::ok([
            'venues' => array_map([$this, 'hydrateVenue'], $rows),
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Admin global→local venue directory: Country ▸ State ▸ City ▸ Venue, each
     * venue carrying its assigned groups (primary/secondary/overflow) and
     * facility facts. Reads the denormalized geo columns LocationSyncService
     * maintains on `venues` (no per-row addresses join). Unlike the public
     * directory this includes EVERY venue — private/unlisted and any status —
     * clearly flagged, gated at the route by `venue.manage`.
     *
     * Pure shaping is delegated to {@see nestVenuesByGeo()} so the tree is
     * unit-testable without a database.
     *
     * @return list<array<string,mixed>>
     */
    public function venueDirectory(string $organizationId): array
    {
        $venues = $this->db->table('venues')
            ->select('id, name, venue_type, status, discovery_status, capacity, '
                . 'country_id, state_id, city_id, country_label, state_label, city_label, location_group_key')
            ->where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        // Assigned groups per venue (one bounded read), keyed by venue_id.
        $assignments = $this->db->table('venue_group_assignments vga')
            ->select('vga.venue_id, vga.assignment_type, g.slug, g.name')
            ->join('groups g', 'g.id = vga.group_id', 'left')
            ->where('vga.organization_id', $organizationId)
            ->get()->getResultArray();
        $byVenue = [];
        foreach ($assignments as $a) {
            $byVenue[(string) $a['venue_id']][] = [
                'slug'            => (string) ($a['slug'] ?? ''),
                'name'            => (string) ($a['name'] ?? ''),
                'assignment_type' => (string) ($a['assignment_type'] ?? 'primary'),
            ];
        }

        return $this->nestVenuesByGeo($venues, $byVenue);
    }

    /**
     * Pure Country ▸ State ▸ City ▸ Venue nesting of admin venue rows. No DB.
     * `$groupsByVenue` maps venue_id → list of assigned-group cards. A venue with
     * no resolved country lands in the pinned "Unlocated" bucket; a resolved
     * country missing state/city holds the venue on an "(Unspecified)" child.
     *
     * @param list<array<string,mixed>>             $venues
     * @param array<string,list<array<string,mixed>>> $groupsByVenue
     *
     * @return list<array<string,mixed>>
     */
    public function nestVenuesByGeo(array $venues, array $groupsByVenue = []): array
    {
        $unlocated = '__unlocated__';
        /** @var array<string,array<string,mixed>> $countries */
        $countries = [];

        foreach ($venues as $v) {
            $countryId   = $v['country_id'] ?? null;
            $countryName = trim((string) ($v['country_label'] ?? ''));

            if ($countryId === null || $countryName === '') {
                $ck = $unlocated;
                if (! isset($countries[$ck])) {
                    $countries[$ck] = ['key' => 'co-' . $ck, 'label' => '', 'level' => 'country',
                        'count' => 0, 'children' => [], '_order' => PHP_INT_MAX];
                }
            } else {
                $ck = (string) $countryId;
                if (! isset($countries[$ck])) {
                    $countries[$ck] = ['key' => 'co-' . $ck, 'label' => $countryName, 'level' => 'country',
                        'count' => 0, 'children' => [], '_order' => 0];
                }
            }

            $stateName = trim((string) ($v['state_label'] ?? ''));
            $cityName  = trim((string) ($v['city_label'] ?? ''));
            $sk = ($v['state_id'] ?? null) !== null && $stateName !== '' ? (string) $v['state_id'] : '__nostate__';
            $xk = ($v['city_id'] ?? null) !== null && $cityName !== '' ? (string) $v['city_id'] : '__nocity__';

            $country = &$countries[$ck];
            if (! isset($country['children'][$sk])) {
                $country['children'][$sk] = ['key' => 'st-' . $ck . '-' . $sk, 'label' => $stateName,
                    'level' => 'state', 'count' => 0, 'children' => [], '_nospec' => $sk === '__nostate__'];
            }
            $state = &$country['children'][$sk];
            if (! isset($state['children'][$xk])) {
                $state['children'][$xk] = ['key' => 'ci-' . $ck . '-' . $sk . '-' . $xk, 'label' => $cityName,
                    'level' => 'city', 'count' => 0, 'venues' => [], '_nospec' => $xk === '__nocity__'];
            }
            $vid = (string) $v['id'];
            $state['children'][$xk]['venues'][] = [
                'id'               => $vid,
                'name'             => (string) ($v['name'] ?? ''),
                'venue_type'       => $v['venue_type'] ?? null,
                'status'           => $v['status'] ?? null,
                'discovery_status' => $v['discovery_status'] ?? null,
                'capacity'         => isset($v['capacity']) ? (int) $v['capacity'] : null,
                'groups'           => $groupsByVenue[$vid] ?? [],
            ];
            $state['children'][$xk]['count']++;
            $state['count']++;
            $country['count']++;
            unset($country, $state);
        }

        // Sort + flatten (named levels alphabetical; unspecified sinks; Unlocated last).
        $byLabel = static function (array $a, array $b): int {
            if (! empty($a['_nospec']) && empty($b['_nospec'])) {
                return 1;
            }
            if (empty($a['_nospec']) && ! empty($b['_nospec'])) {
                return -1;
            }

            return strcasecmp((string) $a['label'], (string) $b['label']);
        };

        $out = array_values($countries);
        usort($out, static fn ($a, $b) => ($a['_order'] <=> $b['_order']) ?: strcasecmp((string) $a['label'], (string) $b['label']));
        foreach ($out as &$country) {
            $states = array_values($country['children']);
            usort($states, $byLabel);
            foreach ($states as &$state) {
                $cities = array_values($state['children']);
                usort($cities, $byLabel);
                foreach ($cities as &$city) {
                    usort($city['venues'], static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
                }
                unset($city);
                $state['children'] = $cities;
            }
            unset($state);
            $country['children'] = $states;
            unset($country['_order']);
        }
        unset($country);

        return $out;
    }

    /**
     * Radius search over an organization's discoverable venues, nearest first.
     * Bounding-box pre-filter then exact Haversine (parameterized).
     */
    public function findNearbyVenues(string $organizationId, float $latitude, float $longitude, float $radiusKm = 25.0, int $limit = 20): Result
    {
        if (($err = $this->coordError($latitude, $longitude)) !== null) {
            return $err;
        }
        $radiusKm = min(max($radiusKm, 1.0), self::MAX_RADIUS_KM);
        $limit    = max(1, min($limit, self::MAX_VENUE_LIMIT));

        $latDelta = $radiusKm / 111.32;
        $cos      = cos(deg2rad($latitude));
        $lngDelta = abs($cos) < 1e-9 ? 180.0 : $radiusKm / (111.32 * $cos);

        $sql = '
            SELECT id, name, venue_type, status, capacity, latitude, longitude, discovery_status,
                (6371 * acos(
                    LEAST(1.0, GREATEST(-1.0,
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    ))
                )) AS distance_km
            FROM venues
            WHERE organization_id = ?
                AND deleted_at IS NULL
                AND latitude IS NOT NULL AND longitude IS NOT NULL
                AND latitude  BETWEEN ? AND ?
                AND longitude BETWEEN ? AND ?
            HAVING distance_km <= ?
            ORDER BY distance_km ASC
            LIMIT ?
        ';
        $rows = $this->db->query($sql, [
            $latitude, $longitude, $latitude,
            $organizationId,
            $latitude - $latDelta, $latitude + $latDelta,
            $longitude - $lngDelta, $longitude + $lngDelta,
            $radiusKm, $limit,
        ])->getResultArray();

        foreach ($rows as &$r) {
            $r['distance_km'] = round((float) $r['distance_km'], 2);
            $r['capacity']    = $r['capacity'] !== null ? (int) $r['capacity'] : null;
        }
        unset($r);

        return Result::ok(['venues' => $rows, 'count' => count($rows), 'radius_km' => $radiusKm]);
    }

    /**
     * Venue analytics for an organization: totals by type/status and capacity.
     */
    public function venueStats(string $organizationId): Result
    {
        $base = fn () => $this->db->table('venues')->where('organization_id', $organizationId)->where('deleted_at', null);

        $byType = $base()->select('venue_type, COUNT(*) AS n')->groupBy('venue_type')->get()->getResultArray();
        $byStat = $base()->select('status, COUNT(*) AS n')->groupBy('status')->get()->getResultArray();
        $cap    = $base()->select('COUNT(*) AS total, SUM(capacity) AS capacity_sum, AVG(capacity) AS capacity_avg')->get()->getRowArray() ?: [];

        return Result::ok([
            'total'         => (int) ($cap['total'] ?? 0),
            'capacity_sum'  => isset($cap['capacity_sum']) ? (int) $cap['capacity_sum'] : 0,
            'capacity_avg'  => isset($cap['capacity_avg']) && $cap['capacity_avg'] !== null ? round((float) $cap['capacity_avg'], 1) : null,
            'by_type'       => array_column(array_map(static fn ($r) => ['k' => $r['venue_type'], 'n' => (int) $r['n']], $byType), 'n', 'k'),
            'by_status'     => array_column(array_map(static fn ($r) => ['k' => $r['status'], 'n' => (int) $r['n']], $byStat), 'n', 'k'),
        ]);
    }

    /**
     * Batch venue creation. Returns per-row results; a failure on one row does
     * not abort the others.
     *
     * @param list<array<string,mixed>> $venues
     */
    public function bulkCreateVenues(string $organizationId, array $venues, ?string $createdBy = null): Result
    {
        $created = [];
        $errors  = [];
        foreach ($venues as $i => $v) {
            $r = $this->createVenue($organizationId, $v, $createdBy);
            if ($r->ok) {
                $created[] = $r->data['id'] ?? null;
            } else {
                $errors[$i] = $r->code;
            }
        }

        return Result::ok([
            'created_count' => count($created),
            'created_ids'   => $created,
            'errors'        => $errors,
        ]);
    }

    /** Associate a venue with a group (idempotent on (venue, group)). */
    public function assignVenueToGroup(string $organizationId, string $venueId, string $groupId, string $assignmentType = 'primary'): Result
    {
        $venue = $this->db->table('venues')->where('id', $venueId)->where('deleted_at', null)->countAllResults();
        if ($venue === 0) {
            return Result::notFound('venue.not_found', 'VENUE_NOT_FOUND');
        }

        // Cross-org safety: a group may only point at a venue in the same org.
        $grp = $this->db->table('groups')->select('organization_id')
            ->where('id', $groupId)->get()->getRowArray();
        if ($grp === null || (string) $grp['organization_id'] !== $organizationId) {
            return Result::fail('GROUP_ORG_MISMATCH', 'venue.group_org_mismatch', 422);
        }

        $existing = $this->db->table('venue_group_assignments')
            ->where('venue_id', $venueId)->where('group_id', $groupId)->get()->getRowArray();
        if ($existing !== null) {
            $this->db->table('venue_group_assignments')->where('id', $existing['id'])
                ->update(['assignment_type' => $assignmentType]);
            $this->applyPrimaryPointer($organizationId, $venueId, $groupId, $assignmentType);

            return Result::ok(['id' => $existing['id'], 'deduplicated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('venue_group_assignments')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'venue_id'        => $venueId,
            'group_id'        => $groupId,
            'assignment_type' => $assignmentType,
            'created_at'      => $this->clock->nowUtcString(),
        ]);
        $this->applyPrimaryPointer($organizationId, $venueId, $groupId, $assignmentType);

        return Result::created(['id' => $id]);
    }

    /**
     * Keep `groups.primary_venue_id` consistent with the PRIMARY assignment row.
     * A `primary` assignment repoints the group at this venue (source of truth
     * for its physical location) and re-stamps its denormalized location; a
     * non-primary assignment only clears the pointer if it had been this venue.
     */
    private function applyPrimaryPointer(string $organizationId, string $venueId, string $groupId, string $assignmentType): void
    {
        if ($assignmentType === 'primary') {
            $this->db->table('groups')->where('id', $groupId)->update([
                'primary_venue_id' => $venueId,
                'updated_at'       => $this->clock->nowUtcString(),
            ]);
            // Geo travels with the pointer — nothing to copy onto the group.

            return;
        }

        // A downgrade of THIS venue from primary → clear the pointer + re-stamp.
        $grp = $this->db->table('groups')->select('primary_venue_id')
            ->where('id', $groupId)->get()->getRowArray();
        if ($grp !== null && (string) ($grp['primary_venue_id'] ?? '') === $venueId) {
            $this->db->table('groups')->where('id', $groupId)->update([
                'primary_venue_id' => null,
                'updated_at'       => $this->clock->nowUtcString(),
            ]);
        }
    }

    /** Venues assigned to a group. */
    public function getGroupVenues(string $groupId): Result
    {
        $rows = $this->db->table('venue_group_assignments a')
            ->select('v.id, v.name, v.venue_type, v.status, v.capacity, v.latitude, v.longitude, a.assignment_type')
            ->join('venues v', 'v.id = a.venue_id')
            ->where('a.group_id', $groupId)
            ->where('v.deleted_at', null)
            ->orderBy('a.assignment_type', 'ASC')
            ->get()->getResultArray();

        return Result::ok(['venues' => $rows, 'count' => count($rows)]);
    }

    // =========================================================================
    // Venue helpers
    // =========================================================================

    /** @param array<string,mixed> $venue @return array<string,mixed> */
    private function hydrateVenue(array $venue): array
    {
        foreach (['operating_hours', 'accessibility_features', 'accessibility'] as $k) {
            if (isset($venue[$k]) && is_string($venue[$k])) {
                $venue[$k] = json_decode($venue[$k], true) ?: null;
            }
        }
        $venue['capacity'] = isset($venue['capacity']) && $venue['capacity'] !== null ? (int) $venue['capacity'] : null;
        $venue['version']  = isset($venue['version']) ? (int) $venue['version'] : 1;
        if (isset($venue['latitude'])) {
            $venue['latitude'] = $venue['latitude'] !== null ? (float) $venue['latitude'] : null;
        }
        if (isset($venue['longitude'])) {
            $venue['longitude'] = $venue['longitude'] !== null ? (float) $venue['longitude'] : null;
        }
        unset($venue['geo_point']); // binary geometry, never serialized

        return $venue;
    }

    private function validVenueType(string $type): string
    {
        return in_array($type, self::VENUE_TYPES, true) ? $type : 'other';
    }

    private function validVenueStatus(string $status): string
    {
        return in_array($status, self::VENUE_STATUSES, true) ? $status : 'active';
    }

    /** @param mixed $value */
    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value; // assume already-encoded JSON string
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
    }

    /** Returns a failing Result when coordinates are out of range, else null. */
    private function coordError(?float $lat, ?float $lng): ?Result
    {
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            return Result::fail('BAD_LATITUDE', 'geo.bad_latitude', 422);
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            return Result::fail('BAD_LONGITUDE', 'geo.bad_longitude', 422);
        }

        return null;
    }

    /** Maintain POINT SRID 4326 (lon, lat) from decimal coordinates. */
    private function syncPoint(string $table, string $id, ?float $lat, ?float $lng): void
    {
        if ($lat === null || $lng === null) {
            $this->db->table($table)->where('id', $id)->update(['geo_point' => null]);

            return;
        }
        // ST_SRID(POINT(lon, lat), 4326) — longitude first per SRS §8.2.
        $sql = 'UPDATE ' . $this->db->protectIdentifiers($table)
            . ' SET geo_point = ST_SRID(POINT(?, ?), 4326) WHERE id = ?';
        $this->db->query($sql, [$lng, $lat, $id]);
    }
}
