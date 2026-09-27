<?php

declare(strict_types=1);

/**
 * Redactor helpers — mask sensitive values for display (SRS: PII is private).
 *
 * CodeIgniter-style procedural helper: load with helper('redactor') and call
 * from any view. These are a PRESENTATION safeguard, not a security boundary —
 * services must still avoid sending secrets to the browser. Every function is
 * null-tolerant and never throws.
 *
 * All output is plain text; callers still pass results through esc() as usual.
 */

if (! function_exists('mask_middle')) {
    /**
     * Keep the first $keepStart and last $keepEnd characters, replace the middle
     * with $mask characters. Short values are fully masked. e.g.
     *   mask_middle('4242424242424242', 4, 4) => "4242********4242"
     */
    function mask_middle(?string $value, int $keepStart = 2, int $keepEnd = 2, string $maskChar = '*'): string
    {
        $value = (string) ($value ?? '');
        $len   = mb_strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= $keepStart + $keepEnd) {
            return str_repeat($maskChar, $len);
        }
        $start  = mb_substr($value, 0, $keepStart);
        $end    = mb_substr($value, $len - $keepEnd, $keepEnd);
        $middle = str_repeat($maskChar, $len - $keepStart - $keepEnd);

        return $start . $middle . $end;
    }
}

if (! function_exists('redact_email')) {
    /**
     * Mask the local part of an email while keeping enough to recognise it:
     *   "jane.doe@example.com" => "ja****@example.com"
     * Non-emails fall back to mask_middle. Returns $fallback when empty.
     */
    function redact_email(?string $email, string $fallback = '—'): string
    {
        $email = trim((string) ($email ?? ''));
        if ($email === '') {
            return $fallback;
        }
        $at = mb_strpos($email, '@');
        if ($at === false) {
            return mask_middle($email, 2, 0);
        }
        $local  = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at); // includes '@'
        $keep   = mb_strlen($local) <= 2 ? 1 : 2;
        $masked = mb_substr($local, 0, $keep) . str_repeat('*', max(1, mb_strlen($local) - $keep));

        return $masked . $domain;
    }
}

if (! function_exists('redact_phone')) {
    /**
     * Keep the last $keepEnd digits of a phone number, mask the rest, preserving
     * a leading '+'. e.g. "+233241234567" => "+•••••••4567". Returns $fallback
     * when there are no digits.
     */
    function redact_phone(?string $phone, int $keepEnd = 4, string $fallback = '—'): string
    {
        $raw = (string) ($phone ?? '');
        $plus = str_starts_with(trim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $len = strlen($digits);
        if ($len === 0) {
            return $fallback;
        }
        $keepEnd = min($keepEnd, $len);
        $tail    = substr($digits, $len - $keepEnd, $keepEnd);
        $masked  = str_repeat('•', $len - $keepEnd);

        return ($plus ? '+' : '') . $masked . $tail;
    }
}

if (! function_exists('redact_name')) {
    /**
     * Reduce a full name to first name + last initials:
     *   "Kwame Nkrumah Mensah" => "Kwame N. M."
     * Returns $fallback when empty. Use for semi-private directory listings.
     */
    function redact_name(?string $name, string $fallback = 'Member'): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($name ?? '')) ?? '');
        if ($name === '') {
            return $fallback;
        }
        $parts = explode(' ', $name);
        $first = array_shift($parts);
        if ($parts === []) {
            return $first;
        }
        $initials = array_map(static fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)) . '.', $parts);

        return $first . ' ' . implode(' ', $initials);
    }
}

if (! function_exists('redact_id')) {
    /**
     * Show only the trailing segment of an opaque id/token/UUID:
     *   "3f9c2b7a-1d4e-4a2b-9c8d-abc123456789" => "…456789"
     * Returns $fallback when empty.
     */
    function redact_id(?string $id, int $keepEnd = 6, string $fallback = '—'): string
    {
        $id = (string) ($id ?? '');
        if ($id === '') {
            return $fallback;
        }
        if (mb_strlen($id) <= $keepEnd) {
            return $id;
        }

        return '…' . mb_substr($id, mb_strlen($id) - $keepEnd, $keepEnd);
    }
}

if (! function_exists('redact')) {
    /**
     * Generic dispatcher for convenience in views: redact($value, 'email').
     * Types: 'email', 'phone', 'name', 'id', 'middle' (default).
     */
    function redact(?string $value, string $type = 'middle'): string
    {
        return match ($type) {
            'email' => redact_email($value),
            'phone' => redact_phone($value),
            'name'  => redact_name($value),
            'id'    => redact_id($value),
            default => mask_middle($value),
        };
    }
}
