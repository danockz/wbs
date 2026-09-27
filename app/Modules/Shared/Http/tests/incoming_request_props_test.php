<?php

declare(strict_types=1);

/**
 * REGRESSION GUARD: every request-scoped `$request->wbs*` property assigned
 * anywhere in the app MUST be declared on WbsIncomingRequest.
 *
 * WHY: WbsIncomingRequest exists precisely to DECLARE the server-side context
 * that filters attach to each request, so PHP 8.2+ does not emit
 * "Creation of dynamic property … is deprecated" on every request. The class
 * declared the auth props (wbsUserId, wbsOrgId, …) but LocaleFilter and
 * WebCsrfIssueFilter later began assigning wbsLocale / wbsLocaleDir /
 * wbsLocaleWrite / wbsCsrf, which were NOT declared — so those deprecation
 * warnings fired on every page (seen in the production log). `php -l` cannot
 * catch this (it is a runtime notice, not a syntax error), and it only surfaces
 * at request time, so a static guard is the cheapest safety net.
 *
 * This scans the whole app for `$request->wbsXxx = …` (and `$this->request->…`)
 * assignments and fails if any assigned name is missing a `public … $wbsXxx`
 * declaration on WbsIncomingRequest.
 *
 *   php app/Modules/Shared/Http/tests/incoming_request_props_test.php
 */

$root      = dirname(__DIR__, 5);
$appDir    = $root . '/app';
$requestFile = $root . '/app/Modules/Shared/Http/WbsIncomingRequest.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** Recursively collect *.php files (skip test dirs to only scan real code). */
$phpFiles = static function (string $dir) use (&$phpFiles): array {
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        if (is_dir($p)) {
            if ($e === 'tests') {
                continue; // fakes in tests may assign anything; scan production only
            }
            $out = array_merge($out, $phpFiles($p));
        } elseif (str_ends_with($e, '.php')) {
            $out[] = $p;
        }
    }

    return $out;
};

// --- 1. Declared properties on WbsIncomingRequest ---------------------------
chk('WbsIncomingRequest source exists', is_file($requestFile));
$src = (string) file_get_contents($requestFile);
preg_match_all('/public\s+[^\s$]+\s+\$(wbs[A-Za-z0-9_]+)\s*=/', $src, $dm);
$declared = array_values(array_unique($dm[1]));
sort($declared);
chk('declares the auth + request-context props', count($declared) >= 6, implode(',', $declared));

// --- 2. Every assigned $request->wbs* / $this->request->wbs* is declared -----
$assigned = [];
foreach ($phpFiles($appDir) as $file) {
    $code = (string) file_get_contents($file);
    // match `->wbsXxx =` (assignment) but not `==`/`===`/`!=`
    if (preg_match_all('/->(?:request->)?(wbs[A-Za-z0-9_]+)\s*=(?!=)/', $code, $m)) {
        foreach ($m[1] as $name) {
            $assigned[$name][] = str_replace($root . '/', '', $file);
        }
    }
}
ksort($assigned);
chk('found request-context assignments to check', $assigned !== []);

$declaredSet = array_flip($declared);
$missing = [];
foreach ($assigned as $name => $files) {
    if (! isset($declaredSet[$name])) {
        $missing[$name] = array_values(array_unique($files));
    }
}
chk(
    'every assigned $request->wbs* property is declared on WbsIncomingRequest',
    $missing === [],
    $missing === [] ? '' : json_encode($missing),
);

// --- 3. Spot-check the specific props from the production log ----------------
foreach (['wbsLocale', 'wbsLocaleDir', 'wbsLocaleWrite', 'wbsCsrf'] as $prop) {
    chk("declares {$prop} (was dynamic in the log)", in_array($prop, $declared, true));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
