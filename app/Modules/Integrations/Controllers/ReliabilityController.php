<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Provider reliability surfaces (SRS FR-INT-012): the documented fallback matrix
 * and the circuit-breaker health/reset controls.
 *
 * The fallback matrix is read-only documentation generated from honest declared
 * capabilities. Circuit health is readable by authorized operators; a manual
 * reset is a configuration action gated by `authorize:provider.configure`.
 *
 * `circuits()` now renders a bespoke operator dashboard for browsers (instead of
 * raw JSON): every provider circuit in the org with its state (closed / open /
 * half-open), failure/success tallies, last error and next-probe time, and — for
 * any tripped breaker — a webcsrf-guarded "reset" control that forces it closed
 * after a fix. `resetCircuit()` PRG-redirects back to the dashboard with a
 * localized flash. API clients keep the identical JSON payloads.
 */
final class ReliabilityController extends BaseController
{
    /** Documented per-feature fallback plan for one adapter. */
    public function adapter(string $code = '')
    {
        $version = $this->field('version');

        return $this->respondPage(
            IntegrationServices::fallbackPlans()->forAdapter(
                $code,
                $version !== null && $version !== '' ? (int) $version : null,
            ),
            'integration_adapter_fallback',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['chain'] ?? $d['steps'] ?? []), 'back' => 'integrations/fallback-matrix'],
        );
    }

    /** The full fallback matrix across every broadcast/meeting adapter. */
    public function matrix()
    {
        return $this->respondPage(
            IntegrationServices::fallbackPlans()->matrix(),
            'integration_fallback_matrix',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['matrix'] ?? $d['adapters'] ?? [])],
        );
    }

    /** Circuit-breaker health for every provider scope in this org. */
    public function circuits()
    {
        $result = IntegrationServices::providerReliability()->health($this->orgId());

        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        return $this->respondWith(
            $result,
            'WBS\Integrations\Views\reliability',
            null,
            [
                'circuits' => $result->ok ? ($result->data['circuits'] ?? []) : [],
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Operator override: force a provider circuit closed after a fix. */
    public function resetCircuit(string $scope = '')
    {
        $result = IntegrationServices::providerReliability()->reset($this->orgId(), $scope);

        return $this->respondReliabilityDecision($result, 'resetFlash');
    }

    /**
     * Post/Redirect/Get for a browser reliability write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the circuits dashboard with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function respondReliabilityDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/integrations/circuits';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Integrations.reliability.' . $okKey));
    }
}
