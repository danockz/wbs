<?php

declare(strict_types=1);

/**
 * nestByGeoVenue → same place chain. Physical = venue+country; else Virtual.
 *
 *   php app/Modules/Groups/Services/tests/geo_venue_directory_nesting_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/GroupPublicService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ref = new ReflectionClass(\WBS\Groups\Services\GroupPublicService::class);
    /** @var \WBS\Groups\Services\GroupPublicService $svc */
    $svc = $ref->newInstanceWithoutConstructor();

    $row = static fn (array $o): array => $o + [
        'name' => 'G', 'slug' => 'g', 'tagline' => null, 'type' => null,
        'location_text' => null, 'contact_email' => null, 'contact_phone' => null,
        'geo_country_id' => null, 'geo_state_id' => null, 'geo_city_id' => null,
        'country_label' => null, 'state_label' => null, 'city_label' => null,
        'primary_venue_id' => null, 'venue_name' => null, 'venue_type' => null,
        'venue_capacity' => null, 'venue_address' => null, 'venue_postal' => null,
    ];

    $rows = [
        $row(['name' => 'Accra Central', 'slug' => 'accra-central', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra',
            'primary_venue_id' => 'V1', 'venue_name' => 'Grace Chapel', 'venue_type' => 'church', 'venue_capacity' => 800]),
        $row(['name' => 'Legon Fellowship', 'slug' => 'legon', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra',
            'primary_venue_id' => 'V1', 'venue_name' => 'Grace Chapel', 'venue_type' => 'church', 'venue_capacity' => 800]),
        $row(['name' => 'Osu Senior Cell', 'slug' => 'osu', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra',
            'primary_venue_id' => 'V2', 'venue_name' => 'Community Hall', 'venue_type' => 'hall', 'venue_capacity' => 150]),
        $row(['name' => 'Accra Roaming', 'slug' => 'roam', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra']),
        $row(['name' => 'Lagos One', 'slug' => 'lagos', 'geo_country_id' => 2, 'country_label' => 'Nigeria',
            'geo_state_id' => 20, 'state_label' => 'Lagos', 'geo_city_id' => 30, 'city_label' => 'Ikeja',
            'primary_venue_id' => 'V9', 'venue_name' => 'Ikeja Center']),
        $row(['name' => 'Nowhere Group', 'slug' => 'nowhere']),
    ];

    $tree = $svc->nestByGeoVenue($rows);
    $labels = array_map(static fn ($c) => (string) $c['label'], $tree);
    $chk('Ghana, Nigeria, then Virtual', $labels === ['Ghana', 'Nigeria', ''], json_encode($labels));
    $chk('Virtual level', ($tree[2]['level'] ?? '') === 'virtual');

    $ghana = $tree[0];
    $chk('Ghana count = 3 physical', (int) $ghana['count'] === 3, (string) $ghana['count']);
    $chk('Ghana has one state', count($ghana['children']) === 1);

    $st = $ghana['children'][0];
    $chk('state Greater Accra', $st['label'] === 'Greater Accra');
    $city = $st['children'][0];
    $chk('city Accra', $city['label'] === 'Accra');
    $chk('two venues (no unspecified)', count($city['children']) === 2, (string) count($city['children']));

    $venLabels = array_map(static fn ($v) => (string) $v['label'], $city['children']);
    $chk('venues alpha', $venLabels === ['Community Hall', 'Grace Chapel'], json_encode($venLabels));

    $grace = null;
    foreach ($city['children'] as $v) {
        if ($v['label'] === 'Grace Chapel') { $grace = $v; }
    }
    $chk('Grace Chapel count 2', ($grace['count'] ?? 0) === 2);
    $chk('Grace Chapel level venue', ($grace['level'] ?? '') === 'venue');
    $chk('capacity 800', ($grace['venue_capacity'] ?? null) === 800);
    $chk('type church', ($grace['venue_type'] ?? null) === 'church');
    $card = $grace['groups'][0];
    $chk('card slug+name', ($card['slug'] ?? '') !== '' && ($card['name'] ?? '') !== '');
    $chk('no geo leak', ! array_key_exists('geo_country_id', $card) && ! array_key_exists('primary_venue_id', $card));

    $virt = $tree[2];
    $names = array_map(static fn ($g) => (string) $g['name'], $virt['groups'] ?? []);
    sort($names);
    $chk('virtual = roaming + nowhere', $names === ['Accra Roaming', 'Nowhere Group'], json_encode($names));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
