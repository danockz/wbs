<?php

declare(strict_types=1);

/**
 * LocationService — primary-venue pointer lifecycle (Groups+Venues+Geo).
 *
 * The fast pointer `groups.primary_venue_id` must stay consistent with the
 * authoritative `venue_group_assignments` row of type 'primary'. The pointer is
 * ALSO how a group gets its location: groups store no geo of their own, they
 * read it through the venue (LocationSyncService stamps venues only). Proves:
 *   • a 'primary' assignment sets groups.primary_venue_id, which places the
 *     group — and copies NO geo onto the group row;
 *   • cross-org assignment is refused (GROUP_ORG_MISMATCH) and writes nothing;
 *   • assigning a venue in another org's group leaves the pointer untouched;
 *   • downgrading the primary venue to 'secondary' clears the pointer;
 *   • a 'secondary' assignment never sets the primary pointer;
 *   • soft-deleting a venue detaches it from every group that pointed at it, so
 *     those groups read as Unlocated (a dangling pointer is impossible);
 *   • hard-delete does the same and removes assignment rows.
 *
 *   php app/Modules/Geo/Services/tests/venue_primary_pointer_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }

        public function protectIdentifiers(string $t): string { return $t; }

        public function query(string $sql, array $binds = []): \Fake\RS
        {
            // Reference reverse-geocode not exercised here; return empty.
            return new \Fake\RS([]);
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

        public function countAllResults(): int { return count($this->filtered()); }

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

        public function delete(): bool
        {
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));

            return true;
        }

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
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Geo/Services/GeoResolverService.php';
require_once $root . '/app/Modules/Geo/Services/LocationSyncService.php';
require_once $root . '/app/Modules/Geo/Services/LocationService.php';

use WBS\Geo\Services\GeoResolverService;
use WBS\Geo\Services\LocationService;
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

$org  = 'org-1';
$org2 = 'org-2';

/** Build a LocationService with real sync + resolver over a fresh Fake DB. */
$make = static function (): array {
    $db = new \CodeIgniter\Database\BaseConnection();
    // Geo reference (Ghana → Greater Accra → Accra) so venues resolve a bucket.
    $db->rows['countries'] = [['id' => 288, 'name' => 'Ghana', 'iso2' => 'GH', 'region_id' => null, 'subregion_id' => null]];
    $db->rows['states']    = [['id' => 3906, 'name' => 'Greater Accra', 'country_id' => 288]];
    $db->rows['cities']    = [['id' => 52001, 'name' => 'Accra', 'state_id' => 3906, 'country_id' => 288, 'latitude' => 5.55, 'longitude' => -0.20, 'flag' => 1]];
    $db->rows['addresses'] = [['id' => 'addr-1', 'organization_id' => 'org-1', 'city_id' => 52001, 'latitude' => 5.55, 'longitude' => -0.20]];
    $resolver = new GeoResolverService($db);
    $sync = new LocationSyncService($db, new Clock(), $resolver);
    $svc  = new LocationService($db, new Clock(), $sync);

    return [$db, $svc];
};

echo "1. primary assignment sets the pointer (which IS the group's location)\n";
[$db, $svc] = $make();
$db->rows['venues'] = [['id' => 'ven-1', 'organization_id' => $org, 'address_id' => 'addr-1', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null,
    'country_id' => 288, 'state_id' => 3906, 'city_id' => 52001, 'country_label' => 'Ghana', 'state_label' => 'Greater Accra', 'city_label' => 'Accra', 'location_group_key' => 'state:3906']];
$db->rows['groups'] = [['id' => 'grp-1', 'organization_id' => $org, 'primary_venue_id' => null, 'address_id' => null]];
$r = $svc->assignVenueToGroup($org, 'ven-1', 'grp-1', 'primary');
chk('assignment ok', $r->ok, (string) $r->code);
chk('pointer set to venue', ($db->rows['groups'][0]['primary_venue_id'] ?? null) === 'ven-1');
chk('assignment row is primary', ($db->rows['venue_group_assignments'][0]['assignment_type'] ?? null) === 'primary');
// The group is PLACED by the pointer — but nothing is copied onto its row.
$venueOf = static fn (array $g) => $db->rows['venues'][0];
chk('group is placed via the venue it now points at',
    ($venueOf($db->rows['groups'][0])['city_label'] ?? null) === 'Accra'
    && ($venueOf($db->rows['groups'][0])['location_group_key'] ?? null) === 'state:3906');
chk('NO geo replicated onto the group row',
    ! array_key_exists('location_group_key', $db->rows['groups'][0])
    && ! array_key_exists('city_label', $db->rows['groups'][0])
    && ! array_key_exists('latitude', $db->rows['groups'][0]),
    json_encode($db->rows['groups'][0]));

