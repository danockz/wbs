<?php

declare(strict_types=1);

namespace WBS\Geo\Controllers;

use InvalidArgumentException;
use WBS\Geo\Config\Services as GeoServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Geo resolution + reverse-geocoding + radius search (SRS §8).
 *
 * These are reference-data reads over regions→…→towns_villages. They expose no
 * personal location and require no consent — personal precision is governed by
 * LocationService/consent, not here. Coordinate inputs are range-validated; the
 * resolver throws InvalidArgumentException on out-of-range coords, which this
 * controller maps to a 422 rather than a 500.
 */
final class GeoController extends BaseController
{
    public function resolve()
    {
        $in     = $this->input();
        $result = GeoServices::resolver()->resolve($in);

        // A resolver payload carries its own `valid`/`errors`; surface HTTP status accordingly.
        if (empty($result['valid'])) {
            return $this->respondWith(Result::fail('UNRESOLVED', 'geo.unresolved', 422, $result['errors'] ?? []));
        }

        return $this->respondWith(Result::ok($result));
    }

    public function reverseGeocode()
    {
        try {
            $lat = (float) $this->field('latitude');
            $lng = (float) $this->field('longitude');
            $loc = GeoServices::resolver()->reverseGeocode($lat, $lng);
        } catch (InvalidArgumentException $e) {
            return $this->respondWith(Result::fail('BAD_COORDINATES', $e->getMessage(), 422));
        }

        if ($loc === null) {
            return $this->respondWith(Result::notFound('geo.no_place_nearby', 'NO_PLACE_NEARBY'));
        }

        return $this->respondPage(
            Result::ok($loc),
            'geo_reverse',
            static fn (array $d): array => ['record' => $d],
        );
    }

    public function placesInRadius()
    {
        try {
            $places = GeoServices::resolver()->findPlacesInRadius(
                (float) $this->field('latitude'),
                (float) $this->field('longitude'),
                (float) $this->field('radius_km', 25),
                (int) $this->field('limit', 50),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWith(Result::fail('BAD_COORDINATES', $e->getMessage(), 422));
        }

        return $this->respondPage(
            Result::ok(['places' => $places, 'count' => count($places)]),
            'geo_places',
            static fn (array $d): array => ['rows' => $d['places'] ?? [], 'count' => $d['count'] ?? null],
        );
    }

    public function stats(string $groupBy = 'country')
    {
        $stats = GeoServices::resolver()->geographicStats($groupBy);
        if ($stats === []) {
            return $this->respondWith(Result::fail('BAD_GROUPING', 'geo.bad_grouping', 422));
        }

        return $this->respondPage(
            Result::ok($stats),
            'geo_stats',
            static function (array $d): array {
                if (array_is_list($d)) {
                    return ['rows' => $d];
                }
                $rows = [];
                foreach ($d as $label => $count) {
                    $rows[] = is_array($count) ? $count : ['label' => (string) $label, 'count' => $count];
                }

                return ['rows' => $rows];
            },
        );
    }
}
