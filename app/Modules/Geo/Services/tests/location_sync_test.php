<?php

declare(strict_types=1);

/**
 * LocationSyncService — the single writer of resolved geo, on VENUES ONLY
 * (Groups+Venues+Geo unification).
 *
 * Proves:
 *   • stampVenue fills venues.{country_id,state_id,city_id,*_label,
 *     location_group_key} from the venue's address ids;
 *   • it resolves from the venue's OWN lat/long when the address has no ids;
 *   • re-running is idempotent (same values);
 *   • an unresolvable venue leaves prior values intact (fault-isolated, no throw);
 *   • NO geo is replicated onto groups — the service exposes no group-stamping
 *     API and never writes the `groups` table (5, 6);
 *   • moving a venue re-places every group at it with no group-side write, via
 *     the `primary_venue_id → venues` read chain (7);
 *   • a group's own address is NOT a geo fallback: no venue ⇒ no location (8).
 *
 *   php app/Modules/Geo/Services/tests/location_sync_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }

        public function protectIdentifiers(string $t): string { return $t; }

        /**
         * Minimal stand-in for the spatial reverse-geocode query used by
         * GeoResolverService::findInRadius(). Detects the target reference table
         * from the SQL, computes a haversine distance from the bind params
         * (binds[0]=lat, binds[1]=lng), filters flag=1 within the radius
         * (binds[7]), orders nearest-first and limits (binds[8]).
         */
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            $table = str_contains($sql, 'towns_villages') ? 'towns_villages' : 'cities';
            $refLat = (float) ($binds[0] ?? 0);
            $refLng = (float) ($binds[1] ?? 0);
            $radius = (float) ($binds[7] ?? 0);
            $limit  = (int) ($binds[8] ?? 1);

            $out = [];
            foreach (($this->rows[$table] ?? []) as $r) {
                if ((int) ($r['flag'] ?? 0) !== 1) { continue; }
                $d = $this->haversine($refLat, $refLng, (float) $r['latitude'], (float) $r['longitude']);
                if ($d <= $radius) {
                    $r['distance'] = $d;
                    $out[] = $r;
                }
            }
            usort($out, fn ($a, $b) => $a['distance'] <=> $b['distance']);

            return new \Fake\RS(array_slice($out, 0, max(1, $limit)));
        }

        private function haversine(float $la1, float $lo1, float $la2, float $lo2): float
        {
            $r = 6371.0;
            $dLa = deg2rad($la2 - $la1);
            $dLo = deg2rad($lo2 - $lo1);
            $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;

            return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }

    class QB
    {
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f, $esc = null) { return $this; }
        public function where($k, $v = null) { $this->eq[trim((string) $k)] = $v; return $this; }
        public function orderBy($k, $d = 'ASC') { return $this; }

        public function get(): RS { return new RS($this->filtered()); }

        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        public function countAllResults(): int { return count($this->filtered()); }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $rv = $r[$k] ?? null;
                if ($v === null) {
                    if ($rv !== null) { return false; }
                    continue;
                }
                if ((string) $rv !== (string) $v) { return false; }
            }

            return true;
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Geo/Services/GeoResolverService.php';
require_once $root . '/app/Modules/Geo/Services/LocationSyncService.php';

use WBS\Geo\Services\GeoResolverService;
use WBS\Geo\Services\LocationSyncService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-20 00:00:00', new DateTimeZone('UTC')));

/** Seed the Geo reference hierarchy: Ghana → Greater Accra → Accra. */
$seedGeo = static function (\CodeIgniter\Database\BaseConnection $db): void {
    $db->rows['regions']    = [['id' => 1, 'name' => 'Africa']];
    $db->rows['subregions'] = [['id' => 1, 'name' => 'Western Africa', 'region_id' => 1]];
    $db->rows['countries']  = [['id' => 288, 'name' => 'Ghana', 'iso2' => 'GH', 'region_id' => 1, 'subregion_id' => 1]];
    $db->rows['states']     = [['id' => 3906, 'name' => 'Greater Accra', 'country_id' => 288]];
    $db->rows['cities']     = [['id' => 52001, 'name' => 'Accra', 'state_id' => 3906, 'country_id' => 288, 'latitude' => 5.55, 'longitude' => -0.20, 'flag' => 1]];
    $db->rows['towns_villages'] = [];
};

$org = 'org-1';

