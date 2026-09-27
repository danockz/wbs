<?php

declare(strict_types=1);

/**
 * AccessControl access-request-detail view i18n + render smoke. Asserts the
 * accessReqShowView.* keys mirror across all locales, the SELF-CONTAINED
 * access_request_show view references lang('AccessControl.accessReqShowView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), verbatim subject/requester ids
 * and grant code, localized grant-type/status/conflict/review-action vocab with
 * raw-value fallback (lowercased), the review trail, and the not-found panel.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_access_request_show_view_test.php
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

echo "language file completeness (accessReqShowView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['accessReqShowView']);
chk('en has accessReqShowView.reviewAction.approve', in_array('reviewAction.approve', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['accessReqShowView'] ?? []);
    chk("$loc mirrors all en accessReqShowView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray accessReqShowView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/access_request_show.php");
chk("access_request_show.php calls lang('AccessControl.accessReqShowView.", str_contains($src, "lang('AccessControl.accessReqShowView."));
chk('access_request_show.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('access_request_show.php includes _locale.php', str_contains($src, '_locale.php'));
chk('access_request_show.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$request = [
    'id' => 'req1', 'grant_type' => 'role', 'role_id' => 'role-elder', 'subject_id' => 'usr-42', 'requested_by' => 'usr-7',
    'scope_group_id' => 'grp-3', 'include_descendants' => 1, 'duration_days' => 30, 'approver_id' => 'usr-9',
    'status' => 'approved', 'conflict_state' => 'flagged', 'reason' => 'Cover during leave', 'created_at' => '2026-09-01',
    'decided_by' => 'usr-9', 'decided_at' => '2026-09-02',
    'reviews' => [
        ['id' => 'rv1', 'action' => 'approve', 'actor_id' => 'usr-9', 'note' => 'Looks fine', 'created_at' => '2026-09-02'],
    ],
];

$h = $render("$viewDir/access_request_show.php", ['request' => $request], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr grant type localized', str_contains($h, 'Attribution de rôle'));
chk('fr subject + requester verbatim', str_contains($h, 'usr-42') && str_contains($h, 'usr-7'));
chk('fr scoped group verbatim', str_contains($h, 'grp-3'));
chk('fr status localized (Approuvée)', str_contains($h, 'Approuvée'));
chk('fr conflict localized (Signalé)', str_contains($h, 'Signalé'));
chk('fr reason shown', str_contains($h, 'Cover during leave'));
chk('fr decision block shown', str_contains($h, 'Décision') && str_contains($h, 'usr-9'));
chk('fr review action localized', str_contains($h, 'Approuvée') && str_contains($h, 'Looks fine'));

// org-wide + empty reviews
$h2 = $render("$viewDir/access_request_show.php", ['request' => ['id' => 'r', 'grant_type' => 'permission', 'permission_code' => 'events.manage', 'subject_id' => 's', 'requested_by' => 'b', 'scope_group_id' => null, 'include_descendants' => 0, 'status' => 'pending', 'conflict_state' => 'none', 'reason' => 'x', 'created_at' => 'now', 'reviews' => []]], 'fr');
chk('fr permission grant type localized', str_contains($h2, 'Attribution de permission'));
chk('fr org-wide localized', str_contains($h2, 'À l’échelle de l’organisation'));
chk('fr empty reviews localized', str_contains($h2, 'Aucune revue enregistrée pour l’instant.'));

// unknown status vocab falls back to raw
$hU = $render("$viewDir/access_request_show.php", ['request' => ['id' => 'r', 'grant_type' => 'role', 'role_id' => 'z', 'subject_id' => 's', 'requested_by' => 'b', 'scope_group_id' => null, 'include_descendants' => 0, 'status' => 'escalated', 'conflict_state' => 'none', 'reason' => 'x', 'created_at' => 'now', 'reviews' => []]], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'escalated'));

// not found (null)
$hn = $render("$viewDir/access_request_show.php", ['request' => null], 'fr');
chk('fr not-found localized', str_contains($hn, 'Cette demande d’accès est introuvable.'));

// ar — RTL + not found
$ha = $render("$viewDir/access_request_show.php", ['request' => null], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar not-found translated', str_contains($ha, 'لم يُعثر على طلب الوصول هذا.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
