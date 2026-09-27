<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;

/**
 * Production LifecycleConfigPort (M6): resolves verify-expiry config through the
 * existing hierarchical EffectiveConfigResolver (nearest-group-wins, walking
 * ancestry to the org root) — the platform's one config store per the standing
 * constraint, no parallel config. Returns null on any miss so the sweep's gate
 * falls back to OFF (default-safe).
 */
final class LifecycleConfigAdapter implements LifecycleConfigPort
{
    public function __construct(
        private readonly EffectiveConfigResolver $resolver,
        private readonly BaseConnection $db,
    ) {
    }

    public function value(string $groupId, string $capability): mixed
    {
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

    public function orgRootGroup(string $organizationId): ?string
    {
        if ($organizationId === '') {
            return null;
        }
        $row = $this->db->table('groups')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('depth', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getRowArray();

        return $row !== null ? (string) $row['id'] : null;
    }
}
