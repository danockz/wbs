<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Route -> menu coverage analyzer (the "can it auto-link new routes?" seam).
 *
 * A route table cannot be turned into a menu automatically without human input:
 * it has no labels, categories, icons, display order, and no notion of which of a
 * group's routes is the linkable *landing page* vs (:segment) detail routes, POST
 * actions, or API/system endpoints. Auto-linking would also happily surface
 * auth-only routes inside privileged categories (a visibility leak).
 *
 * So instead of silently auto-linking, this analyzer makes new routes *impossible
 * to miss*: it classifies every GET route as page-like or not, and reports which
 * page-like routes are neither in the catalog nor explicitly excluded. The
 * coverage test fails on any such route, forcing a human to either add a MenuItem
 * (with a real label/category/icon) or record an intentional exclusion.
 *
 * Pure static analysis of Config/Routes.php — no DB, no framework boot.
 */
final class MenuCoverage
{
    /**
     * Reviewed, intentional NON-menu page-like routes — the SINGLE SOURCE OF TRUTH
     * shared by the coverage test and `php spark menu:scaffold`. Each entry is a
     * deliberate "a human looked and this GET route is not navigation" decision.
     * Keep it tight. (Structural API/system/auth endpoints are already filtered by
     * isPageLike() and need NOT be listed here.)
     *
     * @var list<string>
     */
    public const EXCLUSIONS = [
        // Committee oversight queue — a filtered view of the Event committees hub
        // (which IS in the menu), linked from that hub and from each event's committee
        // console. Not a separate landing page.
        'event-committees/decisions',
        // Integration-decision confirmation queue — the mentor's make-checker
        // inbox over self-declarations, linked from "My integration" (which IS in
        // the menu). A filtered action surface, not a separate landing page.
        'me/integration-decisions',
        // iCalendar SUBSCRIPTION feeds — text/calendar data endpoints fetched by
        // calendar clients (Google/Apple/Outlook "add by URL"), reached from the
        // Events calendar page's / My-events page's "Subscribe" links. The
        // personal feed (mine.ics) is authorized by a signed ?token=, not a
        // session, so it is public-by-route. Neither is a browsable page.
        'events/calendar.ics',
        'events/mine.ics',
        // Integrations diagnostic/read surfaces — auth-only today; not primary nav.
        'integrations/circuits',
        'integrations/connections',
        // Connector-profile authoring console — a secondary surface inside the
        // Integrations area (the "Integrations" catalogue item IS in the menu),
        // gated by provider.configure. Not a primary landing page.
        'integrations/profiles',
        'integrations/custom-adapters',
        'integrations/custom-adapters/allowlist',
        'integrations/fallback-matrix',
        'integrations/oauth',
        // Involvement-triage config console — a secondary settings surface reached
        // from the journey pipeline's "Triage settings" link (the pipeline IS in
        // the menu), scope-gated by gamification.manage. Not a primary landing page.
        'journey/involvement/config',
        // Venue proximity lookup — a query surface used inside pages, not a landing page.
        'venues/nearby',
        // Public global->local group MAP — an alternate presentation of the public
        // group directory ('g', which is public-by-route and not a member-menu
        // item), reached from the /g directory's "Find on a map" link. A public
        // discovery surface, not an authenticated member landing page.
        'g/map',
        // Admin global->local VENUE map — a secondary presentation of the venue
        // directory (the venue directory 'venues' IS in the menu), reached from
        // its "Map view" link and gated by venue.manage. Not a primary landing page.
        'venues/directory',
        // Signed-in landing shell (redirects to /me/dashboard, which IS in the menu).
        'me',
        // Member avatar image endpoint — returns an <img> (photo or initials SVG),
        // consumed by pages, never a landing page itself.
        'me/avatar',
        // Rule CREATE form — an action reached from the Rules catalogue's "New
        // rule" button, not primary navigation (the catalogue itself IS in the
        // menu). The edit form (rules/(:segment)/edit) needs a rule id so it is
        // already non-page-like.
        'rules/new',
        // Role CREATE form — reached from the Roles catalogue's "New role" button
        // (the catalogue itself IS in the menu); the edit form needs a role id.
        'roles/new',
        // ABAC policy CREATE form — reached from the policy catalogue's "New
        // policy" button (the catalogue itself IS in the menu).
        'abac-policies/new',
        // Venue CREATE form — reached from the venue directory's "New venue"
        // button (the directory itself IS in the menu); the edit form needs a
        // venue id.
        'venues/new',
        // Gamification point-rule CREATE form — reached from the rules list's
        // "New rule" button (the list itself IS in the menu); the edit form
        // needs a rule code.
        'gamification/rules/new',
        // Cause CREATE form — reached from the cause directory's "New cause"
        // button (the directory itself IS in the menu, under Giving); the edit
        // form needs a cause id.
        'causes/new',
        // Follow-up RECORD capture form — reached from the follow-ups work queue's
        // "Record a follow-up" button (the queue itself IS in the menu); it is a
        // capture action, not a landing page.
        'gamification/follow-ups/new',
        // Giving-commitment (pledge) CAPTURE form — reached from a subject's
        // commitments list "New commitment" button. The list is subject-scoped
        // (vbcs/subjects/(:segment)/commitments — needs an id, already non-page-
        // like), so this create form is a capture action, not a landing page.
        'vbcs/commitments/new',
        // Manual/in-kind giving MAKER capture form — reached from the manual
        // approval queue's "Record manual gift" button (vbcs/manual/pending IS
        // menu-reachable via giving.manual). This is a capture action, not a
        // landing page.
        'vbcs/manual/new',
        // Campaign CREATE form — reached from the group-campaigns list "New
        // campaign" CTA (that list IS menu-reachable). The edit form
        // (gamification/campaigns/(:segment)/edit) needs an id so it is already
        // non-page-like. Both are config actions, not landing pages.
        'gamification/campaigns/new',
    ];

