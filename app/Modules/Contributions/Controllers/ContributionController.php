<?php

declare(strict_types=1);

namespace WBS\Contributions\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Http\BaseController;

/**
 * Contribution intent/checkout endpoints (SRS FR-VBCS-002).
 *
 * The checkout initiation creates an intent only; the actual provider checkout
 * is created by the approved adapter and confirmed later via a signed webhook.
 * No card PAN/CVV ever reaches these servers.
 */
final class ContributionController extends BaseController
{
    public function createIntent(string $causeId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(ContributionServices::contributions()->createIntent($orgId, $causeId, [
            'amount_minor'       => $in['amount_minor'] ?? 0,
            'currency'           => $in['currency'] ?? 'GHS',
            'user_id'            => $in['user_id'] ?? null,
            'provider'           => $in['provider'] ?? null,
            'provider_config_id' => $in['provider_config_id'] ?? null,
            'recurrence'         => $in['recurrence'] ?? 'once',
            'recognition'        => $in['recognition'] ?? 'public',
            'idempotency_key'    => $in['idempotency_key'] ?? ($this->request->getHeaderLine('Idempotency-Key') ?: null),
        ]));
    }
}
