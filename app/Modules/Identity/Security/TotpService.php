<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

/**
 * RFC 6238 TOTP (SRS: TOTP preferred MFA factor). Generates base32 secrets,
 * builds otpauth:// provisioning URIs, and verifies codes with a small time
 * window to tolerate clock skew. The shared secret is encrypted at rest by the
 * caller (SecretBox) — this class only computes codes.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly string $algo = 'sha1',
        private readonly int $digits = 6,
        private readonly int $period = 30,
    ) {
    }

    /** Generate a random base32 secret (default 160 bits). */
    public function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);

        return $this->base32Encode($raw);
    }

    /** otpauth:// URI for authenticator apps / QR provisioning. */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $q     = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algo),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ]);

        return "otpauth://totp/{$label}?{$q}";
    }

    /** Verify a code against the current time, allowing +/- $window steps. */
    public function verify(string $secret, string $code, int $window = 1, ?int $at = null): bool
    {
        $at   = $at ?? time();
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $this->digits) {
            return false;
        }

        for ($i = -$window; $i <= $window; $i++) {
            $counter = intdiv($at, $this->period) + $i;
            if (hash_equals($this->codeForCounter($secret, $counter), $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeForCounter(string $base32Secret, int $counter): string
    {
        $key  = $this->base32Decode($base32Secret);
        $bin  = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac($this->algo, $bin, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part   = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        $mod = $part % (10 ** $this->digits);

        return str_pad((string) $mod, $this->digits, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private function base32Decode(string $b32): string
    {
        $b32  = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
