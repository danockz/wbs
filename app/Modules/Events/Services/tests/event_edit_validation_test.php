<?php

declare(strict_types=1);

/**
 * EVENT EDIT + VALIDATION wiring test (pre-event gaps G1 + G2).
 *
 * G1 — event EDIT path: proves EventService::update() exists and is a whitelisted
 * partial update that freezes terminal events; that EventController exposes
 * editForm()/update() rendering the bespoke edit view + JSON; and that the
 * GET/POST events/{id}/edit routes exist, are organizer-gated, and the POST is
 * webcsrf-guarded (the segment makes them structurally non-page-like → no menu
 * entry needed).
 *
 * G2 — shared VALIDATION: exercises the pure, DB-free EventValidator directly
 * (required fields, ends>=starts, enum guards, non-negative capacity, virtual
 * mode needs access_url), and proves BOTH create() and update() route through it.
 *
 * Also asserts the edit view is self-contained / CSP-clean / no-JS, renders in
 * fr + ar (RTL) with sticky values, and that Events.editForm.* + Events.validate.*
 * have full key parity across all 6 locales.
 *
 *   php app/Modules/Events/Services/tests/event_edit_validation_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Events/Services/EventValidator.php';

use WBS\Events\Services\EventValidator;

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

// ── 1. EventValidator (pure) — create-mode (full) ────────────────────────────
echo "EventValidator — create mode (full)\n";
$ok = ['title' => 'X', 'starts_at' => '2026-10-01 10:00:00'];
chk('valid minimal create passes', EventValidator::validate($ok, false) === null);
chk('missing title fails TITLE_REQUIRED', (EventValidator::validate(['starts_at' => '2026-10-01 10:00:00'], false)?->code) === 'TITLE_REQUIRED');
chk('blank title (whitespace) fails', (EventValidator::validate(['title' => '   ', 'starts_at' => '2026-10-01 10:00:00'], false)?->code) === 'TITLE_REQUIRED');
chk('missing starts_at fails START_REQUIRED', (EventValidator::validate(['title' => 'X'], false)?->code) === 'START_REQUIRED');

echo "EventValidator — cross-field + enums\n";
$r = EventValidator::validate($ok + ['ends_at' => '2026-10-01 09:00:00'], false);
chk('ends before starts fails BAD_TIME_RANGE', ($r?->code) === 'BAD_TIME_RANGE');
chk('ends equal to starts is allowed', EventValidator::validate($ok + ['ends_at' => '2026-10-01 10:00:00'], false) === null);
chk('ends after starts is allowed', EventValidator::validate($ok + ['ends_at' => '2026-10-01 12:00:00'], false) === null);
chk('bad mode fails BAD_MODE', (EventValidator::validate($ok + ['mode' => 'telepathy'], false)?->code) === 'BAD_MODE');
chk('valid mode physical passes', EventValidator::validate($ok + ['mode' => 'physical'], false) === null);
chk('bad registration_policy fails BAD_REG_POLICY', (EventValidator::validate($ok + ['registration_policy' => 'nope'], false)?->code) === 'BAD_REG_POLICY');
chk('invite policy is accepted by validator', EventValidator::validate($ok + ['registration_policy' => 'invite'], false) === null);
chk('bad attendance_policy fails BAD_ATT_POLICY', (EventValidator::validate($ok + ['attendance_policy' => 'wat'], false)?->code) === 'BAD_ATT_POLICY');
chk('streaming attendance policy passes', EventValidator::validate($ok + ['attendance_policy' => 'streaming'], false) === null);

echo "EventValidator — capacity\n";
chk('negative capacity fails BAD_CAPACITY', (EventValidator::validate($ok + ['capacity' => -1], false)?->code) === 'BAD_CAPACITY');
chk('non-integer capacity fails BAD_CAPACITY', (EventValidator::validate($ok + ['capacity' => '3.5'], false)?->code) === 'BAD_CAPACITY');
chk('non-numeric capacity fails BAD_CAPACITY', (EventValidator::validate($ok + ['capacity' => 'lots'], false)?->code) === 'BAD_CAPACITY');
chk('zero capacity passes', EventValidator::validate($ok + ['capacity' => 0], false) === null);
chk('positive capacity passes', EventValidator::validate($ok + ['capacity' => 250], false) === null);
chk('empty capacity string is ignored (unlimited)', EventValidator::validate($ok + ['capacity' => ''], false) === null);
chk('null capacity is ignored (unlimited)', EventValidator::validate($ok + ['capacity' => null], false) === null);

echo "EventValidator — virtual modes need access_url\n";
chk('online with no access_url fails ACCESS_URL_REQUIRED', (EventValidator::validate($ok + ['mode' => 'online'], false)?->code) === 'ACCESS_URL_REQUIRED');
chk('hybrid with no access_url fails ACCESS_URL_REQUIRED', (EventValidator::validate($ok + ['mode' => 'hybrid'], false)?->code) === 'ACCESS_URL_REQUIRED');
chk('online WITH access_url passes', EventValidator::validate($ok + ['mode' => 'online', 'access_url' => 'https://x'], false) === null);
chk('physical with no access_url passes', EventValidator::validate($ok + ['mode' => 'physical'], false) === null);

echo "EventValidator — partial (update) mode\n";
chk('partial empty payload passes (nothing to check)', EventValidator::validate([], true) === null);
chk('partial does NOT force title', EventValidator::validate(['capacity' => 10], true) === null);
chk('partial does NOT force starts_at', EventValidator::validate(['mode' => 'physical'], true) === null);
chk('partial still rejects a bad enum', (EventValidator::validate(['mode' => 'bogus'], true)?->code) === 'BAD_MODE');
chk('partial still rejects negative capacity', (EventValidator::validate(['capacity' => -5], true)?->code) === 'BAD_CAPACITY');
chk('partial merged range check catches ends<starts', (EventValidator::validate(['starts_at' => '2026-10-01 10:00:00', 'ends_at' => '2026-10-01 08:00:00'], true)?->code) === 'BAD_TIME_RANGE');
chk('partial omitting mode does NOT trigger access_url rule', EventValidator::validate(['capacity' => 5], true) === null);
chk('partial setting mode=online DOES require access_url', (EventValidator::validate(['mode' => 'online'], true)?->code) === 'ACCESS_URL_REQUIRED');
chk('validator messages are stable Events.validate.* keys', str_starts_with((string) (EventValidator::validate([], false)?->message), 'Events.validate.'));

// ── 2. EventService — create() + update() route through validator ─────────────
echo "EventService source: update() contract + validator wiring\n";
$svc = (string) file_get_contents($root . '/app/Modules/Events/Services/EventService.php');
chk('create() calls EventValidator::validate(... false)', (bool) preg_match('/function create\(.*?EventValidator::validate\(\$data, false\)/s', $svc));
chk('update() method exists', (bool) preg_match('/function update\(string \$eventId, array \$data\)/', $svc));
chk('update() 404s an unknown event', (bool) preg_match('/function update\(.*?notFound\(/s', $svc));
chk('update() freezes terminal events (BAD_STATE)', (bool) preg_match("/function update\\(.*?'cancelled', 'completed', 'completed_no_attendance'.*?BAD_STATE/s", $svc));
chk('update() validates in PARTIAL mode', (bool) preg_match('/function update\(.*?EventValidator::validate\(\$merged, true\)/s', $svc));
chk('update() validates the MERGED (stored+incoming) view', (bool) preg_match('/function update\(.*?array_merge\(\$event, \$changes\)/s', $svc));
chk('update() only writes whitelisted EDITABLE columns', (bool) preg_match('/function update\(.*?self::EDITABLE/s', $svc));
chk('update() rejects an empty change set (NO_CHANGES)', (bool) preg_match('/function update\(.*?NO_CHANGES/s', $svc));
chk('update() stamps updated_at', (bool) preg_match("/function update\\(.*?'updated_at'\\s*=>\\s*\\\$this->clock->nowUtcString\\(\\)/s", $svc));
chk('EDITABLE excludes id/organization_id/status/created_*', ! (bool) preg_match("/const EDITABLE = \\[[^\\]]*'(id|organization_id|status|created_by|created_at)'/s", $svc));
chk('create() no longer uses the old ad-hoc empty(title) check', ! str_contains($svc, "event.title_required"));

// ── 3. Controller: editForm() + update() ─────────────────────────────────────
echo "controller: editForm() / update()\n";
$ctrl = (string) file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
chk('editForm() renders Views\\edit', (bool) preg_match('/function editForm\(.*?Views\\\\edit/s', $ctrl));
chk('editForm() 404s an unknown event', (bool) preg_match('/function editForm\(.*?find\(\$eventId\).*?respondWith\(Result::notFound/s', $ctrl));
chk('editForm() refuses terminal events', (bool) preg_match('/function editForm\(.*?completed_no_attendance/s', $ctrl));
chk('update() calls events()->update', (bool) preg_match('/function update\(.*?events\(\)->update\(/s', $ctrl));
chk('update() PRG-redirects to the event on success', (bool) preg_match('/function update\(.*?redirect\(\)->to\(.*?events/s', $ctrl));
chk('update() re-renders the edit form with error + old on failure', (bool) preg_match("/function update\\(.*?Views\\\\edit.*?'error'.*?'old'/s", $ctrl));
chk('controller translates validator keys via humanizeError', str_contains($ctrl, 'humanizeError'));
chk('create failure path uses humanizeError', (bool) preg_match('/function create\(.*?humanizeError\(/s', $ctrl));

// ── 4. Routes: guarded edit form + submit ────────────────────────────────────
echo "routes: events/{id}/edit\n";
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
chk('GET (:segment)/edit → editForm, organizer-gated', (bool) preg_match("#get\\('\\(:segment\\)/edit'.*?EventController::editForm.*?authorize:event\\.create#s", $routes));
chk('POST (:segment)/edit → update, gated + webcsrf', (bool) preg_match("#post\\('\\(:segment\\)/edit'.*?EventController::update.*?authorize:event\\.create.*?webcsrf#s", $routes));
$posEdit = strpos($routes, "EventController::editForm");
$posShow = strpos($routes, "get('(:segment)', '\\WBS\\Events\\Controllers\\EventController::show");
chk('Events edit route declared after the show route (both segment routes)', $posEdit !== false && $posShow !== false && $posEdit > $posShow);
// Segment route → structurally non-page-like → needs no MenuCoverage exclusion.
require_once $root . '/app/Modules/Shared/Navigation/MenuCoverage.php';
chk('GET events/(:segment)/edit is non-page-like (no menu entry needed)', ! \WBS\Shared\Navigation\MenuCoverage::isPageLike('GET events/(:segment)/edit'));

// ── 5. i18n parity: Events.editForm.* + Events.validate.* across 6 locales ────
echo "i18n parity (editForm + validate, all locales)\n";
$en   = require $langDir . '/en/Events.php';
$enEF = $flat($en['editForm'] ?? []);
$enV  = $flat($en['validate'] ?? []);
chk('en has editForm block', $enEF !== []);
chk('en has validate block', $enV !== []);
chk('en editForm has regPolicy.invite (invite is offered)', in_array('regPolicy.invite', $enEF, true));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $langDir . "/$loc/Events.php";
    foreach (['editForm' => $enEF, 'validate' => $enV] as $block => $enKeys) {
        $keys = $flat($arr[$block] ?? []);
        chk("$loc mirrors $block keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
            'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

// ── 6. View: self-contained, CSP-clean, no-JS ────────────────────────────────
echo "view: edit.php self-contained + CSP-clean\n";
$src = (string) file_get_contents("$viewDir/edit.php");
chk('edit.php includes _locale.php', str_contains($src, '_locale.php'));
chk('edit.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('edit.php uses lang(\'Events.', str_contains($src, "lang('Events."));
chk('edit.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('edit.php posts to the {id}/edit endpoint', str_contains($src, "events/' . rawurlencode(\$eventId) . '/edit"));
chk('edit.php binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('edit.php CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('edit.php CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));
chk('edit.php exposes registration_policy input', str_contains($src, 'name="registration_policy"'));
chk('edit.php exposes access_url input', str_contains($src, 'name="access_url"'));
chk('edit.php exposes attendance_policy input', str_contains($src, 'name="attendance_policy"'));
chk('edit.php exposes timezone input', str_contains($src, 'name="timezone"'));

// show.php gains an Edit link.
$showSrc = (string) file_get_contents("$viewDir/show.php");
chk('show.php links to /edit', str_contains($showSrc, "/edit\""));
chk('show.php uses lang for the edit link', str_contains($showSrc, "lang('Events.editForm.editLink')"));

// ── 7. Render smoke (fr + ar) with sticky values ─────────────────────────────
echo "render smoke (fr + ar)\n";
require __DIR__ . '/../../Views/tests/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$event = [
    'id' => 'evt-1', 'title' => 'Stored Title', 'starts_at' => '2026-10-01T10:00',
    'ends_at' => '', 'mode' => 'online', 'capacity' => 120, 'access_url' => 'https://join.example',
    'registration_policy' => 'invite', 'attendance_policy' => 'streaming', 'timezone' => 'Africa/Accra',
    'description' => 'Hello', 'status' => 'draft',
];
$h = $render("$viewDir/edit.php", ['csrf' => 'TKN', 'event_id' => 'evt-1', 'event' => $event, 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Modifier l’événement'));
chk('fr csrf rendered', str_contains($h, 'value="TKN"'));
chk('fr posts to /events/evt-1/edit', str_contains($h, 'action="https://public.test/events/evt-1/edit"'));
chk('fr sticky title from stored row', str_contains($h, 'value="Stored Title"'));
chk('fr sticky capacity from stored row', str_contains($h, 'value="120"'));
chk('fr sticky mode=online selected', (bool) preg_match('/value="online" selected/', $h));
chk('fr sticky registration_policy=invite selected', (bool) preg_match('/value="invite" selected/', $h));
chk('fr sticky access_url from stored row', str_contains($h, 'value="https://join.example"'));

// Sticky $old overrides the stored row + error banner.
$h2 = $render("$viewDir/edit.php", [
    'csrf' => 'T', 'event_id' => 'evt-1', 'event' => $event,
    'error' => 'Boom', 'old' => ['title' => 'Edited Title', 'mode' => 'physical'],
], 'fr');
chk('fr error banner shown', str_contains($h2, 'Boom'));
chk('fr $old title overrides stored', str_contains($h2, 'value="Edited Title"'));
chk('fr $old mode=physical selected', (bool) preg_match('/value="physical" selected/', $h2));

$ha = $render("$viewDir/edit.php", ['csrf' => 'T', 'event_id' => 'evt-1', 'event' => $event, 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'تعديل الفعالية'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
