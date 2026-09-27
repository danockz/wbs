<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * Deterministic, resource-light fallback avatar for members without a photo.
 *
 * WHY: we now carry an optional `users.profile_photo_url`, but the vast majority
 * of members will not have set one. Rather than ship a byte-heavy default PNG or
 * — worse — call a remote gravatar/identicon service on every render (a per-view
 * network round-trip, and one that leaks a hashed email), we synthesize a tiny
 * SVG initials avatar from the member's own display name/email.
 *
 * Properties that keep it cheap and safe:
 *   - PURE: no DB, no GD/Imagick, no network, no filesystem. Just string work,
 *     so it renders even in the network-less in-app preview and is trivially
 *     unit-testable.
 *   - DETERMINISTIC: the same seed always yields the same colour + initials, so
 *     an avatar is stable across requests and cacheable/ETag-able. The colour is
 *     picked from a fixed, accessible palette by a hash of the seed.
 *   - SELF-CONTAINED: emits an SVG string (for inlining) or a `data:` URI (for
 *     an <img src>), both embeddable with zero external references.
 *
 * This is the fallback ONLY — {@see resolveUrl()} prefers a real photo URL when
 * one is present and returns the initials data-URI otherwise, so callers have a
 * single "give me something to show" entry point.
 */
final class Avatar
{
    /**
     * Accessible, reasonably-distinct background palette (WCAG-friendly against
     * white text). Index chosen deterministically from the seed hash.
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e',
        '#ef4444', '#f97316', '#f59e0b', '#10b981', '#14b8a6',
        '#06b6d4', '#3b82f6', '#a855f7', '#d946ef', '#84cc16',
        '#22c55e',
    ];

    /**
     * The URL a caller should render for a member: their real photo when set,
     * otherwise a self-contained initials data-URI. Never returns an empty
     * string, so a template can use it unconditionally.
     *
     * @param array<string,mixed> $user a users row (or subset)
     */
    public static function resolveUrl(array $user, int $size = 128): string
    {
        $photo = trim((string) ($user['profile_photo_url'] ?? ''));
        if ($photo !== '') {
            return $photo;
        }

        return self::dataUri(self::seedFrom($user), self::initialsFrom($user), $size);
    }

    /** True when the member has an explicit, non-empty photo URL. */
    public static function hasPhoto(array $user): bool
    {
        return trim((string) ($user['profile_photo_url'] ?? '')) !== '';
    }

    /** A `data:image/svg+xml` URI for an <img src> (base64, cache-friendly). */
    public static function dataUri(string $seed, string $initials, int $size = 128): string
    {
        $svg = self::svg($seed, $initials, $size);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * A standalone SVG document (square, rounded) with a deterministic colour
     * and up to two initials. Safe to inline directly into HTML.
     */
    public static function svg(string $seed, string $initials, int $size = 128): string
    {
        $size     = max(16, min(1024, $size));
        $bg       = self::colorFor($seed);
        $fg       = '#ffffff';
        $initials = self::sanitizeInitials($initials);
        $radius   = (int) round($size * 0.16);
        $fontSize = (int) round($size * 0.42);
        $cy       = (int) round($size * 0.5);

        // xml:space + text-anchor centre keeps it crisp at any size; everything
        // is escaped so a hostile display_name can never break out of the SVG.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" '
            . 'viewBox="0 0 ' . $size . ' ' . $size . '" role="img" '
            . 'aria-label="' . self::xml($initials) . '">'
            . '<rect width="' . $size . '" height="' . $size . '" rx="' . $radius . '" ry="' . $radius . '" fill="' . $bg . '"/>'
            . '<text x="50%" y="' . $cy . '" dy="0.35em" fill="' . $fg . '" '
            . 'font-family="system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif" '
            . 'font-size="' . $fontSize . '" font-weight="600" text-anchor="middle">'
            . self::xml($initials)
            . '</text></svg>';
    }

    /**
     * Up to two initials for a member: first letters of the first two words of
     * the display name, else the first two of the email local-part, else '?'.
     *
     * @param array<string,mixed> $user
     */
    public static function initialsFrom(array $user): string
    {
        $name = trim((string) ($user['display_name'] ?? ''));
        if ($name === '') {
            $email = trim((string) ($user['email'] ?? ''));
            $name  = $email !== '' ? (string) strstr($email . '@', '@', true) : '';
        }
        if ($name === '') {
            return '?';
        }

        // Split on whitespace and common separators; keep letters/digits.
        $parts = preg_split('/[\s._\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';
        foreach ($parts as $p) {
            $ch = self::firstAlnum($p);
            if ($ch !== '') {
                $letters .= $ch;
            }
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }
        if ($letters === '') {
            $letters = self::firstAlnum($name);
        }

        return $letters === '' ? '?' : mb_strtoupper(mb_substr($letters, 0, 2));
    }

    /**
     * A stable seed for colour selection: prefer the immutable user id, else the
     * email, else the display name.
     *
     * @param array<string,mixed> $user
     */
    public static function seedFrom(array $user): string
    {
        foreach (['id', 'email', 'display_name'] as $k) {
            $v = trim((string) ($user[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return 'anonymous';
    }

    /** Deterministic palette colour for a seed. */
    public static function colorFor(string $seed): string
    {
        $h = crc32($seed);

        return self::PALETTE[$h % count(self::PALETTE)];
    }

    // ---------------------------------------------------------------- helpers

    private static function firstAlnum(string $s): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $s, $m) === 1) {
            return $m[0];
        }

        return '';
    }

    /** Clamp to at most two display characters; fall back to '?'. */
    private static function sanitizeInitials(string $initials): string
    {
        $initials = trim($initials);
        if ($initials === '') {
            return '?';
        }

        return mb_substr($initials, 0, 2);
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
