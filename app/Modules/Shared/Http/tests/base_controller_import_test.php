<?php

declare(strict_types=1);

/**
 * REGRESSION GUARD: every concrete controller that `extends BaseController` must
 * import the real class `WBS\Shared\Http\BaseController`.
 *
 * WHY: LocaleController lived in namespace `WBS\Shared\Controllers` and wrote
 * `extends BaseController` WITHOUT a `use` import. PHP then resolved the parent to
 * `WBS\Shared\Controllers\BaseController` — which does not exist — so POST
 * /prefs/locale fatally errored ("Class WBS\Shared\Controllers\BaseController not
 * found") the first time the class was autoloaded. `php -l` can't catch this
 * (it's a runtime resolution, not a syntax error) and it only fires when the
 * route is hit, so a static guard is the cheapest safety net.
 *
 * This scans every module controller source: if it extends BaseController (and is
 * not the abstract definition itself, nor a subclass in the Http namespace where
 * the short name already resolves), it MUST carry
 * `use WBS\Shared\Http\BaseController;`.
 *
 *   php app/Modules/Shared/Http/tests/base_controller_import_test.php
 */

$root       = dirname(__DIR__, 5);
$modulesDir = $root . '/app/Modules';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** Recursively collect *.php files. */
$phpFiles = static function (string $dir) use (&$phpFiles): array {
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        if (is_dir($p)) {
            $out = array_merge($out, $phpFiles($p));
        } elseif (str_ends_with($e, '.php')) {
            $out[] = $p;
        }
    }
    return $out;
};

$checked = 0;
$offenders = [];
foreach ($phpFiles($modulesDir) as $file) {
    $src = (string) file_get_contents($file);

    // Only files that actually declare a class extending the short name BaseController.
    if (! preg_match('/\bclass\s+\w+\s+extends\s+BaseController\b/', $src)) {
        continue;
    }
    // Skip the abstract definition itself.
    if (str_contains($src, 'abstract class BaseController')) {
        continue;
    }

    // Determine the declaring namespace.
    preg_match('/^namespace\s+([^;]+);/m', $src, $nm);
    $ns = trim($nm[1] ?? '');

    // In the Http namespace the short name already resolves to the sibling class.
    if ($ns === 'WBS\Shared\Http') {
        continue;
    }

    $checked++;
    $hasImport = str_contains($src, 'use WBS\Shared\Http\BaseController;');
    if (! $hasImport) {
        $offenders[] = str_replace($root . '/', '', $file);
    }
}

echo "scanned module controllers extending BaseController\n";
chk('found a meaningful number of controllers to check', $checked >= 50, "only {$checked}");
chk('every one imports WBS\Shared\Http\BaseController', $offenders === [], 'missing import in: ' . implode(', ', $offenders));

echo "specific regression: LocaleController\n";
$locale = (string) file_get_contents($modulesDir . '/Shared/Controllers/LocaleController.php');
chk('LocaleController imports the real BaseController', str_contains($locale, 'use WBS\Shared\Http\BaseController;'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
