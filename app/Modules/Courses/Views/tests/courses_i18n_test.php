<?php

declare(strict_types=1);

/**
 * Courses i18n test — asserts every locale's Courses.php mirrors the English keys
 * (incl. nested status/drip enum groups), that the three views reference
 * lang('Courses.*') rather than hardcoded English, and that views render
 * translated strings with graceful fallback for unknown status/drip values and
 * preserve numeric output.
 *
 * These views extend the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation + interpolation.
 *
 *   php app/Modules/Courses/Views/tests/courses_i18n_test.php
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
$enKeys = $flatten($en);
chk('en has nested status.published', in_array('status.published', $enKeys, true));
chk('en has nested drip.immediate', in_array('drip.immediate', $enKeys, true));
chk('en courseMeta keeps {0}', str_contains((string) $en['courseMeta'], '{0}'));
chk('en unlocks keeps {0}', str_contains((string) $en['unlocks'], '{0}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Courses.php";
    if (! is_file($f)) {
        chk("$loc/Courses.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc courseMeta keeps {0}", str_contains((string) ($arr['courseMeta'] ?? ''), '{0}'));
}

echo "views use lang(), not hardcoded English\n";
foreach (['index', 'overview', 'syllabus'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Courses.", str_contains($src, "lang('Courses."));
}
$bare = [
    ['index', '<h1>Courses</h1>'],
    ['syllabus', '<h1>Course syllabus</h1>'],
];
foreach ($bare as [$v, $needle]) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php no bare $needle", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback\n";
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
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Courses.php";
    $renderer           = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data) {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

// fr index — heading, plural, status label + unknown status fallback
$h = $render("$viewDir/index.php", [
    'result' => ['courses' => [
        ['id' => 'c1', 'title' => 'Discipleship', 'status' => 'published', 'lesson_count' => 3, 'category' => 'Core'],
        ['id' => 'c2', 'title' => 'Odd', 'status' => 'weird_status', 'lesson_count' => 1],
    ]],
], 'fr');
chk('fr index heading translated', str_contains($h, '<h1>Cours</h1>'));
chk('fr index status published -> publié', str_contains($h, 'publié'));
chk('fr index unknown status falls back', str_contains($h, 'weird_status'));
chk('fr index lesson singular', (bool) preg_match('/1 leçon\b/u', $h) && ! str_contains($h, '1 leçons'));
chk('fr index lesson plural', str_contains($h, '3 leçons'));

// ar overview — RTL locale strings + drip fallback + numbers preserved
$h = $render("$viewDir/overview.php", [
    'result' => [
        'title' => 'التلمذة', 'status' => 'draft', 'lesson_count' => 5, 'required_count' => 2, 'course_id' => 'X1',
        'description' => 'وصف', 'lessons' => [
            ['position' => 1, 'title' => 'الدرس الأول', 'required' => true, 'drip' => 'immediate', 'has_content' => true],
            ['position' => 2, 'title' => 'الثاني', 'drip' => 'custom_drip', 'has_content' => false],
        ],
    ],
], 'ar');
chk('ar overview status draft translated', str_contains($h, 'مسودة'));
chk('ar overview lessons label translated', str_contains($h, 'الدروس'));
chk('ar overview required tag translated', str_contains($h, 'مطلوب'));
chk('ar overview drip immediate translated', str_contains($h, 'فوري'));
chk('ar overview unknown drip falls back', str_contains($h, 'custom_drip'));
chk('ar overview content-attached translated', str_contains($h, 'المحتوى مرفق'));
chk('ar overview course meta interpolated', str_contains($h, 'الدورة X1'));
chk('ar overview numbers preserved', str_contains($h, '>5<') && str_contains($h, '>2<'));

// fr syllabus — locked unlock interpolation + available + content label
$h = $render("$viewDir/syllabus.php", [
    'result' => ['course_id' => 'X1', 'lessons' => [
        ['position' => 1, 'title' => 'Intro', 'locked' => false, 'content_ref' => 'ref-1'],
        ['position' => 2, 'title' => 'Deux', 'locked' => true, 'unlock_at' => '2026-10-01'],
    ]],
], 'fr');
chk('fr syllabus heading translated', str_contains($h, 'Programme du cours'));
chk('fr syllabus available translated', str_contains($h, 'disponible'));
chk('fr syllabus content label interpolated', str_contains($h, 'Contenu : ref-1'));
chk('fr syllabus unlock interpolated', str_contains($h, 'déverrouillage 2026-10-01'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
