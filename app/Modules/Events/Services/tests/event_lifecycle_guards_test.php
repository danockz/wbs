<?php

declare(strict_types=1);

/**
 * EVENT LIFECYCLE-GUARDS wiring test (gaps L1 + G7 + G6).
 *
 * L1 — complete() STATE GUARD: only a published event may be completed; a
 *      draft/cancelled event is a conflict; re-completing is idempotent.
 * G7 — safe CANCEL: guarded transition (draft/published only), reason required,
 *      actor + timestamp recorded, updated_at bumped (ICS SEQUENCE advances).
 * G6 — publish READINESS: pure EventReadiness assessment (blockers/warnings);
 *      publish() refuses when not ready; the event page shows the checklist.
 *
 * Exercises the pure EventReadiness directly, proves the service/controller/route
 * wiring by source inspection, verifies the cancellation migration, asserts
 * i18n parity for the new Events.lifecycle.* + Events.readiness.* keys across all
 * 6 locales, and render-smokes the readiness + cancel controls on show.php.
 *
 *   php app/Modules/Events/Services/tests/event_lifecycle_guards_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

require_once $root . '/app/Modules/Events/Services/EventValidator.php';
require_once $root . '/app/Modules/Events/Services/EventReadiness.php';

use WBS\Events\Services\EventReadiness;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};

$now  = '2026-09-14 00:00:00';
$good = [
    'title' => 'Conf', 'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-10-01 12:00:00',
    'mode' => 'physical', 'capacity' => 100, 'description' => 'x', 'venue_id' => 'v1', 'access_url' => '',
];

// ── 1. EventReadiness (pure) — blockers ──────────────────────────────────────
echo "EventReadiness — blockers\n";
$r = EventReadiness::assess($good, $now);
chk('a coherent future event is ready', $r['ready'] === true && $r['blockers'] === []);
chk('past start is a blocker', in_array('Events.readiness.blockPast', EventReadiness::assess(['title' => 'x', 'starts_at' => '2020-01-01 00:00:00'], $now)['blockers'], true));
chk('missing title is a blocker', in_array('Events.readiness.blockTitle', EventReadiness::assess(['starts_at' => '2026-10-01 10:00:00'], $now)['blockers'], true));
chk('missing start is a blocker', in_array('Events.readiness.blockStart', EventReadiness::assess(['title' => 'x'], $now)['blockers'], true));
chk('ends before starts is a blocker', in_array('Events.readiness.blockTimeRange', EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-10-01 09:00:00'], $now)['blockers'], true));
chk('online with no access_url is a blocker', in_array('Events.readiness.blockAccessUrl', EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'mode' => 'online'], $now)['blockers'], true));
chk('online WITH access_url clears that blocker', ! in_array('Events.readiness.blockAccessUrl', EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'mode' => 'online', 'access_url' => 'https://x'], $now)['blockers'], true));
chk('not ready when a blocker exists', EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'mode' => 'online'], $now)['ready'] === false);

echo "EventReadiness — warnings (non-blocking)\n";
$w = EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'mode' => 'physical'], $now);
chk('no description warns', in_array('Events.readiness.warnDescription', $w['warnings'], true));
chk('no capacity warns', in_array('Events.readiness.warnCapacity', $w['warnings'], true));
chk('no end time warns', in_array('Events.readiness.warnEndTime', $w['warnings'], true));
chk('physical + no venue warns', in_array('Events.readiness.warnVenue', $w['warnings'], true));
chk('warnings never block readiness', $w['ready'] === true);
chk('a fully-specified event has no warnings', EventReadiness::assess($good, $now)['warnings'] === []);
chk('online event does not warn about venue', ! in_array('Events.readiness.warnVenue', EventReadiness::assess(['title' => 'x', 'starts_at' => '2026-10-01 10:00:00', 'mode' => 'online', 'access_url' => 'https://x', 'description' => 'y', 'capacity' => 5, 'ends_at' => '2026-10-01 11:00:00'], $now)['warnings'], true));
chk('readiness messages are stable Events.readiness.* keys', str_starts_with($w['warnings'][0] ?? '', 'Events.readiness.'));

// ── 2. EventService source: the three guards ─────────────────────────────────
echo "EventService — publish/cancel/complete guards\n";
$svc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
chk('readiness() read-only report exists', (bool) preg_match('/function readiness\(string \$eventId\)/', $svc));
chk('publish() gates on EventReadiness::assess', (bool) preg_match('/function publish\(.*?EventReadiness::assess/s', $svc));
chk('publish() fails NOT_READY when not ready', (bool) preg_match('/function publish\(.*?NOT_READY/s', $svc));
chk('publish() still enforces draft-only', (bool) preg_match("/function publish\\(.*?status'\\] !== 'draft'/s", $svc));

chk('cancel() takes a reason + cancelledBy', (bool) preg_match('/function cancel\(string \$eventId, string \$reason = .*?\?string \$cancelledBy/s', $svc));
chk('cancel() guards to draft/published only', (bool) preg_match("/function cancel\\(.*?in_array\\(\\(string\\) \\\$e\\['status'\\], \\['draft', 'published'\\]/s", $svc));
chk('cancel() requires a reason', (bool) preg_match('/function cancel\(.*?REASON_REQUIRED/s', $svc));
chk('cancel() records reason + cancelled_at + cancelled_by', (bool) preg_match("/function cancel\\(.*?'cancellation_reason'.*?'cancelled_at'.*?'cancelled_by'/s", $svc));
chk('cancel() bumps updated_at (ICS SEQUENCE)', (bool) preg_match("/function cancel\\(.*?'updated_at'\\s*=>\\s*\\\$now/s", $svc));
chk('cancel() emits event.cancelled (E-B1, for MT5)', (bool) preg_match("/function cancel\\(.*?outbox\\?->stage\\('event', \\\$eventId, 'event\\.cancelled'/s", $svc));

chk('complete() only completes a published event', (bool) preg_match("/function complete\\(.*?status !== 'published'.*?BAD_STATE/s", $svc));
chk('complete() is idempotent on already-completed', (bool) preg_match("/function complete\\(.*?'completed' \\|\\| \\\$status === 'completed_no_attendance'.*?deduplicated/s", $svc));
chk('complete() still records completed_no_attendance for zero attendance', str_contains($svc, 'completed_no_attendance'));

// ── 3. Controller: cancel action + readiness on show ─────────────────────────
echo "controller: cancel() + readiness wiring\n";
$ctrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
chk('cancel() action exists', (bool) preg_match('/function cancel\(string \$eventId/', $ctrl));
chk('cancel() reads a reason from input', (bool) preg_match("/function cancel\\(.*?input\\(\\)\\['reason'\\]/s", $ctrl));
chk('cancel() passes the actor id', (bool) preg_match('/function cancel\(.*?events\(\)->cancel\(.*?actorId\(\)/s', $ctrl));
chk('cancel() flows through respondLifecycle with cancelledFlash', (bool) preg_match("/function cancel\\(.*?respondLifecycle\\(.*?'cancelledFlash'/s", $ctrl));
chk('respondLifecycle translates error via humanizeError', (bool) preg_match('/function respondLifecycle\(.*?humanizeError\(/s', $ctrl));
chk('show() computes readiness for a draft', (bool) preg_match("/function show\\(.*?'draft'.*?events\\(\\)->readiness\\(/s", $ctrl));
chk('show() passes readiness to the view', (bool) preg_match("/function show\\(.*?'readiness'/s", $ctrl));

// ── 4. Routes: cancel guarded + webcsrf ──────────────────────────────────────
echo "routes: events/{id}/cancel\n";
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
chk('POST (:segment)/cancel → cancel, gated + webcsrf', (bool) preg_match("#post\\('\\(:segment\\)/cancel'.*?EventController::cancel.*?authorize:event\\.create,any.*?webcsrf#s", $routes));

// ── 5. Migration: cancellation audit columns ─────────────────────────────────
echo "migration: event cancellation columns\n";
$migs = glob($root . '/app/Modules/Events/Database/Migrations/*AddEventCancellation.php');
chk('AddEventCancellation migration exists', $migs !== []);
$mig = $migs !== [] ? (string) file_get_contents($migs[0]) : '';
chk('adds cancellation_reason', str_contains($mig, 'cancellation_reason'));
chk('adds cancelled_at', str_contains($mig, 'cancelled_at'));
chk('adds cancelled_by', str_contains($mig, 'cancelled_by'));
chk('has a reversible down()', str_contains($mig, 'DROP COLUMN cancellation_reason'));

// ── 6. i18n parity: lifecycle + readiness across 6 locales ───────────────────
echo "i18n parity (lifecycle + readiness, all locales)\n";
$en   = require $langDir . '/en/Events.php';
$enL  = $flat($en['lifecycle'] ?? []);
$enR  = $flat($en['readiness'] ?? []);
chk('en has readiness block', $enR !== []);
chk('en lifecycle has new cancel + err keys', in_array('cancelledFlash', $enL, true) && in_array('errNotReady', $enL, true) && in_array('cancelHeading', $enL, true));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $langDir . "/$loc/Events.php";
    foreach (['lifecycle' => $enL, 'readiness' => $enR] as $block => $enKeys) {
        $keys = $flat($arr[$block] ?? []);
        chk("$loc mirrors $block keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
            'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

// ── 7. show.php render smoke: readiness checklist + cancel control ────────────
echo "show.php render smoke (readiness + cancel)\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') {
            return $key;
        }
        $v = $GLOBALS['__lgLang'];
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
$GLOBALS['__lgLang'] = require $langDir . '/en/Events.php';
$renderer = new class {
    public function extend($x) { return ''; }
    public function section($x) { return ''; }
    public function endSection() { return ''; }
    public function render(string $file, array $data): string
    {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};
$show = "$viewDir/show.php";

// Draft with blockers + warnings → checklist renders; cancel control present.
$h = $renderer->render($show, [
    'result'    => ['id' => 'ev-9', 'title' => 'Draft', 'status' => 'draft', 'mode' => 'online'],
    'csrf'      => 'TKN',
    'readiness' => ['ready' => false, 'blockers' => ['Events.readiness.blockAccessUrl'], 'warnings' => ['Events.readiness.warnDescription']],
]);
chk('draft renders readiness heading', str_contains($h, 'Publish readiness'));
chk('draft shows a translated blocker', str_contains($h, 'An online or hybrid event needs an access URL.'));
chk('draft shows a translated warning', str_contains($h, 'No description yet.'));
chk('draft shows the cancel form', str_contains($h, 'action="/events/ev-9/cancel"'));
chk('cancel form requires a reason', (bool) preg_match('/name="reason" required/', $h));
chk('cancel form has confirm()', str_contains($h, 'Events.lifecycle.cancelConfirm') === false && str_contains($h, 'onsubmit="return confirm('));

// Published → cancel present, no readiness checklist (readiness is draft-only).
$h2 = $renderer->render($show, [
    'result'    => ['id' => 'ev-9', 'title' => 'Pub', 'status' => 'published'],
    'csrf'      => 'TKN',
    'readiness' => null,
]);
chk('published shows cancel form', str_contains($h2, 'action="/events/ev-9/cancel"'));
chk('published shows complete form', str_contains($h2, 'action="/events/ev-9/complete"'));
chk('published shows no readiness checklist', ! str_contains($h2, 'Publish readiness'));

// Completed → no lifecycle/cancel controls at all.
$h3 = $renderer->render($show, [
    'result'    => ['id' => 'ev-9', 'title' => 'Done', 'status' => 'completed'],
    'csrf'      => 'TKN',
    'readiness' => null,
]);
chk('completed shows no cancel form', ! str_contains($h3, '/events/ev-9/cancel'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
