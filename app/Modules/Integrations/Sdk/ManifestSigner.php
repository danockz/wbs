<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

use WBS\Shared\Security\KeyProvider;

/**
 * Signs and verifies custom-adapter manifests (SRS FR-INT-013 — adapters are
 * added as "versioned, SIGNED, reviewed" modules).
 *
 * The signature binds the EXACT manifest bytes (canonical JSON) to a key epoch,
 * so the reviewed-and-approved manifest cannot be altered after certification
 * without invalidating the signature. It reuses the platform KeyProvider (the
 * same switchable env/KMS/Vault seam as SecretBox), so rotating or switching the
 * signing backend is a provider/config change — never a code change here.
 *
 * Format: "v1:{keyId}:{base64url(hmac_sha256(canonicalJson))}". Verification is
 * constant-time and resolves the historical key via the embedded keyId, so old
 * signatures stay verifiable across key rotation.
 */
final class ManifestSigner
{
    private const PREFIX = 'v1';

    public function __construct(
        private readonly KeyProvider $keys,
    ) {
    }

    public function sign(AdapterManifest $manifest): string
    {
        $keyId = $this->keys->activeKeyId();
        $mac   = $this->mac($manifest->canonicalJson(), $keyId);

        return self::PREFIX . ':' . $keyId . ':' . $this->b64url($mac);
    }

    /**
     * Constant-time verification of a manifest against a signature. Fail-closed:
     * a malformed signature, unknown key, or mismatch all return false.
     */
    public function verify(AdapterManifest $manifest, string $signature): bool
    {
        $parts = explode(':', $signature, 3);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return false;
        }
        [, $keyId, $sig] = $parts;
        if ($keyId === '' || $sig === '') {
            return false;
        }

        try {
            $expected = $this->mac($manifest->canonicalJson(), $keyId);
        } catch (\Throwable) {
            return false; // unknown keyId => hard fail, never soft-accept
        }

        $provided = $this->b64urlDecode($sig);
        if ($provided === null) {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function mac(string $payload, string $keyId): string
    {
        $key = $this->keys->keyFor($keyId);

        return hash_hmac('sha256', $payload, $key, true);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): ?string
    {
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
