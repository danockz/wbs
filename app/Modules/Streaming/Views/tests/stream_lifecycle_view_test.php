<?php

declare(strict_types=1);

/**
 * STREAM LIFECYCLE write-UI test.
 *
 * The streams index and the organizer dashboard used to render read-only, so
 * every lifecycle write (create / add-destination / provision / go-live / end /
 * archive) fell through to the generic data-page with no webcsrf. They are now
 * bespoke write surfaces: index carries a "create a stream" form; the dashboard
 * carries the stage-appropriate lifecycle controls. Each control POSTs to a
 * webcsrf-guarded route; the controller PRGs back with a localized flash (JSON
 * preserved for API clients) and the creator identity comes from the session.
 * This test asserts the view controls, the controller PRG + identity + JSON
 * parity, the route webcsrf upgrades, i18n parity for the new lifecycle.* keys,
 * and a headless render smoke across stream states (+ ar RTL).
 *
 *   php app/Modules/Streaming/Views/tests/stream_lifecycle_view_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Streaming/Language';
$viewDir    = $root . '/app/Modules/Streaming/Views';
$ctrlFile   = $root . '/app/Modules/Streaming/Controllers/StreamController.php';
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

// ── 1. i18n parity for lifecycle.* ───────────────────────────────────────────
echo "language parity (lifecycle.*)\n";
$en   = require $langDir . '/en/Streaming.php';
$enLc = $flatten($en['lifecycle']);
$need = ['heading', 'createHeading', 'titleLabel', 'titlePh', 'accessLabel', 'scheduledLabel', 'createBtn',
    'addDestinationLabel', 'providerLabel', 'destinationLabelPh', 'addDestinationBtn', 'provisionBtn',
    'liveBtn', 'liveConfirm', 'endBtn', 'endConfirm', 'archiveLabel', 'archiveUrlPh', 'archiveBtn',
    'createdFlash', 'destinationFlash', 'provisionedFlash', 'liveFlash', 'endedFlash', 'archivedFlash'];
foreach ($need as $k) {
    chk("en lifecycle.$k present", in_array($k, $enLc, true));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l    = require $langDir . "/$loc/Streaming.php";
    $flat = $flatten($l['lifecycle'] ?? []);
    chk("$loc mirrors all en lifecycle keys", array_diff($enLc, $flat) === [],
        'missing: ' . implode(',', array_diff($enLc, $flat)));
    chk("$loc no stray lifecycle keys", array_diff($flat, $enLc) === [],
        'extra: ' . implode(',', array_diff($flat, $enLc)));
}

// ── 2. index create form ─────────────────────────────────────────────────────
echo "index.php exposes a create form\n";
$iv = (string) file_get_contents("$viewDir/index.php");
chk('create form posts to /streams', str_contains($iv, "base_url('streams')"));
chk('create form has a title field (required)', str_contains($iv, 'name="title"') && str_contains($iv, 'required'));
chk('create form has access select', str_contains($iv, 'name="access_policy"'));
chk('create form has scheduled_at', str_contains($iv, 'name="scheduled_at"'));
chk('create form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $iv));
chk('index renders PRG flash', str_contains($iv, "session('success')") && str_contains($iv, "session('error')"));

// ── 3. dashboard lifecycle controls ──────────────────────────────────────────
echo "dashboard.php exposes lifecycle controls\n";
$dv = (string) file_get_contents("$viewDir/dashboard.php");
chk('add-destination form posts to /destinations', str_contains($dv, "'/destinations'"));
chk('provision form posts to /provision', str_contains($dv, "'/provision'"));
chk('go-live form posts to /live', str_contains($dv, "'/live'"));
chk('end form posts to /end', str_contains($dv, "'/end'"));
chk('archive form posts to /archive', str_contains($dv, "'/archive'"));
chk('go-live asks confirm()', str_contains($dv, 'liveConfirm'));
chk('end asks confirm()', str_contains($dv, 'endConfirm'));
chk('go-live only for draft/scheduled', (bool) preg_match("/in_array\(\\\$status, \['draft', 'scheduled'\], true\).*?\/live/s", $dv));
chk('end only for live', (bool) preg_match("/\\\$status === 'live'.*?\/end/s", $dv));
chk('archive only for ended', (bool) preg_match("/\\\$status === 'ended'.*?\/archive/s", $dv));
chk('provision only when pending destinations', str_contains($dv, '$hasPending'));
chk('archive form has external_url + access', str_contains($dv, 'name="external_url"') && str_contains($dv, 'name="access_policy"'));
chk('dashboard forms carry _csrf', substr_count($dv, 'name="_csrf" value="<?= esc($csrf') >= 4);
chk('dashboard renders PRG flash', str_contains($dv, "session('success')") && str_contains($dv, "session('error')"));
chk('no spoofable created_by field in forms', ! str_contains($dv, 'name="created_by"'));

// ── 4. controller PRG + identity + JSON parity ───────────────────────────────
echo "controller: PRG + session identity + JSON parity\n";
$ctrl = (string) file_get_contents($ctrlFile);
chk('has respondStreamDecision PRG helper', str_contains($ctrl, 'private function respondStreamDecision'));
chk('index passes csrf', (bool) preg_match('/function index\(\).*?wbsCsrf/s', $ctrl));
chk('dashboard passes csrf + streamId', (bool) preg_match('/function dashboard\(.*?wbsCsrf/s', $ctrl) && str_contains($ctrl, "'streamId' => \$streamId"));
chk('create uses session creator (currentUserId)', str_contains($ctrl, "currentUserId('created_by')"));
chk('create PRGs with createdFlash to /streams', (bool) preg_match("/function create\(.*?createdFlash/s", $ctrl));
chk('addDestination PRGs with destinationFlash', (bool) preg_match('/function addDestination\(.*?destinationFlash/s', $ctrl));
chk('provision PRGs with provisionedFlash', (bool) preg_match('/function provision\(.*?provisionedFlash/s', $ctrl));
chk('goLive PRGs with liveFlash', (bool) preg_match('/function goLive\(.*?liveFlash/s', $ctrl));
chk('end PRGs with endedFlash', (bool) preg_match('/function end\(.*?endedFlash/s', $ctrl));
chk('archive PRGs with archivedFlash', (bool) preg_match('/function archive\(.*?archivedFlash/s', $ctrl));
chk('PRG redirects to dashboard path', str_contains($ctrl, 'dashboardPath') && str_contains($ctrl, 'rawurlencode($streamId)'));
chk('PRG keeps JSON for API clients', (bool) preg_match('/respondStreamDecision.*?if \(\$this->wantsJson\(\)\)/s', $ctrl));

// ── 5. routes webcsrf ────────────────────────────────────────────────────────
echo "routes: webcsrf-guarded lifecycle writes\n";
$routes = (string) file_get_contents($routesFile);
foreach ([
    'StreamController::create',
    'StreamController::addDestination/$1',
    'StreamController::provision/$1',
    'StreamController::goLive/$1',
    'StreamController::end/$1',
    'StreamController::archive/$1',
] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
        chk("$needle keeps stream.create", str_contains($mm[0], 'authorize:stream.create'));
    } else {
        chk("$needle route present", false);
    }
}

// ── 6. headless render smoke ─────────────────────────────────────────────────
echo "render smoke — index + dashboard across states (fr + ar)\n";
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Streaming') { return $key; }
        $v = $GLOBALS['__sLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return '/' . ltrim((string) $p, '/'); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__sFlash'][$k] ?? null; }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__sLang'] = require $langDir . "/$loc/Streaming.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data) {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__sFlash'] = [];
// index — create form present + flash
$ih = $render("$viewDir/index.php", ['result' => ['streams' => []], 'csrf' => 'SX'], 'fr');
chk('index smoke: create form present', str_contains($ih, 'action="/streams"') && str_contains($ih, 'name="title"'));
chk('index smoke: create heading localized (fr)', str_contains($ih, 'Créer une diffusion'));
chk('index smoke: csrf present', str_contains($ih, 'name="_csrf" value="SX"'));

$GLOBALS['__sFlash'] = ['success' => 'Diffusion créée.'];
$ihf = $render("$viewDir/index.php", ['result' => ['streams' => []], 'csrf' => 'SX'], 'fr');
chk('index smoke: success flash rendered', str_contains($ihf, 'Diffusion créée.'));
$GLOBALS['__sFlash'] = [];

// dashboard — draft (go-live + add dest, no end/archive)
$mk = static fn (string $status, array $dests = []): array => [
    'result' => [
        'stream'       => ['id' => 'st1', 'title' => 'Sunday', 'status' => $status, 'access_policy' => 'public'],
        'destinations' => $dests,
        'engagement'   => [],
        'provider_metrics' => [],
    ],
    'streamId' => 'st1',
    'csrf'     => 'SX',
];
$dDraft = $render("$viewDir/dashboard.php", $mk('draft', [['provider' => 'youtube', 'status' => 'pending']]), 'fr');
chk('dash smoke(draft): add-destination form', str_contains($dDraft, 'action="/streams/st1/destinations"'));
chk('dash smoke(draft): provision form (pending exists)', str_contains($dDraft, 'action="/streams/st1/provision"'));
chk('dash smoke(draft): go-live form', str_contains($dDraft, 'action="/streams/st1/live"'));
chk('dash smoke(draft): NO end form', ! str_contains($dDraft, 'action="/streams/st1/end"'));
chk('dash smoke(draft): NO archive form', ! str_contains($dDraft, 'action="/streams/st1/archive"'));
chk('dash smoke(draft): go-live localized (fr)', str_contains($dDraft, 'Passer en direct'));

// dashboard — draft with NO pending destinations: no provision button
$dDraftNP = $render("$viewDir/dashboard.php", $mk('draft', [['provider' => 'youtube', 'status' => 'ready']]), 'fr');
chk('dash smoke(draft,no-pending): NO provision form', ! str_contains($dDraftNP, 'action="/streams/st1/provision"'));

// dashboard — live (end only, no go-live/archive)
$dLive = $render("$viewDir/dashboard.php", $mk('live'), 'fr');
chk('dash smoke(live): end form', str_contains($dLive, 'action="/streams/st1/end"'));
chk('dash smoke(live): NO go-live form', ! str_contains($dLive, 'action="/streams/st1/live"'));
chk('dash smoke(live): add-destination still allowed', str_contains($dLive, 'action="/streams/st1/destinations"'));

// dashboard — ended (archive only)
$dEnded = $render("$viewDir/dashboard.php", $mk('ended'), 'fr');
chk('dash smoke(ended): archive form', str_contains($dEnded, 'action="/streams/st1/archive"'));
chk('dash smoke(ended): NO end/go-live/add-dest', ! str_contains($dEnded, 'action="/streams/st1/end"') && ! str_contains($dEnded, 'action="/streams/st1/live"') && ! str_contains($dEnded, 'action="/streams/st1/destinations"'));
chk('dash smoke(ended): archive url field', str_contains($dEnded, 'name="external_url"'));

// dashboard flash + csrf count
chk('dash smoke: csrf on every form', substr_count($dDraft, 'name="_csrf" value="SX"') >= 3);
$GLOBALS['__sFlash'] = ['error' => 'Échec.'];
$dErr = $render("$viewDir/dashboard.php", $mk('live'), 'fr');
chk('dash smoke: error flash rendered', str_contains($dErr, 'Échec.'));
$GLOBALS['__sFlash'] = [];

// ar RTL smoke
$dAr = $render("$viewDir/dashboard.php", $mk('live'), 'ar');
chk('dash smoke(ar): end button localized', str_contains($dAr, lang('Streaming.lifecycle.endBtn')));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
