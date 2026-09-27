<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use SensitiveParameter;

/**
 * Argon2id password hashing (SRS FR-ID-003 / NFR-SEC). Uses PHP's native
 * password_hash with Argon2id when available, transparently falling back to
 * bcrypt. needsRehash() lets callers upgrade parameters on successful login.
 *
 * The Argon2id cost parameters are CALIBRATABLE via environment so an operator
 * can tune them to their hardware (OWASP recommends ~memory 19 MiB+, and tuning
 * time_cost so a single hash takes roughly 0.25–0.5s on the target box):
 *   - identity.argon.memoryCost  (KiB, default 65536 = 64 MiB)
 *   - identity.argon.timeCost    (iterations, default 4)
 *   - identity.argon.threads     (parallelism, default 2)
 *   - identity.bcrypt.cost       (bcrypt fallback cost, default 12)
 * Because the active parameters flow into needsRehash(), raising them causes
 * stored hashes to be transparently re-hashed on the user's next successful
 * login — no bulk migration required.
 */
final class PasswordHasher
{
    private string $algo;

    /** @var array<string,int> */
    private array $options;

    /**
     * @param array<string,int>|null $options explicit cost params (overrides env); for tests/calibration
     */
    public function __construct(?array $options = null)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algo    = PASSWORD_ARGON2ID;
            $this->options = $options ?? [
                'memory_cost' => self::envInt('identity.argon.memoryCost', 64 * 1024, 8 * 1024),
                'time_cost'   => self::envInt('identity.argon.timeCost', 4, 1),
                'threads'     => self::envInt('identity.argon.threads', 2, 1),
            ];
        } else {
            $this->algo    = PASSWORD_BCRYPT;
            $this->options = $options ?? ['cost' => self::envInt('identity.bcrypt.cost', 12, 10)];
        }
    }

    /** Read a positive int from env, clamped to a sane floor. */
    private static function envInt(string $key, int $default, int $min): int
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $val = (int) $raw;

        return $val >= $min ? $val : $default;
    }

    public function hash(#[SensitiveParameter] string $plain): string
    {
        return password_hash($plain, $this->algo, $this->options);
    }

    public function verify(#[SensitiveParameter] string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->options);
    }
}
