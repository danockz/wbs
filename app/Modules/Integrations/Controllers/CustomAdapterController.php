<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Custom-adapter SDK admin endpoints (SRS FR-INT-013).
 *
 * Drives the register → contract-test → review → approve → activate lifecycle
 * for a non-conforming provider onboarded as a versioned, signed, reviewed
 * module. All writes are gated by `provider.configure`; approval additionally
 * enforces segregation of duties (checker ≠ submitter) in the service.
 *
 * The GET index now renders a bespoke dashboard (no raw JSON to a browser): the
 * allowlisted impl classes with a register form, plus every org adapter with the
 * stage-appropriate lifecycle controls. Each browser write POSTs to a webcsrf-
 * guarded route and is PRG-redirected back to /integrations/custom-adapters with a
 * localized flash. The submitter/approver come from the authenticated session so
 * SoD is enforced by identity, not a spoofable form field. API clients keep the
 * identical JSON payloads.
 */
final class CustomAdapterController extends BaseController
{
    public function index()
    {
        $status   = $this->field('status');
        $adapters = IntegrationServices::customAdapters()->listForOrg(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['adapters' => $adapters]));
        }

        return $this->respondWith(
            Result::ok(['adapters' => $adapters]),
            'WBS\Integrations\Views\custom_adapters',
            null,
            [
                'adapters'  => $adapters,
                'allowlist' => IntegrationServices::customAdapterRegistry()->classes(),
                'csrf'      => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** The allowlist of shippable custom-adapter classes (reviewed code). */
    public function allowlist()
    {
        return $this->respondPage(
            Result::ok([
                'classes' => IntegrationServices::customAdapterRegistry()->classes(),
            ]),
            'integration_adapter_allowlist',
            static function (array $d): array {
                $classes = $d['classes'] ?? [];
                $rows    = [];
                foreach ($classes as $c) {
                    $rows[] = is_array($c) ? $c : ['value' => (string) $c];
                }

                return ['rows' => $rows];
            },
        );
    }

    public function show(string $adapterId = '')
    {
        $row = IntegrationServices::customAdapters()->find($this->orgId(), $adapterId);
        if ($row === null) {
            return $this->respondWith(Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND'));
        }

        return $this->respondPage(
            Result::ok($row),
            'integration_custom_adapter',
            static fn (array $d): array => [
                'record' => $d,
                'back'   => 'integrations/custom-adapters',
                // Lifecycle actions → webcsrf-guarded POSTs. The service enforces
                // which transitions are valid from the current status and flashes
                // an error otherwise, so we surface the always-available two here.
                'detailActions' => [
                    ['action' => 'integrations/custom-adapters/{id}/contract-test', 'labelKey' => 'Pages.actions.contractTest'],
                    ['action' => 'integrations/custom-adapters/{id}/revoke', 'labelKey' => 'Pages.actions.revoke', 'danger' => true],
                ],
            ],
        );
    }

    /** Register from an allowlisted impl class (manifest taken from the adapter). */
    public function register()
    {
        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->register(
                $this->orgId(),
                (string) ($this->actorId() ?? ''),
                ['impl_class' => $this->field('impl_class', '')],
            ),
            'registeredFlash',
        );
    }

    /** Run the SDK contract-test suite; on pass the manifest is signed. */
    public function contractTest(string $adapterId = '')
    {
        $samples = $this->field('sample_params');

        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->contractTest(
                $this->orgId(),
                $adapterId,
                $this->actorId(),
                is_array($samples) ? $samples : [],
            ),
            'contractTestedFlash',
        );
    }

    /** Advance contract_tested -> security_review. */
    public function advance(string $adapterId = '')
    {
        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->advance(
                $this->orgId(),
                $adapterId,
                (string) $this->field('to_status', ''),
                $this->actorId(),
            ),
            'advancedFlash',
        );
    }

    /** Approve security_review -> approved (checker ≠ submitter). */
    public function approve(string $adapterId = '')
    {
        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->approve(
                $this->orgId(),
                $adapterId,
                (string) ($this->actorId() ?? ''),
                $this->field('note'),
            ),
            'approvedFlash',
        );
    }

    /** Activate approved -> active and publish to the shared catalogue. */
    public function activate(string $adapterId = '')
    {
        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->activate(
                $this->orgId(),
                $adapterId,
                (string) ($this->actorId() ?? ''),
                $this->field('note'),
            ),
            'activatedFlash',
        );
    }

    public function revoke(string $adapterId = '')
    {
        return $this->respondAdapterDecision(
            IntegrationServices::customAdapters()->revoke(
                $this->orgId(),
                $adapterId,
                (string) ($this->actorId() ?? ''),
                $this->field('note'),
            ),
            'revokedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser custom-adapter write: API clients keep the
     * raw Result (JSON); browsers are redirected back to the dashboard with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function respondAdapterDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/integrations/custom-adapters';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Integrations.custom.' . $okKey));
    }
}
