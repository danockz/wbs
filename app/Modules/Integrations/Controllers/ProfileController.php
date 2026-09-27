<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use WBS\Integrations\Canonical\Canonical;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * No-code connector profile authoring (SRS FR-INT-002/004).
 *
 * Only organization administrators reach these routes (enforced by the auth/
 * authorization filter: `authorize:provider.configure`). The service validates
 * every profile server-side via the ProfileValidator before persisting, and a
 * profile advances along a fixed certification flow
 * (draft → sandbox_verified → security_review → finance_review → approved →
 * active) one reviewed step at a time.
 *
 * The GET console turns the previously JSON-only create endpoint into a bespoke
 * browser page: a "define a profile" form (canonical op / family / method /
 * approved host / signature algo — all constrained to the reviewed enums) plus
 * every profile with its stage-appropriate advance / revoke controls. Each
 * browser write POSTs to a webcsrf-guarded route and is PRG-redirected back to
 * /integrations/profiles with a localized flash. API clients keep the identical
 * JSON payloads (they are webcsrf-exempt via the Bearer/session header).
 */
final class ProfileController extends BaseController
{
    /** GET integrations/profiles — the connector-profile authoring console. */
    public function index()
    {
        $profiles = IntegrationServices::connectorProfiles()->listForOrg($this->orgId());

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['profiles' => $profiles]));
        }

        return $this->respondWith(
            Result::ok(['profiles' => $profiles]),
            'WBS\\Integrations\\Views\\profiles',
            null,
            [
                'profiles'   => $profiles,
                'operations' => Canonical::OPERATIONS,
                'families'   => Canonical::FAMILIES,
                'methods'    => Canonical::HTTP_METHODS,
                'algos'      => Canonical::SIGNATURE_ALGOS,
                'csrf'       => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        // The owner defaults to the authenticated actor; an API client may name
        // any owner_id in the body.
        $ownerId = (string) ($in['owner_id'] ?? '') !== '' ? (string) $in['owner_id'] : (string) ($this->actorId() ?? '');

        return $this->respondProfile(
            IntegrationServices::connectorProfiles()->create($orgId, $ownerId, $in),
            'createdFlash',
        );
    }

    /** Advance a profile one reviewed step along the certification flow. */
    public function advance(string $profileId = '')
    {
        $to = (string) $this->field('to_status', '');

        return $this->respondProfile(
            IntegrationServices::connectorProfiles()->advance($profileId, $to),
            'advancedFlash',
        );
    }

    /** Revoke a profile (terminal). */
    public function revoke(string $profileId = '')
    {
        return $this->respondProfile(
            IntegrationServices::connectorProfiles()->revoke($profileId),
            'revokedFlash',
        );
    }

    /**
     * PRG for a browser profile write: API callers (Bearer / X-WBS-Session) keep
     * the raw Result (JSON); browsers redirect back to the authoring console with
     * a localized success flash, or the failing Result's message as an error flash.
     */
    private function respondProfile(Result $result, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to('/integrations/profiles')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/integrations/profiles')->with('success', lang('Integrations.profiles.' . $okKey));
    }
}
