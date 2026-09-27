<?php

declare(strict_types=1);

/**
 * nestByPlaceChain — Region → … → Venue; no venue/country → Virtual.
 *
 *   php app/Modules/Groups/Services/tests/geo_directory_nesting_test.php
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
        'name' => 'G', 'slug' => 'g', 'type' => null, 'hero_theme' => 'aurora',
        'geo_region_id' => 2, 'region_label' => 'Africa',
        'geo_subregion_id' => 3, 'subregion_label' => 'Western Africa',
        'geo_country_id' => null, 'country_label' => null,
        'geo_state_id' => null, 'state_label' => null,
        'geo_city_id' => null, 'city_label' => null,
        'geo_town_id' => null, 'town_label' => null,
        'primary_venue_id' => null, 'venue_name' => null,
    ];

    $rows = [
        $row(['name' => 'Accra Central', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra',
            'primary_venue_id' => 'V1', 'venue_name' => 'Grace Chapel']),
        $row(['name' => 'Accra East', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 9, 'state_label' => 'Greater Accra', 'geo_city_id' => 3, 'city_label' => 'Accra',
            'primary_venue_id' => 'V1', 'venue_name' => 'Grace Chapel']),
        $row(['name' => 'Kumasi Hub', 'geo_country_id' => 1, 'country_label' => 'Ghana',
            'geo_state_id' => 7, 'state_label' => 'Ashanti', 'geo_city_id' => 5, 'city_label' => 'Kumasi',
            'primary_venue_id' => 'V3', 'venue_name' => 'Kumasi Hall']),
        $row(['name' => 'Ghana Roaming', 'geo_country_id' => 1, 'country_label' => 'Ghana']), // no venue → virtual
        $row(['name' => 'Lagos One', 'geo_country_id' => 2, 'country_label' => 'Nigeria',
            'geo_state_id' => 20, 'state_label' => 'Lagos', 'geo_city_id' => 30, 'city_label' => 'Ikeja',
            'primary_venue_id' => 'V9', 'venue_name' => 'Ikeja Center',
            'geo_region_id' => 2, 'region_label' => 'Africa', 'geo_subregion_id' => 3, 'subregion_label' => 'Western Africa']),
        $row(['name' => 'Nowhere Group', 'geo_region_id' => null, 'region_label' => '', 'geo_subregion_id' => null, 'subregion_label' => '']),
    ];

    $tree = $svc->nestByGeo($rows);
    $labels = array_map(static fn ($c) => (string) $c['label'], $tree);
    $chk('Africa then Virtual', $labels === ['Africa', ''], json_encode($labels));
    $chk('top Africa is region', ($tree[0]['level'] ?? '') === 'region');
    $chk('Virtual last + virtual level', ($tree[1]['level'] ?? '') === 'virtual');
    $chk('Africa count = 4 physical (2 Accra + Kumasi + Lagos)', (int) $tree[0]['count'] === 4, (string) $tree[0]['count']);
    $chk('Virtual holds roaming + nowhere', (int) $tree[1]['count'] === 2, (string) $tree[1]['count']);

    $wa = $tree[0]['children'][0];
    $chk('subregion Western Africa', ($wa['label'] ?? '') === 'Western Africa' && ($wa['level'] ?? '') === 'subregion');

    $countries = array_map(static fn ($c) => (string) $c['label'], $wa['children']);
    $chk('Ghana before Nigeria', $countries === ['Ghana', 'Nigeria'], json_encode($countries));

    $ghana = $wa['children'][0];
    $st = array_map(static fn ($s) => (string) $s['label'], $ghana['children']);
    $chk('Ghana states no unspecified', $st === ['Ashanti', 'Greater Accra'], json_encode($st));

    $ga = null;
    foreach ($ghana['children'] as $s) {
        if ($s['label'] === 'Greater Accra') { $ga = $s; }
    }
    $city = $ga['children'][0];
    $chk('Accra city count 2', (int) $city['count'] === 2);
    $ven = $city['children'][0];
    $chk('venue Grace Chapel', ($ven['label'] ?? '') === 'Grace Chapel' && ($ven['level'] ?? '') === 'venue');
    $chk('two groups at venue', count($ven['groups'] ?? []) === 2);

    $virtNames = array_map(static fn ($g) => (string) $g['name'], $tree[1]['groups'] ?? []);
    sort($virtNames);
    $chk('virtual group names', $virtNames === ['Ghana Roaming', 'Nowhere Group'], json_encode($virtNames));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
