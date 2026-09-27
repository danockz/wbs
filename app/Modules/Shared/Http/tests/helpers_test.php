<?php

declare(strict_types=1);

/**
 * View helper tests: time (relative/absolute) + redactor (PII masking).
 *
 * These helpers are presentation-layer utilities loaded into every controller
 * via BaseController::$helpers. They must be null-tolerant and never throw so a
 * view always renders.
 *
 *   php app/Modules/Shared/Http/tests/helpers_test.php
 */

$root = dirname(__DIR__, 5);
require_once $root . '/app/Helpers/time_helper.php';
require_once $root . '/app/Helpers/redactor_helper.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}
function eq(string $label, $got, $want): void
{
    chk($label . " (got: " . var_export($got, true) . ")", $got === $want);
}

echo "view helpers\n";

$now = new DateTimeImmutable('2026-09-09 12:00:00', new DateTimeZone('UTC'));

// -- time_ago ---------------------------------------------------------------
eq('2s ago -> just now', time_ago('2026-09-09 11:59:58', '—', $now), 'just now');
eq('15 min ago', time_ago('2026-09-09 11:45:00', '—', $now), '15 minutes ago');
eq('1 min singular', time_ago('2026-09-09 11:59:00', '—', $now), '1 minute ago');
eq('3 hours ago', time_ago('2026-09-09 09:00:00', '—', $now), '3 hours ago');
eq('2 days ago', time_ago('2026-09-07 12:00:00', '—', $now), '2 days ago');
eq('1 week ago', time_ago('2026-09-02 12:00:00', '—', $now), '1 week ago');
eq('1 month ago', time_ago('2026-08-10 12:00:00', '—', $now), '1 month ago');
eq('future -> in 1 hour', time_ago('2026-09-09 13:00:00', '—', $now), 'in 1 hour');
eq('epoch int accepted', time_ago(1757416800, '—', $now) !== '—' ? 'ok' : 'bad', 'ok');
eq('null -> fallback', time_ago(null, '—', $now), '—');
eq('garbage -> fallback', time_ago('not a date', '—', $now), '—');
eq('custom fallback', time_ago(null, 'never', $now), 'never');

// -- format_datetime / time_tag / to_datetime -------------------------------
eq('format default UTC', format_datetime('2026-09-09 12:00:00', 'Y-m-d H:i'), '2026-09-09 12:00');
eq('format viewer tz', format_datetime('2026-09-09 12:00:00', 'H:i', 'America/New_York'), '08:00');
eq('format bad tz falls back to value zone', format_datetime('2026-09-09 12:00:00', 'H:i', 'Not/AZone'), '12:00');
eq('format null -> fallback', format_datetime(null), '—');
chk('to_datetime parses ISO', to_datetime('2026-09-09T12:00:00+00:00') instanceof DateTimeImmutable);
chk('to_datetime null -> null', to_datetime(null) === null);
$tag = time_tag('2026-09-07 12:00:00');
chk('time_tag emits <time datetime=…>', str_contains($tag, '<time datetime="2026-09-07T12:00:00+00:00"'));
// Uses the real clock (time_tag has no $now param), so assert the SHAPE of the
// relative text rather than an exact day count that drifts as time passes.
chk('time_tag shows human text', (bool) preg_match('/\d+ (day|days|hour|hours|week|weeks|month|months) ago/', $tag) || str_contains($tag, 'just now'));
eq('time_tag null -> empty', time_tag(null), '');

// -- redactors --------------------------------------------------------------
eq('mask_middle card', mask_middle('4242424242424242', 4, 4), '4242********4242');
eq('mask_middle short fully masked', mask_middle('abc', 2, 2), '***');
eq('mask_middle empty', mask_middle(''), '');
eq('redact_email', redact_email('jane.doe@example.com'), 'ja******@example.com');
eq('redact_email short local', redact_email('jo@x.io'), 'j*@x.io');
eq('redact_email non-email', redact_email('notanemail'), 'no********');
eq('redact_email null', redact_email(null), '—');
eq('redact_phone keeps last 4 + plus', redact_phone('+233241234567'), '+••••••••4567');
eq('redact_phone no digits', redact_phone('n/a'), '—');
eq('redact_name initials', redact_name('Kwame Nkrumah Mensah'), 'Kwame N. M.');
eq('redact_name single', redact_name('Kwame'), 'Kwame');
eq('redact_name null default', redact_name(null), 'Member');
eq('redact_id tail', redact_id('3f9c2b7a-1d4e-4a2b-9c8d-abc123456789', 6), '…456789');
eq('redact_id short unchanged', redact_id('abc', 6), 'abc');
eq('redact dispatch email', redact('a@b.com', 'email'), 'a*@b.com');
eq('redact dispatch default middle', redact('secretvalue'), 'se*******ue');

// -- wiring -----------------------------------------------------------------
$base = (string) file_get_contents($root . '/app/Modules/Shared/Http/BaseController.php');
chk('BaseController registers the time helper', str_contains($base, "'time'"));
chk('BaseController registers the redactor helper', str_contains($base, "'redactor'"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
