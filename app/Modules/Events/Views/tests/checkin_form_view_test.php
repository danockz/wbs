<?php

declare(strict_types=1);

/**
 * Manual check-in launcher view i18n + render smoke (dead-link fix: GET
 * /events/checkin). Asserts Events.checkin.* key parity across locales, and that
 * the SELF-CONTAINED page references lang(), includes _locale.php, emits a dynamic
 * <html lang dir>, carries a hidden _csrf bound to $csrf, POSTs to /events/checkin,
 * renders the event picker from $events, honors sticky $old + $error, shows the
 * empty state when there are no events, and is RTL for Arabic.
 *
 *   php app/Modules/Events/Views/tests/checkin_form_view_test.php
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
$enKeys = $flatten($en['checkin']);
chk('en has checkin.attrHint', in_array('attrHint', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $keys = $flatten($m['checkin'] ?? []);
    chk("$loc mirrors all en checkin keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray checkin keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/checkin.php");
chk("checkin.php calls lang('Events.checkin.", str_contains($src, "lang('Events.checkin."));
chk('checkin.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('checkin.php includes _locale.php', str_contains($src, '_locale.php'));
chk('checkin.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('checkin.php has method="post"', str_contains($src, 'method="post"'));
chk('checkin.php binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));

echo "render smoke (fr + ar)\n";
require __DIR__ . '/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$events = [
    ['id' => 'ev-1', 'title' => 'Sunday Celebration', 'starts_at' => '2026-09-13 10:00'],
    ['id' => 'ev-2', 'title' => 'Youth Camp', 'starts_at' => '2026-10-01 09:00'],
];

$h = $render("$viewDir/checkin.php", ['csrf' => 'TOKX', 'events' => $events, 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Enregistrer un pointage manuel'));
chk('fr csrf token rendered', str_contains($h, 'value="TOKX"'));
chk('fr posts to checkin endpoint', str_contains($h, 'action="https://public.test/events/checkin"'));
chk('fr renders event option', str_contains($h, 'value="ev-1"') && str_contains($h, 'Sunday Celebration'));

$h2 = $render("$viewDir/checkin.php", ['csrf' => 'T', 'events' => $events, 'error' => 'User required', 'old' => ['event_id' => 'ev-2', 'user_id' => 'u-9']], 'fr');
chk('fr error banner shown', str_contains($h2, 'User required'));
chk('fr sticky user value', str_contains($h2, 'value="u-9"'));
chk('fr sticky event selected', (bool) preg_match('/value="ev-2" selected/', $h2));

$he = $render("$viewDir/checkin.php", ['csrf' => 'T', 'events' => [], 'error' => '', 'old' => []], 'fr');
chk('empty state shown when no events', str_contains($he, 'Aucun événement disponible'));
chk('no form when no events', ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $he), 'method="post"'));

$ha = $render("$viewDir/checkin.php", ['csrf' => 'T', 'events' => $events, 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'تسجيل حضور يدوي'));

// user_id (attendee) + staff_id (recorder) are entity references → roster-backed
// person pickers when a roster is supplied, else bounded text (unchanged).
echo "user_id + staff_id entity-reference pickers\n";
$roster = [
    ['id' => 'u-1', 'display_name' => 'Ama Owusu'],
    ['id' => 'u-2', 'display_name' => 'Kofi Mensah'],
];
$hp = $render("$viewDir/checkin.php", [
    'csrf' => 'T', 'events' => $events, 'error' => '', 'roster' => $roster,
    'old' => ['user_id' => 'u-2', 'staff_id' => 'u-1'],
], 'fr');
chk('user picker: renders <select id="user_id"> required', str_contains($hp, '<select id="user_id" name="user_id" required>'));
chk('user picker: option value = user id + name', str_contains($hp, 'value="u-1"') && str_contains($hp, 'Ama Owusu'));
chk('user picker: current attendee preselected', (bool) preg_match('/value="u-2" selected/', $hp));
chk('user picker: none option present', str_contains($hp, lang('Events.checkin.userNone')));
chk('staff picker: renders <select id="staff_id"> (not required)', str_contains($hp, '<select id="staff_id" name="staff_id">'));
chk('staff picker: current recorder preselected', (bool) preg_match('/value="u-1" selected/', $hp));
chk('staff picker: none option present', str_contains($hp, lang('Events.checkin.staffNone')));
// stale/off-list ids preserved
$hpStale = $render("$viewDir/checkin.php", [
    'csrf' => 'T', 'events' => $events, 'error' => '', 'roster' => $roster,
    'old' => ['user_id' => 'ghost-x'],
], 'fr');
chk('user picker: stale attendee id preserved as selected option', (bool) preg_match('/value="ghost-x" selected/', $hpStale));
// no roster → bounded text fallback (unchanged behaviour)
$hpNo = $render("$viewDir/checkin.php", ['csrf' => 'T', 'events' => $events, 'error' => '', 'old' => ['user_id' => 'u-9']], 'fr');
chk('user picker: bounded text fallback when no roster', str_contains($hpNo, 'id="user_id" name="user_id" required maxlength="64"'));
chk('user picker: fallback preserves submitted value', str_contains($hpNo, 'value="u-9"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
