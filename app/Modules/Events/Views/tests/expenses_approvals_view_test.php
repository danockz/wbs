<?php

declare(strict_types=1);

/**
 * Expense-approvals launcher view i18n + render smoke (dead-link fix: GET
 * /events/expenses). Asserts Events.expenses.* key parity across locales, and that
 * the SELF-CONTAINED page references lang(), includes _locale.php, emits a dynamic
 * <html lang dir>, renders one approve/reject form per expense (each with a hidden
 * _csrf bound to $csrf and the expense_id) POSTing to /events/expenses, formats the
 * money amount, shows the empty state when the queue is clear, surfaces $error, and
 * is RTL for Arabic.
 *
 *   php app/Modules/Events/Views/tests/expenses_approvals_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

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

echo "language file completeness\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['expenses']);
chk('en has expenses.approve', in_array('approve', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $keys = $flatten($m['expenses'] ?? []);
    chk("$loc mirrors all en expenses keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray expenses keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/expenses.php");
chk("expenses.php calls lang('Events.expenses.", str_contains($src, "lang('Events.expenses."));
chk('expenses.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('expenses.php includes _locale.php', str_contains($src, '_locale.php'));
chk('expenses.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('expenses.php binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));

echo "render smoke (fr + ar)\n";
require __DIR__ . '/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$expenses = [
    ['id' => 'ex-1', 'event_id' => 'ev-1', 'event_title' => 'Sunday Celebration', 'submitted_by' => 'u-3',
        'category' => 'Catering', 'description' => 'Refreshments', 'currency' => 'GHS', 'amount_minor' => 12500, 'status' => 'submitted'],
    ['id' => 'ex-2', 'event_id' => 'ev-2', 'event_title' => 'Youth Camp', 'submitted_by' => 'u-7',
        'category' => 'Transport', 'description' => 'Bus hire', 'currency' => 'GHS', 'amount_minor' => 40000, 'status' => 'submitted'],
];

$h = $render("$viewDir/expenses.php", ['csrf' => 'CSRFY', 'expenses' => $expenses, 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Approbations de dépenses'));
chk('fr csrf token in each form', substr_count($h, 'value="CSRFY"') === 2);
chk('fr posts to expenses endpoint', str_contains($h, 'action="https://public.test/events/expenses"'));
chk('fr carries expense_id', str_contains($h, 'name="expense_id" value="ex-1"') && str_contains($h, 'name="expense_id" value="ex-2"'));
chk('fr approve/reject buttons', str_contains($h, 'value="approve"') && str_contains($h, 'value="reject"'));
chk('fr approve label translated', str_contains($h, 'Approuver'));
chk('fr formats money (125.00 GHS)', str_contains($h, '125.00 GHS'));
chk('fr shows event title', str_contains($h, 'Sunday Celebration'));

$he = $render("$viewDir/expenses.php", ['csrf' => 'T', 'expenses' => [], 'error' => '', 'old' => []], 'fr');
chk('empty state shown when queue clear', str_contains($he, 'Aucune dépense en attente'));

$herr = $render("$viewDir/expenses.php", ['csrf' => 'T', 'expenses' => $expenses, 'error' => 'SOD violation', 'old' => []], 'fr');
chk('fr error banner shown', str_contains($herr, 'SOD violation'));

$ha = $render("$viewDir/expenses.php", ['csrf' => 'T', 'expenses' => $expenses, 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'اعتماد المصروفات'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
