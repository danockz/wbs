<?php

declare(strict_types=1);

/**
 * Members directory-view i18n + render smoke. Asserts Identity.members.* key
 * parity across locales, that the SELF-CONTAINED members view references
 * lang('Identity.members.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction (RTL for
 * Arabic), localized-with-fallback status, PHP singular/plural count, a
 * self-contained avatar (data-URI when no photo, the URL when present), verbatim
 * name/email, and the no-name / no-email / no-date fallbacks.
 *
 *   php app/Modules/Identity/Views/tests/members_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Identity/Language';
$viewDir = $root . '/app/Modules/Identity/Views';

require_once $root . '/app/Modules/Shared/Support/Avatar.php';

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
$en     = require $langDir . '/en/Identity.php';
$enKeys = $flatten($en['members']);
chk('en has members.status.active', in_array('status.active', $enKeys, true));
chk('en has members.countOne', in_array('countOne', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Identity.php";
    $keys = $flatten($m['members'] ?? []);
    chk("$loc mirrors all en members keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray members keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/members.php");
chk("members.php calls lang('Identity.members.", str_contains($src, "lang('Identity.members."));
chk('members.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('members.php includes _locale.php', str_contains($src, '_locale.php'));
chk('members.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('members.php uses Avatar::resolveUrl', str_contains($src, 'Avatar::resolveUrl'));
foreach (['<h1>Members', 'No members yet', 'Suspended</'] as $needle) {
    chk("members.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale() { return $GLOBALS['__mLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Identity') {
            return $key;
        }
        $v = $GLOBALS['__mLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__mLoc']  = $loc;
    $GLOBALS['__mLang'] = require $langDir . "/$loc/Identity.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/members.php", [
    'members' => [
        ['id' => 'u1', 'display_name' => 'Ama Owusu', 'email' => 'ama@example.com', 'status' => 'active', 'profile_photo_url' => 'https://cdn.example.com/ama.jpg', 'created_at' => '2026-03-01 08:00:00'],
        ['id' => 'u2', 'display_name' => '', 'email' => '', 'status' => 'weirdstate', 'profile_photo_url' => null, 'created_at' => null],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, '<h1>Membres'));
chk('fr plural count interpolated', str_contains($h, '2 membres'));
chk('fr status active translated', str_contains($h, 'Actif'));
chk('fr unknown status falls back (Weirdstate)', str_contains($h, 'Weirdstate'));
chk('fr name verbatim', str_contains($h, 'Ama Owusu'));
chk('fr email verbatim', str_contains($h, 'ama@example.com'));
chk('fr photo URL used when present', str_contains($h, 'https://cdn.example.com/ama.jpg'));
chk('fr initials data-URI when no photo', str_contains($h, 'data:image/svg+xml;base64,'));
chk('fr no-name fallback', str_contains($h, 'Membre sans nom'));
chk('fr no-email fallback', str_contains($h, 'Aucun e-mail'));
chk('fr joined date formatted', str_contains($h, '2026-03-01'));

$h1 = $render("$viewDir/members.php", ['members' => [['id' => 'x', 'display_name' => 'Solo', 'email' => 's@e.com', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']]], 'fr');
chk('fr singular count', str_contains($h1, '1 membre') && ! str_contains($h1, '1 membres'));

$ha = $render("$viewDir/members.php", ['members' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'الأعضاء'));
chk('ar empty translated', str_contains($ha, 'لا يوجد أعضاء بعد.'));

echo "M8 — admin lifecycle action forms\n";
// A member in each status; assert the legal action set is exactly what the
// service's TRANSITIONS map allows (minus merged/active/pending targets).
$hm = $render("$viewDir/members.php", [
    'csrf'    => 'TOK-CSRF-123',
    'members' => [
        ['id' => 'a1', 'display_name' => 'Active One',    'email' => 'a@e.com', 'status' => 'active',      'created_at' => '2026-01-01 00:00:00'],
        ['id' => 's1', 'display_name' => 'Suspended One', 'email' => 's@e.com', 'status' => 'suspended',   'created_at' => '2026-01-01 00:00:00'],
        ['id' => 'd1', 'display_name' => 'Deact One',     'email' => 'd@e.com', 'status' => 'deactivated', 'created_at' => '2026-01-01 00:00:00'],
        ['id' => 'z1', 'display_name' => 'Anon One',      'email' => '',        'status' => 'anonymized',  'created_at' => '2026-01-01 00:00:00'],
    ],
], 'en');

// header column present
chk('M8 Actions column header', str_contains($hm, '>Actions<'));
// CSP-safe: no inline JS handlers, uses native <details>/POST form
chk('M8 no inline onclick/on* handlers', ! preg_match('/on(click|submit|change)=/i', $hm));
chk('M8 uses <details> disclosure', str_contains($hm, '<details class="manage">'));

// CSRF token threaded into every form
$formCount = substr_count($hm, '<form method="post"');
$csrfCount = substr_count($hm, 'value="TOK-CSRF-123"');
chk('M8 every action form carries the CSRF token', $formCount > 0 && $formCount === $csrfCount, "forms=$formCount csrf=$csrfCount");
chk('M8 reason field is required', str_contains($hm, 'name="reason" required'));

// active → suspend/lock/deactivate/anonymize, NOT reactivate
chk('M8 active can suspend',   str_contains($hm, 'identity/accounts/a1/suspend'));
chk('M8 active can lock',      str_contains($hm, 'identity/accounts/a1/lock'));
chk('M8 active can deactivate',str_contains($hm, 'identity/accounts/a1/deactivate'));
chk('M8 active can anonymize', str_contains($hm, 'identity/accounts/a1/anonymize'));
chk('M8 active CANNOT reactivate', ! str_contains($hm, 'identity/accounts/a1/reactivate'));

// suspended → reactivate is offered (inverse of teardown, M10)
chk('M8 suspended can reactivate', str_contains($hm, 'identity/accounts/s1/reactivate'));

// deactivated → reactivate + anonymize only (no suspend/lock)
chk('M8 deactivated can reactivate', str_contains($hm, 'identity/accounts/d1/reactivate'));
chk('M8 deactivated can anonymize',  str_contains($hm, 'identity/accounts/d1/anonymize'));
chk('M8 deactivated CANNOT suspend', ! str_contains($hm, 'identity/accounts/d1/suspend'));

// anonymized is terminal → no forms, shows "No actions available"
chk('M8 anonymized has no action form', ! str_contains($hm, 'identity/accounts/z1/'));
chk('M8 terminal shows noActions copy', str_contains($hm, 'No actions available'));

// irreversible warning on anonymize
chk('M8 anonymize shows irreversible warning', str_contains($hm, 'This cannot be undone.'));

// history deep-link present
chk('M8 links to lifecycle history', str_contains($hm, 'identity/accounts/a1/transitions'));

// empty CSRF still renders without fatal (defensive)
$he = $render("$viewDir/members.php", [
    'members' => [['id' => 'e1', 'display_name' => 'No Csrf', 'email' => 'e@e.com', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']],
], 'en');
chk('M8 renders with missing csrf (defaults empty)', str_contains($he, 'identity/accounts/e1/suspend'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
