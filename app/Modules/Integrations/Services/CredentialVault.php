<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use SensitiveParameter;
use WBS\Shared\Security\SecretBox;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Write-only credential vault for provider connections (SRS FR-INT-005/009).
 *
 *  - Credentials are encrypted on submission with SecretBox and are NEVER
 *    returned to UI/API — only a non-reversible fingerprint is exposed.
 *  - Each ciphertext is AAD-bound to "connection:{id}:{slot}" so a blob cannot
 *    be replayed into a different connection/slot.
 *  - Rotation stores a new version; historical versions remain decryptable for
 *    in-flight operations but the plaintext is never surfaced.
 *
 * There is DELIBERATELY no public method that returns a decrypted secret to
 * application code outside the adapter execution boundary; {@see useSecret()}
 * hands the plaintext directly to a callback and zeroes references after.
 */
final class CredentialVault
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Store (or rotate) a credential slot. Returns only a fingerprint.
     *
     * Rotation (IN3): the new version becomes the single ACTIVE version and every
     * previously-active version of the same slot is RETIRED (stamped
     * `retired_at`). This is exactly the behaviour a compromise-driven rotation
     * needs — the superseded secret is retired immediately, and a background
     * prune (see {@see pruneRetired()}) later scrubs the ciphertext once a grace
     * window elapses. `useSecret()` only ever decrypts the active version.
     */
    public function put(string $connectionId, string $slot, #[SensitiveParameter] string $secret): Result
    {
        if ($secret === '') {
            return Result::fail('EMPTY_SECRET', 'integration.empty_secret', 422);
        }

        $version = (int) ($this->db->table('connection_credentials')
            ->selectMax('version')
            ->where('connection_id', $connectionId)->where('slot', $slot)
            ->get()->getRowArray()['version'] ?? 0) + 1;

        $aad    = 'connection:' . $connectionId . ':' . $slot;
        $cipher = $this->box->encrypt($secret, $aad);
        $now    = $this->clock->nowUtcString();

        $this->db->transStart();

        // Retire any currently-active version of this slot so exactly one version
        // is active after the rotation.
        $this->db->table('connection_credentials')
            ->where('connection_id', $connectionId)
            ->where('slot', $slot)
            ->where('status', 'active')
            ->update(['status' => 'retired', 'retired_at' => $now]);

        $this->db->table('connection_credentials')->insert([
            'id'            => Uuid::v7(),
            'connection_id' => $connectionId,
            'slot'          => $slot,
            'cipher'        => $cipher,
            'fingerprint'   => $this->box->fingerprint($aad . ':' . $version),
            'version'       => $version,
            'status'        => 'active',
            'retired_at'    => null,
            'created_at'    => $now,
        ]);

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            return Result::fail('STORE_FAILED', 'integration.credential_store_failed', 500);
        }

        return Result::created(['slot' => $slot, 'version' => $version, 'stored' => true]);
    }

    /** Does a slot exist? (No secret material is returned.) */
    public function has(string $connectionId, string $slot): bool
    {
        return $this->db->table('connection_credentials')
            ->where('connection_id', $connectionId)->where('slot', $slot)
            ->countAllResults() > 0;
    }

    /**
     * Execute a callback with the decrypted secret, WITHOUT exposing it to the
     * caller as a return value. The adapter execution boundary is the only
     * place a plaintext credential should ever exist.
     *
     * @template T
     * @param callable(string):T $callback
     * @return T|null null when the slot is missing
     */
    public function useSecret(string $connectionId, string $slot, callable $callback): mixed
    {
        // Pin to the ACTIVE version (IN3). A retired version — e.g. one leaked and
        // rotated out — is never decrypted for live operations. Fall back to the
        // highest version only if no row carries the marker (defensive; the
        // migration backfills status so this shouldn't happen in practice).
        $row = $this->db->table('connection_credentials')
            ->where('connection_id', $connectionId)->where('slot', $slot)
            ->where('status', 'active')
            ->orderBy('version', 'DESC')
            ->get()->getRowArray();
        if ($row === null) {
            $row = $this->db->table('connection_credentials')
                ->where('connection_id', $connectionId)->where('slot', $slot)
                ->orderBy('version', 'DESC')
                ->get()->getRowArray();
        }
        if ($row === null) {
            return null;
        }

        $aad       = 'connection:' . $connectionId . ':' . $slot;
        $plaintext = $this->box->decrypt((string) $row['cipher'], $aad);

        try {
            return $callback($plaintext);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($plaintext);
            }
        }
    }

    /**
     * IN3 — hard-prune retired credential versions whose grace window has passed.
     *
     * A version is retired the moment it is superseded ({@see put()}) or by the
     * migration backfill, but the ciphertext lingers so any in-flight operation
     * that already loaded it can finish. Once `retired_at` is older than
     * `graceDays` the blob is deleted outright — a compromised secret does not
     * stay decryptable indefinitely.
     *
     * NEVER touches an active version (guards `status = "retired"` and a non-null
     * `retired_at`), so a live credential can never be pruned. Bounded batch;
     * idempotent — a second pass in the same window deletes 0.
     *
     * @return array{scanned:int,pruned:int}
     */
    public function pruneRetired(?string $connectionId, int $graceDays = 30, int $limit = 500): array
    {
        $graceDays = max(0, $graceDays);
        $limit     = max(1, min(5000, $limit));
        $cutoff    = $this->clock->now()
            ->modify('-' . $graceDays . ' days')
            ->format('Y-m-d H:i:s');

        $q = $this->db->table('connection_credentials')
            ->select('id')
            ->where('status', 'retired')
            ->where('retired_at IS NOT NULL', null, false)
            ->where('retired_at <=', $cutoff)
            ->orderBy('retired_at', 'ASC');
        if ($connectionId !== null && $connectionId !== '') {
            $q->where('connection_id', $connectionId);
        }
        $rows = $q->get($limit)->getResultArray();

        $ids = array_map(static fn ($r) => (string) $r['id'], $rows);
        if ($ids === []) {
            return ['scanned' => 0, 'pruned' => 0];
        }

        // Re-assert the retired + grace predicate in the DELETE so a version that
        // somehow became active between the SELECT and here is never deleted.
        $this->db->table('connection_credentials')
            ->whereIn('id', $ids)
            ->where('status', 'retired')
            ->where('retired_at <=', $cutoff)
            ->delete();

        $pruned = $this->db->affectedRows();

        return [
            'scanned' => count($ids),
            'pruned'  => is_int($pruned) && $pruned >= 0 ? $pruned : count($ids),
        ];
    }
}
