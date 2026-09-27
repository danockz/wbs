<?php

declare(strict_types=1);

/**
 * LocationService::nestVenuesByGeo() — pure admin venue directory nesting.
 *
 * Proves the admin global→local venue browser nests Country ▸ State ▸ City ▸
 * Venue off the denormalized geo columns, and attaches each venue's assigned
 * groups:
 *   - venues nest under the right country/state/city and counts roll up;
 *   - two venues in the same city both appear, sorted by name;
 *   - assigned groups (primary/secondary/overflow) attach to their venue;
 *   - a venue with no resolved country lands in the pinned "Unlocated" bucket;
 *   - private/unlisted venues and any status are INCLUDED (admin view) and
 *     carry their discovery_status/status flags;
 *   - a resolved country missing state/city holds the venue on an
 *     "(Unspecified)" child rather than dropping it.
 *
 * Pure: no DB. Build a bare instance without invoking the constructor.
 *
 *   php app/Modules/Geo/Services/tests/venue_directory_nesting_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Geo/Services/GeoResolverService.php';
    require_once $root . '/app/Modules/Geo/Services/LocationSyncService.php';
    require_once $root . '/app/Modules/Geo/Services/LocationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ref = new ReflectionClass(\WBS\Geo\Services\LocationService::class);
    /** @var \WBS\Geo\Services\LocationService $svc */
    $svc = $ref->newInstanceWithoutConstructor();

    $v = static fn (array $o): array => $o + [
        'id' => 'V', 'name' => 'Venue', 'venue_type' => 'other', 'status' => 'active',
        'discovery_status' => 'public', 'capacity' => null,
        'country_id' => null, 'state_id' => null, 'city_id' => null,
        'country_label' => null, 'state_label' => null, 'city_label' => null,
        'location_group_key' => null,
    ];

    $venues = [
        $v(['id' => 'V2', 'name' => 'Community Hall', 'venue_type' => 'hall', 'capacity' => 150,
            'country_id' => 1, 'country_label' => 'Ghana', 'state_id' => 9, 'state_label' => 'Greater Accra',
            'city_id' => 3, 'city_label' => 'Accra', 'discovery_status' => 'private', 'status' => 'maintenance']),
        $v(['id' => 'V1', 'name' => 'Grace Chapel', 'venue_type' => 'church', 'capacity' => 800,
            'country_id' => 1, 'country_label' => 'Ghana', 'state_id' => 9, 'state_label' => 'Greater Accra',
            'city_id' => 3, 'city_label' => 'Accra']),
        // Resolved country only.
        $v(['id' => 'V3', 'name' => 'Ghana Mobile', 'country_id' => 1, 'country_label' => 'Ghana']),
        // Unlocated.
        $v(['id' => 'V9', 'name' => 'Mystery Site']),
    ];

    $groupsByVenue = [
        'V1' => [
            ['slug' => 'accra-central', 'name' => 'Accra Central', 'assignment_type' => 'primary'],
            ['slug' => 'legon', 'name' => 'Legon Fellowship', 'assignment_type' => 'secondary'],
        ],
        'V2' => [
            ['slug' => 'osu', 'name' => 'Osu Senior Cell', 'assignment_type' => 'primary'],
        ],
    ];

    $tree = $svc->nestVenuesByGeo($venues, $groupsByVenue);

    $chk('two top-level buckets (Ghana, Unlocated)', count($tree) === 2, (string) count($tree));
    $chk('Ghana first', ($tree[0]['label'] ?? '') === 'Ghana');
    $chk('Unlocated pinned last + empty label', ($tree[1]['label'] ?? 'x') === '');

    $ghana = $tree[0];
    $chk('Ghana count = 3 venues', ($ghana['count'] ?? 0) === 3, (string) ($ghana['count'] ?? -1));
    // Greater Accra state + "(Unspecified)" state for V3.
    $chk('Ghana has 2 states (Greater Accra + unspecified)', count($ghana['children']) === 2, (string) count($ghana['children']));
    $chk('named state sorts before unspecified', ($ghana['children'][0]['label'] ?? '') === 'Greater Accra'
        && ! empty($ghana['children'][1]['_nospec']));

    $accra = $ghana['children'][0]['children'][0];
    $chk('city Accra', ($accra['label'] ?? '') === 'Accra');
    $chk('Accra has 2 venues', count($accra['venues']) === 2);
    $chk('venues sorted by name (Community Hall before Grace Chapel)',
        $accra['venues'][0]['name'] === 'Community Hall' && $accra['venues'][1]['name'] === 'Grace Chapel');

    $grace = $accra['venues'][1];
    $chk('Grace Chapel has 2 assigned groups', count($grace['groups']) === 2);
    $chk('assignment types carried', $grace['groups'][0]['assignment_type'] === 'primary'
        && $grace['groups'][1]['assignment_type'] === 'secondary');
    $chk('Grace Chapel capacity carried', $grace['capacity'] === 800);

    $hall = $accra['venues'][0];
    $chk('private venue INCLUDED in admin view', $hall['discovery_status'] === 'private');
    $chk('maintenance status flagged', $hall['status'] === 'maintenance');

    $chk('Unlocated has 1 venue', ($tree[1]['count'] ?? 0) === 1);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
