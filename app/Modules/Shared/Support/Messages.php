<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * Central message humanizer for BROWSER-facing error flashes (FR-ARC-002 sweep).
 *
 * Services return Result messages as machine keys (`contact.name_required`,
 * `identity.email_taken`, `CSRF_FAILED`, …) so API clients get a stable,
 * translatable code. Views, however, must never render those raw keys (they
 * read to a member as JSON/leak of internals). Every `->with('error', …)` flash
 * and the generic data-page fallback route the message through here at SET
 * time, so views stay dumb and just `esc()` the flash.
 *
 * Resolution order (deterministic, no eval, no per-string DB read):
 *   1. empty            -> '' (nothing to show)
 *   2. lang() resolves  -> the localized string (catalogs: Events.*, App.*,
 *                          plus the per-family onboarding catalogs this sweep
 *                          ships: contact, identity, group, journey, …)
 *   3. dotted key shape -> humanize the LAST segment: `contact.name_required`
 *                          => "Name required" (English fallback, never raw)
 *   4. UPPER_SNAKE code -> `NAME_REQUIRED` => "Name required"
 *   5. anything else    -> already human copy; pass through untouched.
 *
 * API/JSON responses never call this — respondJson keeps the raw key.
 */
final class Messages
{
    public static function humanize(?string $msg): string
    {
        $msg = trim((string) $msg);
        if ($msg === '') {
            return '';
        }

        // 2) An existing catalog key (File.key… or family.key with a shipped
        //    family catalog) translates; lang() returns the input unchanged
        //    when the key is unknown, which falls through.
        if (function_exists('lang')) {
            $t = lang($msg);
            if (is_string($t) && $t !== '' && $t !== $msg) {
                return $t;
            }
            // Service Result keys historically used a lowercase family
            // (`identity.auth_failed`) while UI catalogs live in PascalCase
            // files (`Identity.php`). NTFS cannot ship both, so retry with a
            // capitalised file segment after the merge.
            if (preg_match('/^([a-z][A-Za-z0-9_]*)\.(.+)$/', $msg) === 1) {
                $alt = ucfirst($msg);
                // ucfirst only the first character — identity.foo => Identity.foo
                $t2  = lang($alt);
                if (is_string($t2) && $t2 !== '' && $t2 !== $alt && $t2 !== $msg) {
                    return $t2;
                }
            }
        }

        // 3) Dotted service key with no catalog entry -> last segment, humanized.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/', $msg) === 1) {
            $last = substr($msg, (int) strrpos($msg, '.') + 1);

            return self::prettify($last);
        }

        // 4) Bare machine code (Result codes surface as data-page titles).
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $msg) === 1) {
            return self::prettify($msg);
        }

        // 5) Already human copy ("Invalid email or password.").
        return $msg;
    }

    /** `name_required` / `NAME_REQUIRED` / `errInviteRequired` -> "Name required". */
    private static function prettify(string $token): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $token) ?? $token;
        $spaced = str_replace(['_', '-'], ' ', $spaced);
        $spaced = strtolower($spaced);

        return ucfirst(trim($spaced));
    }
}
