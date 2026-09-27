<?php

declare(strict_types=1);

/**
 * Create-course FORM view i18n + render smoke (dead-link fix: GET /courses/create).
 * Asserts Courses.createForm.* key parity, self-contained locale wiring, a hidden
 * _csrf field bound to $csrf, a POST to the courses endpoint, a localized delivery
 * dropdown, sticky $old values, RTL for Arabic, and an $error banner.
 *
 *   php app/Modules/Courses/Views/tests/create_form_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Courses/Language';
$viewDir = $root . '/app/Modules/Courses/Views';

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
$en     = require $langDir . '/en/Courses.php';
$enKeys = $flatten($en['createForm']);
chk('en has createForm.delivery.self_paced', in_array('delivery.self_paced', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Courses.php";
    $keys = $flatten($m['createForm'] ?? []);
    chk("$loc mirrors all en createForm keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray createForm keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/create.php");
chk("create.php calls lang('Courses.createForm.", str_contains($src, "lang('Courses.createForm."));
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
            function getLocale() { return $GLOBALS['__cLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Courses') {
            return $key;
        }
        $v = $GLOBALS['__cLang'];
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
    $GLOBALS['__cLoc']  = $loc;
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Courses.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/create.php", ['csrf' => 'TOK', 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Créer un cours'));
chk('fr csrf token rendered', str_contains($h, 'value="TOK"'));
chk('fr posts to courses endpoint', str_contains($h, 'action="https://public.test/courses"'));
chk('fr delivery option localized (Cohorte)', str_contains($h, 'Cohorte'));
chk('fr submit label translated', str_contains($h, 'Créer le cours'));

$h2 = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => 'Title required', 'old' => ['title' => 'Faith 101', 'delivery_mode' => 'blended']], 'fr');
chk('fr error banner shown', str_contains($h2, 'Title required'));
chk('fr sticky title value', str_contains($h2, 'value="Faith 101"'));
chk('fr sticky delivery selected', (bool) preg_match('/value="blended" selected/', $h2));

$ha = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'إنشاء دورة'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
