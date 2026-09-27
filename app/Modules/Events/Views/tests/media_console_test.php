<?php

declare(strict_types=1);

/**
 * MEDIA console — the write face of MediaController::listForReview plus the
 * register / review write actions.
 *
 * GET events/{id}/media/review is now a console: a register-by-reference form and
 * per-item approve/reject controls layered onto the existing read-only listing.
 * The read-only render path (no $csrf) is unchanged — verified here and by
 * report_views_test.php. The public listing stays JSON. This test covers key
 * parity, both render paths, approve/reject gating, PRG wiring, and route guards.
 *
 *   php app/Modules/Events/Views/tests/media_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/MediaController.php');
$svc    = file_get_contents($root . '/app/Modules/Events/Services/MediaService.php');
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

echo "language parity (Events.mediaReview.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['mediaReview'] ?? []);
chk('en defines Events.mediaReview.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['mediaReview'] ?? []));
    chk("$loc mediaReview.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);
$file   = $viewDir . '/media_review.php';

echo "\nview: read-only render path (no csrf — unchanged behaviour)\n";
$ro = $render($file, ['media' => [
    ['id' => 'm-1', 'caption' => 'Opening', 'mime_type' => 'image/jpeg', 'byte_size' => 2097152, 'object_ref' => 'obj-1', 'scan_state' => 'clean', 'review_state' => 'pending', 'visibility' => 'private'],
]], 'en');
chk('read-only shows listing', str_contains($ro, 'Opening') && str_contains($ro, 'obj-1'));
chk('read-only has NO register form', ! str_contains($ro, 'name="object_ref"'));
chk('read-only has NO review buttons', ! str_contains($ro, '/media/m-1/review'));

echo "\nview: console render path (with csrf)\n";
$h = $render($file, ['media' => [
    ['id' => 'm-1', 'caption' => 'Clean pending', 'mime_type' => 'image/jpeg', 'byte_size' => 1024, 'object_ref' => 'obj-1', 'scan_state' => 'clean', 'review_state' => 'pending', 'visibility' => 'private'],
    ['id' => 'm-2', 'caption' => 'Still scanning', 'mime_type' => 'image/png', 'byte_size' => 2048, 'object_ref' => 'obj-2', 'scan_state' => 'pending', 'review_state' => 'pending', 'visibility' => 'private'],
    ['id' => 'm-3', 'caption' => 'Already rejected', 'mime_type' => 'video/mp4', 'byte_size' => 4096, 'object_ref' => 'obj-3', 'scan_state' => 'clean', 'review_state' => 'rejected', 'visibility' => 'private'],
], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('register form present', str_contains($h, 'action="/events/ev-1/media"'));
chk('register form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('required object_ref field', str_contains($h, 'name="object_ref" required'));
chk('optional caption/alt fields', str_contains($h, 'name="caption"') && str_contains($h, 'name="alt_text"'));
chk('clean item shows approve+reject forms', str_contains($h, 'action="/media/m-1/review"'));
chk('approve carries visibility select', (bool) preg_match('#/media/m-1/review".*?name="visibility"#s', $h));
chk('clean item approve enabled', (bool) preg_match('#value="approve".*?<button[^>]*class="approve"(?![^>]*disabled)#s', $h));
chk('scanning item approve disabled', (bool) preg_match('#m-2/review.*?value="approve".*?<button[^>]*class="approve"[^>]*disabled#s', $h));
chk('scanning item shows blocked note', str_contains($h, 'Approval available once the scan is clean.'));
chk('rejected item shows no review controls', ! str_contains($h, '/media/m-3/review'));
chk('reject decision field present', str_contains($h, 'name="decision" value="reject"'));
chk('approve decision field present', str_contains($h, 'name="decision" value="approve"'));

echo "\nview: escaping + i18n + RTL\n";
$h = $render($file, ['media' => [['id' => 'm-x', 'caption' => '<x>c</x>', 'object_ref' => 'o', 'scan_state' => 'clean', 'review_state' => 'pending', 'visibility' => 'private']], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('escapes caption', ! str_contains($h, '<x>c</x>') && str_contains($h, '&lt;x&gt;'));
$h = $render($file, ['media' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'fr');
chk('fr localized register heading', str_contains($h, 'Enregistrer un média'));
$h = $render($file, ['media' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'ar');
chk('ar sets dir=rtl', str_contains($h, 'dir="rtl"'));

echo "\ncontroller wiring\n";
chk('register uses respondMedia', (bool) preg_match('/function register.*?respondMedia/s', $ctrl));
chk('review uses respondMedia', (bool) preg_match('/function review.*?respondMedia/s', $ctrl));
chk('review picks approved/rejected flash by decision', str_contains($ctrl, "'approvedFlash'") && str_contains($ctrl, "'rejectedFlash'"));
chk('listForReview renders console via renderForm', str_contains($ctrl, 'renderForm') && str_contains($ctrl, 'media_review'));
chk('listForReview serves JSON for API', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, 'respondAdmin'));
// publicMedia now renders a bespoke, locale-aware page for browsers via the
// shared presenter (respondPage → PageSpecs 'event_media'); API clients still
// get JSON (respondPage short-circuits on wantsJson()).
chk('publicMedia renders bespoke page (event_media)', (bool) preg_match("/function publicMedia.*?respondPage\\(.*?'event_media'/s", $ctrl));
chk('respondMedia redirects to media review console', str_contains($ctrl, "/media/review"));
chk('respondMedia keeps JSON for API', (bool) preg_match('/function respondMedia.*?wantsJson\(\)/s', $ctrl));

echo "\nservice wiring\n";
chk('review result carries event_id', (bool) preg_match("/function review.*?'event_id'\s*=>\s*\\\$media\['event_id'\]/s", $svc));

echo "\nroutes\n";
chk('POST register webcsrf', (bool) preg_match('#post\(\x27\(:segment\)/media\x27.*?register.*?webcsrf#s', $routes));
chk('GET media review console (gated)', (bool) preg_match('#get\(\x27\(:segment\)/media/review\x27.*?listForReview.*?event.media.manage#s', $routes));
chk('POST review webcsrf', (bool) preg_match('#\(:segment\)/review\x27.*?MediaController::review.*?webcsrf#s', $routes));
chk('public media GET stays open (no webcsrf)', (bool) preg_match('#get\(\x27\(:segment\)/media\x27.*?publicMedia#', $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
