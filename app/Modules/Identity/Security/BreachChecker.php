<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use SensitiveParameter;

/**
 * Breached-password check (SRS FR-ID-003 — "breach-list check").
 *
 * This is the single swappable seam for how the platform decides whether a
 * candidate password appears in a known-compromised-credentials corpus. It
 * mirrors the {@see \WBS\Shared\Security\KeyProvider} pattern: the default
 * implementation ({@see LocalListBreachChecker}) is fully offline and
 * behaviour-safe, while a network-backed provider
 * ({@see PwnedPasswordsBreachChecker}, HaveIBeenPwned k-anonymity range API) is
 * a drop-in — implement this interface and rebind
 * `WBS\Identity\Config\Services::breachChecker()`.
 *
 * Contract: implementations MUST be fail-OPEN — a provider that cannot reach its
 * corpus (network error, timeout) returns `false` (not breached) rather than
 * blocking a legitimate password change. The plaintext MUST never be logged and,
 * for network providers, MUST never leave the process in full (k-anonymity: only
 * a hash prefix is sent).
 */
interface BreachChecker
{
    /**
     * @param string $plain the candidate plaintext password
     *
     * @return bool true when the password is known to be compromised
     */
    public function isBreached(#[SensitiveParameter] string $plain): bool;
}
