<?php

declare(strict_types=1);

/**
 * IcsFeedService unit test — the pure RFC-5545 formatter behind the Events
 * iCalendar subscription feed (GET /events/calendar.ics). It is DB-free, so we
 * feed it plain rows and assert the emitted VCALENDAR is spec-correct:
 *
 *   - VCALENDAR envelope: VERSION/PRODID/METHOD:PUBLISH + X-WR-CALNAME;
 *   - one VEVENT per row with a stable UID (id@host) so re-subscribe UPDATEs;
 *   - DTSTART/DTEND stamped in UTC ("...Z") from stored "Y-m-d H:i:s";
 *   - TEXT escaping (backslash, comma, semicolon, newline) in SUMMARY/DESCRIPTION;
 *   - long lines FOLDED to <=75 octets with CRLF + space, multibyte-safe;
 *   - cancelled events -> STATUS:CANCELLED; others -> STATUS:CONFIRMED;
 *   - SEQUENCE derived from updated_at; malformed rows skipped, not fatal;
 *   - CRLF line endings throughout.
 *
 *   php app/Modules/Events/Services/tests/ics_feed_test.php
 */

$root = dirname(__DIR__, 5);
require $root . '/app/Modules/Events/Services/IcsFeedService.php';

use WBS\Events\Services\IcsFeedService;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$svc = new IcsFeedService();

$events = [
    [
        'id' => 'evt-1', 'title' => 'National Convention', 'description' => "Line one\nLine two; part, part",
        'status' => 'published', 'mode' => 'physical', 'access_url' => '',
        'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '2026-10-04 17:30:00',
        'updated_at' => '2026-09-10 12:00:00', 'created_at' => '2026-09-01 08:00:00',
    ],
    [
        'id' => 'evt-2', 'title' => 'Youth Worship Night', 'description' => '',
        'status' => 'published', 'mode' => 'virtual', 'access_url' => 'https://public.test/join/xyz',
        'starts_at' => '2026-10-18 18:00:00', 'ends_at' => '',
        'updated_at' => '', 'created_at' => '2026-09-01 08:00:00',
    ],
    [
        'id' => 'evt-3', 'title' => 'Cancelled Seminar', 'description' => '',
        'status' => 'cancelled', 'mode' => 'physical', 'access_url' => '',
        'starts_at' => '2026-10-25 10:00:00', 'ends_at' => '2026-10-25 12:00:00',
        'updated_at' => '2026-09-12 09:00:00', 'created_at' => '2026-09-01 08:00:00',
    ],
    // Malformed: no start -> must be skipped, not crash.
    ['id' => 'evt-bad', 'title' => 'No Start', 'starts_at' => '', 'status' => 'published'],
];

$ics = $svc->build($events, 'Events calendar', 'public.test', '2026-09-14 03:00:00', 'https://public.test/');

// ── Envelope ─────────────────────────────────────────────────────────────────
echo "envelope\n";
chk('BEGIN/END VCALENDAR', str_contains($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'END:VCALENDAR'));
chk('VERSION 2.0', str_contains($ics, 'VERSION:2.0'));
chk('PRODID present', str_contains($ics, 'PRODID:-//WBS Platform//Events//EN'));
chk('METHOD PUBLISH', str_contains($ics, 'METHOD:PUBLISH'));
chk('X-WR-CALNAME uses passed name', str_contains($ics, 'X-WR-CALNAME:Events calendar'));
chk('X-WR-TIMEZONE UTC', str_contains($ics, 'X-WR-TIMEZONE:UTC'));
chk('DTSTAMP is generation time (UTC Z)', str_contains($ics, 'DTSTAMP:20260914T030000Z'));

// ── VEVENT count + UID + stamps ──────────────────────────────────────────────
echo "vevents\n";
chk('three VEVENTs (bad row skipped)', substr_count($ics, 'BEGIN:VEVENT') === 3, (string) substr_count($ics, 'BEGIN:VEVENT'));
chk('VEVENT begin/end balanced', substr_count($ics, 'BEGIN:VEVENT') === substr_count($ics, 'END:VEVENT'));
chk('stable UID id@host', str_contains($ics, 'UID:evt-1@public.test'));
chk('DTSTART UTC stamp', str_contains($ics, 'DTSTART:20261004T090000Z'));
chk('DTEND UTC stamp', str_contains($ics, 'DTEND:20261004T173000Z'));
chk('no DTEND when ends_at empty (evt-2)', ! str_contains($ics, 'DTEND:20261018'));
chk('canonical URL emitted', str_contains($ics, 'URL:https://public.test/events/evt-1'));

// ── TEXT escaping ────────────────────────────────────────────────────────────
echo "escaping\n";
chk('newline escaped to \\n', str_contains($ics, 'DESCRIPTION:Line one\nLine two'));
chk('semicolon escaped', str_contains($ics, 'two\; part'));
chk('comma escaped', str_contains($ics, 'part\, part'));

// ── LOCATION by mode ─────────────────────────────────────────────────────────
echo "location\n";
chk('virtual access_url -> LOCATION', str_contains($ics, 'LOCATION:https://public.test/join/xyz'));

// ── STATUS ───────────────────────────────────────────────────────────────────
echo "status\n";
chk('published -> CONFIRMED', str_contains($ics, 'STATUS:CONFIRMED'));
chk('cancelled -> CANCELLED', str_contains($ics, 'STATUS:CANCELLED'));

// ── SEQUENCE ─────────────────────────────────────────────────────────────────
echo "sequence\n";
chk('SEQUENCE from updated_at (evt-1)', str_contains($ics, 'SEQUENCE:' . strtotime('2026-09-10 12:00:00 UTC')));
chk('SEQUENCE 0 when updated_at missing (evt-2)', str_contains($ics, 'SEQUENCE:0'));

// ── CRLF + folding ───────────────────────────────────────────────────────────
echo "crlf + folding\n";
chk('uses CRLF line endings', str_contains($ics, "\r\n") && ! preg_match('/[^\r]\n/', $ics));
$long = $svc->build([[
    'id' => 'L', 'title' => str_repeat('A', 200), 'status' => 'published',
    'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '', 'updated_at' => '',
]], 'Cal', 'public.test', '2026-09-14 03:00:00', '');
$folded = true;
foreach (explode("\r\n", $long) as $ln) {
    // Continuation lines start with a space; the octet cap is 75.
    if (strlen($ln) > 75) {
        $folded = false;
        break;
    }
}
chk('no content line exceeds 75 octets', $folded);
chk('folded continuation starts with space', (bool) preg_match('/\r\n [A]/', $long));

// Multibyte safety: a long unicode SUMMARY must fold without producing invalid UTF-8.
$mb = $svc->build([[
    'id' => 'M', 'title' => str_repeat('é', 100), 'status' => 'published',
    'starts_at' => '2026-10-04 09:00:00', 'ends_at' => '', 'updated_at' => '',
]], 'Cal', 'public.test', '2026-09-14 03:00:00', '');
$unfolded = str_replace("\r\n ", '', $mb); // undo folding
chk('multibyte folding preserves valid UTF-8', mb_check_encoding($unfolded, 'UTF-8'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
