<?php

declare(strict_types=1);

/**
 * Time-formatting helpers for views (SRS FR-ARC-002 presentation layer).
 *
 * CodeIgniter-style procedural helper: load with helper('time') (BaseController
 * already registers it) and call the functions directly from any view. All
 * functions are locale/timezone tolerant and never throw on bad input — a view
 * must render even when a value is null, malformed, or in an unexpected type.
 */

if (! function_exists('to_datetime')) {
    /**
     * Coerce mixed input into a DateTimeImmutable (UTC assumed for naive strings
     * / epoch ints), or null when it cannot be parsed. Never throws.
     *
     * @param DateTimeInterface|string|int|null $value
     */
    function to_datetime($value, ?string $assumeTz = 'UTC'): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone($assumeTz ?? 'UTC');
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if ($value instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($value);
            }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (new DateTimeImmutable('@' . (int) $value))->setTimezone($tz);
            }

            return new DateTimeImmutable((string) $value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}

if (! function_exists('time_ago')) {
    /**
     * Human "time ago" / "in …" relative label, e.g. "3 hours ago", "just now",
     * "in 2 days". Returns $fallback (default '—') when the input is unparseable.
     *
     * @param DateTimeInterface|string|int|null $value
     * @param DateTimeInterface|string|int|null $now       reference point (default: current time)
     */
    function time_ago($value, string $fallback = '—', $now = null): string
    {
        $then = to_datetime($value);
        if ($then === null) {
            return $fallback;
        }
        $ref  = to_datetime($now) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $ref->getTimestamp() - $then->getTimestamp();
        $future = $diff < 0;
        $s = abs($diff);

        if ($s < 5) {
            return 'just now';
        }

        $units = [
            ['year',   31536000],
            ['month',  2592000],
            ['week',   604800],
            ['day',    86400],
            ['hour',   3600],
            ['minute', 60],
            ['second', 1],
        ];
        $label = 'just now';
        foreach ($units as [$name, $secs]) {
            if ($s >= $secs) {
                $n     = (int) floor($s / $secs);
                $label = $n . ' ' . $name . ($n === 1 ? '' : 's');
                break;
            }
        }

        return $future ? 'in ' . $label : $label . ' ago';
    }
}

if (! function_exists('format_datetime')) {
    /**
     * Absolute datetime formatted for display, optionally converted to a viewer
     * timezone (e.g. the user's user_preferences.timezone). Returns $fallback
     * when unparseable. Keeps the machine value available via a <time> element
     * caller-side if desired.
     *
     * @param DateTimeInterface|string|int|null $value
     */
    function format_datetime($value, string $format = 'Y-m-d H:i', ?string $viewerTz = null, string $fallback = '—'): string
    {
        $dt = to_datetime($value);
        if ($dt === null) {
            return $fallback;
        }
        if ($viewerTz !== null && $viewerTz !== '') {
            try {
                $dt = $dt->setTimezone(new DateTimeZone($viewerTz));
            } catch (\Throwable) {
                // Unknown tz — fall back to the value's own zone.
            }
        }

        return $dt->format($format);
    }
}

if (! function_exists('time_tag')) {
    /**
     * A safe <time> element: machine-readable ISO-8601 in the datetime attribute,
     * human "time ago" as the visible text. Escapes its own output. Returns an
     * empty string when unparseable (so it simply renders nothing).
     *
     * @param DateTimeInterface|string|int|null $value
     */
    function time_tag($value): string
    {
        $dt = to_datetime($value);
        if ($dt === null) {
            return '';
        }
        $iso  = $dt->format(DateTimeInterface::ATOM);
        $human = time_ago($value);

        return '<time datetime="' . htmlspecialchars($iso, ENT_QUOTES)
            . '" title="' . htmlspecialchars($dt->format('Y-m-d H:i T'), ENT_QUOTES) . '">'
            . htmlspecialchars($human, ENT_QUOTES) . '</time>';
    }
}
