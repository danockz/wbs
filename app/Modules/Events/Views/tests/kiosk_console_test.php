<?php

declare(strict_types=1);

/**
 * KIOSK console — the write face of KioskController::console and the register /
 * manifest / reconcile / revoke write actions.
 *
 * GET events/{id}/kiosks is now a console: a register form, a read table of the
 * event's kiosks (label / status / manifest version / queued offline scans), and
 * per-kiosk manage forms. The device-facing offline-scan ENQUEUE endpoint stays
 * JSON-only (kiosk app, not browser). This test covers key parity, both view
 * states, PRG wiring, and route guards.
 *
 *   php app/Modules/Events/Views/tests/kiosk_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/KioskController.php');
$svc    = file_get_contents($root . '/app/Modules/Events/Services/KioskService.php');
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

echo "language parity (Events.kiosk.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['kiosk'] ?? []);
chk('en defines Events.kiosk.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['kiosk'] ?? []));
    chk("$loc kiosk.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);
$file   = $viewDir . '/kiosk_console.php';

echo "\nview: empty state\n";
$h = $render($file, ['kiosks' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('register form posts to /events/ev-1/kiosks', str_contains($h, 'action="/events/ev-1/kiosks"'));
chk('register form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('required device_label field', str_contains($h, 'name="device_label" required'));
chk('optional fingerprint field', str_contains($h, 'name="device_fingerprint"'));
chk('empty kiosks hint', str_contains($h, 'No kiosks registered yet.'));

echo "\nview: populated (active + revoked)\n";
$h = $render($file, [
    'kiosks' => [
        ['id' => 'k-1', 'device_label' => 'Front desk', 'status' => 'active', 'manifest_version' => 3, 'queued_scans' => 5],
        ['id' => 'k-2', 'device_label' => 'Side door', 'status' => 'active', 'manifest_version' => 0, 'queued_scans' => 0],
        ['id' => 'k-3', 'device_label' => 'Old tablet', 'status' => 'revoked', 'manifest_version' => 1, 'queued_scans' => 0],
    ],
    'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('renders kiosk labels', str_contains($h, 'Front desk') && str_contains($h, 'Side door'));
chk('active kiosk shows manifest form', str_contains($h, 'action="/kiosks/k-1/manifest"'));
chk('active kiosk shows reconcile form', str_contains($h, 'action="/kiosks/k-1/reconcile"'));
chk('active kiosk shows revoke form', str_contains($h, 'action="/kiosks/k-1/revoke"'));
chk('revoke has confirm()', str_contains($h, 'onsubmit="return confirm('));
chk('reconcile disabled when no queued scans', (bool) preg_match('#/kiosks/k-2/reconcile".*?<button[^>]*disabled#s', $h));
chk('reconcile enabled when queued scans', (bool) preg_match('#/kiosks/k-1/reconcile".*?<button(?![^>]*disabled)[^>]*>#s', $h));
chk('queued count badge shown', str_contains($h, 'badge') && str_contains($h, '>5<'));
chk('manifest version shown', str_contains($h, 'v3'));
chk('revoked kiosk shows no manifest form', ! str_contains($h, 'action="/kiosks/k-3/manifest"'));
chk('revoked kiosk shows no revoke form', ! str_contains($h, 'action="/kiosks/k-3/revoke"'));
chk('status pills rendered', str_contains($h, 'pill active') && str_contains($h, 'pill revoked'));

echo "\nview: escaping + i18n + RTL\n";
$h = $render($file, ['kiosks' => [['id' => 'k-x', 'device_label' => '<i>x</i>', 'status' => 'active', 'manifest_version' => 0, 'queued_scans' => 0]], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('escapes device label', ! str_contains($h, '<i>x</i>') && str_contains($h, '&lt;i&gt;'));
$h = $render($file, ['kiosks' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'fr');
chk('fr localized heading', str_contains($h, 'Bornes'));
$h = $render($file, ['kiosks' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'ar');
chk('ar sets dir=rtl', str_contains($h, 'dir="rtl"'));

echo "\ncontroller wiring\n";
chk('console() action exists', str_contains($ctrl, 'function console('));
chk('console renders kiosk_console view', str_contains($ctrl, 'WBS\Events\Views\kiosk_console'));
chk('console serves JSON for API', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, "'kiosks'"));
chk('register uses respondKiosk', (bool) preg_match('/function register.*?respondKiosk/s', $ctrl));
chk('revoke uses respondKiosk', (bool) preg_match('/function revoke.*?respondKiosk/s', $ctrl));
chk('manifest uses respondKiosk', (bool) preg_match('/function manifest.*?respondKiosk/s', $ctrl));
chk('reconcile uses respondKiosk', (bool) preg_match('/function reconcile.*?respondKiosk/s', $ctrl));
chk('enqueueScans stays raw JSON respondWith', (bool) preg_match('/function enqueueScans.*?respondWith\(EventServices::kiosks\(\)->enqueueOfflineScans/s', $ctrl));
chk('respondKiosk redirects to /kiosks', str_contains($ctrl, "/kiosks'"));
chk('respondKiosk keeps JSON for API', (bool) preg_match('/function respondKiosk.*?wantsJson\(\)/s', $ctrl));
chk('flash keys used', str_contains($ctrl, 'registeredFlash') && str_contains($ctrl, 'manifestFlash') && str_contains($ctrl, 'reconciledFlash') && str_contains($ctrl, 'revokedFlash'));

echo "\nservice wiring\n";
chk('listForEvent() read helper exists', str_contains($svc, 'function listForEvent('));
chk('listForEvent annotates queued_scans', str_contains($svc, "'queued_scans'") && str_contains($svc, 'offline_checkin_queue'));
chk('revokeKiosk result carries event_id', (bool) preg_match("/function revokeKiosk.*?'event_id'\s*=>\s*\\\$kiosk\['event_id'\]/s", $svc));

echo "\nroutes\n";
chk('GET kiosk console route', (bool) preg_match('#get\([^\n]*\(:segment\)/kiosks\x27[^\n]*KioskController::console#', $routes));
chk('POST register webcsrf', (bool) preg_match('#post\(\x27\(:segment\)/kiosks\x27.*?register.*?webcsrf#s', $routes));
chk('revoke POST webcsrf', (bool) preg_match('#\(:segment\)/revoke\x27.*?KioskController::revoke.*?webcsrf#s', $routes));
chk('manifest POST webcsrf', (bool) preg_match('#\(:segment\)/manifest\x27.*?manifest.*?webcsrf#s', $routes));
chk('reconcile POST webcsrf', (bool) preg_match('#\(:segment\)/reconcile\x27.*?KioskController::reconcile.*?webcsrf#s', $routes));
chk('offline-scans stays JSON device API (no webcsrf)', (bool) preg_match('#offline-scans\x27.*?enqueueScans#', $routes) && ! (bool) preg_match('#offline-scans\x27.*?webcsrf#', $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
