<?php

declare(strict_types=1);

/**
 * AccessControl access-request-queue view i18n + render smoke. Asserts the
 * accessReqView.* keys mirror across all locales, the SELF-CONTAINED
 * access_requests_pending view references lang('AccessControl.accessReqView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), PHP singular/plural count,
 * verbatim subject/requester ids, localized grant-type + conflict vocab with
 * raw-value fallback, and the empty state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_access_requests_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/AccessControl/Language';
$viewDir = $root . '/app/Modules/AccessControl/Views';

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

echo "language file completeness (accessReqView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['accessReqView']);
chk('en has accessReqView.grantType.role', in_array('grantType.role', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['accessReqView'] ?? []);
    chk("$loc mirrors all en accessReqView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray accessReqView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/access_requests_pending.php");
chk("access_requests_pending.php calls lang('AccessControl.accessReqView.", str_contains($src, "lang('AccessControl.accessReqView."));
chk('access_requests_pending.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('access_requests_pending.php includes _locale.php', str_contains($src, '_locale.php'));
chk('access_requests_pending.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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
            function getLocale() { return $GLOBALS['__aLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'AccessControl') {
            return $key;
        }
        $v = $GLOBALS['__aLang'];
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
    $GLOBALS['__aLoc']  = $loc;
    $GLOBALS['__aLang'] = require $langDir . "/$loc/AccessControl.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$requests = [
    ['id' => 'r1', 'grant_type' => 'role', 'role_id' => 'role-elder', 'subject_id' => 'usr-42', 'requested_by' => 'usr-7', 'duration_days' => 30, 'conflict_state' => 'flagged', 'reason' => 'Cover during leave', 'created_at' => '2026-09-01'],
    ['id' => 'r2', 'grant_type' => 'permission', 'permission_code' => 'events.manage', 'subject_id' => 'usr-99', 'requested_by' => 'usr-7', 'conflict_state' => 'none', 'reason' => 'New role', 'created_at' => '2026-09-03'],
];

$h = $render("$viewDir/access_requests_pending.php", ['requests' => $requests], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Approbations des demandes d’accès'));
chk('fr plural count interpolated', str_contains($h, '2 demandes'));
chk('fr subject id verbatim', str_contains($h, 'usr-42'));
chk('fr requester id verbatim', str_contains($h, 'usr-7'));
chk('fr grant type localized', str_contains($h, 'Attribution de rôle') && str_contains($h, 'Attribution de permission'));
chk('fr conflict localized (Signalé/Aucun)', str_contains($h, 'Signalé') && str_contains($h, 'Aucun'));
chk('fr reason shown', str_contains($h, 'Cover during leave'));

// singular
$h1 = $render("$viewDir/access_requests_pending.php", ['requests' => [$requests[0]]], 'fr');
chk('fr singular count', str_contains($h1, '1 demande') && ! str_contains($h1, '1 demandes'));

// unknown conflict vocab falls back to raw
$hU = $render("$viewDir/access_requests_pending.php", ['requests' => [['id' => 'x', 'grant_type' => 'role', 'role_id' => 'z', 'subject_id' => 's', 'requested_by' => 'b', 'conflict_state' => 'quarantined', 'reason' => 'x', 'created_at' => 'now']]], 'fr');
chk('unknown conflict falls back to raw', str_contains($hU, 'quarantined'));

// ar — RTL + empty
$ha = $render("$viewDir/access_requests_pending.php", ['requests' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'موافقات طلبات الوصول'));
chk('ar empty translated', str_contains($ha, 'لا توجد طلبات وصول بانتظار موافقتك.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
