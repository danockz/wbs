<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

/**
 * Pragmatic E.164 phone normalizer (SRS FR-ID-002).
 *
 * A full libphonenumber port is out of scope offline, so this handles the cases
 * the platform actually needs: numbers already in international form (leading
 * "+" or "00"), and national numbers interpreted against a default ISO region
 * (from identity policy). It strips visual separators, converts a leading
 * trunk "0" to the region calling code, and validates a plausible E.164 shape
 * (a "+" followed by 8–15 digits, the ITU maximum).
 *
 * It deliberately does NOT claim per-country length/prefix validity — it
 * guarantees a single canonical representation so uniqueness comparisons are
 * stable. Region coverage centres on the deployment footprint (Ghana, Nigeria)
 * plus common international codes; unknown regions still normalize any number
 * already in international form.
 */
final class PhoneNormalizer
{
    /** ISO 3166-1 alpha-2 region => E.164 country calling code. */
    private const CALLING_CODES = [
        'GH' => '233', 'NG' => '234', 'KE' => '254', 'ZA' => '27',
        'US' => '1', 'CA' => '1', 'GB' => '44', 'IE' => '353',
        'FR' => '33', 'DE' => '49', 'IN' => '91', 'AU' => '61',
    ];

    /**
     * Normalize to E.164 (e.g. "+233241234567"), or null when the input cannot
     * be turned into a plausible E.164 number.
     *
     * @param string      $raw           the user-supplied number
     * @param string|null $defaultRegion ISO alpha-2 used for national numbers
     */
    public function normalize(string $raw, ?string $defaultRegion = null): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // International prefixes: "+233..." or "00233...".
        $hasPlus = false;
        if (str_starts_with($raw, '+')) {
            $hasPlus = true;
            $raw     = substr($raw, 1);
        } elseif (str_starts_with($raw, '00')) {
            $hasPlus = true;
            $raw     = substr($raw, 2);
        }

        // Keep digits only (drop spaces, dashes, parentheses, dots).
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            return $this->finalize($digits);
        }

        // National number: needs a region calling code to become international.
        $region = strtoupper((string) $defaultRegion);
        $cc     = self::CALLING_CODES[$region] ?? null;
        if ($cc === null) {
            // No region context and not international -> cannot canonicalize.
            return null;
        }

        // Drop a single national trunk "0" before prepending the calling code.
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
            if ($digits === '') {
                return null;
            }
        }

        // If the caller already included the calling code without a "+", don't
        // double it (e.g. "233241234567" for GH).
        if (! str_starts_with($digits, $cc)) {
            $digits = $cc . $digits;
        }

        return $this->finalize($digits);
    }

    /** Whether the raw value normalizes to a valid E.164 number. */
    public function isValid(string $raw, ?string $defaultRegion = null): bool
    {
        return $this->normalize($raw, $defaultRegion) !== null;
    }

    private function finalize(string $digits): ?string
    {
        // ITU E.164: max 15 digits; a country code is at least 1 and realistic
        // subscriber numbers push the practical minimum to ~8.
        $len = strlen($digits);
        if ($len < 8 || $len > 15) {
            return null;
        }

        return '+' . $digits;
    }
}
