<?php

declare(strict_types=1);

/**
 * Create-event FORM view i18n + render smoke (dead-link fix: GET /events/create).
 * Asserts Events.createForm.* key parity across locales, that the SELF-CONTAINED
 * form references lang(), includes _locale.php, emits a dynamic <html lang dir>,
 * carries a hidden _csrf field bound to $csrf, posts to the events endpoint, and
 * renders localized labels with correct direction (RTL for Arabic), a localized
 * mode dropdown, sticky $old values, and an $error banner.
 *
 *   php app/Modules/Events/Views/tests/create_form_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

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
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['createForm']);
chk('en has createForm.mode.physical', in_array('mode.physical', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $keys = $flatten($m['createForm'] ?? []);
    chk("$loc mirrors all en createForm keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray createForm keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + form/csrf wiring\n";
$src = (string) file_get_contents("$viewDir/create.php");
chk("create.php calls lang('Events.createForm.", str_contains($src, "lang('Events.createForm."));
chk('create.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('create.php includes _locale.php', str_contains($src, '_locale.php'));
chk('create.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
chk('create.php has method="post"', str_contains($src, 'method="post"'));
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
            function getLocale() { return $GLOBALS['__eLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Events') {
            return $key;
        }
        $v = $GLOBALS['__eLang'];
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
    $GLOBALS['__eLoc']  = $loc;
    $GLOBALS['__eLang'] = require $langDir . "/$loc/Events.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/create.php", ['csrf' => 'TOKEN123', 'error' => '', 'old' => []], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Créer un événement'));
chk('fr csrf token rendered', str_contains($h, 'value="TOKEN123"'));
chk('fr posts to events endpoint', str_contains($h, 'action="https://public.test/events"'));
chk('fr mode option localized (Hybride)', str_contains($h, 'Hybride'));
chk('fr submit label translated', str_contains($h, 'Créer l’événement'));

// sticky old values + error banner
$h2 = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => 'Title required', 'old' => ['title' => 'My Event', 'mode' => 'online']], 'fr');
chk('fr error banner shown', str_contains($h2, 'Title required'));
chk('fr sticky title value', str_contains($h2, 'value="My Event"'));
chk('fr sticky mode selected', (bool) preg_match('/value="online" selected/', $h2));

$ha = $render("$viewDir/create.php", ['csrf' => 'T', 'error' => '', 'old' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'إنشاء فعالية'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
