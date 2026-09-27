<?php

declare(strict_types=1);

/**
 * GroupPublicService — a group's geo comes FROM ITS VENUE (SQL contract).
 *
 * The Groups+Venues+Geo model stores location exactly once: on `venues`. A group
 * has no coordinates and no place ids; it is placed by `groups.primary_venue_id`
 * and reads geo through that pointer. This test guards the READER side of that
 * invariant — the nesting shapes are covered by geo_directory_nesting_test and
 * geo_venue_directory_nesting_test, but those are pure and would still pass if
 * the query went back to selecting replicated `g.*` geo columns.
 *
 * Proves, by capturing the generated SELECT/JOIN of both public readers:
 *   • geoDirectory() takes every geo field from the VENUE alias (`v.`) and
 *     aliases it onto the row keys the pure nester expects;
 *   • directory() resolves country/state/city through the primary venue, not
 *     through `groups.address_id`;
 *   • NEITHER reader selects a replicated geo column off `groups`
 *     (g.geo_country_id / g.latitude / g.location_group_key / g.*_label);
 *   • the venue join is LEFT and excludes soft-deleted venues, so an unplaced
 *     group still appears (and lands in "Unlocated") instead of vanishing;
 *   • the group's own presentation fields (incl. free-text location_text) are
 *     still selected — location_text is a human description, not replicated geo.
 *
 *   php app/Modules/Groups/Services/tests/group_geo_from_venue_test.php
 */

namespace CodeIgniter\Database {
    /** Minimal connection double: records the query shape, returns canned rows. */
    class BaseConnection
    {
        /** @var list<array{select:string,joins:list<array{0:string,1:string,2:string}>}> */
        public array $calls = [];

        /** @var array<string,list<array<string,mixed>>> table-ish key => rows */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }
    }
}

namespace Fake {
    class RS
    {
        /** @param list<array<string,mixed>> $rows */
        public function __construct(private array $rows) {}

        public function getResultArray(): array { return $this->rows; }

        public function getRowArray(): ?array { return $this->rows[0] ?? null; }
    }

    class QB
    {
        private string $select = '';

        /** @var list<array{0:string,1:string,2:string}> */
        private array $joins = [];

        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $from) {}

        public function select($fields, $esc = null): self
        {
            $this->select = (string) $fields;

            return $this;
        }

        public function join(string $table, string $cond, string $type = ''): self
        {
            $this->joins[] = [$table, $cond, $type];

            return $this;
        }

