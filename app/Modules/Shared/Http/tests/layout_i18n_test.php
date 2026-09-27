<?php

declare(strict_types=1);

/**
 * Shared layout chrome i18n test (regression, 2026-09-12).
 *
 * The shared HTML layout app/Views/layouts/app.php wraps most server-rendered
 * module pages. Its top-nav links ("My dashboard" / "Status") were hardcoded in
 * English, so every page that extends the layout showed untranslated chrome for
 * fr/es/pt/zh/ar users. This test asserts:
 *   - App.navDashboard / App.navStatus exist and mirror across all locales,
 *   - the layout references those keys via lang() (not bare English text),
 *   - a headless render in fr + ar emits the translated nav labels and the
 *     correct <html lang/dir>, and still degrades to English when i18n is absent.
 *
 *   php app/Modules/Shared/Http/tests/layout_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Language';
$layout  = $root . '/app/Views/layouts/app.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. Keys exist + parity ───────────────────────────────────────────────────
echo "App.navDashboard / App.navStatus parity\n";
$navKeys = ['navDashboard', 'navStatus'];
$en = require "$langDir/en/App.php";
foreach ($navKeys as $k) {
    chk("en App.$k present", isset($en[$k]) && $en[$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require "$langDir/$loc/App.php";
    foreach ($navKeys as $k) {
        chk("$loc App.$k present", isset($l[$k]) && $l[$k] !== '');
        chk("$loc App.$k differs from en (translated)", ($l[$k] ?? '') !== ($en[$k] ?? '') || $loc === 'en');
    }
}

// ── 2. Layout uses lang(), not bare text ─────────────────────────────────────
echo "layout localizes the top nav\n";
$src = (string) file_get_contents($layout)
    . (string) file_get_contents($root . '/app/Modules/Shared/Views/_shell_open.php');
chk('references App.navDashboard', str_contains($src, 'App.navDashboard'));
chk('references App.navStatus', str_contains($src, 'App.navStatus'));
chk('no bare >My dashboard< nav text', ! str_contains($src, '>My dashboard</a>'));
chk('no bare >Status< nav text', ! str_contains($src, '>Status</a>'));

// ── 3. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke (fr + ar + no-i18n fallback)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            public $wbsCsrf = 'TOK';
            function getLocale() { return $GLOBALS['__loc'] ?? 'en'; }
            function getPath() { return '/me/dashboard'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c) {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
            public array $supported = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'App') { return $key; }
        $v = $GLOBALS['__appLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}

// The layout's footer calls \WBS\Shared\Navigation\MenuFragment::html(); that
// class isn't autoloaded in this bare CLI harness (and pulls framework state we
// don't need here). Register a no-op stub for the FQCN so the include completes.
if (! class_exists('WBS\\Shared\\Navigation\\MenuFragment')) {
    eval('namespace WBS\\Shared\\Navigation; class MenuFragment { public static function html(): string { return ""; } }');
}

$render = static function (string $loc) use ($langDir, $layout): string {
    $GLOBALS['__loc']     = $loc;
    $GLOBALS['__appLang'] = require "$langDir/$loc/App.php";
    // Minimal View-ish host providing renderSection('content').
    $host = new class {
        function renderSection($x) { return '<main>page</main>'; }
    };
    // MenuFragment::html() (called at the foot of the layout) may echo directly
    // to output rather than returning a string; wrap the whole include in an
    // outer buffer too so nothing leaks into this test's own stdout.
    ob_start();
    $bound = Closure::bind(function () use ($layout) {
        ob_start();
        include $layout;
        return (string) ob_get_clean();
    }, $host, $host);
    $out = $bound();
    $out .= (string) ob_get_clean();
    return $out;
};

$fr = $render('fr');
chk('fr: html lang=fr dir=ltr', str_contains($fr, 'lang="fr"') && str_contains($fr, 'dir="ltr"'));
chk('fr: dashboard label translated', str_contains($fr, (string) (require "$langDir/fr/App.php")['navDashboard']));
chk('fr: status label translated', str_contains($fr, (string) (require "$langDir/fr/App.php")['navStatus']));
chk('fr: no raw key leaked', ! str_contains($fr, 'App.navDashboard') && ! str_contains($fr, 'App.navStatus'));

$ar = $render('ar');
chk('ar: html lang=ar dir=rtl', str_contains($ar, 'lang="ar"') && str_contains($ar, 'dir="rtl"'));
chk('ar: dashboard label translated', str_contains($ar, (string) (require "$langDir/ar/App.php")['navDashboard']));

// ── 4. Shared chrome: universal menu fragment + generic data-page fallback ────
// These render on top of EVERY page (the menu launcher/drawer is injected on all
// routes; data_page is the browser fallback for any endpoint without a bespoke
// view). Both used to carry hardcoded English, so all non-en users saw
// untranslated chrome regardless of which page they were on. Assert the keys
// exist + mirror, the sources use lang(), and no bare English literals remain.
echo "shared chrome keys parity (menu + data-page)\n";
$chromeKeys = [
    'menuLoading', 'menuOpen', 'menuTitle',
    'dpResult', 'dpError', 'dpRequestFailed', 'dpDetails',
    'dpServerResponse', 'dpSuccessNoData', 'dpMeta', 'dpGenericNote',
];
foreach ($chromeKeys as $k) {
    chk("en App.$k present", isset($en[$k]) && $en[$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require "$langDir/$loc/App.php";
    foreach ($chromeKeys as $k) {
        chk("$loc App.$k present", isset($l[$k]) && $l[$k] !== '');
    }
}

echo "MenuFragment localizes its chrome\n";
$fragSrc = (string) file_get_contents($root . '/app/Modules/Shared/Navigation/MenuFragment.php');
chk('MenuFragment references App.menuLoading', str_contains($fragSrc, 'menuLoading'));
chk('MenuFragment references App.menuOpen', str_contains($fragSrc, 'menuOpen'));
chk('MenuFragment references App.menuTitle', str_contains($fragSrc, 'menuTitle'));
chk('MenuFragment no bare "Loading menu"', ! str_contains($fragSrc, 'Loading menu&hellip;'));
chk('MenuFragment no bare aria-label="Open menu"', ! str_contains($fragSrc, 'aria-label="Open menu"'));

// Render the fragment in fr with a stub lang() and confirm translated output +
// no leaked raw keys. (The eval'd MenuFragment stub above returns "" — load the
// real class in a subprocess so we exercise the actual html() output.)
$fragProbe = <<<'PHP'
<?php
$loc = $argv[1];
$app = require $argv[2] . "/app/Language/$loc/App.php";
function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
$GLOBALS['__app'] = $app;
function lang(string $k) {
    $p = explode('.', $k); array_shift($p);
    $v = $GLOBALS['__app'];
    foreach ($p as $s) { if (! is_array($v) || ! array_key_exists($s, $v)) { return $k; } $v = $v[$s]; }
    return $v;
}
require $argv[2] . '/app/Modules/Shared/Navigation/MenuFragment.php';
echo \WBS\Shared\Navigation\MenuFragment::html(false);
PHP;
$probeFile = sys_get_temp_dir() . '/wbs_frag_probe.php';
file_put_contents($probeFile, $fragProbe);
$frFrag = (string) shell_exec('php ' . escapeshellarg($probeFile) . ' fr ' . escapeshellarg($root) . ' 2>&1');
$frApp  = require "$langDir/fr/App.php";
chk('fr fragment: loading translated', str_contains($frFrag, (string) $frApp['menuLoading']));
chk('fr fragment: open-menu aria translated', str_contains($frFrag, (string) $frApp['menuOpen']));
chk('fr fragment: no leaked App.menu* key', ! str_contains($frFrag, 'App.menu'));
@unlink($probeFile);

echo "data_page localizes its fallback strings\n";
$dpSrc = (string) file_get_contents($root . '/app/Modules/Shared/Views/data_page.php');
foreach (['dpResult', 'dpError', 'dpServerResponse', 'dpSuccessNoData', 'dpGenericNote'] as $k) {
    chk("data_page references App.$k", str_contains($dpSrc, $k));
}
chk('data_page no bare "no bespoke template" text', ! str_contains($dpSrc, 'this page has no bespoke template'));
chk('data_page no bare "Server response" text', ! str_contains($dpSrc, '>Server response'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
