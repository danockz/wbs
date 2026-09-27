<?php

declare(strict_types=1);

/**
 * ANTI-DRIFT: prove the menu catalog cannot advertise a destination the router or
 * the PDP would treat differently. For every catalog item with a route:
 *   (1) the GET route must exist in the route table, and
 *   (2) if the item declares a permission, the route's authorize: filter must
 *       declare the SAME permission code (and 'any' vs exact must match scopeCheck).
 *
 * Some starter items point at feature routes not built yet -> listed in KNOWN_GAPS
 * so CI stays green while still REPORTING them. Removing a gap entry once its route
 * lands turns the check back on automatically.
 *
 * Pure static analysis of Config/Routes.php + the catalog: no DB, no framework.
 */

require __DIR__ . '/../PermissionBits.php';
require __DIR__ . '/../MenuCategory.php';
require __DIR__ . '/../MenuItem.php';
require __DIR__ . '/../CoreMenuProvider.php';

use WBS\Shared\Navigation\CoreMenuProvider;

$ROUTES_FILE = __DIR__ . '/../../../../Config/Routes.php';

/** Routes not yet implemented; documented, not failed. Trim as features land. */
const KNOWN_GAPS = [
    // (empty) — every catalog menu item now resolves to a real GET route.
];

/**
 * Parse Config/Routes.php into: method+path => [filters...]. Handles top-level
 * routes and nested $routes->group('prefix', [opts]?, fn). Good enough for the
 * canonical single-file route table used here.
 *
 * @return array<string,list<string>> "GET path" => filter list
 */
function parseRoutes(string $file): array
{
    $src   = file_get_contents($file);
    $lines = explode("\n", $src);
    $out   = [];

    /** @var list<array{prefix:string,filters:list<string>}> $stack */
    $stack = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        // group open: $routes->group('prefix', ['filter'=>...], static function
        if (preg_match("/\\\$routes->group\\(\\s*'([^']*)'\\s*(?:,\\s*(\\[[^\\]]*\\]))?/", $trim, $g)) {
            $prefix  = $g[1];
            $filters = isset($g[2]) ? extractFilters($g[2]) : [];
            $parent  = end($stack);
            $stack[] = [
                'prefix'  => joinPath($parent['prefix'] ?? '', $prefix),
                'filters' => array_merge($parent['filters'] ?? [], $filters),
            ];
            continue;
        }

        // group close: a line that is just }); (best-effort for this file's style)
        if ($trim === '});' && $stack !== []) {
            array_pop($stack);
            continue;
        }

        // a verb route: $routes->get('path', 'handler', ['filter'=>...]);
        if (preg_match("/\\\$routes->(get|post|put|patch|delete|match)\\(\\s*'([^']*)'\\s*,\\s*'[^']*'\\s*(?:,\\s*(\\[.*\\]))?\\s*\\)/", $trim, $m)) {
            $verb   = strtoupper($m[1]);
            $parent = end($stack);
            $path   = joinPath($parent['prefix'] ?? '', $m[2]);
            $filt   = array_merge($parent['filters'] ?? [], isset($m[3]) ? extractFilters($m[3]) : []);
            $out[$verb . ' ' . ($path === '' ? '/' : $path)] = $filt;
        }
    }

    return $out;
}

/** @return list<string> filter strings from an options array literal. */
function extractFilters(string $opts): array
{
    if (! preg_match("/'filter'\\s*=>\\s*(\\[[^\\]]*\\]|'[^']*')/", $opts, $m)) {
        return [];
    }
    $val = $m[1];
    if ($val[0] === "'") {
        return [trim($val, "'")];
    }
    preg_match_all("/'([^']*)'/", $val, $all);

    return $all[1];
}

function joinPath(string $a, string $b): string
{
    $a = trim($a, '/');
    $b = trim($b, '/');
    if ($a === '') {
        return $b;
    }
    if ($b === '') {
        return $a;
    }

    return $a . '/' . $b;
}

// ---- Run the checks ----
$routes = parseRoutes($ROUTES_FILE);
$p = 0; $f = 0; $gapsHit = [];
function chk(string $n, bool $c): void { global $p, $f; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $c ? $p++ : $f++; }

chk('route table parsed (has entries)', count($routes) > 20);

foreach (CoreMenuProvider::items() as $item) {
    if ($item->route === '') {
        continue;
    }
    $key    = 'GET ' . trim($item->route, '/');
    $exists = isset($routes[$key]);

    if (! $exists) {
        if (in_array($item->route, KNOWN_GAPS, true)) {
            $gapsHit[] = $item->route;
            continue; // documented gap: report, don't fail
        }
        chk("route exists for item '{$item->id}' ({$item->route})", false);
        continue;
    }

    chk("route exists for item '{$item->id}' ({$item->route})", true);

    // Permission alignment: if the item needs a permission, the route's authorize:
    // filter must declare the same code.
    if ($item->permissions !== []) {
        $filters   = $routes[$key];
        $authz     = array_values(array_filter($filters, static fn ($x) => str_starts_with($x, 'authorize:')));
        $declared  = [];
        $anyMode   = false;
        foreach ($authz as $a) {
            $spec = substr($a, strlen('authorize:'));
            $parts = explode(',', $spec);
            $declared[] = $parts[0];
            if (($parts[1] ?? '') === 'any') {
                $anyMode = true;
            }
        }
        $missing = array_diff($item->permissions, $declared);
        chk("permission aligned for '{$item->id}' (needs " . implode('+', $item->permissions) . ')',
            $missing === []);
        if ($item->scopeCheck === 'any') {
            chk("scopeCheck 'any' matches route filter for '{$item->id}'", $anyMode || $declared === []);
        }
    }
}

echo "\n-- Known gaps hit (feature routes not built yet): " . (count($gapsHit) ? implode(', ', $gapsHit) : 'none') . "\n";
echo "== $p passed, $f failed ==\n";
exit($f > 0 ? 1 : 0);