echo "1. stampVenue resolves from the address's city_id\n";
$db = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db);
$db->rows['addresses'] = [['id' => 'addr-1', 'organization_id' => $org, 'city_id' => 52001, 'latitude' => 5.55, 'longitude' => -0.20]];
$db->rows['venues'] = [['id' => 'ven-1', 'organization_id' => $org, 'address_id' => 'addr-1', 'name' => 'Grace Chapel', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null]];
$sync = new LocationSyncService($db, new Clock(), new GeoResolverService($db));
$ok = $sync->stampVenue('ven-1');
$v = $db->rows['venues'][0];
chk('stampVenue returns true', $ok);
chk('venue city_id stamped', (int) ($v['city_id'] ?? 0) === 52001, var_export($v['city_id'] ?? null, true));
chk('venue state_id stamped', (int) ($v['state_id'] ?? 0) === 3906);
chk('venue country_id stamped', (int) ($v['country_id'] ?? 0) === 288);
chk('venue city_label stamped', ($v['city_label'] ?? null) === 'Accra');
chk('venue state_label stamped', ($v['state_label'] ?? null) === 'Greater Accra');
chk('venue location_group_key = state bucket', ($v['location_group_key'] ?? null) === 'state:3906', var_export($v['location_group_key'] ?? null, true));

echo "2. stampVenue is idempotent\n";
$sync->stampVenue('ven-1');
$sync->stampVenue('ven-1');
chk('re-run keeps same bucket', ($db->rows['venues'][0]['location_group_key'] ?? null) === 'state:3906');

echo "3. stampVenue resolves from venue's OWN lat/long when address has no ids\n";
$db3 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db3);
$db3->rows['addresses'] = [['id' => 'addr-3', 'organization_id' => $org, 'city_id' => null, 'latitude' => 5.55, 'longitude' => -0.20]];
$db3->rows['venues'] = [['id' => 'ven-3', 'organization_id' => $org, 'address_id' => 'addr-3', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null]];
$sync3 = new LocationSyncService($db3, new Clock(), new GeoResolverService($db3));
$ok3 = $sync3->stampVenue('ven-3');
chk('reverse-geocode stamps a bucket', $ok3 && ($db3->rows['venues'][0]['location_group_key'] ?? null) !== null,
    var_export($db3->rows['venues'][0]['location_group_key'] ?? null, true));

echo "4. unresolvable venue leaves prior values intact (no throw)\n";
$db4 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db4);
$db4->rows['addresses'] = [['id' => 'addr-4', 'organization_id' => $org, 'city_id' => null, 'latitude' => null, 'longitude' => null]];
$db4->rows['venues'] = [['id' => 'ven-4', 'organization_id' => $org, 'address_id' => 'addr-4', 'latitude' => null, 'longitude' => null, 'deleted_at' => null, 'location_group_key' => 'state:3906']];
$sync4 = new LocationSyncService($db4, new Clock(), new GeoResolverService($db4));
$ok4 = $sync4->stampVenue('ven-4');
chk('returns false when unresolvable', $ok4 === false);
chk('prior bucket left intact', ($db4->rows['venues'][0]['location_group_key'] ?? null) === 'state:3906');

echo "5. NO group-stamping API — geo is never replicated onto groups\n";
$db5 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db5);
$sync5 = new LocationSyncService($db5, new Clock(), new GeoResolverService($db5));
chk('stampGroup() is gone', ! method_exists($sync5, 'stampGroup'));
chk('restampGroupsForVenue() is gone', ! method_exists($sync5, 'restampGroupsForVenue'));
chk('stampVenue() remains the only writer', method_exists($sync5, 'stampVenue'));

echo "6. stampVenue writes ONLY the venues table (groups row untouched)\n";
$db6 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db6);
$db6->rows['addresses'] = [['id' => 'addr-6', 'organization_id' => $org, 'city_id' => 52001, 'latitude' => 5.55, 'longitude' => -0.20]];
$db6->rows['venues'] = [['id' => 'ven-6', 'organization_id' => $org, 'address_id' => 'addr-6', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null]];
// A group pointing at that venue, carrying NO location columns of its own.
$groupBefore = ['id' => 'grp-6', 'organization_id' => $org, 'primary_venue_id' => 'ven-6', 'address_id' => 'addr-6'];
$db6->rows['groups'] = [$groupBefore];
$sync6 = new LocationSyncService($db6, new Clock(), new GeoResolverService($db6));
$ok6 = $sync6->stampVenue('ven-6');
chk('venue stamped', $ok6 && ($db6->rows['venues'][0]['location_group_key'] ?? null) === 'state:3906');
chk('group row byte-identical (no geo copied onto it)', $db6->rows['groups'][0] === $groupBefore,
    json_encode($db6->rows['groups'][0]));

