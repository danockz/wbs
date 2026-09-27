<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use SensitiveParameter;

/**
 * Default {@see BreachChecker}: an OFFLINE membership test against an embedded
 * list of the most common / most-breached passwords, plus a small set of
 * cheap structural rules that catch the obvious weak-but-policy-passing choices
 * (e.g. "Password1234", "Aaaaaaaaaaaa1"). No network dependency, so it is safe
 * as the always-on default and behaviour-identical across environments.
 *
 * The embedded list is intentionally compact (the worst offenders that people
 * actually reuse); deployments that want full corpus coverage switch the bound
 * provider to {@see PwnedPasswordsBreachChecker} (HIBP k-anonymity range API).
 *
 * Matching is case-insensitive against the normalized password and also checks a
 * "de-leeted" form (4→a, 3→e, 1→i/l, 0→o, $→s, @→a) so "P@ssw0rd" is caught.
 * An optional caller-supplied extra list (e.g. org name, product terms) can be
 * injected to ban context-specific tokens.
 */
final class LocalListBreachChecker implements BreachChecker
{
    /**
     * The compact embedded corpus of common/breached base words (lower-case,
     * no trailing digits — the structural rules handle appended numbers).
     *
     * @var list<string>
     */
    private const COMMON = [
        'password', 'passw0rd', 'passwort', 'letmein', 'welcome', 'admin', 'administrator',
        'qwerty', 'qwertyuiop', 'azerty', 'asdfghjkl', 'zxcvbnm', 'iloveyou', 'sunshine',
        'princess', 'dragon', 'monkey', 'football', 'baseball', 'basketball', 'superman',
        'batman', 'trustno1', 'master', 'shadow', 'michael', 'jennifer', 'jordan', 'hunter',
        'freedom', 'whatever', 'ninja', 'mustang', 'access', 'flower', 'hottie', 'loveme',
        'starwars', 'computer', 'internet', 'samsung', 'google', 'facebook', 'secret',
        'changeme', 'default', 'test', 'temp', 'guest', 'root', 'toor', 'login', 'abc123',
        'abcd1234', 'password1', 'password123', 'qazwsx', 'qwe123', '1q2w3e4r', '1qaz2wsx',
        'zaq12wsx', 'q1w2e3r4', 'welcome1', 'welcome123', 'admin123', 'iloveu', 'ashley',
        'bailey', 'passphrase', 'letmein123', 'summer', 'winter', 'autumn', 'spring',
    ];

    /** @var list<string> extra org/context-specific banned base words (lower-case) */
    private array $extra;

    /**
     * @param list<string> $extra additional banned base words (e.g. org/product names)
     */
    public function __construct(array $extra = [])
    {
        $this->extra = array_values(array_filter(array_map(
            static fn ($w): string => strtolower(trim((string) $w)),
            $extra,
        ), static fn (string $w): bool => $w !== ''));
    }

    public function isBreached(#[SensitiveParameter] string $plain): bool
    {
        $normal = strtolower(trim($plain));
        if ($normal === '') {
            return false;
        }

        // Candidate forms to test: the password itself, a digit/symbol-stripped
        // "base" (so "Password2024!" reduces to "password"), and a de-leeted form.
        $base     = self::stripTrailingNoise($normal);
        $deleeted = self::deleet($normal);
        $deleetBase = self::stripTrailingNoise($deleeted);

        $haystack = array_merge(self::COMMON, $this->extra);

        foreach ([$normal, $base, $deleeted, $deleetBase] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $haystack, true)) {
                return true;
            }
        }

        // Structural weak patterns that slip past length+complexity policies.
        return self::isStructurallyWeak($normal);
    }

    /** Remove leading/trailing non-letters (digits, punctuation, spaces). */
    private static function stripTrailingNoise(string $s): string
    {
        return preg_replace('/^[^a-z]+|[^a-z]+$/', '', $s) ?? $s;
    }

    /** Map common leet substitutions back to letters. */
    private static function deleet(string $s): string
    {
        return strtr($s, [
            '4' => 'a', '@' => 'a', '3' => 'e', '1' => 'i', '!' => 'i',
            '0' => 'o', '$' => 's', '5' => 's', '7' => 't', '8' => 'b',
        ]);
    }

    /**
     * Catch weak-but-policy-passing strings: a single repeated character, a
     * pure sequential run (abc…/123…), or "one word + appended digits" whose
     * word part is a common base.
     */
    private static function isStructurallyWeak(string $s): bool
    {
        // All one character (e.g. "aaaaaaaaaaaa" — plus a digit to pass policy).
        $letters = preg_replace('/[^a-z]/', '', $s) ?? '';
        if ($letters !== '' && preg_match('/^(.)\1+$/', $letters) === 1) {
            return true;
        }

        // Sequential keyboard/alpha/number runs of length >= 6.
        foreach (['abcdefghijklmnopqrstuvwxyz', '0123456789', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm'] as $seq) {
            if (self::hasSequentialRun($s, $seq, 6)) {
                return true;
            }
        }

        return false;
    }

    private static function hasSequentialRun(string $s, string $seq, int $len): bool
    {
        $rev = strrev($seq);
        for ($i = 0, $n = strlen($seq) - $len; $i <= $n; $i++) {
            $run = substr($seq, $i, $len);
            $rrun = substr($rev, $i, $len);
            if (str_contains($s, $run) || str_contains($s, $rrun)) {
                return true;
            }
        }

        return false;
    }
}
