<?php

declare(strict_types=1);

/**
 * Groups i18n test — asserts every locale's Groups.php mirrors the English keys
 * (incl. nested nav/detail/directory/page/join/joinDone/notFound groups); that
 * the views reference lang('Groups.*') and dropped hardcoded lang="en" +
 * English copy; and that the SELF-CONTAINED public pages render translated
 * strings with correct <html lang dir> (incl. RTL for Arabic) end-to-end.
 *
 * The public pages own their <html> (they don't extend layouts/app), so they
 * include _locale.php — this test drives that path via a stubbed service()/config.
 *
 *   php app/Modules/Groups/Views/tests/groups_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';

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
$en     = require $langDir . '/en/Groups.php';
$enKeys = $flatten($en);
chk('en has nested nav.groups', in_array('nav.groups', $enKeys, true));
chk('en has nested join.heading', in_array('join.heading', $enKeys, true));
chk('en has nested joinDone.bodyWelcome', in_array('joinDone.bodyWelcome', $enKeys, true));
chk('en directory.summary keeps {0}..{3}', str_contains((string) $en['directory']['summary'], '{0}') && str_contains((string) $en['directory']['summary'], '{3}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Groups.php";
    if (! is_file($f)) {
        chk("$loc/Groups.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc join.metaTitle keeps {0}", str_contains((string) ($arr['join']['metaTitle'] ?? ''), '{0}'));
}

echo "views localized (no hardcoded English / lang=en)\n";
foreach (['_public_nav', 'detail', 'public_directory', 'public_join', 'public_join_done', 'public_not_found', 'public_page'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Groups.", str_contains($src, "lang('Groups."));
    chk("$v.php has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
}
foreach (['public_directory', 'public_join', 'public_join_done', 'public_not_found', 'public_page'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
    chk("$v.php includes _locale.php", str_contains($src, '_locale.php'));
}

echo "render smoke (fr + ar) — self-contained public pages\n";
// Framework stubs so _locale.php resolves a locale + rtl set.
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
            function getLocale() { return $GLOBALS['__grpLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Groups') {
            return $key;
        }
        $v = $GLOBALS['__grpLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}
// _public_nav is pulled in via view(); stub it to a no-op marker.
if (! function_exists('view')) {
    function view(string $name, array $data = [])
    {
        return '<!--nav-->';
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__grpLoc']  = $loc;
    $GLOBALS['__grpLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr directory
$h = $render("$viewDir/public_directory.php", [
    'sections' => [
        ['key' => 'co-1', 'label' => 'Ghana', 'level' => 'country', 'count' => 2, 'children' => [
            ['key' => 'st-1-9', 'label' => 'Greater Accra', 'level' => 'state', 'count' => 2, 'children' => [
                ['key' => 'ci-1-9-3', 'label' => 'Accra', 'level' => 'city', 'count' => 2, 'groups' => [
                    ['slug' => 'a', 'name' => 'Alpha', 'hero_theme' => 'aurora'],
                    ['slug' => 'b', 'name' => 'Beta', 'hero_theme' => 'forest'],
                ]],
            ]],
        ]],
    ],
    'viewer' => null, 'csrf' => null,
], 'fr');
chk('fr directory lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr directory heading translated', str_contains($h, 'Groupes par localité'));
chk('fr directory summary interpolated + pluralized', str_contains($h, '2 groupes dans 1 localité'));

// ar directory (RTL) + empty state
$h = $render("$viewDir/public_directory.php", ['sections' => [], 'viewer' => null, 'csrf' => null], 'ar');
chk('ar directory lang=ar dir=rtl', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar directory empty state translated', str_contains($h, 'لم يتم نشر أي مجموعة بعد'));

// fr join form — metaTitle + sponsor interpolation + labels + open policy
$h = $render("$viewDir/public_join.php", [
    'data'   => ['group' => ['slug' => 'alpha', 'name' => 'Alpha', 'join_policy' => 'open', 'hero_theme' => 'aurora'], 'leader' => ['display_name' => 'Ama']],
    'errors' => [], 'old' => [], 'viewer' => null, 'csrf' => 'x',
], 'fr');
chk('fr join lang=fr', str_contains($h, 'lang="fr"'));
chk('fr join title interpolates group name', str_contains($h, 'Rejoindre Alpha'));
chk('fr join sponsor interpolated', str_contains($h, 'Ama sera votre parrain'));
chk('fr join name label translated', str_contains($h, 'Votre nom'));
chk('fr join open policy translated', str_contains($h, 'ajouté immédiatement'));

// ar join_done pending (RTL)
$h = $render("$viewDir/public_join_done.php", ['slug' => 'alpha', 'pending' => true, 'viewer' => null, 'csrf' => null], 'ar');
chk('ar join_done dir=rtl', str_contains($h, 'dir="rtl"'));
chk('ar join_done pending heading translated', str_contains($h, 'تم استلام الطلب'));

// es join_done welcome + setup url
$h = $render("$viewDir/public_join_done.php", ['slug' => 'alpha', 'pending' => false, 'setup_url' => '/set?t=1', 'viewer' => null, 'csrf' => null], 'es');
chk('es join_done welcome heading translated', str_contains($h, '¡Ya estás dentro!'));
chk('es join_done set-password button translated', str_contains($h, 'Establecer mi contraseña'));

// fr not_found — slug interpolation
$h = $render("$viewDir/public_not_found.php", ['slug' => 'ghost', 'viewer' => null, 'csrf' => null], 'fr');
chk('fr not_found body interpolates slug', str_contains($h, 'ghost') && str_contains($h, 'aucun groupe public'));

// fr public_page — hero chips, CTA, sections, members interpolation
$h = $render("$viewDir/public_page.php", [
    'data' => [
        'group' => ['slug' => 'alpha', 'name' => 'Alpha', 'hero_theme' => 'aurora', 'public_join' => true, 'description' => 'Hi', 'contact_email' => 'a@b.com'],
        'leader' => ['display_name' => 'Ama'],
        'member_count' => 42,
        'upcoming_events' => [],
        'causes' => [],
        'ancestors' => [],
    ],
    'viewer' => null, 'csrf' => null,
], 'fr');
chk('fr page lang=fr', str_contains($h, 'lang="fr"'));
chk('fr page members chip interpolated', str_contains($h, '42 membres'));
chk('fr page led-by interpolated', str_contains($h, 'Dirigé par Ama'));
chk('fr page join CTA translated', str_contains($h, 'Rejoindre ce groupe'));
chk('fr page about heading translated', str_contains($h, 'À propos'));
chk('fr page no-events translated', str_contains($h, 'Aucun événement programmé'));
chk('fr page get-in-touch translated', str_contains($h, 'Nous contacter'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
