<?php

declare(strict_types=1);

/**
 * PARTNERSHIP-TIER ADMIN CATALOG CRUD wiring test — proves the VBCS partnership
 * tier config grew from a read-only public catalogue (define/disable were
 * JSON-API-only and the write routes lacked `webcsrf`) into a full inline no-JS
 * admin CRUD surface, mirroring the gamification ranks/achievements catalogs:
 *
 *   - a new GET /vbcs/partnership/tiers/manage list route → VbcsController
 *     ::managePartnershipTiers → partnership_tiers_admin view (ALL tiers incl.
 *     disabled), passing csrf;
 *   - the "New tier" form and each row's "Edit" form both POST to the
 *     webcsrf-guarded upsert route /vbcs/partnership/tiers (define() upserts by
 *     org+code, so one route serves create + edit — no PATCH on the no-JS path);
 *   - a per-row Disable form POSTs to /partnership/tiers/{code}/disable (status
 *     flip);
 *   - definePartnershipTier/disablePartnershipTier PRG back to the manage list
 *     with a localized flash while keeping JSON for API clients;
 *   - min_pgv_minor captured in minor units, CSP-clean (no <script>/inline on*);
 *   - the public catalogue (partnership_tiers.php) stays read-only + active-only;
 *   - i18n parity for the new Contributions.partnershipTierForm.* block and the
 *     giving_partnership_admin menu label across all 6 locales.
 *
 *   php app/Modules/Contributions/Views/tests/contributions_tier_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Contributions/Language';
$viewDir    = $root . '/app/Modules/Contributions/Views';
$controller = $root . '/app/Modules/Contributions/Controllers/VbcsController.php';
$service    = $root . '/app/Modules/Contributions/Services/PartnershipService.php';
$routesFile = $root . '/app/Config/Routes.php';

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
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

// ── 1. i18n parity for the new partnershipTierForm block + menu label ─────────
echo "language parity (Contributions.partnershipTierForm.* + menu label)\n";
$en      = require $langDir . '/en/Contributions.php';
$enBlock = $en['partnershipTierForm'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en has partnershipTierForm block (>= 25 keys)', count($enKeys) >= 25, (string) count($enKeys));
foreach (['title', 'newTier', 'codeLabel', 'codeLocked', 'nameLabel', 'minPgvLabel', 'minPgvHint',
    'minMonthsLabel', 'sortLabel', 'saveNew', 'saveEdit', 'edit', 'disable', 'disableConfirm',
    'disableYes', 'createdFlash', 'updatedFlash', 'disabledFlash', 'empty', 'disabled', 'viewPublic'] as $k) {
    chk("en partnershipTierForm.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l  = require $langDir . "/$loc/Contributions.php";
    $lk = $flatten($l['partnershipTierForm'] ?? []);
    chk("$loc mirrors all en partnershipTierForm keys", array_diff($enKeys, $lk) === [], implode(',', array_slice(array_diff($enKeys, $lk), 0, 8)));
    chk("$loc has no stray partnershipTierForm keys", array_diff($lk, $enKeys) === [], implode(',', array_slice(array_diff($lk, $enKeys), 0, 8)));
}
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require $root . "/app/Language/$loc/App.php";
    chk("$loc App.menuItems.giving_partnership_admin present", isset($app['menuItems']['giving_partnership_admin']) && $app['menuItems']['giving_partnership_admin'] !== '');
}

// ── 2. Service exposes admin list ────────────────────────────────────────────
echo "PartnershipService admin list\n";
$svc = (string) file_get_contents($service);
chk('listForAdmin() present', str_contains($svc, 'function listForAdmin'));
chk('listForAdmin does NOT filter status (incl. inactive)',
    (bool) preg_match('/function listForAdmin.*?getResultArray/s', $svc)
    && ! (bool) preg_match("/function listForAdmin.*?where\('status'.*?getResultArray/s", $svc));
chk('public tiers() still active-only', (bool) preg_match("/function tiers\(.*?where\('status', 'active'\)/s", $svc));
chk('define() upserts by org+code', str_contains($svc, "->where('code', \$code)") && str_contains($svc, "'updated' => true"));

// ── 3. Admin view controls ───────────────────────────────────────────────────
echo "partnership_tiers_admin.php exposes create/edit/disable controls\n";
$src = (string) file_get_contents("$viewDir/partnership_tiers_admin.php");
chk('extends layouts/app', str_contains($src, "\$this->extend('layouts/app')"));
chk('create/edit form posts to /vbcs/partnership/tiers', str_contains($src, 'action="/vbcs/partnership/tiers"'));
chk('has a New (create) panel', str_contains($src, '$defineForm([], true)'));
chk('has a per-row Edit panel', str_contains($src, '$defineForm($'));
chk('code locked on edit path', str_contains($src, 'codeLocked'));
chk('min_pgv captured in minor units', str_contains($src, 'name="min_pgv_minor"'));
chk('has min_consecutive_months field', str_contains($src, 'name="min_consecutive_months"'));
chk('has a Disable form', str_contains($src, '/disable"'));
chk('disable confirmation copy', str_contains($src, 'disableConfirm'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('progressive-enhancement <details> (no-JS friendly)', str_contains($src, '<details'));
chk('links to public catalogue', str_contains($src, '/vbcs/partnership/tiers"'));
$noComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script> tag', ! str_contains($noComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noComments));

// public catalogue stays read-only
$pubSrc = (string) file_get_contents("$viewDir/partnership_tiers.php");
chk('public catalogue has no write forms', ! str_contains($pubSrc, '<form'));

// ── 4. Controller list route + PRG + JSON ────────────────────────────────────
echo "controller: manage list + PRG + csrf + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('managePartnershipTiers() present', str_contains($ctrl, 'function managePartnershipTiers'));
chk('manage renders partnership_tiers_admin view', str_contains($ctrl, 'WBS\Contributions\Views\partnership_tiers_admin'));
chk('manage uses listForAdmin + passes csrf', str_contains($ctrl, 'listForAdmin') && (bool) preg_match('/function managePartnershipTiers\(.*?wbsCsrf/s', $ctrl));
chk('definePartnershipTier PRGs via respondTierDecision', (bool) preg_match('/function definePartnershipTier\(.*?respondTierDecision/s', $ctrl));
chk('disablePartnershipTier PRGs with disabledFlash', (bool) preg_match('/function disablePartnershipTier\(.*?disabledFlash/s', $ctrl));
chk('respondTierDecision redirects to manage list', str_contains($ctrl, "/vbcs/partnership/tiers/manage'"));
chk('respondTierDecision infers created/updated flash', str_contains($ctrl, "! empty(\$result->data['updated']) ? 'updatedFlash' : 'createdFlash'"));
chk('JSON kept for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

// ── 5. Routes gated + webcsrf ────────────────────────────────────────────────
echo "routes: manage GET gated; write routes webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*partnership/tiers/manage.*managePartnershipTiers.*$#m', $routes, $m)) {
    chk('GET partnership/tiers/manage route present', true);
    chk('manage route authorize:contribution.manage', str_contains($m[0], 'authorize:contribution.manage'));
} else {
    chk('GET partnership/tiers/manage route present', false);
}
$writeRoutes = [
    'definePartnershipTier'  => "post('partnership/tiers'",
    'disablePartnershipTier' => 'disablePartnershipTier/$1',
];
foreach ($writeRoutes as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label keeps authorize:contribution.manage", str_contains($m[0], 'authorize:contribution.manage'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — new + edit + disable + empty (fr/ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Contributions') { return $key; }
        $v = $GLOBALS['__cLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir, $view) {
        extract($data);
        ob_start();
        include "$viewDir/$view.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = ['success' => 'Niveau créé.'];
$h = $render('partnership_tiers_admin', [
    'csrf' => 'TT',
    'tiers' => [
        ['code' => 'gold', 'name' => 'Gold', 'min_pgv_minor' => 500000, 'min_consecutive_months' => 12,
            'icon' => '🥇', 'color' => '#f59e0b', 'sort_order' => 30, 'description' => 'Top partner', 'status' => 'active'],
        ['code' => 'retired', 'name' => 'Retired tier', 'min_pgv_minor' => 100000, 'min_consecutive_months' => 3, 'status' => 'inactive'],
    ],
], 'fr');
chk('render: new-tier create panel posts to /vbcs/partnership/tiers', str_contains($h, 'action="/vbcs/partnership/tiers"'));
chk('render: row edit prefilled name', str_contains($h, 'value="Gold"'));
chk('render: locked code hidden field on edit', str_contains($h, 'name="code" value="gold"'));
chk('render: min_pgv_minor prefilled', str_contains($h, 'value="500000"'));
chk('render: disable form for active row', str_contains($h, 'action="/vbcs/partnership/tiers/gold/disable"'));
chk('render: inactive row marked disabled', str_contains($h, 'tier-disabled'));
chk('render: inactive row has NO disable form', ! str_contains($h, 'action="/vbcs/partnership/tiers/retired/disable"'));
chk('render: csrf token present', str_contains($h, 'value="TT"'));
chk('render: success flash rendered', str_contains($h, 'Niveau créé.'));
chk('render: no untranslated partnershipTierForm keys leaked', ! str_contains($h, 'Contributions.partnershipTierForm'));

$GLOBALS['__flash'] = [];
$he = $render('partnership_tiers_admin', ['csrf' => 'TT', 'tiers' => []], 'ar');
chk('render: empty state + create panel still shown',
    str_contains($he, lang('Contributions.partnershipTierForm.empty')) && str_contains($he, 'action="/vbcs/partnership/tiers"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