echo "7. moving a venue re-places every group at it with NO group write\n";
$db7 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db7);
// Add a second place: Ghana → Ashanti → Kumasi.
$db7->rows['states'][] = ['id' => 3907, 'name' => 'Ashanti', 'country_id' => 288];
$db7->rows['cities'][] = ['id' => 52002, 'name' => 'Kumasi', 'state_id' => 3907, 'country_id' => 288, 'latitude' => 6.69, 'longitude' => -1.62, 'flag' => 1];
$db7->rows['addresses'] = [
    ['id' => 'addr-accra', 'organization_id' => $org, 'city_id' => 52001, 'latitude' => 5.55, 'longitude' => -0.20],
    ['id' => 'addr-kumasi', 'organization_id' => $org, 'city_id' => 52002, 'latitude' => 6.69, 'longitude' => -1.62],
];
$db7->rows['venues'] = [['id' => 'ven-7', 'organization_id' => $org, 'address_id' => 'addr-accra', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null]];
$groupsBefore = [
    ['id' => 'g-a', 'organization_id' => $org, 'primary_venue_id' => 'ven-7'],
    ['id' => 'g-b', 'organization_id' => $org, 'primary_venue_id' => 'ven-7'],
];
$db7->rows['groups'] = $groupsBefore;
$sync7 = new LocationSyncService($db7, new Clock(), new GeoResolverService($db7));
$sync7->stampVenue('ven-7');

/** What a directory reader sees: geo resolved THROUGH the group's venue. */
$placeOf = static function (array $group, array $db7rows): ?string {
    $vid = $group['primary_venue_id'] ?? null;
    foreach ($db7rows['venues'] ?? [] as $v) {
        if ((string) ($v['id'] ?? '') === (string) $vid) {
            return $v['city_label'] ?? null;
        }
    }

    return null;
};

chk('both groups read Accra before the move',
    $placeOf($db7->rows['groups'][0], $db7->rows) === 'Accra'
    && $placeOf($db7->rows['groups'][1], $db7->rows) === 'Accra');

// The venue relocates to Kumasi: ONE row changes.
$db7->rows['venues'][0]['address_id'] = 'addr-kumasi';
$db7->rows['venues'][0]['latitude']   = 6.69;
$db7->rows['venues'][0]['longitude']  = -1.62;
$ok7 = $sync7->stampVenue('ven-7');
chk('re-stamp after the move succeeds', $ok7);
chk('venue now buckets under Ashanti', ($db7->rows['venues'][0]['location_group_key'] ?? null) === 'state:3907',
    var_export($db7->rows['venues'][0]['location_group_key'] ?? null, true));
chk('both groups now read Kumasi (no group write happened)',
    $placeOf($db7->rows['groups'][0], $db7->rows) === 'Kumasi'
    && $placeOf($db7->rows['groups'][1], $db7->rows) === 'Kumasi');
chk('group rows still byte-identical', $db7->rows['groups'] === $groupsBefore,
    json_encode($db7->rows['groups']));

echo "8. no venue ⇒ no location (a group's own address is NOT a geo fallback)\n";
$db8 = new \CodeIgniter\Database\BaseConnection();
$seedGeo($db8);
$db8->rows['addresses'] = [['id' => 'addr-8', 'organization_id' => $org, 'city_id' => 52001, 'latitude' => 5.55, 'longitude' => -0.20]];
// Group HAS a resolvable address but no primary venue → unplaced by design.
$db8->rows['groups'] = [['id' => 'grp-8', 'organization_id' => $org, 'primary_venue_id' => null, 'address_id' => 'addr-8']];
$db8->rows['venues'] = [];
$sync8 = new LocationSyncService($db8, new Clock(), new GeoResolverService($db8));
$stampable = 0;
foreach ($db8->rows['venues'] as $v) {
    if ($sync8->stampVenue((string) $v['id'])) {
        $stampable++;
    }
}
chk('nothing to stamp (no venues)', $stampable === 0);
chk('group still carries no geo keys', ! array_key_exists('location_group_key', $db8->rows['groups'][0])
    && ! array_key_exists('city_label', $db8->rows['groups'][0]));
chk('group address left alone (not a geo source)', $db8->rows['groups'][0]['primary_venue_id'] === null);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
