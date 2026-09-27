<?php

declare(strict_types=1);

/**
 * AccessControl break-glass-detail view i18n + render smoke. Asserts the
 * breakGlassShowView.* keys mirror across all locales, the SELF-CONTAINED
 * break_glass_show view references lang('AccessControl.breakGlassShowView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), verbatim permission code and
 * ids, org-wide vs scoped group, localized status + review-state vocab with
 * raw-value fallback (lowercased), the window block, and the not-found panel.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_break_glass_show_view_test.php
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

echo "language file completeness (breakGlassShowView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['breakGlassShowView']);
chk('en has breakGlassShowView.review.pending', in_array('review.pending', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['breakGlassShowView'] ?? []);
    chk("$loc mirrors all en breakGlassShowView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray breakGlassShowView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/break_glass_show.php");
chk("break_glass_show.php calls lang('AccessControl.breakGlassShowView.", str_contains($src, "lang('AccessControl.breakGlassShowView."));
chk('break_glass_show.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('break_glass_show.php includes _locale.php', str_contains($src, '_locale.php'));
chk('break_glass_show.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

echo "render smoke (fr + ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__aLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'AccessControl') { return $key; }
        $v = $GLOBALS['__aLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
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

$session = [
    'id' => 's1', 'permission_code' => 'finance.admin', 'subject_id' => 'usr-42', 'opened_by' => 'usr-7',
    'scope_group_id' => 'grp-2', 'include_descendants' => 1, 'reason' => 'Outage recovery', 'mfa_level' => 'strong',
    'status' => 'closed', 'review_state' => 'pending', 'effective_from' => '2026-09-01T10:00', 'effective_to' => '2026-09-01T11:00', 'closed_at' => '2026-09-01T10:45',
];

$h = $render("$viewDir/break_glass_show.php", ['session' => $session], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr permission code verbatim', str_contains($h, 'finance.admin'));
chk('fr subject + opener verbatim', str_contains($h, 'usr-42') && str_contains($h, 'usr-7'));
chk('fr scoped group verbatim', str_contains($h, 'grp-2'));
chk('fr status localized (Fermée)', str_contains($h, 'Fermée'));
chk('fr review state localized (En attente)', str_contains($h, 'En attente'));
chk('fr reason shown', str_contains($h, 'Outage recovery'));
chk('fr window block shown', str_contains($h, 'Fenêtre') && str_contains($h, 'Fermée le'));

// org-wide + reviewed
$h2 = $render("$viewDir/break_glass_show.php", ['session' => ['id' => 's', 'permission_code' => 'p', 'subject_id' => 's', 'opened_by' => 'o', 'scope_group_id' => null, 'include_descendants' => 0, 'reason' => 'x', 'mfa_level' => 'strong', 'status' => 'expired', 'review_state' => 'reviewed', 'effective_from' => 'a', 'effective_to' => 'b', 'closed_at' => null]], 'fr');
chk('fr org-wide localized', str_contains($h2, 'À l’échelle de l’organisation'));
chk('fr reviewed localized', str_contains($h2, 'Revue'));
chk('fr expired localized', str_contains($h2, 'Expirée'));

// unknown status falls back to raw
$hU = $render("$viewDir/break_glass_show.php", ['session' => ['id' => 's', 'permission_code' => 'p', 'subject_id' => 's', 'opened_by' => 'o', 'scope_group_id' => null, 'include_descendants' => 0, 'reason' => 'x', 'mfa_level' => 'strong', 'status' => 'suspended', 'review_state' => 'pending', 'effective_from' => 'a', 'effective_to' => 'b', 'closed_at' => null]], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'suspended'));

// not found (null)
$hn = $render("$viewDir/break_glass_show.php", ['session' => null], 'fr');
chk('fr not-found localized', str_contains($hn, 'Cette session est introuvable.'));

// ar — RTL + not found
$ha = $render("$viewDir/break_glass_show.php", ['session' => null], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar not-found translated', str_contains($ha, 'لم يُعثر على هذه الجلسة.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
