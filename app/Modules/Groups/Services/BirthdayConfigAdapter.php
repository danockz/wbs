<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Groups\Support\BirthdayConfigPort;

/**
 * Production BirthdayConfigPort: nearest-group-wins through the platform's
 * one config store. Unset / throwing resolver → null so BirthdayConfig
 * fail-closes to OFF.
 */
final class BirthdayConfigAdapter implements BirthdayConfigPort
{
    public function __construct(private readonly EffectiveConfigResolver $resolver)
    {
    }

    public function value(string $groupId, string $capability): mixed
    {
        if ($groupId === '' || $capability === '') {
            return null;
        }
        try {
            $res = $this->resolver->resolve($groupId, $capability);
        } catch (Throwable) {
            return null;
        }
        if (! $res->ok) {
            return null;
        }

        return $res->data['value'] ?? null;
    }
}
