<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

/** Production ReviewConfigPort: delegates to the real ConfigService (G2). */
final class ConfigReviewAdapter implements ReviewConfigPort
{
    public function __construct(private readonly ConfigService $config)
    {
    }

    public function get(string $organizationId, string $key, mixed $default = null): mixed
    {
        return $this->config->get($organizationId, $key, $default);
    }
}
