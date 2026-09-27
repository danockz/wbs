<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Per-jurisdiction identity policy administration (SRS FR-ID-002).
 *
 * Configures the uniqueness switches, minimum age, default phone region, and
 * minor allowance per country code ("*" = organization default). All endpoints
 * are authenticated and gated by `authorize:identity.manage` in Routes.php.
 */
final class IdentityPolicyController extends BaseController
{
    public function index()
    {
        $rows = IdentityServices::identityPolicies()->listForOrg($this->orgId());
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok(['policies' => $rows, 'count' => count($rows)]),
                'Identity policies',
            );
        }

        return $this->respondWith(
            Result::ok(['policies' => $rows, 'count' => count($rows)]),
            'WBS\Identity\Views\identity_policies',
            null,
            [
                'policies' => $rows,
                // Token the global webcsrfissue filter minted this request, so the
                // inline upsert forms satisfy the webcsrf check.
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Effective (resolved) policy for a country code, with fallbacks applied. */
    public function resolve(string $countryCode = '')
    {
        $policy = IdentityServices::identityPolicies()->resolve($this->orgId(), $countryCode);
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok(['country_code' => $countryCode, 'effective' => $policy]),
                'Effective identity policy',
                $countryCode,
            );
        }

        return $this->respondWith(
            Result::ok(['country_code' => $countryCode, 'effective' => $policy]),
            'WBS\Identity\Views\policy_resolve',
            null,
            ['effective' => $policy, 'countryCode' => $countryCode],
        );
    }

    /**
     * Create/update the policy for a country code (upsert).
     *
     * The "add a policy" form posts to the segment-less route with the country
     * code in the body (no-JS / CSP-safe — an inline handler cannot rewrite the
     * action under our CSP), so when no path segment is present we read
     * `country_code` from the input. The edit form keeps the jurisdiction in the
     * path segment (the field is read-only there), which always wins.
     */
    public function upsert(string $countryCode = '')
    {
        $input = $this->input();
        if (trim($countryCode) === '') {
            $countryCode = (string) ($input['country_code'] ?? '');
        }

        $result = IdentityServices::identityPolicies()->upsert(
            $this->orgId(),
            $countryCode,
            $input,
        );

        return $this->respondPolicyUpsert($result);
    }

    /**
     * PRG for a browser policy upsert: redirect back to the policy console with a
     * success/error flash; API clients keep the JSON Result. Success copy comes
     * from the localized policies flash key; failures surface the Result message.
     */
    private function respondPolicyUpsert(Result $result)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/identity/policies')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Identity.policies.savedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
