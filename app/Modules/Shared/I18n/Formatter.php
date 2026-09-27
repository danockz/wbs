<?php

declare(strict_types=1);

namespace WBS\Shared\I18n;

/**
 * Locale-aware formatting that is intl-OPTIONAL (Phase 1 language-awareness).
 *
 * ext-intl gives correct ICU dates/numbers/currency, but it is not guaranteed on
 * every host, so every method degrades to a sensible ASCII/EN fallback when
 * `intl` is absent. Translated TEXT (via lang()) is a separate concern; this is
 * about numbers, money and dates that must follow the viewer's locale.
 *
 * Money is handled in MINOR units everywhere in the platform; formatCurrency()
 * takes minor units + an ISO-4217 code and returns a localized display string.
 */
final class Formatter
{
    private string $locale;

    private bool $hasIntl;

    public function __construct(string $locale = 'en')
    {
        $this->locale  = $locale !== '' ? $locale : 'en';
        $this->hasIntl = extension_loaded('intl');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function hasIntl(): bool
    {
        return $this->hasIntl;
    }

    /** Localized decimal number, e.g. 1234567.5 -> "1,234,567.5" (en) / "1 234 567,5" (fr). */
    public function number(int|float $value, int $maxFractionDigits = 3): string
    {
        if ($this->hasIntl) {
            $fmt = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
            $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);

            return (string) $fmt->format($value);
        }

        // Fallback: en-style grouping.
        $decimals = (is_float($value) && floor($value) !== $value) ? min($maxFractionDigits, 3) : 0;

        return number_format($value, $decimals, '.', ',');
    }

    /**
     * Localized currency from MINOR units (e.g. cents/pesewas). 4200 + 'USD' ->
     * "$42.00" (en-US) / "42,00 $US" (fr). Falls back to "SYMBOL 42.00".
     */
    public function formatCurrency(int $amountMinor, string $currency, int $minorExponent = 2): string
    {
        $major = $amountMinor / (10 ** $minorExponent);
        $currency = strtoupper($currency);

        if ($this->hasIntl) {
            $fmt = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);

            return (string) $fmt->formatCurrency($major, $currency);
        }

        // Minimal fallback: a few common symbols, else the ISO code.
        static $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'GHS' => 'GH₵', 'NGN' => '₦'];
        $sym = $symbols[$currency] ?? ($currency . ' ');

        return $sym . number_format($major, $minorExponent, '.', ',');
    }

    /**
     * Localized absolute date/time. $dateType/$timeType use IntlDateFormatter
     * constants (SHORT=3, MEDIUM=2, LONG=1, FULL=0, NONE=-1) when intl is present;
     * otherwise a fixed 'Y-m-d H:i' style is used.
     *
     * @param \DateTimeInterface|string|int|null $value
     */
    public function dateTime($value, int $dateType = 2, int $timeType = 2, ?string $tz = null): string
    {
        $dt = $this->toDate($value, $tz);
        if ($dt === null) {
            return '';
        }

        if ($this->hasIntl) {
            // ICU needs a named/offset zone it recognises; a DateTime built from an
            // epoch reports its zone as "+00:00", which IntlDateFormatter rejects.
            $zoneName = $tz ?? $dt->getTimezone()->getName();
            if ($zoneName === '' || $zoneName[0] === '+' || $zoneName[0] === '-' || $zoneName === 'Z') {
                $zoneName = 'UTC';
            }
            $fmt = new \IntlDateFormatter($this->locale, $dateType, $timeType, $zoneName);

            return (string) $fmt->format($dt);
        }

        $pattern = match (true) {
            $timeType < 0 => 'Y-m-d',
            $dateType < 0 => 'H:i',
            default       => 'Y-m-d H:i',
        };

        return $dt->format($pattern);
    }

    /** @param \DateTimeInterface|string|int|null $value */
    private function toDate($value, ?string $tz): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $zone = new \DateTimeZone($tz ?? 'UTC');
            if ($value instanceof \DateTimeInterface) {
                $out = \DateTimeImmutable::createFromInterface($value);
            } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $out = (new \DateTimeImmutable('@' . (int) $value));
            } else {
                $out = new \DateTimeImmutable((string) $value, $zone);
            }
            if ($tz !== null) {
                $out = $out->setTimezone($zone);
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }
}
