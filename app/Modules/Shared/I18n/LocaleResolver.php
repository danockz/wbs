<?php

declare(strict_types=1);

namespace WBS\Shared\I18n;

/**
 * Pure, framework-free locale resolution (Phase 1 language-awareness).
 *
 * Given the allowlist plus the raw signals available on a request, decide which
 * locale to serve. Deterministic precedence, highest wins:
 *
 *   1. explicit    ?lang= / switcher this request        (manual switch)
 *   2. cookie      wbs_locale (last chosen / synced pref)
 *   3. org         organizations.default_locale
 *   4. geo         country (edge header / GeoIP) -> countryLocale map
 *   5. browser     Accept-Language, best match in allowlist
 *   6. default     app default ('en')
 *
 * Every candidate is clamped to the allowlist by canonicalize(), so a hostile
 * ?lang=../../etc/passwd or an unsupported locale can never escape. The class is
 * intentionally I/O-free: the caller (LocaleFilter) reads request state and the
 * DB, this decides. That keeps it unit-testable and keeps DB work off the hot
 * path when a cookie is already present.
 */
final class LocaleResolver
{
    /** @var list<string> lower-cased supported primary subtags */
    private array $supported;

    private string $default;

    /** @var array<string,string> UPPER country code => locale */
    private array $countryLocale;

    /**
     * @param list<string>          $supported     e.g. ['en','fr','es','pt','zh','ar']
     * @param array<string,string>  $countryLocale ISO-3166 alpha-2 => locale
     */
    public function __construct(array $supported, string $default, array $countryLocale = [])
    {
        $this->supported = array_values(array_unique(array_map(
            static fn ($l) => strtolower((string) $l),
            $supported,
        )));
        if ($this->supported === []) {
            $this->supported = ['en'];
        }
        $canonDefault    = $this->canonicalize($default);
        $this->default   = $canonDefault ?? $this->supported[0];

        $map = [];
        foreach ($countryLocale as $cc => $loc) {
            $map[strtoupper((string) $cc)] = strtolower((string) $loc);
        }
        $this->countryLocale = $map;
    }

    /**
     * Reduce any locale-ish string to a SUPPORTED primary subtag, or null.
     *
     *   'fr-CA' -> 'fr' (if 'fr' supported); 'FR' -> 'fr'; 'de' -> null;
     *   '../x'  -> null; 'zh_Hans_CN' -> 'zh'.
     */
    public function canonicalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }
        // Primary subtag = leading run of ASCII letters only. This also rejects
        // any path/control characters outright.
        if (! preg_match('/^([A-Za-z]{2,3})/', trim($locale), $m)) {
            return null;
        }
        $primary = strtolower($m[1]);

        return in_array($primary, $this->supported, true) ? $primary : null;
    }

    /** True when $locale (after canonicalization) is one we serve. */
    public function isSupported(?string $locale): bool
    {
        return $this->canonicalize($locale) !== null;
    }

    /** Map an ISO-3166 alpha-2 country to a supported locale, or null. */
    public function fromCountry(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }
        $cc = strtoupper(trim($countryCode));
        // Cloudflare uses 'XX' (unknown) and 'T1' (Tor); ignore non A-Z pairs.
        if (! preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX') {
            return null;
        }

        return isset($this->countryLocale[$cc]) ? $this->canonicalize($this->countryLocale[$cc]) : null;
    }

    /**
     * Best supported match for an Accept-Language header, honoring q-weights and
     * the allowlist's own priority order as the tie-breaker.
     *
     *   'fr-CA,fr;q=0.9,en;q=0.5' -> 'fr'    (if fr supported)
     *   'de,en;q=0.4'             -> 'en'
     */
    public function fromAcceptLanguage(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $best      = null;
        $bestScore = -1.0;
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag  = $this->canonicalize($bits[0] ?? '');
            if ($tag === null) {
                continue;
            }
            $q = 1.0;
            if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $qm)) {
                $q = (float) $qm[1];
            }
            if ($q <= 0.0) {
                continue;
            }
            // Prefer higher q; break ties by allowlist priority (lower index wins).
            $priority = array_search($tag, $this->supported, true);
            $score    = $q * 1000 - (int) $priority;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $tag;
            }
        }

        return $best;
    }

    /**
     * Resolve the final locale from a bag of already-extracted signals. Any value
     * may be null. Never returns null — always at least the app default.
     *
     * @param array{
     *     explicit?: ?string,
     *     cookie?: ?string,
     *     orgDefault?: ?string,
     *     country?: ?string,
     *     acceptLanguage?: ?string
     * } $signals
     */
    public function resolve(array $signals): string
    {
        return $this->canonicalize($signals['explicit'] ?? null)
            ?? $this->canonicalize($signals['cookie'] ?? null)
            ?? $this->canonicalize($signals['orgDefault'] ?? null)
            ?? $this->fromCountry($signals['country'] ?? null)
            ?? $this->fromAcceptLanguage($signals['acceptLanguage'] ?? null)
            ?? $this->default;
    }

    /**
     * Like resolve(), but also reports WHICH signal won — handy for the switcher
     * (to know whether to persist) and for diagnostics/tests.
     *
     * @param array<string,?string> $signals
     * @return array{locale:string,source:string}
     */
    public function resolveWithSource(array $signals): array
    {
        $chain = [
            'explicit'   => $this->canonicalize($signals['explicit'] ?? null),
            'cookie'     => $this->canonicalize($signals['cookie'] ?? null),
            'org'        => $this->canonicalize($signals['orgDefault'] ?? null),
            'geo'        => $this->fromCountry($signals['country'] ?? null),
            'browser'    => $this->fromAcceptLanguage($signals['acceptLanguage'] ?? null),
        ];
        foreach ($chain as $source => $value) {
            if ($value !== null) {
                return ['locale' => $value, 'source' => $source];
            }
        }

        return ['locale' => $this->default, 'source' => 'default'];
    }

    public function default(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function supported(): array
    {
        return $this->supported;
    }
}
