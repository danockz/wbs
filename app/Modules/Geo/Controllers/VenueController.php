<?php

declare(strict_types=1);

namespace WBS\Geo\Controllers;

use WBS\Geo\Config\Services as GeoServices;
use WBS\Shared\Http\BaseController;

/**
 * Venue CRUD + discovery (SRS §8.3). Venue data is non-personal facility data;
 * writes are org-scoped and use optimistic version locking. Discovery reads
 * (nearby/list) respect each venue's discovery_status via caller-supplied
 * filters. Authorization is enforced on the routes.
 */
final class VenueController extends BaseController
{
    public function index()
    {
        $orgId = $this->orgId();

        $res  = GeoServices::location()->listVenues($orgId, [
            'venue_type'       => $this->field('venue_type'),
            'status'           => $this->field('status'),
            'discovery_status' => $this->field('discovery_status'),
            'q'                => $this->field('q'),
            'limit'            => (int) $this->field('limit', 20),
            'offset'           => (int) $this->field('offset', 0),
        ]);
        $data = is_array($res->data) ? $res->data : [];

        // Browsers get the bespoke venue-directory view (no raw JSON); API JSON.
        return $this->respondWith(
            $res,
            'WBS\Geo\Views\venues',
            null,
            [
                'venues' => $data['venues'] ?? [],
                'total'  => (int) ($data['total'] ?? 0),
                'limit'  => (int) ($data['limit'] ?? 20),
                'offset' => (int) ($data['offset'] ?? 0),
                // Token the global webcsrfissue filter minted this request, so the
                // inline delete forms in the directory satisfy the webcsrf check.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /**
     * Admin global→local venue directory: Country ▸ State ▸ City ▸ Venue with
     * each venue's assigned groups and facility facts. Browsers get the nested
     * tree view; JSON clients get the tree payload. Gated `venue.manage`.
     */
    public function geoDirectory()
    {
        $orgId = $this->orgId();
        $tree  = GeoServices::location()->venueDirectory($orgId);

        return $this->respondWith(
            \WBS\Shared\Support\Result::ok(['tree' => $tree]),
            'WBS\Geo\Views\venue_geo_directory',
            null,
            ['tree' => $tree],
        );
    }

    public function show(string $venueId = '')
    {
        return $this->respondPage(
            GeoServices::location()->getVenue($venueId),
            'venue_show',
            static fn (array $d): array => ['record' => $d['venue'] ?? $d],
        );
    }

    /** GET venues/new — the CREATE form (browser). */
    public function createForm()
    {
        return $this->renderForm('WBS\Geo\Views\venue_form', [
            'mode'  => 'create',
            'venue' => [],
        ]);
    }

    /** GET venues/{id}/edit — the EDIT form (browser), prefilled. */
    public function editForm(string $venueId = '')
    {
        $result = GeoServices::location()->getVenue($venueId);
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($result);
            }

            return redirect()->to('/venues')->with('error', $this->errText((string) $result->message));
        }

        return $this->renderForm('WBS\Geo\Views\venue_form', [
            'mode'  => 'edit',
            'venue' => (array) $result->data,
        ]);
    }

    public function create()
    {
        $actor  = $this->actorId();
        $result = GeoServices::location()->createVenue($this->orgId(), $this->input(), $actor);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/venues/' . (string) ($result->data['id'] ?? ''))
                    ->with('success', (string) lang('Geo.venueForm.createdFlash'));
            }

            return $this->renderForm('WBS\Geo\Views\venue_form', [
                'mode'  => 'create',
                'venue' => $this->input(),
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function update(string $venueId = '')
    {
        $in      = $this->input();
        $actor   = $this->actorId();
        $version = isset($in['expected_version']) && $in['expected_version'] !== '' ? (int) $in['expected_version'] : null;
        $result  = GeoServices::location()->updateVenue($venueId, $in, $actor, $version);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/venues/' . $venueId)
                    ->with('success', (string) lang('Geo.venueForm.updatedFlash'));
            }

            return $this->renderForm('WBS\Geo\Views\venue_form', [
                'mode'  => 'edit',
                'venue' => ['id' => $venueId] + $in,
                'error' => (string) $result->message,
            ]);
        }

        return $this->respondWith($result);
    }

    public function delete(string $venueId = '')
    {
        $hard   = (bool) $this->field('hard', false);
        $result = GeoServices::location()->deleteVenue($venueId, $hard);

        if (! $this->wantsJson()) {
            return redirect()->to('/venues')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Geo.venueForm.deletedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    public function nearby()
    {
        return $this->respondPage(
            GeoServices::location()->findNearbyVenues(
                $this->orgId(),
                (float) $this->field('latitude'),
                (float) $this->field('longitude'),
                (float) $this->field('radius_km', 25),
                (int) $this->field('limit', 20),
            ),
            'venue_nearby',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['venues'] ?? [])],
        );
    }

    public function stats()
    {
        return $this->respondPage(
            GeoServices::location()->venueStats($this->orgId()),
            'venue_stats',
            static function (array $d): array {
                if (array_is_list($d)) {
                    return ['rows' => $d];
                }
                $rows = [];
                foreach ($d as $label => $value) {
                    $rows[] = is_array($value) ? $value : ['label' => (string) $label, 'value' => $value];
                }

                return ['rows' => $rows];
            },
        );
    }

    public function bulkCreate()
    {
        $in     = $this->input();
        $actor  = $this->actorId();
        $venues = is_array($in['venues'] ?? null) ? $in['venues'] : [];

        return $this->respondWith(GeoServices::location()->bulkCreateVenues($this->orgId(), $venues, $actor));
    }

    public function assignToGroup(string $venueId = '')
    {
        $in = $this->input();

        return $this->respondWith(GeoServices::location()->assignVenueToGroup(
            $this->orgId(),
            $venueId,
            (string) ($in['group_id'] ?? ''),
            (string) ($in['assignment_type'] ?? 'primary'),
        ));
    }

    public function groupVenues(string $groupId = '')
    {
        return $this->respondPage(
            GeoServices::location()->getGroupVenues($groupId),
            'venue_group',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['venues'] ?? [])],
        );
    }

}
