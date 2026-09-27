<?php

declare(strict_types=1);

/**
 * GEO REFERENCE FULL-WORLD test — locks the location-data decision of
 * 2026-09-24: GeoReferenceSeeder populates states/regions AND cities for EVERY
 * seeded country so the group location directory's Country -> State/Region ->
 * City tree resolves on a fresh deployment.
 *
 * Data integrity (via reflection on the private consts):
 *   - every COUNTRIES iso2 has >=1 state and >=1 city,
 *   - state codes are unique per country (states_country_code_uq),
 *   - every city references an existing state code (never orphaned),
 *   - counts are at the expected full-world scale,
 *   - Ghana (the deployment footprint) carries all 16 regions.
 *
 *   php app/Modules/Geo/Database/Seeds/tests/geo_reference_full_world_test.php
 */

// Minimal stub so the seeder class loads without the framework.
namespace CodeIgniter\Database {
    if (! class_exists(\CodeIgniter\Database\Seeder::class, false)) {
        class Seeder
        {
            public $db;

            public function __construct()
            {
            }
        }
    }
}

namespace {
    $root = dirname(__DIR__, 6);
    require_once $root . '/app/Modules/Geo/Database/Seeds/GeoReferenceSeeder.php';

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $r = new ReflectionClass(\WBS\Geo\Database\Seeds\GeoReferenceSeeder::class);
    $countries = $r->getConstant('COUNTRIES');
    $states    = $r->getConstant('STATES');
    $cities    = $r->getConstant('CITIES');

    echo "coverage\n";
    chk('COUNTRIES seeded', count($countries) >= 18, (string) count($countries));
    chk('every country has states', array_diff(array_keys($countries), array_keys($states)) === [], implode(',', array_diff(array_keys($countries), array_keys($states))));
    chk('every country has cities', array_diff(array_keys($countries), array_keys($cities)) === [], implode(',', array_diff(array_keys($countries), array_keys($cities))));
    chk('no extra countries beyond COUNTRIES', array_diff(array_keys($states), array_keys($countries)) === []);

    echo "scale (full world)\n";
    $stateTotal = array_sum(array_map('count', $states));
    $cityTotal  = array_sum(array_map('count', $cities));
    chk('>= 350 states/regions', $stateTotal >= 350, (string) $stateTotal);
    chk('>= 400 cities', $cityTotal >= 400, (string) $cityTotal);
    foreach ($states as $iso => $rows) {
        if ($rows === []) {
            chk("{$iso} has at least one state", false);
        }
    }
    chk('every state list non-empty', ! in_array([], $states, true));

    echo "integrity\n";
    $dup = [];
    foreach ($states as $iso => $rows) {
        $codes = array_column($rows, 1);
        if (count($codes) !== count(array_unique($codes))) {
            $dup[] = $iso;
        }
    }
    chk('state codes unique per country (states_country_code_uq)', $dup === [], implode(',', $dup));
    $orphans = [];
    foreach ($cities as $iso => $rows) {
        $valid = array_column($states[$iso] ?? [], 1);
        foreach ($rows as [$sc, $city]) {
            if (! in_array($sc, $valid, true)) {
                $orphans[] = "{$iso}:{$sc}";
            }
            if ($city === '' || $sc === '') {
                $orphans[] = "{$iso}:empty";
            }
        }
    }
    chk('every city references an existing state code', $orphans === [], implode(',', array_unique($orphans)));

    echo "deployment footprint (Ghana)\n";
    $ghStates = $states['GH'] ?? [];
    chk('Ghana has all 16 regions', count($ghStates) === 16, (string) count($ghStates));
    $ghCities = array_column($cities['GH'] ?? [], 1);
    foreach (['Accra', 'Kumasi', 'Tamale', 'Sekondi-Takoradi', 'Cape Coast', 'Ho', 'Bolgatanga', 'Wa'] as $seat) {
        chk("Ghana seat present: {$seat}", in_array($seat, $ghCities, true));
    }

    echo "run() wiring\n";
    $src = file_get_contents($root . '/app/Modules/Geo/Database/Seeds/GeoReferenceSeeder.php');
    chk('run() seeds states (upsert on country+state_code)', str_contains($src, "table('states')") && str_contains($src, "where('state_code', \$sCode)"));
    chk('run() seeds cities (upsert on country+state+name)', str_contains($src, "table('cities')") && str_contains($src, "where('name', \$cityName)"));
    chk('run() never writes venue coordinates (geo source of truth unchanged)', ! preg_match("/table\('states'\)->insert\(\[.*?latitude/s", $src) && ! preg_match("/table\('cities'\)->insert\(\[.*?latitude/s", $src));
    chk('cities guarded against missing state (no orphans at runtime)', str_contains($src, 'if (! isset($stateId[$sKey]))'));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
