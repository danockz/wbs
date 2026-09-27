<?php

declare(strict_types=1);

/**
 * Journey view i18n + render smoke. Asserts every locale's Journey.php mirrors
 * the English keys (incl. the nested phase.* enum), that the SELF-CONTAINED
 * pipeline view references lang('Journey.*'), includes _locale.php, emits a
 * dynamic <html lang dir>, and renders localized strings with correct direction
 * (RTL for Arabic), localized-with-fallback phase, verbatim stage data, and
 * PHP-computed counts/share.
 *
 *   php app/Modules/Journey/Views/tests/journey_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Journey/Language';
$viewDir = $root . '/app/Modules/Journey/Views';

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
$en     = require $langDir . '/en/Journey.php';
$enKeys = $flatten($en);
chk('en has nested phase.win', in_array('phase.win', $enKeys, true));
chk('en has heading + colStage', in_array('heading', $enKeys, true) && in_array('colStage', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Journey.php";
    if (! is_file($f)) {
        chk("$loc/Journey.php exists", false);
        continue;
    }
    $keys = $flatten(require $f);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/pipeline.php");
chk("pipeline.php calls lang('Journey.", str_contains($src, "lang('Journey."));
chk('pipeline.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('pipeline.php includes _locale.php', str_contains($src, '_locale.php'));
chk('pipeline.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Membership journey', 'Active members', 'Organization-wide'] as $needle) {
    chk("pipeline.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback & verbatim data\n";
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
            function getLocale() { return $GLOBALS['__jLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Journey') {
            return $key;
        }
        $v = $GLOBALS['__jLang'];
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
    $GLOBALS['__jLoc']  = $loc;
    $GLOBALS['__jLang'] = require $langDir . "/$loc/Journey.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — org-wide, phase labels, share %, verbatim stage code/name, dynamic dir
$h = $render("$viewDir/pipeline.php", [
    'group_id' => null,
    'total'    => 10,
    'triage'   => ['hot' => 4, 'warm' => 3, 'cold' => 3],
    'stages'   => [
        ['code' => 'first_timer', 'name' => 'First Timer', 'phase' => 'win', 'order' => 1, 'count' => 5, 'hot' => 4, 'warm' => 1, 'cold' => 0],
        ['code' => 'growing',     'name' => 'Growing',     'phase' => 'build', 'order' => 2, 'count' => 3, 'hot' => 0, 'warm' => 2, 'cold' => 1],
        ['code' => 'sent',        'name' => 'Sent Out',    'phase' => 'weird_phase', 'order' => 3, 'count' => 2, 'hot' => 0, 'warm' => 0, 'cold' => 2],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Pipeline du parcours du membre'));
chk('fr org-wide scope translated', str_contains($h, 'chelle de l') && str_contains($h, 'organisation'));
chk('fr total active translated', str_contains($h, 'Membres actifs'));
chk('fr phase win -> Gagner', str_contains($h, 'Gagner'));
chk('fr phase build -> Affermir', str_contains($h, 'Affermir'));
chk('fr unknown phase falls back (Weird_phase)', str_contains($h, 'Weird_phase'));
chk('fr stage name verbatim', str_contains($h, 'First Timer'));
chk('fr stage code verbatim', str_contains($h, 'first_timer'));
chk('fr total preserved', str_contains($h, '>10<'));
chk('fr share computed (50%)', str_contains($h, '50%'));
// triage board (fr)
chk('fr triage column header translated', str_contains($h, '>Tri<'));
chk('fr triage temperature labels translated', str_contains($h, 'Actif') && str_contains($h, 'Tiède') && str_contains($h, 'Froid'));
chk('fr triage summary sub translated', str_contains($h, 'froids') && str_contains($h, 'stagnent'));
chk('fr triage hot total rendered', str_contains($h, '>4<'));
chk('fr triage segmented bar has hot colour', str_contains($h, '#f87171'));
chk('fr triage segmented bar has cold colour', str_contains($h, '#60a5fa'));
// drill-down links into the per-stage roster
chk('fr stage name links to roster', str_contains($h, 'href="/journey/stages/first_timer/members"'));
chk('fr hot count links to temperature-filtered roster', str_contains($h, '/journey/stages/first_timer/members?temperature=hot'));
chk('fr cold count links to temperature-filtered roster', str_contains($h, '/journey/stages/growing/members?temperature=cold'));

// group-scoped interpolation
$hg = $render("$viewDir/pipeline.php", ['group_id' => 'grp-7', 'total' => 0, 'stages' => [['code' => 'a', 'name' => 'A', 'phase' => 'win', 'order' => 1, 'count' => 0]]], 'fr');
chk('fr group-scoped label interpolated', str_contains($hg, 'Groupe grp-7'));
chk('fr no-members empty state translated', str_contains($hg, 'Aucun membre actif') && str_contains($hg, 'tape pour l'));

// ar — RTL, empty stages
$ha = $render("$viewDir/pipeline.php", ['group_id' => null, 'total' => 0, 'stages' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'مسار رحلة العضو'));
chk('ar empty stages translated', str_contains($ha, 'لم تُحدَّد أي مراحل للرحلة بعد.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