        public function where($k, $v = null): self
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, $v): self { return $this; }

        public function orderBy($k, $d = 'ASC'): self { return $this; }

        public function limit($n): self { return $this; }

        public function get(): RS
        {
            $this->db->calls[] = ['select' => $this->select, 'joins' => $this->joins];

            return new RS($this->db->rows[$this->from] ?? []);
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Groups/Services/GroupPublicService.php';

use WBS\Groups\Services\GroupPublicService;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** A GroupPublicService wired to a recording connection (no real collaborators). */
$make = static function (): array {
    $db  = new \CodeIgniter\Database\BaseConnection();
    $ref = new ReflectionClass(GroupPublicService::class);
    /** @var GroupPublicService $svc */
    $svc = $ref->newInstanceWithoutConstructor();
    $p   = $ref->getProperty('db');
    $p->setAccessible(true);
    $p->setValue($svc, $db);

    return [$svc, $db];
};

$org = 'org-1';

/** Geo columns that must NEVER be read off `groups` (they no longer exist). */
$replicated = [
    'g.geo_country_id', 'g.geo_state_id', 'g.geo_city_id', 'g.country_label',
    'g.region_label', 'g.city_label', 'g.location_group_key',
    'g.latitude', 'g.longitude',
];

echo "1. geoDirectory() takes geo from the venue, aliased for the nester\n";
[$svc, $db] = $make();
$db->rows['groups g'] = [];
$svc->geoDirectory($org);
$call = $db->calls[0];
$sel  = preg_replace('/\s+/', ' ', $call['select']);

chk('country id from venue', str_contains($sel, 'v.country_id AS geo_country_id'), $sel);
chk('state id from venue', str_contains($sel, 'v.state_id AS geo_state_id'));
chk('city id from venue', str_contains($sel, 'v.city_id AS geo_city_id'));
chk('town id from venue', str_contains($sel, 'v.town_village_id AS geo_town_id'));
chk('country label from venue', str_contains($sel, 'v.country_label'));
chk('state label from venue', str_contains($sel, 'v.state_label'));
chk('city label from venue', str_contains($sel, 'v.city_label'));
chk('GPS from the venue', str_contains($sel, 'v.latitude') && str_contains($sel, 'v.longitude'));
chk('region/subregion via country reference', str_contains($sel, 'co.region_id') && str_contains($sel, 'rg.name AS region_label'));

echo "2. geoDirectory() never selects replicated geo off groups\n";
$leaked = array_values(array_filter($replicated, static fn (string $c) => str_contains($sel, $c)));
chk('no g.* geo column selected', $leaked === [], implode(', ', $leaked));
chk('the link itself IS selected', str_contains($sel, 'g.primary_venue_id'));
chk('group presentation fields still selected',
    str_contains($sel, 'g.slug') && str_contains($sel, 'g.name') && str_contains($sel, 'g.location_text'));

echo "3. the venue join is LEFT and skips soft-deleted venues\n";
$vjoin = null;
foreach ($call['joins'] as $j) {
    if (str_starts_with($j[0], 'venues')) {
        $vjoin = $j;
    }
}
chk('joins venues on the primary pointer', $vjoin !== null && str_contains($vjoin[1], 'v.id = g.primary_venue_id'),
    json_encode($vjoin));
chk('join type is left (unplaced groups still returned)', strtolower((string) ($vjoin[2] ?? '')) === 'left');
chk('soft-deleted venues excluded', str_contains((string) ($vjoin[1] ?? ''), 'v.deleted_at IS NULL'));

echo "4. directory() resolves place through the venue, not groups.address_id\n";
[$svc4, $db4] = $make();
$db4->rows['groups g'] = [];
$svc4->directory($org);
$call4 = $db4->calls[0];
$sel4  = preg_replace('/\s+/', ' ', $call4['select']);
$joins4 = array_map(static fn ($j) => $j[0], $call4['joins']);

chk('country/state/city ids from the venue',
    str_contains($sel4, 'v.country_id') && str_contains($sel4, 'v.state_id') && str_contains($sel4, 'v.city_id'), $sel4);
chk('place names from venue labels',
    str_contains($sel4, 'v.country_label')
    && str_contains($sel4, 'v.state_label')
    && str_contains($sel4, 'v.city_label'));
$leaked4 = array_values(array_filter($replicated, static fn (string $c) => str_contains($sel4, $c)));
chk('no g.* geo column selected', $leaked4 === [], implode(', ', $leaked4));
chk('venue first; country chain for region/subregion/town names',
    in_array('venues v', $joins4, true)
    && in_array('countries co', $joins4, true)
    && in_array('towns_villages tv', $joins4, true), json_encode($joins4));
chk('GPS from the venue', str_contains($sel4, 'v.latitude') && str_contains($sel4, 'v.longitude'));

echo "5. an unplaced group still comes back, and reads as Virtual\n";
[$svc5, $db5] = $make();
// One group with a geo-resolved venue, one with no venue at all.
$db5->rows['groups g'] = [
    [
        'slug' => 'accra-central', 'name' => 'Accra Central', 'primary_venue_id' => 'V1',
        'geo_country_id' => 288, 'country_label' => 'Ghana',
        'geo_state_id' => 3906, 'region_label' => 'Greater Accra',
        'geo_city_id' => 52001, 'city_label' => 'Accra',
        'venue_name' => 'Grace Chapel', 'venue_type' => 'church', 'venue_capacity' => 800,
    ],
    [
        'slug' => 'roaming', 'name' => 'Roaming Fellowship', 'primary_venue_id' => null,
        'geo_country_id' => null, 'country_label' => null,
        'geo_state_id' => null, 'region_label' => null,
        'geo_city_id' => null, 'city_label' => null,
        'venue_name' => null,
    ],
];
$tree = $svc5->geoDirectory($org);
chk('placed group nests under its venue\'s country', ($tree[0]['label'] ?? null) === 'Ghana');
chk('placed group hangs on the venue node',
    ($tree[0]['children'][0]['children'][0]['children'][0]['label'] ?? null) === 'Grace Chapel');
$last = $tree[count($tree) - 1];
chk('unplaced group sinks to the pinned Virtual bucket',
    ($last['key'] ?? null) === 'virtual' && ($last['level'] ?? '') === 'virtual', json_encode($last['key'] ?? null));
chk('Virtual holds exactly the venue-less group', ($last['count'] ?? 0) === 1);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
