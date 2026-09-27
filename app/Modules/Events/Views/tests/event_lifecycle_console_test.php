<?php

declare(strict_types=1);

/**
 * EVENT LIFECYCLE controls — the write face of EventController::publish +
 * complete, surfaced on the event show page.
 *
 * The event show page (GET events/{id}) now renders publish (draft only) and
 * complete (published only) PRG forms when a webcsrf token is present. The
 * publish/complete POSTs — previously ungated — are now authorize:event.create +
 * webcsrf. API clients still get JSON. This test covers key parity, the show
 * view's status-gated controls, PRG wiring, and route guards.
 *
 *   php app/Modules/Events/Views/tests/event_lifecycle_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
$routes = file_get_contents($root . '/app/Config/Routes.php');

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

echo "language parity (Events.lifecycle.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['lifecycle'] ?? []);
chk('en defines Events.lifecycle.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['lifecycle'] ?? []));
    chk("$loc lifecycle.* parity", $miss === [], implode(',', $miss));
}

// ---- render the show view in a layout-less harness (mirrors events_i18n) -----
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') {
            return $key;
        }
        $v = $GLOBALS['__lcLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$GLOBALS['__lcLang'] = require $langDir . '/en/Events.php';

$renderer = new class {
    function extend($x) { return ''; }
    function section($x) { return ''; }
    function endSection() { return ''; }
    function render(string $file, array $data): string
    {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};
$show = "$viewDir/show.php";

echo "\nview: draft event (publish available)\n";
$h = $renderer->render($show, ['result' => ['id' => 'ev-1', 'title' => 'Draft', 'status' => 'draft'], 'csrf' => 'TKN']);
chk('publish form present for draft', str_contains($h, 'action="/events/ev-1/publish"'));
chk('publish form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('no complete form for draft', ! str_contains($h, 'action="/events/ev-1/complete"'));

echo "\nview: published event (complete available)\n";
$h = $renderer->render($show, ['result' => ['id' => 'ev-1', 'title' => 'Pub', 'status' => 'published'], 'csrf' => 'TKN']);
chk('complete form present for published', str_contains($h, 'action="/events/ev-1/complete"'));
chk('complete has confirm()', str_contains($h, 'onsubmit="return confirm('));
chk('no publish form for published', ! str_contains($h, 'action="/events/ev-1/publish"'));

echo "\nview: completed event (no controls) + no-token guard\n";
$h = $renderer->render($show, ['result' => ['id' => 'ev-1', 'title' => 'Done', 'status' => 'completed'], 'csrf' => 'TKN']);
chk('no lifecycle forms once completed', ! str_contains($h, '/events/ev-1/publish') && ! str_contains($h, '/events/ev-1/complete'));
$h = $renderer->render($show, ['result' => ['id' => 'ev-1', 'title' => 'Draft', 'status' => 'draft']]); // no csrf
chk('no controls without a csrf token', ! str_contains($h, 'action="/events/ev-1/publish"'));

echo "\nview: flash banners\n";
// flash uses session() which is undefined in the harness -> guarded, renders no banner but must not fatal
$h = $renderer->render($show, ['result' => ['id' => 'ev-1', 'title' => 'Draft', 'status' => 'draft'], 'csrf' => 'TKN']);
chk('renders without session() defined', str_contains($h, 'Draft'));

echo "\ncontroller wiring\n";
chk('publish uses respondLifecycle', (bool) preg_match('/function publish.*?respondLifecycle/s', $ctrl));
chk('complete uses respondLifecycle', (bool) preg_match('/function complete.*?respondLifecycle/s', $ctrl));
chk('respondLifecycle redirects to event page', str_contains($ctrl, "'/events/' . rawurlencode(\$eventId)"));
chk('respondLifecycle keeps JSON for API', (bool) preg_match('/function respondLifecycle.*?wantsJson\(\)/s', $ctrl));
chk('flash keys used', str_contains($ctrl, 'publishedFlash') && str_contains($ctrl, 'completedFlash'));

echo "\nroutes\n";
chk('publish POST authorize+webcsrf', (bool) preg_match('#\(:segment\)/publish\x27.*?authorize:event.create,any.*?webcsrf#s', $routes));
chk('complete POST authorize+webcsrf', (bool) preg_match('#\(:segment\)/complete\x27.*?authorize:event.create,any.*?webcsrf#s', $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