    /**
     * Parse Config/Routes.php into: "VERB path" => list<filter>.
     * Handles top-level routes and nested $routes->group('prefix', [opts]?, fn).
     *
     * @return array<string,list<string>>
     */
    public static function parseRoutes(string $routesFile): array
    {
        $lines = explode("\n", (string) file_get_contents($routesFile));
        $out   = [];
        /** @var list<array{prefix:string,filters:list<string>}> $stack */
        $stack = [];

        foreach ($lines as $line) {
            $trim = trim($line);

            if (preg_match("/\\\$routes->group\\(\\s*'([^']*)'\\s*(?:,\\s*(\\[[^\\]]*\\]))?/", $trim, $g)) {
                $parent  = end($stack) ?: ['prefix' => '', 'filters' => []];
                $stack[] = [
                    'prefix'  => self::joinPath($parent['prefix'], $g[1]),
                    'filters' => array_merge($parent['filters'], isset($g[2]) ? self::extractFilters($g[2]) : []),
                ];
                continue;
            }
            if ($trim === '});' && $stack !== []) {
                array_pop($stack);
                continue;
            }
            if (preg_match("/\\\$routes->(get|post|put|patch|delete|match)\\(\\s*'([^']*)'\\s*,\\s*'[^']*'\\s*(?:,\\s*(\\[.*\\]))?\\s*\\)/", $trim, $m)) {
                $parent = end($stack) ?: ['prefix' => '', 'filters' => []];
                $path   = self::joinPath($parent['prefix'], $m[2]);
                $filt   = array_merge($parent['filters'], isset($m[3]) ? self::extractFilters($m[3]) : []);
                $out[strtoupper($m[1]) . ' ' . ($path === '' ? '/' : $path)] = $filt;
            }
        }

        return $out;
    }

    /**
     * Is this a page-like GET route a human might reasonably put in the menu?
     *
     * Excludes: non-GET verbs; routes with URI params (:segment / :num / :any) —
     * those need an id, so they are detail/action routes, not landing pages;
     * and a small set of API/system/auth path prefixes that are never navigation.
     */
    public static function isPageLike(string $verbPath): bool
    {
        if (! str_starts_with($verbPath, 'GET ')) {
            return false;
        }
        $path = substr($verbPath, 4);

        if ($path === '/' || $path === '') {
            return false;                 // site root / landing
        }
        if (str_contains($path, '(:')) {
            return false;                 // needs a parameter -> not a landing page
        }

        // API / system / auth-flow endpoints that are structurally never menu items.
        static $prefixes = [
            'openapi.json', 'health', 'docs',
            'login', 'mfa', 'set-password', 'logout',
            'me/menu',                    // the menu endpoints themselves
            'auth/', 'tokens', 'geo/',    // token/geo lookups are API surfaces
        ];
        foreach ($prefixes as $p) {
            if ($path === rtrim($p, '/') || str_starts_with($path, $p)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compute coverage: which page-like GET routes are linked, unlinked, or
     * explicitly excluded.
     *
     * @param array<string,list<string>> $routes    from parseRoutes()
     * @param list<string>               $catalog   route strings already in the menu (no leading slash)
     * @param list<string>               $excluded  reviewed "intentionally not in menu" route strings
     * @return array{linked:list<string>,missing:list<string>,staleExclusions:list<string>}
     */
    public static function analyze(array $routes, array $catalog, array $excluded): array
    {
        $catalog  = array_map(static fn ($r) => trim($r, '/'), $catalog);
        $excluded = array_map(static fn ($r) => trim($r, '/'), $excluded);

        $linked  = [];
        $missing = [];
        $seen    = [];

        foreach (array_keys($routes) as $vp) {
            if (! self::isPageLike($vp)) {
                continue;
            }
            $path        = trim(substr($vp, 4), '/');
            $seen[$path] = true;

            if (in_array($path, $catalog, true)) {
                $linked[] = $path;
            } elseif (in_array($path, $excluded, true)) {
                // intentional exclusion — fine
            } else {
                $missing[] = $path;       // NEW page-like route nobody has triaged
            }
        }

        // Exclusions that no longer match any route (route renamed/removed) -> prune.
        $stale = array_values(array_filter($excluded, static fn ($e) => ! isset($seen[$e])));

        sort($linked);
        sort($missing);

        return ['linked' => $linked, 'missing' => $missing, 'staleExclusions' => $stale];
    }

    /**
     * Run the full coverage analysis against a routes file and the live catalog,
     * using the shared EXCLUSIONS list. One call for both the CLI and the test.
     *
     * @param list<string>|null $excluded override exclusions (defaults to self::EXCLUSIONS)
     * @return array{linked:list<string>,missing:list<string>,staleExclusions:list<string>}
     */
    public static function report(string $routesFile, ?array $excluded = null): array
    {
        return self::analyze(
            self::parseRoutes($routesFile),
            self::catalogRoutes(),
            $excluded ?? self::EXCLUSIONS,
        );
    }

    /** @return list<string> the route string each catalog MenuItem points at. */
    public static function catalogRoutes(): array
    {
        $routes = [];
        foreach (CoreMenuProvider::items() as $item) {
            if ($item->route !== '') {
                $routes[] = trim($item->route, '/');
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return list<string> filter strings from an options array literal. */
    private static function extractFilters(string $opts): array
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

    private static function joinPath(string $a, string $b): string
    {
        $a = trim($a, '/');
        $b = trim($b, '/');

        return $a === '' ? $b : ($b === '' ? $a : $a . '/' . $b);
    }
}
