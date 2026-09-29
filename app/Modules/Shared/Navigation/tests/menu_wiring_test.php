<?php

declare(strict_types=1);

/**
 * Wiring test: the dynamic menu is actually mounted in the shared layout and
 * delivered by the zero-burden client renderer — and does so within the platform
 * CSP (no inline <script>, same-origin fetch).
 *
 * Source-level assertions only (no framework/browser runtime in the sandbox).
 *
 *   php app/Modules/Shared/Navigation/tests/menu_wiring_test.php
 */

$root   = dirname(__DIR__, 5);            // tests->Navigation->Shared->Modules->app->ROOT
$layout = (string) @file_get_contents($root . '/app/Views/layouts/app.php');
$universal = (string) @file_get_contents($root . '/app/Views/layouts/universal.php');
$js     = (string) @file_get_contents($root . '/public/assets/js/menu.js');

require_once $root . '/app/Modules/Shared/Navigation/MenuFragment.php';
$fragment = \WBS\Shared\Navigation\MenuFragment::html();

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "menu wiring\n";

chk('layout view exists', $layout !== '');
chk('universal layout exists', $universal !== '');
chk('client renderer exists', $js !== '');

// -- SINGLE LOCATION: app layout delegates to the universal layout; the shell menu
//    still comes from MenuFragment (used by universal and by injection filter). --
chk('compat layout delegates to universal', str_contains($layout, "include __DIR__ . '/universal.php'"));
chk('universal layout composes shell close partial', str_contains($universal, 'shell/close.php'));
chk('layout no longer has a bespoke inline sidebar (.shell)', ! str_contains($layout, 'class="shell"'));
chk('layout does not hardcode its own #wbs-menu mount', ! str_contains($layout, 'id="wbs-menu"'));

// -- The canonical fragment carries the mount, endpoint, drawer + launcher --
chk('fragment has the #wbs-menu mount node', str_contains($fragment, 'id="wbs-menu"'));
chk('fragment mount declares the /me/menu endpoint', str_contains($fragment, 'data-endpoint="/me/menu"'));
chk('fragment loads the external renderer', str_contains($fragment, 'src="/assets/js/menu.js"'));
chk('fragment uses the single drawer location (wbs-menu--drawer)', str_contains($fragment, 'wbs-menu--drawer'));
chk('fragment has the fixed launcher', str_contains($fragment, 'wbs-menu-fab'));

// -- Responsive: pinned-open sidebar on desktop, collapsible drawer on mobile,
//    still ONE component. --
$style = \WBS\Shared\Navigation\MenuFragment::style();
chk('responsive: has a desktop media query', str_contains($style, '@media (min-width:1024px)'));
chk('responsive: desktop docks the drawer (page inset)', str_contains($style, 'padding-left:260px'));
chk('responsive: desktop hides the launcher/backdrop', str_contains($style, '.wbs-menu-fab,.wbs-menu-backdrop{display:none}'));

// -- Static nav removed (replaced by the dynamic one) --
chk('old hardcoded /courses nav link removed', ! str_contains($layout, '<a href="/courses">Courses</a>'));
chk('old hardcoded /reports nav link removed', ! str_contains($layout, '/reports/funnel'));

// -- CSP safety: NO inline script body; the only <script> is a same-origin src. --
$scriptTags = preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $fragment, $m);
chk('exactly one <script> tag in the fragment', $scriptTags === 1);
chk('the <script> has NO inline body (CSP script-src \'self\')',
    $scriptTags === 1 && trim($m[1][0]) === '');
chk('menu inline styles present (CSP style-src allows unsafe-inline)',
    str_contains($fragment, '.wbs-menu__link'));

// -- Renderer behaviour: conditional GET + signed word + same-origin --
chk('renderer sends If-None-Match (304 revalidation)', str_contains($js, 'If-None-Match'));
chk('renderer echoes the signed word header', str_contains($js, 'X-Menu-Word'));
chk('renderer handles 304 (no re-render)', str_contains($js, '304'));
chk('renderer fetches same-origin', str_contains($js, "credentials: 'same-origin'"));
chk('renderer paints from localStorage cache first', str_contains($js, 'localStorage'));

// -- Renderer consumes the SAME tree shape MenuService::render() emits --
chk('renderer reads tree.categories', str_contains($js, '.categories'));
chk('renderer reads item.route', str_contains($js, 'it.route'));
chk('renderer sets aria-current for the active item', str_contains($js, 'aria-current'));
chk('renderer uses textContent for labels (XSS-safe)', str_contains($js, 'textContent'));
chk('renderer has NO eval', ! str_contains($js, 'eval('));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
