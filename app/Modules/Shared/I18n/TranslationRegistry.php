<?php

declare(strict_types=1);

namespace WBS\Shared\I18n;

/**
 * Unified, resource-light translation read layer.
 *
 * Merges translations from ALL sources — bundled file catalogs plus every
 * DB-backed source (notification_templates, Geo reference translations, and any
 * future locale/translations-bearing table) — into a single flat catalog per
 * locale, so the frontend has one place to read a string regardless of where it
 * was authored.
 *
 * ── Precedence (last provider wins on key collision) ──────────────────────────
 * Providers are supplied in ASCENDING priority. Later providers override earlier
 * ones for the same key, so org/DB-authored overrides can shadow bundled
 * defaults. English is always merged UNDER the requested locale as the
 * guaranteed fallback, so a missing translation degrades to English rather than
 * exposing a raw key.
 *
 * ── Resource discipline (the hard constraint) ─────────────────────────────────
 *  1. LAZY: a locale's catalog is built only when first requested.
 *  2. MEMOISED: built catalogs live in a per-instance static-friendly array for
 *     the whole request; repeat lookups are O(1) array reads — zero I/O.
 *  3. ONE LOAD PER PROVIDER PER LOCALE: providers are asked exactly once while
 *     building a locale (never per string), so DB providers do one bounded query.
 *  4. VERSION-STAMPED CROSS-REQUEST CACHE: an optional PSR-16-ish cache stores
 *     the merged catalog under a key that embeds every provider's O(1) version
 *     token. A data change flips a version => new key => rebuild; otherwise every
 *     request after the first is a single cache GET and no DB work at all.
 *
 * The class performs no framework calls itself (cache + providers are injected),
 * so it is unit-testable and safe on the DB-free hot path.
 */
final class TranslationRegistry
{
    /** @var list<TranslationProvider> ascending priority (last wins) */
    private array $providers;

    private string $default;

    /** Optional cross-request cache: [get(key), set(key,value,ttl)]. */
    private $cacheGet;
    private $cacheSet;
    private int $cacheTtl;

    /**
     * Per-instance memo of fully merged locale catalogs (this request only).
     *
     * @var array<string,array<string,string>>
     */
    private array $memo = [];

    /**
     * @param list<TranslationProvider> $providers ascending priority
     * @param callable(string):?array   $cacheGet  optional cross-request cache read
     * @param callable(string,array,int):void $cacheSet optional cache write
     */
    public function __construct(
        array $providers,
        string $default = 'en',
        ?callable $cacheGet = null,
        ?callable $cacheSet = null,
        int $cacheTtl = 3600,
    ) {
        $this->providers = array_values($providers);
        $this->default   = $default !== '' ? $default : 'en';
        $this->cacheGet  = $cacheGet;
        $this->cacheSet  = $cacheSet;
        $this->cacheTtl  = $cacheTtl;
    }

    /**
     * Translate $key into $locale, interpolating positional and/or named params.
     * Falls back to the English catalog, then to $key itself (so the UI never
     * shows an empty hole and callers can detect a miss by identity).
     *
     * @param array<int|string,scalar|null> $params
     */
    public function translate(string $key, string $locale, array $params = []): string
    {
        $catalog = $this->catalog($locale);
        $value   = $catalog[$key] ?? $key;

        return $params === [] ? $value : Interpolator::apply($value, $params);
    }

    /** True when $key resolves to a real translation (not the raw-key fallback). */
    public function has(string $key, string $locale): bool
    {
        return array_key_exists($key, $this->catalog($locale));
    }

    /**
     * The complete merged catalog for $locale — English-backed. This is exactly
     * what a frontend bundle endpoint would serialize.
     *
     * @return array<string,string>
     */
    public function catalog(string $locale): array
    {
        $locale = $locale !== '' ? $locale : $this->default;
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }

        // Cross-request cache keyed by the combined provider versions, so a data
        // change anywhere flips the key and forces exactly one rebuild.
        $cacheKey = $this->cacheKey($locale);
        if ($this->cacheGet !== null) {
            $hit = ($this->cacheGet)($cacheKey);
            if (is_array($hit)) {
                return $this->memo[$locale] = $hit;
            }
        }

        $merged = $this->build($locale);

        if ($this->cacheSet !== null) {
            ($this->cacheSet)($cacheKey, $merged, $this->cacheTtl);
        }

        return $this->memo[$locale] = $merged;
    }

    /**
     * Build a locale catalog from scratch: English base (unless already English)
     * overlaid by the requested locale, each provider applied in priority order.
     *
     * @return array<string,string>
     */
    private function build(string $locale): array
    {
        $merged = [];

        // English fallback base first (skip when the target IS English).
        if ($locale !== $this->default) {
            foreach ($this->providers as $p) {
                foreach ($p->load($this->default) as $k => $v) {
                    $merged[$k] = $v;
                }
            }
        }

        // Requested locale overlaid on top, providers in ascending priority.
        foreach ($this->providers as $p) {
            foreach ($p->load($locale) as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $merged[$k] = $v;
                }
            }
        }

        return $merged;
    }

    /** Deterministic cache key: locale + every provider's O(1) version token. */
    private function cacheKey(string $locale): string
    {
        $parts = ['wbs.i18n', $locale];
        foreach ($this->providers as $p) {
            $parts[] = $p->name() . '=' . $p->version();
        }

        return implode('|', $parts);
    }

    /** @return list<string> provider names in priority order (diagnostics). */
    public function providerNames(): array
    {
        return array_map(static fn (TranslationProvider $p): string => $p->name(), $this->providers);
    }
}
