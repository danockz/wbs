<?php

declare(strict_types=1);

/**
 * Group move/restructure launcher view i18n + render smoke (dead-link fix: GET
 * /groups/move). Asserts Groups.move.* key parity across locales, and that the
 * SELF-CONTAINED page references lang(), includes _locale.php, emits a dynamic
 * <html lang dir>, carries a hidden _csrf bound to $csrf, POSTs to /groups/move,
 * renders the group + new-parent pickers from $groups (indented by depth), honors
 * sticky $old + $error, shows the empty state, and is RTL for Arabic.
 *
 *   php app/Modules/Groups/Views/tests/move_form_view_test.php
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
$enKeys = $flatten($en['move']);
chk('en has move.parentPh', in_array('parentPh', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Groups.php";
    $keys = $flatten($m['move'] ?? []);
    chk("$loc mirrors all en move keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray move keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/move.php");
chk("move.php calls lang('Groups.move.", str_contains($src, "lang('Groups.move."));
chk('move.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('move.php includes _locale.php', str_contains($src, '_locale.php'));
chk('move.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('move.php has method="post"', str_contains($src, 'method="post"'));
chk('move.php binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));

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
            public function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
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

$groups = [
    ['id' => 'g-root', 'name' => 'National', 'depth' => 1, 'path' => '/g-root/', 'parent_id' => null],
    ['id' => 'g-reg', 'name' => 'Greater Accra', 'depth' => 2, 'path' => '/g-root/g-reg/', 'parent_id' => 'g-root'],
    ['id' => 'g-cell', 'name' => 'Osu Cell', 'depth' => 3, 'path' => '/g-root/g-reg/g-cell/', 'parent_id' => 'g-reg'],
];

$h = $render("$viewDir/move.php", ['csrf' => 'MTOK', 'groups' => $groups, 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Déplacer un groupe'));
chk('fr csrf token rendered', str_contains($h, 'value="MTOK"'));
chk('fr posts to move endpoint', str_contains($h, 'action="https://public.test/groups/move"'));
chk('fr renders group options', str_contains($h, 'value="g-cell"') && str_contains($h, 'Osu Cell'));
chk('fr indents by depth', str_contains($h, '— — Osu Cell'));
chk('fr submit label translated', str_contains($h, 'Déplacer le groupe'));

$h2 = $render("$viewDir/move.php", ['csrf' => 'T', 'groups' => $groups, 'error' => 'Cycle detected', 'old' => ['group_id' => 'g-reg', 'new_parent_id' => 'g-root']], 'fr');
chk('fr error banner shown', str_contains($h2, 'Cycle detected'));
chk('fr sticky group selected', (bool) preg_match('/value="g-reg" selected/', $h2));
chk('fr sticky new parent selected', (bool) preg_match('/value="g-root" selected/', $h2));

$he = $render("$viewDir/move.php", ['csrf' => 'T', 'groups' => [], 'error' => '', 'old' => []], 'fr');
chk('empty state shown when no groups', str_contains($he, 'Aucun groupe disponible'));
chk('no form when no groups', ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $he), 'method="post"'));

$ha = $render("$viewDir/move.php", ['csrf' => 'T', 'groups' => $groups, 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'نقل مجموعة'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
