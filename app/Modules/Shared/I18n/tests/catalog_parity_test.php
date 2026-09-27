<?php

declare(strict_types=1);

/**
 * CROSS-MODULE i18n parity sweep (repo-wide regression). For every Language tree
 * (app + every module) and every English catalog file, asserts each non-English
 * locale:
 *   - ships the file,
 *   - mirrors the English key set EXACTLY (no missing, no stray) — nested keys
 *     flattened with dotted paths, so enum groups (status.*, temperature.*, …)
 *     and the menu (menu.*, menuItems.*) are all covered,
 *   - preserves every {0}/{1}… placeholder token present in the English value
 *     (placeholder drift would break in-view interpolation).
 *
 * This is the guarantee behind "English is always the fallback and no locale can
 * silently diverge": adding a module or a key without translating it fails here.
 *
 *   php app/Modules/Shared/I18n/tests/catalog_parity_test.php
 */

$root      = dirname(__DIR__, 5);
$appLang   = $root . '/app/Language';
$moduleGlob = $root . '/app/Modules/*/Language';
$locales   = ['fr', 'es', 'pt', 'zh', 'ar'];

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        if (is_array($v)) {
            $o += $flatten($v, $key);
        } else {
            $o[$key] = (string) $v;
        }
    }
    return $o;
};
$ph = static function (string $s): array {
    preg_match_all('/\{(\d+)\}/', $s, $m);
    $t = array_unique($m[0]);
    sort($t);
    return $t;
};

$roots = array_merge([$appLang], glob($moduleGlob, GLOB_ONLYDIR) ?: []);
sort($roots);

$fileCount = 0;
$keyCount  = 0;
foreach ($roots as $rootDir) {
    $enDir = $rootDir . '/en';
    $rel   = str_replace($root . '/', '', $rootDir);
    chk("{$rel} has en/ dir", is_dir($enDir));
    if (! is_dir($enDir)) {
        continue;
    }
    foreach (glob($enDir . '/*.php') as $enFile) {
        $ns  = basename($enFile, '.php');
        $en  = $flatten(require $enFile);
        $enKeys = array_keys($en);
        $fileCount++;
        $keyCount += count($enKeys);

        foreach ($locales as $loc) {
            $f = $rootDir . '/' . $loc . '/' . $ns . '.php';
            if (! is_file($f)) {
                chk("{$rel}/{$loc}/{$ns}.php exists", false);
                continue;
            }
            $arr  = $flatten(require $f);
            $keys = array_keys($arr);
            $miss = array_diff($enKeys, $keys);
            $extra = array_diff($keys, $enKeys);
            chk("{$rel} {$loc}/{$ns} key parity", $miss === [] && $extra === [],
                ($miss ? 'missing: ' . implode(',', $miss) . ' ' : '') . ($extra ? 'stray: ' . implode(',', $extra) : ''));

            $drift = [];
            foreach ($en as $k => $v) {
                if (isset($arr[$k]) && $ph($v) !== $ph($arr[$k])) {
                    $drift[] = $k;
                }
            }
            chk("{$rel} {$loc}/{$ns} placeholder integrity", $drift === [], 'drift: ' . implode(',', $drift));
        }
    }
}

echo "\n-- swept {$fileCount} English catalogs ({$keyCount} keys) x " . count($locales) . " locales --\n";
echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
