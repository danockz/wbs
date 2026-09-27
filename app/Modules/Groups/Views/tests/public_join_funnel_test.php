<?php

declare(strict_types=1);

/**
 * PUBLIC group self-join funnel — CSRF-hardening + render smoke.
 *
 * Closes the last member-facing Section-C write-gap: `POST g/{slug}/join` had a
 * bespoke confirm page already, but the form carried NO hidden `_csrf` field
 * (the controller only minted a token for a logged-in viewer's logout form, not
 * for the ANONYMOUS guest who is the join form's primary audience) and the POST
 * route had NO `webcsrf` guard.
 *
 * This pins:
 *   - the join POST route is now ratelimit + webcsrf guarded (and stays
 *     auth-free — guests may join);
 *   - the controller mints a double-submit token for EVERY visitor (pure crypto,
 *     no DB — resource-light) so the form and cookie match for guests too;
 *   - the join form renders the hidden `_csrf` bound to $csrf;
 *   - i18n `Groups.join.*` parity across 6 locales;
 *   - render smoke (fr open policy + ar RTL approval policy, sticky old input,
 *     error banner) with no leaked lang keys.
 *
 *   php app/Modules/Groups/Views/tests/public_join_funnel_test.php
 */

$root     = dirname(__DIR__, 5);
$langDir  = $root . '/app/Modules/Groups/Language';
$viewDir  = $root . '/app/Modules/Groups/Views';
$ctrlFile = $root . '/app/Modules/Groups/Controllers/GroupPublicController.php';
$routes   = $root . '/app/Config/Routes.php';

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
    sort($o);
    return $o;
};

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "i18n: Groups.join.* parity across 6 locales\n";
$en     = require $langDir . '/en/Groups.php';
$enKeys = $flatten($en['join'] ?? []);
chk('en join block present (>= 12 keys)', count($enKeys) >= 12, (string) count($enKeys));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Groups.php";
    $keys = $flatten($m['join'] ?? []);
    chk("$loc mirrors all en join keys", $keys === $enKeys, 'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
}

// ── 2. Route: join POST is ratelimit + webcsrf, still auth-free ───────────────
echo "routes: POST g/{slug}/join guards\n";
$rt = (string) file_get_contents($routes);
if (preg_match('#\$routes->post\([^\n]*GroupPublicController::join[^\n]*#', $rt, $m)) {
    chk('POST g/{slug}/join present', true);
    chk('join POST webcsrf-guarded', str_contains($m[0], 'webcsrf'));
    chk('join POST keeps ratelimit', str_contains($m[0], 'ratelimit:auth.register'));
    chk('join POST stays auth-free (guests may join)', ! preg_match("/'auth'/", $m[0]));
} else {
    chk('POST g/{slug}/join present', false);
}
if (preg_match('#\$routes->get\([^\n]*GroupPublicController::joinForm[^\n]*#', $rt, $mg)) {
    chk('GET g/{slug}/join form route present', true);
    chk('join form GET is a read (no webcsrf)', ! str_contains($mg[0], 'webcsrf'));
} else {
    chk('GET g/{slug}/join form route present', false);
}

// ── 3. Controller: token minted for every visitor ────────────────────────────
echo "controller: CSRF token for anonymous guests\n";
$ctrl = (string) file_get_contents($ctrlFile);
chk('viewerContext mints csrf up-front (guest too)', (bool) preg_match('/function viewerContext.*?\$csrf\s*=\s*IdentityServices::webAuth\(\)->issueCsrf\(\)/s', $ctrl));
chk('anonymous branch returns the minted csrf (not null)', (bool) preg_match("/viewerCache\s*=\s*\['viewer'\s*=>\s*null,\s*'csrf'\s*=>\s*\\\$csrf\]/", $ctrl));
chk('htmlWithViewer passes csrf into the view', str_contains($ctrl, "'csrf' => \$ctx['csrf']"));
chk('csrf cookie set on the response', str_contains($ctrl, "'name'     => 'wbs_csrf'"));

// ── 4. View: hidden _csrf bound to $csrf, CSP-clean ──────────────────────────
echo "public_join.php controls\n";
$src = (string) file_get_contents("$viewDir/public_join.php");
chk('form binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\(string\) \(\$csrf/', $src));
chk('form posts to /g/{slug}/join', str_contains($src, 'action="/g/<?= esc($slug) ?>/join"'));
chk('self-contained locale wiring', str_contains($src, '_locale.php'));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));

// ── 5. Render smoke ──────────────────────────────────────────────────────────
echo "render smoke — fr (open) + ar (approval, sticky + error)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { public function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return 'https://public.test/' . ltrim((string) $p, '/'); }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Groups') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
// The view includes _locale.php which references config(\Config\Locale::class).
if (! class_exists('Config\\Locale')) {
    eval('namespace Config; class Locale { public array $rtl = ["ar","he","fa","ur"]; }');
}
// public_join.php embeds the shared _public_nav partial via view(); stub it so the
// headless render exercises the join card without booting the framework renderer.
if (! function_exists('view')) {
    function view(string $name, array $data = []) { return '<nav data-partial="' . htmlspecialchars($name, ENT_QUOTES) . '"></nav>'; }
}
$render = static function (array $data, string $loc) use ($viewDir, $langDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/public_join.php";
    return (string) ob_get_clean();
};

// fr — open-policy group, anonymous guest, fresh form
$h = $render([
    'data'   => ['group' => ['slug' => 'accra-central', 'name' => 'Accra Central', 'join_policy' => 'open', 'hero_theme' => 'aurora'], 'leader' => null],
    'errors' => [],
    'old'    => [],
    'csrf'   => 'TKN-FR',
    'viewer' => null,
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr csrf token rendered in hidden field', str_contains($h, 'name="_csrf" value="TKN-FR"'));
chk('fr form posts to the slugged join endpoint', str_contains($h, 'action="/g/accra-central/join"'));
chk('fr group name shown', str_contains($h, 'Accra Central'));
chk('fr open-policy note used', str_contains($h, esc($GLOBALS['__gLang']['join']['policyOpen'])));
chk('fr no leaked Groups.join.* keys', ! str_contains($h, 'Groups.join.'));

// ar — approval-policy group, sticky old input + error banner, RTL
$ha = $render([
    'data'   => ['group' => ['slug' => 'youth-cell', 'name' => 'Youth Cell', 'join_policy' => 'approval', 'hero_theme' => 'forest'], 'leader' => ['display_name' => 'Ama']],
    'errors' => ['A valid email is required.'],
    'old'    => ['name' => 'Kofi', 'email' => 'kofi@example.test'],
    'csrf'   => 'TKN-AR',
    'viewer' => null,
], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar csrf token rendered', str_contains($ha, 'name="_csrf" value="TKN-AR"'));
chk('ar error banner shown', str_contains($ha, 'A valid email is required.'));
chk('ar sticky name value', str_contains($ha, 'value="Kofi"'));
chk('ar sticky email value', str_contains($ha, 'value="kofi@example.test"'));
chk('ar approval-policy note used', str_contains($ha, esc($GLOBALS['__gLang']['join']['policyApproval'])));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