echo "2. cross-org assignment refused, nothing written\n";
[$db2, $svc2] = $make();
$db2->rows['venues'] = [['id' => 'ven-x', 'organization_id' => $org, 'address_id' => 'addr-1', 'deleted_at' => null]];
$db2->rows['groups'] = [['id' => 'grp-other', 'organization_id' => $org2, 'primary_venue_id' => null]];
$r2 = $svc2->assignVenueToGroup($org, 'ven-x', 'grp-other', 'primary');
chk('refused', ! $r2->ok && $r2->code === 'GROUP_ORG_MISMATCH', (string) $r2->code);
chk('no assignment row written', ($db2->rows['venue_group_assignments'] ?? []) === []);
chk('pointer untouched', ($db2->rows['groups'][0]['primary_venue_id'] ?? null) === null);

echo "3. downgrading the primary venue clears the pointer\n";
[$db3, $svc3] = $make();
$db3->rows['venues'] = [['id' => 'ven-3', 'organization_id' => $org, 'address_id' => 'addr-1', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null,
    'country_id' => 288, 'state_id' => 3906, 'city_id' => 52001, 'state_label' => 'Greater Accra', 'city_label' => 'Accra', 'location_group_key' => 'state:3906']];
$db3->rows['groups'] = [['id' => 'grp-3', 'organization_id' => $org, 'primary_venue_id' => null, 'address_id' => null]];
$svc3->assignVenueToGroup($org, 'ven-3', 'grp-3', 'primary');
chk('pointer set after primary', ($db3->rows['groups'][0]['primary_venue_id'] ?? null) === 'ven-3');
$svc3->assignVenueToGroup($org, 'ven-3', 'grp-3', 'secondary');
chk('assignment downgraded to secondary', ($db3->rows['venue_group_assignments'][0]['assignment_type'] ?? null) === 'secondary');
chk('pointer cleared on downgrade', $db3->rows['groups'][0]['primary_venue_id'] === null);

echo "4. a secondary assignment never sets the primary pointer\n";
[$db4, $svc4] = $make();
$db4->rows['venues'] = [['id' => 'ven-4', 'organization_id' => $org, 'address_id' => 'addr-1', 'deleted_at' => null]];
$db4->rows['groups'] = [['id' => 'grp-4', 'organization_id' => $org, 'primary_venue_id' => null]];
$svc4->assignVenueToGroup($org, 'ven-4', 'grp-4', 'secondary');
chk('pointer stays null for secondary', ($db4->rows['groups'][0]['primary_venue_id'] ?? null) === null);

echo "5. soft-deleting a venue detaches every group pointing at it\n";
[$db5, $svc5] = $make();
$db5->rows['venues'] = [['id' => 'ven-5', 'organization_id' => $org, 'address_id' => 'addr-1', 'latitude' => 5.55, 'longitude' => -0.20, 'deleted_at' => null,
    'country_id' => 288, 'state_id' => 3906, 'city_id' => 52001, 'location_group_key' => 'state:3906']];
$db5->rows['groups'] = [
    ['id' => 'g1', 'organization_id' => $org, 'primary_venue_id' => 'ven-5', 'address_id' => null],
    ['id' => 'g2', 'organization_id' => $org, 'primary_venue_id' => 'ven-5', 'address_id' => null],
    ['id' => 'g3', 'organization_id' => $org, 'primary_venue_id' => null, 'address_id' => null],
];
$r5 = $svc5->deleteVenue('ven-5', false);
chk('soft delete ok', $r5->ok && $r5->data['deleted'] === 'soft');
chk('venue soft-deleted', ($db5->rows['venues'][0]['deleted_at'] ?? null) !== null);
chk('g1 pointer detached', $db5->rows['groups'][0]['primary_venue_id'] === null);
chk('g2 pointer detached', $db5->rows['groups'][1]['primary_venue_id'] === null);
chk('g3 untouched', $db5->rows['groups'][2]['primary_venue_id'] === null);

echo "6. hard-delete removes assignment rows and detaches pointers\n";
[$db6, $svc6] = $make();
$db6->rows['venues'] = [['id' => 'ven-6', 'organization_id' => $org, 'address_id' => 'addr-1', 'deleted_at' => null,
    'country_id' => 288, 'state_id' => 3906, 'city_id' => 52001, 'location_group_key' => 'state:3906']];
$db6->rows['groups'] = [['id' => 'g6', 'organization_id' => $org, 'primary_venue_id' => 'ven-6', 'address_id' => null]];
$db6->rows['venue_group_assignments'] = [['id' => 'a6', 'organization_id' => $org, 'venue_id' => 'ven-6', 'group_id' => 'g6', 'assignment_type' => 'primary']];
$r6 = $svc6->deleteVenue('ven-6', true);
chk('hard delete ok', $r6->ok && $r6->data['deleted'] === 'hard');
chk('venue row removed', ($db6->rows['venues'] ?? []) === []);
chk('assignment rows removed', ($db6->rows['venue_group_assignments'] ?? []) === []);
chk('pointer detached', $db6->rows['groups'][0]['primary_venue_id'] === null);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
