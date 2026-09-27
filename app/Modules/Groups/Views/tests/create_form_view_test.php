<?php

declare(strict_types=1);

/**
 * Create-group FORM view i18n + render smoke (dead-link fix: GET /groups/create).
 * Asserts Groups.createForm.* key parity, self-contained locale wiring, a hidden
 * _csrf field bound to $csrf, a POST to the groups endpoint, the full hierarchy
 * type dropdown (National → … → Cell), sticky $old values, RTL for Arabic, and an
 * $error banner.
 *
 *   php app/Modules/Groups/Views/tests/create_form_view_test.php
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
$enKeys = $flatten($en['createForm']);
chk('en has createForm.type.senior_cell', in_array('type.senior_cell', $enKeys, true));
chk('en has createForm.type.fellowship', in_array('type.fellowship', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Groups.php";
    $keys = $flatten($m['createForm'] ?? []);
    chk("$loc mirrors all en createForm keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray createForm keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/create.php");
chk("create.php calls lang('Groups.createForm.", str_contains($src, "lang('Groups.createForm."));
chk('create.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('create.php includes _locale.php', str_contains($src, '_locale.php'));
chk('create.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('create.php binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));

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
            function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
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
if (! function_exists('base_url')) {
    function base_url($p = '')
    {
        return 'https://public.test/' . ltrim((string) $p, '/');
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Groups') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
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
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/create.php", ['csrf' => 'TOK', 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Créer un groupe'));
chk('fr csrf token rendered', str_contains($h, 'value="TOK"'));
chk('fr posts to groups endpoint', str_contains($h, 'action="https://public.test/groups"'));
chk('fr type option Local Assembly localized', str_contains($h, 'Assemblée locale'));
chk('fr type option Senior Cell localized', str_contains($h, 'Cellule principale'));
chk('fr submit label translated', str_contains($h, 'Créer le groupe'));

$h2 = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => 'Name required', 'old' => ['name' => 'Accra Central', 'type' => 'fellowship']], 'fr');
chk('fr error banner shown', str_contains($h2, 'Name required'));
chk('fr sticky name value', str_contains($h2, 'value="Accra Central"'));
chk('fr sticky type selected', (bool) preg_match('/value="fellowship" selected/', $h2));

$ha = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'إنشاء مجموعة'));

// parent_id is an entity reference → render an org groups picker (name→id) when a
// groups list is supplied, with hierarchy indentation, a "top level" option, and
// preservation of a selected/stale parent. Falls back to bounded text otherwise.
echo "parent_id entity-reference picker\n";
$grpList = [
    ['id' => 'g-nat', 'name' => 'Ghana National', 'type' => 'national', 'depth' => 0],
    ['id' => 'g-reg', 'name' => 'Greater Accra', 'type' => 'region', 'depth' => 1],
];
$hp = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => ['parent_id' => 'g-reg'], 'groups' => $grpList], 'fr');
chk('parent picker: renders <select id="parent_id">', str_contains($hp, '<select id="parent_id" name="parent_id">'));
chk('parent picker: option value = group id + name', str_contains($hp, 'value="g-nat"') && str_contains($hp, 'Ghana National'));
chk('parent picker: current parent preselected', (bool) preg_match('/value="g-reg" selected/', $hp));
chk('parent picker: top-level none option present', str_contains($hp, lang('Groups.createForm.parentNone')));
chk('parent picker: type label appended', str_contains($hp, 'Région') || str_contains($hp, 'National'));
$hpStale = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => ['parent_id' => 'archived-g'], 'groups' => $grpList], 'fr');
chk('parent picker: stale parent preserved as selected option', (bool) preg_match('/value="archived-g" selected/', $hpStale));
$hpNo = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => ['parent_id' => 'g9'], 'groups' => []], 'fr');
chk('parent picker: bounded text fallback when no groups', str_contains($hpNo, 'id="parent_id" name="parent_id" maxlength="64"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
