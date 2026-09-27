<?php

declare(strict_types=1);

/**
 * Service-locator key invariant (regression for log-2026-09-09).
 *
 * CodeIgniter's BaseService::getSharedInstance($key) resolves a service by a key
 * that, on a cache miss, is dispatched to a factory METHOD of that same name via
 * service discovery. If a factory method calls getSharedInstance('somethingElse')
 * with a key that is NOT itself a factory method name, discovery finds nothing
 * and the shared getter returns null — which then blows up as a TypeError against
 * the method's declared return type (exactly what happened to
 * Journey\Services::attribution() / recommendations()).
 *
 * This test scans every module's Config/Services.php and asserts each
 *   public static function <name>(...) { if ($getShared) return getSharedInstance('<key>'); ... }
 * uses <key> === <name>.
 *
 *   php app/Modules/Shared/Http/tests/service_keys_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "service-locator key invariant\n";

$files = glob($root . '/app/Modules/*/Config/Services.php');
chk('found module Services files', is_array($files) && count($files) > 0);

$checked = 0;
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    $mod = basename(dirname($file, 2));

    // Each public static factory method + its (optional) getSharedInstance key.
    if (! preg_match_all('/public static function (\w+)\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $src, $methods, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($methods as $m) {
        $name = $m[1];
        $body = $m[2];
        if (preg_match("/getSharedInstance\\('([^']+)'\\)/", $body, $km)) {
            $key = $km[1];
            $checked++;
            chk("{$mod}\\Services::{$name}() uses key '{$name}' (got '{$key}')", $key === $name);
        }
    }
}
chk('scanned at least one shared factory', $checked > 0);

// ---------------------------------------------------------------------------
// Cross-module collision guard (regression for the 'rules' TypeError, 2026-09-12).
//
// BaseService::getSharedInstance($key) keys into ONE process-global registry
// shared across every Config\Services subclass. So if two DIFFERENT modules both
// call getSharedInstance('rules'), whichever instantiates first wins and the
// other module's rules() returns the wrong concrete type — a TypeError against
// its declared return. The intra-module key===name check above cannot see this.
//
// Fix pattern (already applied to campaigns + rules): a colliding factory must
// NOT use getSharedInstance(); it uses a module-local `private static ?T $shared…`
// cache instead. So the invariant we assert is: no bare getSharedInstance('key')
// string is used as a real shared key by more than one module.
// ---------------------------------------------------------------------------
echo "\ncross-module shared-key collisions\n";
$keyToModules = [];
foreach ($files as $file) {
    $mod = basename(dirname($file, 2));
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $trim = ltrim($line);
        // ignore comment lines so the explanatory notes don't count as usage.
        if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
            continue;
        }
        if (preg_match("/return static::getSharedInstance\\('([^']+)'\\)/", $line, $km)) {
            $keyToModules[$km[1]][$mod] = true;
        }
    }
}
foreach ($keyToModules as $key => $mods) {
    $names = implode(', ', array_keys($mods));
    chk("shared key '{$key}' is used by exactly one module (got: {$names})", count($mods) === 1);
}
chk('collected at least one shared key', $keyToModules !== []);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
