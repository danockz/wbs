<?php

declare(strict_types=1);

namespace WBS\Shared\I18n\Providers;

use WBS\Shared\I18n\TranslationProvider;

/**
 * Bundled file catalogs — the CI4-native module language arrays already shipped
 * (app/Language/<locale>/*.php and app/Modules/<M>/Language/<locale>/*.php).
 *
 * These return nested PHP arrays addressed as lang('File.key.sub'); this
 * provider flattens them to the same dotted keys ("File.key.sub" => "…") so they
 * live in the unified catalog next to DB-authored strings.
 *
 * Resource discipline: the set of catalog FILES per locale is discovered once
 * and memoised; version() is a cheap max-mtime over those files (static assets
 * change only on deploy), so the merged cross-request cache stays valid between
 * releases without any per-request disk scan beyond the first build.
 */
final class FileCatalogProvider implements TranslationProvider
{
    /** @var list<string> absolute roots that contain <locale>/*.php trees */
    private array $roots;

    /** @var array<string,array<string,string>> per-locale flattened memo */
    private array $memo = [];

    private ?string $version = null;

    /**
     * @param list<string> $roots directories each holding <locale>/File.php sets.
     *                            Defaults to the app + module Language dirs.
     */
    public function __construct(array $roots = [])
    {
        $this->roots = $roots !== [] ? $roots : self::defaultRoots();
    }

    public function name(): string
    {
        return 'files';
    }

    public function load(string $locale): array
    {
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }

        $flat = [];
        foreach ($this->roots as $root) {
            $dir = rtrim($root, '/') . '/' . $locale;
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $ns   = basename($file, '.php'); // "Identity", "Events", …
                $data = @include $file;
                if (! is_array($data)) {
                    continue;
                }
                self::flatten($data, $ns, $flat);
            }
        }

        return $this->memo[$locale] = $flat;
    }

    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $max = 0;
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*/*.php') ?: [] as $file) {
                $m = @filemtime($file);
                if ($m !== false && $m > $max) {
                    $max = $m;
                }
            }
        }

        return $this->version = 'f' . $max;
    }

    /** @param array<string,mixed> $data @param array<string,string> $out */
    private static function flatten(array $data, string $prefix, array &$out): void
    {
        foreach ($data as $k => $v) {
            $key = $prefix . '.' . $k;
            if (is_array($v)) {
                self::flatten($v, $key, $out);
            } elseif (is_scalar($v)) {
                $out[$key] = (string) $v;
            }
        }
    }

    /** @return list<string> */
    private static function defaultRoots(): array
    {
        $roots = [];
        $app   = defined('APPPATH') ? rtrim(APPPATH, '/') : __DIR__ . '/../../../..';
        if (is_dir($app . '/Language')) {
            $roots[] = $app . '/Language';
        }
        foreach (glob($app . '/Modules/*/Language', GLOB_ONLYDIR) ?: [] as $modLang) {
            $roots[] = $modLang;
        }

        return $roots;
    }
}
