<?php

declare(strict_types=1);

/**
 * Identity admin READ views i18n + render smoke — the five browser faces that
 * replaced the generic admin console (respondAdmin) on:
 *   account_history    (GET identity/accounts/{id}/history)
 *   merges_pending     (GET identity/merges/pending)
 *   merge_show         (GET identity/merges/{id})
 *   identity_policies  (GET identity/policies)
 *   policy_resolve     (GET identity/policies/{cc}/resolve)
 *
 * Asserts per block: Identity.<block>.* key parity across all 6 locales,
 * self-contained locale wiring (includes _locale.php, dynamic <html lang dir>,
 * lang('Identity.<block>.'), no hardcoded lang="en"), and a render smoke covering
 * populated + empty + not-found states, {0} count interpolation, RTL for Arabic,
 * and vocabulary raw-value fallback.
 *
 *   php app/Modules/Identity/Views/tests/admin_views_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Identity/Language';
$viewDir = $root . '/app/Modules/Identity/Views';

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

$blocks = [
    'accountHistory' => 'account_history.php',
    'mergesPending'  => 'merges_pending.php',
    'mergeShow'      => 'merge_show.php',
    'policies'       => 'identity_policies.php',
    'policyResolve'  => 'policy_resolve.php',
];

echo "language file completeness (parity across 6 locales)\n";
$en = require $langDir . '/en/Identity.php';
foreach ($blocks as $block => $_f) {
    $enKeys = $flatten($en[$block] ?? []);
    chk("en defines Identity.$block.*", $enKeys !== []);
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $m    = require $langDir . "/$loc/Identity.php";
        $keys = $flatten($m[$block] ?? []);
        chk("$loc mirrors all en $block keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
        chk("$loc has no stray $block keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

echo "view localized + self-contained wiring\n";
foreach ($blocks as $block => $file) {
    $src = (string) file_get_contents("$viewDir/$file");
    chk("$file calls lang('Identity.$block.", str_contains($src, "lang('Identity.$block."));
    chk("$file has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$file includes _locale.php", str_contains($src, '_locale.php'));
    chk("$file emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
}

echo "render smoke\n";
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
            function getLocale() { return $GLOBALS['__idloc'] ?? 'en'; }
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
$GLOBALS['__idL'] = [];
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Identity') {
            return $key;
        }
        $v = $GLOBALS['__idL'];
        foreach ($p as $s) {
            if (! is_array($v) || ! array_key_exists($s, $v)) {
                return $key;
            }
            $v = $v[$s];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__idloc'] = $loc;
    $GLOBALS['__idL']   = require $langDir . "/$loc/Identity.php";
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};

// account_history
$h = $render('account_history.php', ['transitions' => [
    ['from_status' => 'pending', 'to_status' => 'active', 'reason' => 'Verified', 'actor_id' => 'a1', 'created_at' => '2026-09-01'],
    ['from_status' => 'active', 'to_status' => 'suspended', 'reason' => 'Policy', 'created_at' => '2026-09-05'],
], 'userId' => 'u-9'], 'fr');
chk('account_history fr lang/dir', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('account_history shows user id', str_contains($h, 'u-9'));
chk('account_history localizes status active (fr)', str_contains($h, 'Actif'));
chk('account_history plural count interpolated (fr)', str_contains($h, '2 transitions'));
$h = $render('account_history.php', ['transitions' => [['to_status' => 'weirdstate', 'created_at' => 'x']], 'userId' => 'u'], 'en');
chk('account_history vocab raw-value fallback', str_contains($h, 'weirdstate'));
$h = $render('account_history.php', ['transitions' => [], 'userId' => 'u'], 'ar');
chk('account_history empty + RTL (ar)', str_contains($h, 'dir="rtl"') && str_contains($h, 'لم تُسجَّل'));

// merges_pending
$h = $render('merges_pending.php', ['merges' => [
    ['primary_user_id' => 'p1', 'duplicate_user_id' => 'd1', 'reason' => 'Same person', 'requested_by' => 'r1', 'created_at' => '2026-09-02'],
]], 'es');
chk('merges_pending es lang', str_contains($h, 'lang="es"'));
chk('merges_pending shows primary+duplicate', str_contains($h, 'p1') && str_contains($h, 'd1'));
chk('merges_pending singular count interpolated (es)', str_contains($h, '1 solicitud por revisar'));
$h = $render('merges_pending.php', ['merges' => []], 'en');
chk('merges_pending empty', str_contains($h, 'No account merges'));

// merge_show
$h = $render('merge_show.php', ['merge' => [
    'primary_user_id' => 'p1', 'duplicate_user_id' => 'd1', 'status' => 'pending', 'reason' => 'Dup',
    'requested_by' => 'r1', 'reviews' => [['action' => 'approve', 'actor_id' => 'rev1', 'created_at' => '2026-09-03']],
]], 'pt');
chk('merge_show pt lang', str_contains($h, 'lang="pt"'));
chk('merge_show shows accounts + status', str_contains($h, 'p1') && str_contains($h, 'd1'));
chk('merge_show localizes status pending (pt)', str_contains($h, 'Pendente'));
chk('merge_show shows review trail', str_contains($h, 'rev1'));
$h = $render('merge_show.php', ['merge' => null], 'ar');
chk('merge_show not-found + RTL (ar)', str_contains($h, 'dir="rtl"') && str_contains($h, 'تعذّر العثور على طلب الدمج'));

// identity_policies
$h = $render('identity_policies.php', ['policies' => [
    ['country_code' => '*', 'min_age' => 13, 'phone_default_region' => 'GH', 'require_email_unique' => true, 'require_phone_unique' => false, 'allow_minor' => false],
    ['country_code' => 'GB', 'min_age' => 16, 'phone_default_region' => 'GB', 'require_email_unique' => true, 'require_phone_unique' => true, 'allow_minor' => true],
]], 'en');
chk('identity_policies shows country codes', str_contains($h, 'GB'));
chk('identity_policies marks org default', str_contains($h, 'Org default'));
chk('identity_policies plural count interpolated', str_contains($h, '2 policies'));
chk('identity_policies yes/no localized', str_contains($h, 'Yes') && str_contains($h, 'No'));
$h = $render('identity_policies.php', ['policies' => []], 'fr');
chk('identity_policies empty (fr)', str_contains($h, 'Aucune politique'));

// policy_resolve
$h = $render('policy_resolve.php', ['effective' => [
    'country_code' => 'GB', 'min_age' => 16, 'phone_default_region' => 'GB',
    'require_email_unique' => true, 'require_phone_unique' => true, 'allow_minor' => false,
], 'countryCode' => 'GB'], 'fr');
chk('policy_resolve fr lang', str_contains($h, 'lang="fr"'));
chk('policy_resolve shows effective country', str_contains($h, 'GB'));
chk('policy_resolve min age shown', str_contains($h, '16'));
chk('policy_resolve fallback note (fr)', str_contains($h, 'repli'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
