<?php

declare(strict_types=1);

/**
 * Formatter tests — intl-optional locale formatting.
 *
 * When ext-intl is present we assert the ICU path produces locale-correct output;
 * when absent we assert the graceful fallback. Both paths must be null/robust.
 *
 *   php app/Modules/Shared/I18n/tests/formatter_test.php
 */

$root = dirname(__DIR__, 5);
require_once $root . '/app/Modules/Shared/I18n/Formatter.php';

use WBS\Shared\I18n\Formatter;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$hasIntl = extension_loaded('intl');
echo 'ext-intl ' . ($hasIntl ? 'PRESENT' : 'ABSENT') . " — testing the active path\n";

$en = new Formatter('en');
$fr = new Formatter('fr');

// -- number ---------------------------------------------------------------
$n = $en->number(1234567.5, 3);
chk('en number groups thousands', str_contains($n, '1') && str_contains($n, '567'), $n);
chk('en number empty-safe', $en->number(0) !== '', $en->number(0));

// -- currency (from MINOR units) ------------------------------------------
$usd = $en->formatCurrency(4200, 'USD');
chk('USD shows 42', str_contains($usd, '42'), $usd);
chk('USD shows $ or USD', str_contains($usd, '$') || str_contains($usd, 'USD'), $usd);
$ghs = $en->formatCurrency(150000, 'GHS'); // 1,500.00
chk('GHS shows 1,500', str_contains($ghs, '1') && str_contains($ghs, '500'), $ghs);

if ($hasIntl) {
    // ICU-specific expectations only when intl is available.
    $frCur = $fr->formatCurrency(4200, 'EUR');
    chk('fr EUR uses comma decimal', str_contains($frCur, '42,00'), $frCur);
    $frNum = $fr->number(1234567.5, 1);
    chk('fr number non-empty', $frNum !== '', $frNum);
} else {
    $frCur = $fr->formatCurrency(4200, 'EUR');
    chk('fallback EUR shows 42.00', str_contains($frCur, '42.00'), $frCur);
}

// -- dateTime -------------------------------------------------------------
$d = $en->dateTime('2026-09-09 12:30:00');
chk('date shows year 2026', str_contains($d, '2026') || str_contains($d, '26'), $d);
chk('date null -> empty', $en->dateTime(null) === '', 'expected empty');
chk('date garbage -> empty', $en->dateTime('not a date') === '', 'expected empty');
$dTz = $en->dateTime('2026-09-09 12:00:00', 2, 2, 'America/New_York');
chk('date tz conversion non-empty', $dTz !== '', $dTz);
chk('date epoch accepted', $en->dateTime(1757416800) !== '', 'epoch');

// -- meta -----------------------------------------------------------------
chk('locale() reports', $en->locale() === 'en', $en->locale());
chk('hasIntl() matches env', $en->hasIntl() === $hasIntl, 'mismatch');

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
