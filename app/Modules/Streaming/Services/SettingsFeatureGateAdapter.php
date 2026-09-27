<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use Throwable;
use WBS\Admin\Services\SettingsService;

/**
 * Production StreamFeatureGatePort: resolves the feature flag through the
 * platform's existing Admin\SettingsService (org-wide default with an optional
 * per-group override, the single feature-flag store — no parallel config). Any
 * error resolves to false so the gate fails safe (default OFF).
 */
final class SettingsFeatureGateAdapter implements StreamFeatureGatePort
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function enabled(string $organizationId, string $flagKey, ?string $groupId = null): bool
    {
        try {
            return $this->settings->isEnabled($organizationId, $flagKey, $groupId);
        } catch (Throwable) {
            return false;
        }
    }
}
