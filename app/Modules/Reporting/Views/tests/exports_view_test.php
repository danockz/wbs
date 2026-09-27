<?php

declare(strict_types=1);

/**
 * Export-history view i18n + render smoke (dead-link fix: GET /reports/export).
 * Asserts Reporting.exports.* key parity across locales, that the SELF-CONTAINED
 * view references lang(), includes _locale.php, emits a dynamic <html lang dir>,
 * and renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback status, PHP singular/plural count, verbatim data, a
 * download link only for ready exports, and the no-rows / no-expiry fallbacks.
 *
 *   php app/Modules/Reporting/Views/tests/exports_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Reporting/Language';
$viewDir = $root . '/app/Modules/Reporting/Views';

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
$en     = require $langDir . '/en/Reporting.php';
$enKeys = $flatten($en['exports']);
chk('en has exports.status.ready', in_array('status.ready', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Reporting.php";
    $keys = $flatten($m['exports'] ?? []);
    chk("$loc mirrors all en exports keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray exports keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/exports.php");
chk("exports.php calls lang('Reporting.exports.", str_contains($src, "lang('Reporting.exports."));
chk('exports.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('exports.php includes _locale.php', str_contains($src, '_locale.php'));
chk('exports.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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
            function getLocale() { return $GLOBALS['__rLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Reporting') {
            return $key;
        }
        $v = $GLOBALS['__rLang'];
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
    $GLOBALS['__rLoc']  = $loc;
    $GLOBALS['__rLang'] = require $langDir . "/$loc/Reporting.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/exports.php", [
    'exports' => [
        ['id' => 'e1', 'report_key' => 'funnel_q2', 'format' => 'csv', 'status' => 'ready', 'row_count' => 4200, 'created_at' => '2026-06-01 08:00:00', 'expires_at' => '2026-06-02 08:00:00'],
        ['id' => 'e2', 'report_key' => 'giving_ytd', 'format' => 'xlsx', 'status' => 'weirdstate', 'row_count' => null, 'created_at' => '2026-06-03 08:00:00', 'expires_at' => null],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Historique des exports'));
chk('fr plural count interpolated', str_contains($h, '2 exports'));
chk('fr status ready translated', str_contains($h, 'Prêt'));
chk('fr unknown status falls back (Weirdstate)', str_contains($h, 'Weirdstate'));
chk('fr report key verbatim', str_contains($h, 'funnel_q2'));
chk('fr format shown uppercase', str_contains($h, 'CSV') && str_contains($h, 'XLSX'));
chk('fr row count verbatim', str_contains($h, '4200'));
chk('fr download link only for ready', substr_count($h, 'reports/exports/e1/download') === 1 && ! str_contains($h, 'e2/download'));
chk('fr download label translated', str_contains($h, 'Télécharger'));
chk('fr no-rows fallback', str_contains($h, '—'));

$h1 = $render("$viewDir/exports.php", ['exports' => [['id' => 'x', 'report_key' => 'r', 'format' => 'csv', 'status' => 'queued', 'row_count' => 1, 'created_at' => '2026-01-01 00:00:00', 'expires_at' => null]]], 'fr');
chk('fr singular count', str_contains($h1, '1 export') && ! str_contains($h1, '1 exports'));

$ha = $render("$viewDir/exports.php", ['exports' => [], 'csrf' => 'RX1'], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'سجل التصدير'));
chk('ar empty translated', str_contains($ha, 'لا توجد عمليات تصدير مطلوبة بعد.'));

// ── write-UI: request-export form + csrf + PRG + route ───────────────────────
echo "write-UI: request form + csrf + PRG + route\n";
$hw = $render("$viewDir/exports.php", ['exports' => [], 'csrf' => 'RXCSRF'], 'fr');
chk('request form posts to /reports/exports', str_contains($hw, 'action="/reports/exports"') && str_contains($hw, 'method="post"'));
chk('request form has report_key + format', str_contains($hw, 'name="report_key"') && str_contains($hw, 'name="format"'));
chk('request form carries _csrf bound to $csrf', str_contains($hw, 'name="_csrf" value="RXCSRF"'));
chk('request form offers csv + xlsx', str_contains($hw, '>CSV<') && str_contains($hw, '>XLSX<'));
chk('request button localized (fr)', str_contains($hw, 'Demander l’export'));
chk('report label localized (fr funnel)', str_contains($hw, 'Entonnoir'));

// flash
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__rFlash'][$k] ?? null; }
}
$GLOBALS['__rFlash'] = ['success' => 'Export demandé.'];
$hs = $render("$viewDir/exports.php", ['exports' => [], 'csrf' => 'RX1'], 'fr');
chk('success flash rendered', str_contains($hs, 'Export demandé.') && str_contains($hs, 'flash ok'));
$GLOBALS['__rFlash'] = ['error' => 'Format invalide.'];
$he = $render("$viewDir/exports.php", ['exports' => [], 'csrf' => 'RX1'], 'fr');
chk('error flash rendered', str_contains($he, 'Format invalide.') && str_contains($he, 'flash err'));
$GLOBALS['__rFlash'] = [];

echo "controller: PRG + no-JSON-to-browser\n";
$ctrl = (string) file_get_contents("$root/app/Modules/Reporting/Controllers/DashboardController.php");
chk('requestExport PRG keeps JSON for API', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, 'respondJson($result)'));
chk('requestExport redirects to /reports/export', str_contains($ctrl, "'/reports/export'"));
chk('requestExport flashes requestedFlash', str_contains($ctrl, 'requestedFlash'));
chk('exports() passes csrf to view', str_contains($ctrl, "'csrf'    => (string) (\$this->request->wbsCsrf"));

echo "route: webcsrf-guarded (ratelimit preserved)\n";
$routes = (string) file_get_contents("$root/app/Config/Routes.php");
if (preg_match('#^.*DashboardController::requestExport.*$#m', $routes, $mm)) {
    chk('requestExport route present', true);
    chk('requestExport webcsrf-guarded', str_contains($mm[0], 'webcsrf'));
    chk('requestExport ratelimit preserved', str_contains($mm[0], 'ratelimit:report.generate'));
} else {
    chk('requestExport route present', false);
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
