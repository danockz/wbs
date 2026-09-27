<?php

declare(strict_types=1);

namespace WBS\Geo\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use WBS\Geo\Config\Services as GeoServices;

/**
 * One-time (re-runnable) backfill of resolved geo for the
 * Groups+Venues+Geo unification.
 *
 * Stamps every existing VENUE's resolved geo (country/state/city + bucket key).
 * Groups are deliberately NOT stamped: a group holds no copy of location, it
 * reads geo through `groups.primary_venue_id`, so stamping the venues places
 * every group that meets at one. Idempotent — safe to run repeatedly.
 *
 * Then reports (read-only) how well groups are LINKED into that chain — placed
 * (venue with resolved geo), venue-without-geo, and no-venue/unlocated — plus a
 * reconciliation check that `groups.primary_venue_id` agrees with the
 * `venue_group_assignments` PRIMARY row.
 *
 *   php spark geo:backfill-location
 *   php spark geo:backfill-location --org=<uuid>     # limit to one organization
 */
final class BackfillLocationCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'geo:backfill-location';
    protected $description = 'Stamp resolved geo on venues; report group→venue placement (Groups+Venues+Geo). Idempotent.';
    protected $usage       = 'geo:backfill-location [--org=UUID]';
    protected $options     = [
        '--org' => 'Limit the backfill to a single organization_id.',
    ];

    public function run(array $params): int
    {
        $db   = Database::connect();
        $sync = GeoServices::locationSync();
        $org  = CLI::getOption('org');

        // ---- Venues -------------------------------------------------------
        $vq = $db->table('venues')->select('id')->where('deleted_at', null);
        if (is_string($org) && $org !== '') {
            $vq->where('organization_id', $org);
        }
        $venues = $vq->get()->getResultArray();
        $vStamped = 0;
        foreach ($venues as $v) {
            if ($sync->stampVenue((string) $v['id'])) {
                $vStamped++;
            }
        }
        CLI::write("Venues: {$vStamped}/" . count($venues) . ' stamped.', 'green');

        // ---- Group placement (read-only: geo is the venue's, not the group's)
        $gq = $db->table('groups g')
            ->select('g.id, g.primary_venue_id, v.country_id, v.state_id, v.city_id, v.location_group_key', false)
            ->join('venues v', 'v.id = g.primary_venue_id AND v.deleted_at IS NULL', 'left');
        if (is_string($org) && $org !== '') {
            $gq->where('g.organization_id', $org);
        }
        $placed = $venueNoGeo = $noVenue = 0;
        foreach ($gq->get()->getResultArray() as $g) {
            if ((string) ($g['primary_venue_id'] ?? '') === '') {
                $noVenue++;                       // nothing to resolve from → Unlocated
            } elseif ($g['location_group_key'] !== null && $g['location_group_key'] !== '') {
                $placed++;                        // venue carries a resolved bucket
            } else {
                $venueNoGeo++;                    // linked, but the venue isn't geocodable
            }
        }
        $total = $placed + $venueNoGeo + $noVenue;
        CLI::write("Groups: {$placed}/{$total} placed via a geo-resolved venue.", 'green');
        if ($venueNoGeo > 0) {
            CLI::write("  {$venueNoGeo} linked to a venue with no resolvable geo (add an address or GPS).", 'yellow');
        }
        if ($noVenue > 0) {
            CLI::write("  {$noVenue} with no primary venue → Unlocated in the directory.", 'yellow');
        }

        // ---- Reconciliation: primary pointer vs PRIMARY assignment row ----
        $drift = $this->reconcilePrimaryPointers($db, is_string($org) ? $org : null);
        if ($drift === []) {
            CLI::write('Reconciliation: primary_venue_id ↔ assignment rows consistent.', 'green');
        } else {
            CLI::write('Reconciliation: ' . count($drift) . ' group(s) with primary-pointer drift:', 'yellow');
            foreach ($drift as $d) {
                CLI::write("  group {$d['group_id']}: pointer=" . ($d['pointer'] ?? 'NULL')
                    . ' assignment=' . ($d['assignment'] ?? 'NULL'), 'yellow');
            }
        }

        return EXIT_SUCCESS;
    }

    /**
     * Find groups whose `primary_venue_id` disagrees with the venue in their
     * `venue_group_assignments` row of type 'primary'. Read-only report.
     *
     * @return list<array{group_id:string,pointer:?string,assignment:?string}>
     */
    private function reconcilePrimaryPointers(\CodeIgniter\Database\BaseConnection $db, ?string $org): array
    {
        $gq = $db->table('groups g')->select('g.id, g.primary_venue_id');
        if ($org !== null && $org !== '') {
            $gq->where('g.organization_id', $org);
        }
        $groups = $gq->get()->getResultArray();

        $drift = [];
        foreach ($groups as $g) {
            $primaryAssign = $db->table('venue_group_assignments')
                ->select('venue_id')
                ->where('group_id', (string) $g['id'])
                ->where('assignment_type', 'primary')
                ->get()->getRowArray();
            $assignVenue = $primaryAssign['venue_id'] ?? null;
            $pointer     = $g['primary_venue_id'] ?? null;
            if ((string) ($pointer ?? '') !== (string) ($assignVenue ?? '')) {
                $drift[] = [
                    'group_id'   => (string) $g['id'],
                    'pointer'    => $pointer,
                    'assignment' => $assignVenue,
                ];
            }
        }

        return $drift;
    }
}
