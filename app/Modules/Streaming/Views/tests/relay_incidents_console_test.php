<?php

declare(strict_types=1);

/**
 * RELAY INCIDENT operator-console write-UI test.
 *
 * GET /streams/{id}/relay/incidents previously listed incidents read-only (its
 * writes — report / acknowledge / bypass / resolve — fell through to the generic
 * page with no webcsrf and a spoofable actor_id). It is now a bespoke operator
 * console: a "report a failure" form plus, per OPEN/ACKNOWLEDGED incident, the
 * stage-appropriate controls (acknowledge only when open; activate bypass with a
 * destination select; resolve with a note). Each control POSTs to a webcsrf-
 * guarded route and PRGs back with a localized flash; the operator identity comes
 * from the session. This test asserts the view controls, the controller PRG +
 * identity + JSON parity, the route webcsrf upgrades, i18n parity for the new
 * keys, and a headless render smoke (fr across incident states + ar RTL).
 *
 *   php app/Modules/Streaming/Views/tests/relay_incidents_console_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Streaming/Language';
$viewDir    = $root . '/app/Modules/Streaming/Views';
$ctrlFile   = $root . '/app/Modules/Streaming/Controllers/StreamRelayController.php';
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

// ── 1. i18n parity for the new relayIncidents.* console keys ──────────────────
echo "language parity (relayIncidents console keys)\n";
$en   = require $langDir . '/en/Streaming.php';
$enRI = $flatten($en['relayIncidents']);
$need = ['reportHeading', 'severityLabel', 'causeLabel', 'causePh', 'reportBtn', 'acknowledgeBtn', 'bypassBtn',
    'bypassConfirm', 'destinationLabel', 'destinationNone', 'resolveBtn', 'resolveConfirm', 'notePh',
    'reportedFlash', 'acknowledgedFlash', 'bypassFlash', 'resolvedFlash',
    'severity.critical', 'severity.warning', 'statusLabel.open', 'statusLabel.acknowledged', 'statusLabel.resolved'];
foreach ($need as $k) {
    chk("en relayIncidents.$k present", in_array($k, $enRI, true));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l    = require $langDir . "/$loc/Streaming.php";
    $flat = $flatten($l['relayIncidents'] ?? []);
    chk("$loc mirrors all en relayIncidents keys", array_diff($enRI, $flat) === [],
        'missing: ' . implode(',', array_diff($enRI, $flat)));
    chk("$loc no stray relayIncidents keys", array_diff($flat, $enRI) === [],
        'extra: ' . implode(',', array_diff($flat, $enRI)));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "relay_incidents.php exposes console controls\n";
$src = (string) file_get_contents("$viewDir/relay_incidents.php");
chk('extends layouts/app', str_contains($src, "extend('layouts/app')"));
chk('report form posts to /relay/report', str_contains($src, "'streams/' . \$sidAttr . '/relay/report'"));
chk('acknowledge form posts to /stream-incidents/…/acknowledge', str_contains($src, "'stream-incidents/' . \$iidAttr . '/acknowledge'"));
chk('bypass form posts to /stream-incidents/…/bypass', str_contains($src, "'stream-incidents/' . \$iidAttr . '/bypass'"));
chk('resolve form posts to /stream-incidents/…/resolve', str_contains($src, "'stream-incidents/' . \$iidAttr . '/resolve'"));
chk('acknowledge only for open incidents', (bool) preg_match("/\\\$status === 'open'.*?acknowledge/s", $src));
chk('bypass asks confirm()', str_contains($src, 'bypassConfirm'));
chk('resolve asks confirm()', str_contains($src, 'resolveConfirm'));
chk('bypass offers destination select', str_contains($src, 'name="destination_id"'));
chk('resolve carries a note field', str_contains($src, 'name="note"'));
chk('every write form carries _csrf bound to $csrf', substr_count($src, 'name="_csrf" value="<?= esc($csrf') >= 4);
chk('forms carry stream_id for PRG redirect', str_contains($src, 'name="stream_id"'));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('no spoofable actor_id field in forms', ! str_contains($src, 'name="actor_id"'));
chk('uses lang(Streaming.relayIncidents.*)', str_contains($src, "lang('Streaming.relayIncidents."));

// ── 3. Controller PRG + identity + JSON parity ───────────────────────────────
echo "controller: PRG + session identity + JSON parity\n";
$ctrl = (string) file_get_contents($ctrlFile);
chk('has respondRelayDecision PRG helper', str_contains($ctrl, 'private function respondRelayDecision'));
chk('incidents() renders bespoke view + csrf', str_contains($ctrl, 'WBS\Streaming\Views\relay_incidents') && str_contains($ctrl, 'wbsCsrf'));
chk('incidents() passes bypass destinations', str_contains($ctrl, "'destinations'") && str_contains($ctrl, "bypass_procedure']['candidate_destinations']"));
chk('incidents() keeps JSON for API', (bool) preg_match('/function incidents\(.*?if \(\$this->wantsJson\(\)\)/s', $ctrl));
chk('report PRGs with reportedFlash', (bool) preg_match('/function report\(.*?reportedFlash/s', $ctrl));
chk('acknowledge PRGs with acknowledgedFlash', (bool) preg_match('/function acknowledge\(.*?acknowledgedFlash/s', $ctrl));
chk('bypass PRGs with bypassFlash', (bool) preg_match('/function bypass\(.*?bypassFlash/s', $ctrl));
chk('resolve PRGs with resolvedFlash', (bool) preg_match('/function resolve\(.*?resolvedFlash/s', $ctrl));
chk('operator identity from session (currentUserId)', substr_count($ctrl, "currentUserId('actor_id')") >= 4);
chk('PRG redirects back to stream incidents console', str_contains($ctrl, "/relay/incidents'") && str_contains($ctrl, 'rawurlencode($streamId)'));
chk('PRG keeps JSON for API clients', (bool) preg_match('/respondRelayDecision.*?if \(\$this->wantsJson\(\)\)/s', $ctrl));

// ── 4. Routes: webcsrf-guarded writes ────────────────────────────────────────
echo "routes: webcsrf-guarded relay writes\n";
$routes = (string) file_get_contents($routesFile);
foreach ([
    'StreamRelayController::report/$1',
    'StreamRelayController::acknowledge/$1',
    'StreamRelayController::bypass/$1',
    'StreamRelayController::resolve/$1',
] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
        chk("$needle keeps stream.moderate", str_contains($mm[0], 'authorize:stream.moderate'));
    } else {
        chk("$needle route present", false);
    }
}
// heartbeat stays a trusted server-to-server signal (no webcsrf — not a browser form)
if (preg_match('#^.*StreamRelayController::heartbeat/\$1.*$#m', $routes, $hm)) {
    chk('heartbeat NOT webcsrf-guarded (server signal)', ! str_contains($hm[0], 'webcsrf'));
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — relay console (fr states + ar RTL)\n";
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
$data = [
    'streamId'     => 'stream-9',
    'csrf'         => 'RC',
    'destinations' => [
        ['id' => 'd1', 'label' => 'Primary YouTube', 'provider' => 'youtube', 'status' => 'ready'],
        ['id' => 'd2', 'label' => '', 'provider' => 'facebook', 'status' => 'error'],
    ],
    'incidents' => [
        ['id' => 'i-open', 'severity' => 'critical', 'status' => 'open', 'cause' => 'Relay fan-out down', 'detected_at' => '2026-09-12 10:00'],
        ['id' => 'i-ack', 'severity' => 'warning', 'status' => 'acknowledged', 'cause' => 'High latency', 'detected_at' => '2026-09-12 09:00'],
        ['id' => 'i-res', 'severity' => 'critical', 'status' => 'resolved', 'cause' => 'Old', 'detected_at' => '2026-09-11 08:00', 'resolved_at' => '2026-09-11 09:00', 'resolution_note' => 'Fixed upstream', 'bypass_activated' => 1],
    ],
];
$h = $render("$viewDir/relay_incidents.php", $data, 'fr');
chk('smoke: report form present', str_contains($h, 'action="/streams/stream-9/relay/report"'));
chk('smoke: open incident shows acknowledge', str_contains($h, 'action="/stream-incidents/i-open/acknowledge"'));
chk('smoke: open incident shows bypass + resolve', str_contains($h, 'action="/stream-incidents/i-open/bypass"') && str_contains($h, 'action="/stream-incidents/i-open/resolve"'));
chk('smoke: ack incident has NO acknowledge (already ack)', ! str_contains($h, 'action="/stream-incidents/i-ack/acknowledge"'));
chk('smoke: ack incident still shows bypass + resolve', str_contains($h, 'action="/stream-incidents/i-ack/bypass"') && str_contains($h, 'action="/stream-incidents/i-ack/resolve"'));
chk('smoke: resolved incident has NO action forms', ! str_contains($h, 'action="/stream-incidents/i-res/'));
chk('smoke: bypass destination options rendered', str_contains($h, 'value="d1"') && str_contains($h, 'Primary YouTube'));
chk('smoke: severity localized (fr Critique)', str_contains($h, 'Critique'));
chk('smoke: status localized (fr Ouvert/Accusé)', str_contains($h, 'Ouvert') && str_contains($h, 'Accusé de réception'));
chk('smoke: count interpolated (fr)', str_contains($h, '3 incidents'));
chk('smoke: forms carry csrf RC', substr_count($h, 'name="_csrf" value="RC"') >= 4);
chk('smoke: forms carry stream_id', str_contains($h, 'name="stream_id" value="stream-9"'));
chk('smoke: resolved note shown', str_contains($h, 'Fixed upstream'));

$GLOBALS['__sFlash'] = ['success' => 'Incident résolu.'];
$hf = $render("$viewDir/relay_incidents.php", ['streamId' => 's', 'csrf' => 'RC', 'destinations' => [], 'incidents' => []], 'fr');
chk('smoke: success flash rendered', str_contains($hf, 'Incident résolu.'));
chk('smoke: empty state shown', str_contains($hf, lang('Streaming.relayIncidents.empty')));
chk('smoke: report form present even when empty', str_contains($hf, '/relay/report"'));
$GLOBALS['__sFlash'] = [];

// bypass without candidate destinations: no select, but button still present
$hb = $render("$viewDir/relay_incidents.php", [
    'streamId' => 's', 'csrf' => 'RC', 'destinations' => [],
    'incidents' => [['id' => 'x', 'severity' => 'critical', 'status' => 'open', 'cause' => 'c', 'detected_at' => 't']],
], 'fr');
chk('smoke: bypass button present without destinations', str_contains($hb, 'action="/stream-incidents/x/bypass"'));
chk('smoke: no destination select when none', ! str_contains($hb, 'name="destination_id"'));

// ar RTL
$ha = $render("$viewDir/relay_incidents.php", [
    'streamId' => 's', 'csrf' => 'RC', 'destinations' => [],
    'incidents' => [['id' => 'a', 'severity' => 'critical', 'status' => 'open', 'cause' => 'c', 'detected_at' => 't']],
], 'ar');
chk('smoke(ar): resolve button localized', str_contains($ha, lang('Streaming.relayIncidents.resolveBtn')));
chk('smoke(ar): status open localized', str_contains($ha, 'مفتوح'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
