<?php

declare(strict_types=1);

namespace WBS\Shared\I18n\Providers;

use WBS\Shared\I18n\TranslationProvider;

/**
 * Generic DB-backed source for any table carrying a `translations JSON` column
 * alongside a canonical `name` (the world-countries-style reference model used by
 * Geo: countries, regions, subregions, states, cities, …).
 *
 * Each row exposes one catalog entry per configured table:
 *
 *   "<keyPrefix>.<id>" => translations[locale]  (falling back to `name`)
 *
 * where translations is a JSON object like {"fr":"Allemagne","ar":"ألمانيا"}.
 * This lets localized reference-data labels merge into the same catalog the
 * frontend reads, without a bespoke provider per table — one instance per table,
 * all registered together.
 *
 * Resource discipline: identical contract to the other providers — ONE bounded
 * row load per locale per rebuild (injected $rowsFn), an O(1) injected
 * version stamp, and zero framework calls inside the class. The JSON is decoded
 * once per row during that single load, never per lookup.
 */
final class JsonColumnProvider implements TranslationProvider
{
    /** @var callable():iterable<array<string,mixed>> rows {id,name,translations} */
    private $rowsFn;

    /** @var callable():string O(1) version stamp */
    private $versionFn;

    private string $name;
    private string $keyPrefix;

    /**
     * @param string            $name      unique provider id (e.g. 'geo.countries')
     * @param callable():iterable<array{id:mixed,name?:?string,translations?:mixed}> $rowsFn
     * @param callable():string $versionFn O(1) change stamp
     * @param string            $keyPrefix catalog key prefix (defaults to $name)
     */
    public function __construct(string $name, callable $rowsFn, callable $versionFn, string $keyPrefix = '')
    {
        $this->name      = $name;
        $this->rowsFn    = $rowsFn;
        $this->versionFn = $versionFn;
        $this->keyPrefix = trim($keyPrefix !== '' ? $keyPrefix : $name, '.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function load(string $locale): array
    {
        $out = [];
        foreach (($this->rowsFn)() as $row) {
            $id = $row['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }
            $label = $this->pick($row, $locale);
            if ($label !== null && $label !== '') {
                $out[$this->keyPrefix . '.' . (string) $id] = $label;
            }
        }

        return $out;
    }

    public function version(): string
    {
        return 'j' . ($this->versionFn)();
    }

    /**
     * Resolve the localized label for a row: translations[locale] if present,
     * otherwise the canonical `name`. Accepts translations as a JSON string or an
     * already-decoded array (both occur depending on the driver/query).
     *
     * @param array<string,mixed> $row
     */
    private function pick(array $row, string $locale): ?string
    {
        $tr = $row['translations'] ?? null;
        if (is_string($tr) && $tr !== '') {
            $decoded = json_decode($tr, true);
            $tr      = is_array($decoded) ? $decoded : null;
        }
        if (is_array($tr) && isset($tr[$locale]) && is_scalar($tr[$locale]) && (string) $tr[$locale] !== '') {
            return (string) $tr[$locale];
        }

        $name = $row['name'] ?? null;

        return is_scalar($name) ? (string) $name : null;
    }
}
