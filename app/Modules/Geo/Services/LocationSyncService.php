<?php

declare(strict_types=1);

namespace WBS\Geo\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;

/**
 * The single writer of resolved GEO — on VENUES only
 * (Groups+Venues+Geo unification).
 *
 * Location is NOT replicated across the three entities. The chain is:
 *
 *     groups.primary_venue_id → venues → Geo reference (countries/states/cities)
 *                                  └── venues.latitude/longitude (GPS)
 *
 * A group has no coordinates and no place ids of its own; it meets at a venue,
 * and the venue holds the geo. So this service stamps exactly ONE table:
 *
 *   • stampVenue($venueId) — resolve the venue's geo (from its address ids, or
 *     from lat/long via GeoResolverService when the address lacks ids) and write
 *     venues.{country_id,state_id,city_id,town_village_id,*_label,location_group_key}.
 *
 * Group-facing reads join the primary venue and take geo from it (see
 * GroupPublicService::geoDirectory / LocationService::venueDirectory); a group
 * with no venue is simply UNLOCATED. Moving a venue is therefore a ONE-table
 * write and every group pointing at it follows automatically — there is no
 * second copy to keep in step, and no group re-stamp fan-out.
 *
 * Stamping is idempotent (re-running yields the same values), fault-isolated (a
 * resolution failure leaves prior values intact and returns false; it never
 * throws into the caller's write), and self-contained (no cross-module hard FK).
 *
 * `location_group_key` is the stable directory bucket:
 *   "state:<id>"  when a state is resolved,
 *   "country:<id>" when only a country is resolved,
 *   null           when nothing resolves (→ the pinned "Unlocated" bucket).
 */
final class LocationSyncService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GeoResolverService $resolver,
    ) {
    }

    /**
     * Resolve + stamp a venue's denormalized geo from its address (or lat/long).
     * Returns true when a row was written, false on a missing venue or an
     * unresolvable location (prior values left intact).
     */
    public function stampVenue(string $venueId): bool
    {
        $venue = $this->db->table('venues')->where('id', $venueId)->get()->getRowArray();
        if ($venue === null) {
            return false;
        }

        $geo = $this->resolveForVenue($venue);
        if ($geo === null) {
            return false; // leave prior stamp intact
        }

        $this->db->table('venues')->where('id', $venueId)->update([
            'country_id'         => $geo['country_id'],
            'state_id'           => $geo['state_id'],
            'city_id'            => $geo['city_id'],
            'town_village_id'    => $geo['town_village_id'],
            'country_label'      => $geo['country_name'],
            'state_label'        => $geo['state_name'],
            'city_label'         => $geo['city_name'],
            'location_group_key' => $this->bucketKey($geo),
            'updated_at'         => $this->clock->nowUtcString(),
        ]);

        return true;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve a venue's geo: prefer explicit address ids, else the venue's own
     * lat/long via reverse-geocode. Returns the resolver's shape or null.
     *
     * @param array<string,mixed> $venue
     * @return array<string,mixed>|null
     */
    private function resolveForVenue(array $venue): ?array
    {
        $lat = null;
        $lng = null;
        $geo = $this->resolveFromAddressId($venue['address_id'] ?? null, $lat, $lng);
        if ($geo !== null) {
            return $geo;
        }

        // No address / unresolved → try the venue's own coordinates.
        $vlat = isset($venue['latitude']) ? (float) $venue['latitude'] : null;
        $vlng = isset($venue['longitude']) ? (float) $venue['longitude'] : null;
        if ($vlat !== null && $vlng !== null) {
            $res = $this->resolver->resolve(['latitude' => $vlat, 'longitude' => $vlng]);
            if (! empty($res['valid'])) {
                return $res;
            }
        }

        return null;
    }

    /**
     * Resolve geo from an address row's stored ids (town → city → coords),
     * writing back the address's lat/long into $lat/$lng by reference.
     *
     * @return array<string,mixed>|null
     */
    private function resolveFromAddressId(mixed $addressId, ?float &$lat, ?float &$lng): ?array
    {
        if (! is_string($addressId) || $addressId === '') {
            return null;
        }
        $addr = $this->db->table('addresses')->where('id', $addressId)->get()->getRowArray();
        if ($addr === null) {
            return null;
        }
        $lat = isset($addr['latitude']) ? (float) $addr['latitude'] : $lat;
        $lng = isset($addr['longitude']) ? (float) $addr['longitude'] : $lng;

        $input = [];
        if (! empty($addr['town_village_id'])) {
            $input['town_village_id'] = (int) $addr['town_village_id'];
        }
        if (! empty($addr['city_id'])) {
            $input['city_id'] = (int) $addr['city_id'];
        }
        if ($lat !== null && $lng !== null) {
            $input['latitude']  = $lat;
            $input['longitude'] = $lng;
        }
        if ($input === []) {
            return null;
        }

        $res = $this->resolver->resolve($input);

        return ! empty($res['valid']) ? $res : null;
    }

    /**
     * Stable directory bucket key from a resolved geo array. Prefers state, then
     * country; null when neither resolves.
     *
     * @param array<string,mixed> $geo
     */
    private function bucketKey(array $geo): ?string
    {
        if (! empty($geo['state_id'])) {
            return 'state:' . (int) $geo['state_id'];
        }
        if (! empty($geo['country_id'])) {
            return 'country:' . (int) $geo['country_id'];
        }

        return null;
    }
}
