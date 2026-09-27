<?php

declare(strict_types=1);

/**
 * AccessControl break-glass-queue view i18n + render smoke. Asserts the
 * breakGlassView.* keys mirror across all locales, the SELF-CONTAINED
 * break_glass_pending view references lang('AccessControl.breakGlassView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), PHP singular/plural count,
 * verbatim permission code + ids, localized status vocab with raw-value fallback,
 * and the empty state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_break_glass_view_test.php
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

echo "language file completeness (breakGlassView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['breakGlassView']);
chk('en has breakGlassView.status.expired', in_array('status.expired', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['breakGlassView'] ?? []);
    chk("$loc mirrors all en breakGlassView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray breakGlassView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/break_glass_pending.php");
chk("break_glass_pending.php calls lang('AccessControl.breakGlassView.", str_contains($src, "lang('AccessControl.breakGlassView."));
chk('break_glass_pending.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('break_glass_pending.php includes _locale.php', str_contains($src, '_locale.php'));
chk('break_glass_pending.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$sessions = [
    ['id' => 's1', 'permission_code' => 'finance.admin', 'subject_id' => 'usr-42', 'opened_by' => 'usr-7', 'reason' => 'Outage recovery', 'mfa_level' => 'strong', 'status' => 'closed', 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-01', 'review_state' => 'pending'],
    ['id' => 's2', 'permission_code' => 'identity.admin', 'subject_id' => 'usr-99', 'opened_by' => 'usr-7', 'reason' => 'Locked out', 'mfa_level' => 'strong', 'status' => 'expired', 'effective_from' => '2026-09-02', 'effective_to' => '2026-09-02', 'review_state' => 'pending'],
];

$h = $render("$viewDir/break_glass_pending.php", ['sessions' => $sessions], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'File de revue des accès d’urgence'));
chk('fr plural count interpolated', str_contains($h, '2 sessions'));
chk('fr permission code verbatim', str_contains($h, 'finance.admin'));
chk('fr subject id verbatim', str_contains($h, 'usr-42'));
chk('fr status localized (Fermée/Expirée)', str_contains($h, 'Fermée') && str_contains($h, 'Expirée'));
chk('fr reason shown', str_contains($h, 'Outage recovery'));

// singular
$h1 = $render("$viewDir/break_glass_pending.php", ['sessions' => [$sessions[0]]], 'fr');
chk('fr singular count', str_contains($h1, '1 session') && ! str_contains($h1, '1 sessions'));

// unknown status vocab falls back to raw
$hU = $render("$viewDir/break_glass_pending.php", ['sessions' => [['id' => 'x', 'permission_code' => 'z', 'subject_id' => 's', 'opened_by' => 'b', 'reason' => 'x', 'mfa_level' => 'strong', 'status' => 'quarantined', 'effective_from' => 'a', 'effective_to' => 'b']]], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'quarantined'));

// ar — RTL + empty
$ha = $render("$viewDir/break_glass_pending.php", ['sessions' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'قائمة مراجعة الوصول الطارئ'));
chk('ar empty translated', str_contains($ha, 'لا توجد جلسات بانتظار المراجعة.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
