<?php

declare(strict_types=1);

/**
 * COVERAGE GUARD — new routes cannot silently go unlinked.
 *
 * This is the honest answer to "can it auto-link new routes?": full auto-linking
 * is wrong (a route has no label/category/icon and can't tell a landing page from
 * a detail/action route), but a route going MISSING from the menu unnoticed is the
 * real risk. So this test fails whenever a page-like GET route exists that is
 * neither in the catalog nor in the reviewed EXCLUSIONS list below. To make it
 * pass you either add a MenuItem (with a real label/category/icon) or record an
 * intentional exclusion here — a human decision, enforced automatically.
 *
 *   php app/Modules/Shared/Navigation/tests/menu_coverage_test.php
 */

require __DIR__ . '/../PermissionBits.php';
require __DIR__ . '/../MenuItem.php';
require __DIR__ . '/../MenuCategory.php';
require __DIR__ . '/../CoreMenuProvider.php';
require __DIR__ . '/../MenuCoverage.php';

use WBS\Shared\Navigation\MenuCoverage;

$ROUTES_FILE = __DIR__ . '/../../../../Config/Routes.php';

// Reviewed, intentional NON-menu routes live in ONE place: MenuCoverage::EXCLUSIONS.
// The `php spark menu:scaffold --check` CI guard reads the same constant, so the
// test and the command can never disagree about what "triaged" means.

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "menu coverage\n";

$routes  = MenuCoverage::parseRoutes($ROUTES_FILE);
$report  = MenuCoverage::report($ROUTES_FILE);

chk('route table parsed', count($routes) > 20);
chk('catalog has linked routes', count($report['linked']) > 20);

// The core guard: nothing page-like is untriaged.
if ($report['missing'] !== []) {
    echo "\n  --> Untriaged page-like GET routes (add a MenuItem or list in EXCLUSIONS):\n";
    foreach ($report['missing'] as $m) {
        echo "        - {$m}\n";
    }
    echo "\n";
}
chk('no untriaged page-like routes', $report['missing'] === []);

// Hygiene: exclusions must still correspond to real routes.
if ($report['staleExclusions'] !== []) {
    echo "\n  --> Stale EXCLUSIONS (route renamed/removed — delete these entries):\n";
    foreach ($report['staleExclusions'] as $s) {
        echo "        - {$s}\n";
    }
    echo "\n";
}
chk('no stale exclusions', $report['staleExclusions'] === []);

echo "\n-- coverage: " . count($report['linked']) . " linked, "
    . count(MenuCoverage::EXCLUSIONS) . " excluded, " . count($report['missing']) . " untriaged\n";
echo "== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
