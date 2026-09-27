<?php

declare(strict_types=1);

namespace WBS\Shared\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Shared\Navigation\MenuCategory;
use WBS\Shared\Navigation\MenuCoverage;

/**
 * Assisted menu linking (the practical answer to "auto-link new routes").
 *
 *   php spark menu:scaffold
 *
 * Full auto-linking is intentionally NOT done: a route has no label/category/icon
 * and can't tell a landing page from a detail/action route, and blindly linking
 * auth-only routes into privileged categories leaks those categories. Instead this
 * command reports every page-like GET route that is not yet in the catalog and
 * prints a ready-to-paste MenuItem stub for each — with the category guessed from
 * the path prefix and the permission read from the route's authorize: filter — so
 * a human finishes it (label wording, icon, order) in seconds.
 *
 * Pair with the coverage TEST (menu_coverage_test.php), which FAILS in CI when an
 * untriaged page-like route exists, so nothing goes missing silently.
 */
final class MenuScaffoldCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'menu:scaffold';
    protected $description = 'Report page-like routes missing from the menu and print MenuItem stubs to paste.';
    protected $usage       = 'menu:scaffold [--check]';
    protected $options     = [
        '--check' => 'Exit non-zero if any page-like route is untriaged (for CI). No stubs printed.',
    ];

    /** Map a top-level path prefix to a menu category constant name. */
    private const CATEGORY_HINT = [
        'me'            => 'OVERVIEW',
        'members'       => 'PEOPLE',
        'identity'      => 'PEOPLE',
        'journey'       => 'PEOPLE',
        'memberships'   => 'PEOPLE',
        'referrals'     => 'PEOPLE',
        'g'             => 'GROUPS',
        'groups'        => 'GROUPS',
        'group-kinds'   => 'GROUPS',
        'venues'        => 'GROUPS',
        'geo'           => 'GROUPS',
        'events'        => 'EVENTS',
        'courses'       => 'LEARNING',
        'vbcs'          => 'GIVING',
        'contributions' => 'GIVING',
        'causes'        => 'GIVING',
        'community'     => 'COMMUNICATIONS',
        'notifications' => 'COMMUNICATIONS',
        'meetings'      => 'COMMUNICATIONS',
        'streams'       => 'STREAMING',
        'reports'       => 'REPORTS',
        'gamification'  => 'REPORTS',
        'roles'         => 'ACCESS',
        'rules'         => 'ACCESS',
        'abac-policies' => 'ACCESS',
        'break-glass'   => 'ACCESS',
        'access-requests' => 'ACCESS',
        'admin'         => 'ADMIN',
        'integrations'  => 'ADMIN',
    ];

    public function run(array $params): int
    {
        $check      = array_key_exists('check', $params) || CLI::getOption('check');
        $routesFile = APPPATH . 'Config/Routes.php';
        $routes     = MenuCoverage::parseRoutes($routesFile);

        // Use the shared, reviewed exclusion list (single source of truth with the
        // coverage test) so the CLI and CI agree on what "missing" means.
        $report = MenuCoverage::report($routesFile);

        // Hygiene: exclusions that no longer match a real route.
        if ($report['staleExclusions'] !== []) {
            CLI::write('Stale exclusions (route renamed/removed — clean up MenuCoverage::EXCLUSIONS):', 'light_red');
            foreach ($report['staleExclusions'] as $s) {
                CLI::write('  - ' . $s, 'light_red');
            }
            CLI::newLine();
        }

        if ($report['missing'] === []) {
            CLI::write('All page-like GET routes are triaged (linked or excluded).', 'green');

            return $report['staleExclusions'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
        }

        // --check: CI mode — fail loudly, no stubs.
        if ($check) {
            CLI::error('Untriaged page-like GET routes (' . count($report['missing']) . '):');
            foreach ($report['missing'] as $path) {
                CLI::write('  - ' . $path, 'red');
            }
            CLI::newLine();
            CLI::write('Fix: `php spark menu:scaffold` to get MenuItem stubs, add them to', 'yellow');
            CLI::write('CoreMenuProvider, or record intentional exclusions in', 'yellow');
            CLI::write('WBS\\Shared\\Navigation\\MenuCoverage::EXCLUSIONS.', 'yellow');

            return EXIT_ERROR;
        }

        CLI::write('Page-like routes not yet in the menu (' . count($report['missing']) . '):', 'yellow');
        CLI::newLine();
        CLI::write('Paste the stubs you want into CoreMenuProvider::items(), then set a', 'dark_gray');
        CLI::write('human label/icon/order. Anything you intentionally omit: add to the', 'dark_gray');
        CLI::write('EXCLUSIONS list in menu_coverage_test.php so CI stays green.', 'dark_gray');
        CLI::newLine();

        foreach ($report['missing'] as $path) {
            $filters = $routes['GET ' . $path] ?? [];
            $perm    = null;
            $anyMode = false;
            foreach ($filters as $f) {
                if (str_starts_with($f, 'authorize:')) {
                    $spec  = substr($f, strlen('authorize:'));
                    $parts = explode(',', $spec);
                    $perm  = $parts[0];
                    $anyMode = (($parts[1] ?? '') === 'any');
                }
            }

            $id       = str_replace(['/', '-'], ['.', '_'], $path);
            $cat      = $this->guessCategory($path);
            $label    = $this->guessLabel($path);
            $permArg  = $perm !== null ? ", permissions: ['{$perm}']" : '';
            $scopeArg = $anyMode ? ", scopeCheck: 'any'" : '';

            CLI::write("  // route filter: " . ($filters === [] ? '(none)' : implode(', ', $filters)), 'dark_gray');
            CLI::write(
                "  new MenuItem('{$id}', \$C::{$cat}, '{$label}', '{$path}'{$permArg}{$scopeArg}, icon: 'dot', order: 100),",
                'white'
            );
            CLI::newLine();
        }

        CLI::write('Reminder: an item with NO permission shows to every authenticated user,', 'light_red');
        CLI::write('which also reveals its category header. Only omit the permission when the', 'light_red');
        CLI::write('route is genuinely auth-only AND you want everyone to see it.', 'light_red');

        return EXIT_SUCCESS;
    }

    private function guessCategory(string $path): string
    {
        $prefix = explode('/', $path)[0];
        $name   = self::CATEGORY_HINT[$prefix] ?? 'OVERVIEW';

        // Defensive: ensure the constant actually exists on MenuCategory.
        return defined(MenuCategory::class . '::' . $name) ? $name : 'OVERVIEW';
    }

    private function guessLabel(string $path): string
    {
        $last  = (string) array_slice(explode('/', $path), -1)[0];
        $words = ucwords(str_replace(['-', '_'], ' ', $last));

        return $words === '' ? $path : $words;
    }
}
