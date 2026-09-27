<?php

declare(strict_types=1);

namespace WBS\Shared\I18n;

/**
 * A source of translation key => string pairs for a single locale.
 *
 * Providers are the pluggable inputs the TranslationRegistry merges. Each one
 * owns exactly one origin (bundled file catalogs, notification_templates rows,
 * Geo reference translations, …) and returns a FLAT map for one locale:
 *
 *   ['brand' => 'WBS', 'notif.security.subject' => 'Sign-in alert', …]
 *
 * Contract / resource discipline:
 *  - load() is called AT MOST ONCE PER LOCALE PER CACHE REBUILD by the registry,
 *    never per translate() call — so a DB-backed provider issues one bounded
 *    query per locale, not one per string. Keep it that way.
 *  - Return only strings; nested structures must be pre-flattened with '.' .
 *  - Missing locale => return [] (the registry supplies English fallback).
 *  - name() must be stable and unique; it participates in the cache version key
 *    and lets callers reason about precedence.
 */
interface TranslationProvider
{
    /** Stable unique id, e.g. 'files', 'notification_templates', 'geo'. */
    public function name(): string;

    /**
     * Flat key => string map for $locale. One bounded load per locale.
     *
     * @return array<string,string>
     */
    public function load(string $locale): array;

    /**
     * Opaque version token that changes whenever this provider's underlying
     * data changes (so the merged cache can be invalidated cheaply). For static
     * files this can be a constant or a max-mtime; for DB sources an O(1) counter
     * (e.g. a MAX(updated_at) or an INCR stamp) — NEVER a full scan.
     */
    public function version(): string;
}
