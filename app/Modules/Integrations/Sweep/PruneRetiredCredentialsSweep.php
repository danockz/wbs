<?php

declare(strict_types=1);

namespace WBS\Integrations\Sweep;

use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, IN3): hard-prune retired credential versions.
 *
 * `CredentialVault::put()` versions each slot and, on rotation, retires the prior
 * version (stamps `retired_at`) but keeps the ciphertext so in-flight operations
 * can finish. Nothing scrubbed those retired blobs — a compromised secret rotated
 * out stayed decryptable forever. This wraps the already-idempotent
 * CredentialVault::pruneRetired() (bounded batch; re-asserts status+grace in the
 * DELETE; never touches an active version) so a superseded secret leaves the
 * vault once its grace window elapses.
 *
 * Pure security hygiene — no config gate, no notifications (mirrors the
 * session-prune sweep).
 */
final class PruneRetiredCredentialsSweep implements SweepContract
{
    public function key(): string
    {
        return 'integrations.prune-retired-credentials';
    }

    public function description(): string
    {
        return 'Hard-delete retired credential versions past their grace window so rotated-out secrets stop being decryptable.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        // Credentials are keyed by connection, not org; scope to a specific
        // connection only if a caller passes one, else prune platform-wide.
        $connectionId = isset($options['connection_id']) ? (string) $options['connection_id'] : null;
        $graceDays    = isset($options['grace_days']) ? (int) $options['grace_days'] : 30;
        $limit        = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = IntegrationServices::credentialVault()->pruneRetired($connectionId, $graceDays, $limit);

        $pruned = (int) ($r['pruned'] ?? 0);

        return SweepResult::ok($pruned, [
            'scanned' => (int) ($r['scanned'] ?? 0),
            'pruned'  => $pruned,
        ]);
    }
}
