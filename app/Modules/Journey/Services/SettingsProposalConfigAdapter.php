<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use WBS\Admin\Services\SettingsService;

/** Production ProposalConfigPort: delegates to the org-wide SettingsService (J6). */
final class SettingsProposalConfigAdapter implements ProposalConfigPort
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function get(string $organizationId, string $key, mixed $default = null): mixed
    {
        return $this->settings->get($organizationId, $key, $default);
    }
}
