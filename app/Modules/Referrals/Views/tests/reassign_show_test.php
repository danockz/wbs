<?php

declare(strict_types=1);

/**
 * Referrals reassign_show view test — covers the bespoke detail page that
 * replaced respondAdmin's generic console for
 * SponsorReassignmentController::show. Asserts locale parity for the
 * `reassignShow.*` block, that the view is self-contained (own <html>,
 * _locale.php, dynamic lang/dir), references lang('Referrals.reassignShow.*')
 * with no hardcoded English, and renders translated copy with a not-found panel,
 * status/eligibility vocab (+ raw fallback), verbatim ids, before/after paths,
 * decision block and RTL.
 *
 *   php app/Modules/Referrals/Views/tests/reassign_show_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Referrals/Language';
$viewDir = $root . '/app/Modules/Referrals/Views';

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

echo "language parity for reassignShow block\n";
$en = require $langDir . '/en/Referrals.php';
chk('en has reassignShow block', isset($en['reassignShow']) && is_array($en['reassignShow']));
$enLeaves = $flatten(['reassignShow' => $en['reassignShow']]);
sort($enLeaves);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l      = require $langDir . "/$loc/Referrals.php";
    $leaves = $flatten(['reassignShow' => $l['reassignShow'] ?? []]);
    sort($leaves);
    $diff = array_merge(array_diff($enLeaves, $leaves), array_diff($leaves, $enLeaves));
    chk("$loc mirrors en reassignShow leaves (" . count($leaves) . ')', $diff === [], implode(',', $diff));
}

echo "static checks\n";
$src = (string) file_get_contents("$viewDir/reassign_show.php");
chk("calls lang('Referrals.reassignShow.", str_contains($src, "lang('Referrals.reassignShow."));
chk('no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('includes _locale.php', str_contains($src, '_locale.php'));
chk('emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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
            function getLocale() { return $GLOBALS['__refLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Referrals') {
            return $key;
        }
        $v = $GLOBALS['__refLang'];
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
    $GLOBALS['__refLoc']  = $loc;
    $GLOBALS['__refLang'] = require $langDir . "/$loc/Referrals.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — full approved request with paths + decision
$h = $render("$viewDir/reassign_show.php", [
    'requestId' => 'req9',
    'request'   => [
        'id' => 'req9', 'member_id' => 'MEM-1', 'current_sponsor_id' => 'OLD-1', 'new_sponsor_id' => 'NEW-1',
        'requested_by' => 'RB-1', 'approver_id' => 'AP-1', 'reason' => 'moved house', 'effective_at' => '2026-10-01',
        'status' => 'approved', 'eligibility_state' => 'ok',
        'before_path' => json_encode(['OLD-1', 'GP-1']), 'after_path' => json_encode(['NEW-1', 'GP-2']),
        'impact' => json_encode(['descendant_count' => 7]),
        'decided_by' => 'AP-1', 'decided_at' => '2026-09-30 12:00:00',
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Réaffectation de parrain'));
chk('fr status vocab (approved)', str_contains($h, 'Approuvée'));
chk('fr elig vocab (ok)', str_contains($h, 'Éligible'));
chk('fr ids verbatim', str_contains($h, 'MEM-1') && str_contains($h, 'NEW-1') && str_contains($h, 'OLD-1'));
chk('fr reason verbatim', str_contains($h, 'moved house'));
chk('fr before/after paths joined', str_contains($h, 'OLD-1 → GP-1') && str_contains($h, 'NEW-1 → GP-2'));
chk('fr descendants count', str_contains($h, '>7<'));
chk('fr decision block shown', str_contains($h, 'Décision') && str_contains($h, 'AP-1'));

// unknown status falls back to ucfirst(raw)
$h = $render("$viewDir/reassign_show.php", [
    'requestId' => 'r2',
    'request'   => ['id' => 'r2', 'member_id' => 'M', 'new_sponsor_id' => 'N', 'requested_by' => 'RB', 'status' => 'weird_status', 'eligibility_state' => 'blocked', 'reason' => 'x'],
], 'en');
chk('unknown status fallback (Weird_status)', str_contains($h, 'Weird_status'));
chk('elig blocked vocab', str_contains($h, 'Blocked'));
chk('no decision block when undecided', ! str_contains($h, '>Decision<'));

// not-found panel
$h = $render("$viewDir/reassign_show.php", ['requestId' => 'missing', 'request' => null], 'en');
chk('notFound panel shown', str_contains($h, 'could not be found'));
chk('notFound still shows request id', str_contains($h, 'missing'));

// ar RTL not-found
$h = $render("$viewDir/reassign_show.php", ['requestId' => 'm', 'request' => null], 'ar');
chk('ar rtl + notFound translated', str_contains($h, 'dir="rtl"') && str_contains($h, 'تعذّر العثور على طلب إعادة التعيين هذا.'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
