<?php

declare(strict_types=1);

/**
 * Universal shell contract + allowlisted capability loading.
 *
 *   php app/Modules/Shared/Views/tests/universal_layout_contract_test.php
 */

$root = dirname(__DIR__, 5);
$compat = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$universal = (string) file_get_contents($root . '/app/Views/layouts/universal.php');
$ctxSrc = (string) file_get_contents($root . '/app/Modules/Shared/Views/shell/context.php');

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "universal layout contract\n";
chk('compat layout delegates to universal', str_contains($compat, "include __DIR__ . '/universal.php'"));
chk('universal layout composes shell open partial', str_contains($universal, 'shell/open.php'));
chk('universal layout composes shell close partial', str_contains($universal, 'shell/close.php'));
chk('context defines shell-app-js allowlist capability', str_contains($ctxSrc, "'shell-app-js'"));
chk('context gates capability names with strict regex', str_contains($ctxSrc, "/^[a-z0-9._-]+$/"));

if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            public string $wbsCsrf = 'TOK';
            public function getLocale(): string { return 'en'; }
            public function getPath(): string { return '/me/dashboard'; }
        };
    }
}
if (! function_exists('config')) {
    function config($x) {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
            public array $supported = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $map = [
            'App.navDashboard' => 'My dashboard',
            'App.navStatus' => 'Status',
            'App.menuOpen' => 'Open menu',
            'App.menuTitle' => 'Menu',
            'App.menuLoading' => 'Loading menu…',
            'App.languageNames' => ['en' => 'English'],
        ];
        return $map[$key] ?? $key;
    }
}

if (! class_exists('WBS\\Shared\\Navigation\\MenuFragment')) {
    eval('namespace WBS\\Shared\\Navigation; class MenuFragment { public static function html(bool $withStyle = true): string { return "<nav id=\"wbs-menu\"></nav>"; } }');
}

$render = static function (array $caps): string use ($root) {
    $layout = $root . '/app/Views/layouts/universal.php';
    $host = new class {
        public function renderSection($x): string { return '<main>content</main>'; }
    };
    $bound = Closure::bind(function () use ($layout, $caps) {
        $GLOBALS['wbsShellCtx'] = [];
        $wbsPageCapabilities = $caps;
        $wbsShellWrapContent = true;
        $wbsShellVariant = 'public';
        ob_start();
        include $layout;
        return (string) ob_get_clean();
    }, $host, $host);
    return $bound();
};

$safe = $render(['calendar', 'shell-app-js', 'javascript:alert(1)', 'https://evil.invalid/a.js']);
chk('render includes global shell app JS via allowlist', str_contains($safe, '/assets/js/app'));
chk('render includes local calendar capability bundle', str_contains($safe, '/assets/js/capabilities/calendar.js'));
chk('render blocks external capability URLs', ! str_contains($safe, 'evil.invalid'));
chk('render blocks javascript: capability payloads', ! str_contains($safe, 'javascript:'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
