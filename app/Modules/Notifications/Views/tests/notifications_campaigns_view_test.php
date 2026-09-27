<?php

declare(strict_types=1);

/**
 * Notifications campaigns-view i18n + render smoke. Asserts Notifications.* key
 * parity across locales, that the SELF-CONTAINED campaigns view references
 * lang('Notifications.*'), includes _locale.php, emits a dynamic <html lang dir>,
 * and renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback status + priority, PHP singular/plural count, humanized
 * channel, verbatim data, and the no-audience fallback.
 *
 *   php app/Modules/Notifications/Views/tests/notifications_campaigns_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Notifications/Language';
$viewDir = $root . '/app/Modules/Notifications/Views';

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
$en     = require $langDir . '/en/Notifications.php';
$enKeys = $flatten($en);
chk('en has nested status.pending_approval', in_array('status.pending_approval', $enKeys, true));
chk('en has nested priority.high', in_array('priority.high', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/Notifications.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/campaigns.php");
chk("campaigns.php calls lang('Notifications.", str_contains($src, "lang('Notifications."));
chk('campaigns.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('campaigns.php includes _locale.php', str_contains($src, '_locale.php'));
chk('campaigns.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Broadcast campaigns', 'No campaigns yet', 'Pending approval'] as $needle) {
    chk("campaigns.php no bare '$needle'", ! str_contains($src, $needle));
}

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
            function getLocale() { return $GLOBALS['__nLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Notifications') {
            return $key;
        }
        $v = $GLOBALS['__nLang'];
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
    $GLOBALS['__nLoc']  = $loc;
    $GLOBALS['__nLang'] = require $langDir . "/$loc/Notifications.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — plural, status/priority labels, humanized channel, verbatim, no-audience
$h = $render("$viewDir/campaigns.php", [
    'campaigns' => [
        ['name' => 'Easter Invite', 'template_key' => 'easter_2026', 'channel' => 'email', 'status' => 'pending_approval', 'priority' => 'high', 'audience_count' => 1200],
        ['name' => 'SMS Reminder', 'template_key' => 'reminder', 'channel' => 'sms', 'status' => 'weird_status', 'priority' => 'weird_pri', 'audience_count' => null],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Campagnes de diffusion'));
chk('fr plural count interpolated', str_contains($h, '2 campagnes'));
chk('fr status pending_approval translated', str_contains($h, 'attente d'));
chk('fr priority high translated', str_contains($h, 'Élevée'));
chk('fr unknown status falls back (Weird Status)', str_contains($h, 'Weird Status'));
chk('fr unknown priority falls back (Weird_pri)', str_contains($h, 'Weird_pri'));
chk('fr channel humanized verbatim (Email)', str_contains($h, 'Email'));
chk('fr campaign name verbatim', str_contains($h, 'Easter Invite'));
chk('fr template key verbatim', str_contains($h, 'easter_2026'));
chk('fr audience count verbatim', str_contains($h, '1200'));

// singular
$h1 = $render("$viewDir/campaigns.php", ['campaigns' => [['name' => 'Solo', 'template_key' => 't', 'channel' => 'email', 'status' => 'draft', 'priority' => 'normal', 'audience_count' => 1]]], 'fr');
chk('fr singular count', str_contains($h1, '1 campagne') && ! str_contains($h1, '1 campagnes'));

// ar — RTL + empty
$ha = $render("$viewDir/campaigns.php", ['campaigns' => [], 'csrf' => 'NC1'], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'حملات البث'));
chk('ar empty campaigns translated', str_contains($ha, 'لا توجد حملات بعد.'));

// ── write-UI: forms, csrf, PRG, routes, SoD ─────────────────────────────────
echo "write-UI: forms + csrf + PRG + routes\n";
$hw = $render("$viewDir/campaigns.php", [
    'csrf'      => 'NCCSRF',
    'campaigns' => [
        ['id' => 'c-draft', 'name' => 'Draft One', 'template_key' => 't1', 'channel' => 'email', 'status' => 'draft', 'priority' => 'normal', 'audience_count' => null],
        ['id' => 'c-pend', 'name' => 'Pending One', 'template_key' => 't2', 'channel' => 'sms', 'status' => 'pending_approval', 'priority' => 'high', 'audience_count' => 500],
        ['id' => 'c-appr', 'name' => 'Approved One', 'template_key' => 't3', 'channel' => 'push', 'status' => 'approved', 'priority' => 'low', 'audience_count' => 500],
    ],
], 'fr');
chk('draft form posts to /notifications/campaigns', str_contains($hw, 'action="/notifications/campaigns"') && str_contains($hw, 'method="post"'));
chk('draft form has name + channel + priority', str_contains($hw, 'name="name"') && str_contains($hw, 'name="channel"') && str_contains($hw, 'name="priority"'));
chk('draft form does NOT expose requester_id (SoD by identity)', ! str_contains($hw, 'name="requested_by"'));
chk('submit form only on draft rows', str_contains($hw, 'action="/notifications/campaigns/c-draft/submit"'));
chk('submit form freezes audience_count', str_contains($hw, 'name="audience_count"'));
chk('approve form only on pending rows', str_contains($hw, 'action="/notifications/campaigns/c-pend/approve"'));
chk('approve form does NOT expose approver_id (SoD by identity)', ! str_contains($hw, 'name="approver_id"'));
chk('no submit/approve action on approved row', ! str_contains($hw, 'campaigns/c-appr/submit') && ! str_contains($hw, 'campaigns/c-appr/approve'));
chk('every write form carries _csrf bound to $csrf', substr_count($hw, 'name="_csrf" value="NCCSRF"') >= 3);
chk('SoD hint shown on pending row', str_contains($hw, 'approuver une campagne que vous avez demandée'));
chk('draft button localized (fr)', str_contains($hw, 'Créer le brouillon'));

// flash
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__nFlash'][$k] ?? null; }
}
$GLOBALS['__nFlash'] = ['success' => 'Brouillon de campagne créé.'];
$hs = $render("$viewDir/campaigns.php", ['campaigns' => [], 'csrf' => 'NC1'], 'fr');
chk('success flash rendered', str_contains($hs, 'Brouillon de campagne créé.') && str_contains($hs, 'flash ok'));
$GLOBALS['__nFlash'] = ['error' => 'Mauvais état.'];
$he = $render("$viewDir/campaigns.php", ['campaigns' => [], 'csrf' => 'NC1'], 'fr');
chk('error flash rendered', str_contains($he, 'Mauvais état.') && str_contains($he, 'flash err'));
$GLOBALS['__nFlash'] = [];

echo "controller: PRG + SoD-by-identity + no-JSON-to-browser\n";
$ctrl = (string) file_get_contents("$root/app/Modules/Notifications/Controllers/CampaignController.php");
chk('has respondCampaignDecision PRG helper', str_contains($ctrl, 'private function respondCampaignDecision'));
chk('PRG keeps JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, 'respondJson'));
chk('PRG redirects back to campaigns dashboard', str_contains($ctrl, "/notifications/campaigns"));
chk('requester defaults to authenticated actor', str_contains($ctrl, '$this->actorId() ?? (string) ($in[\'requested_by\']'));
chk('approver defaults to authenticated actor', str_contains($ctrl, '$this->actorId() ?? (string) $this->field(\'approver_id\''));
chk('create flashes createdFlash', str_contains($ctrl, "'createdFlash'"));
chk('submit flashes submittedFlash', str_contains($ctrl, "'submittedFlash'"));
chk('approve flashes approvedFlash', str_contains($ctrl, "'approvedFlash'"));
chk('index passes csrf to the view', str_contains($ctrl, "'csrf'      => (string) (\$this->request->wbsCsrf"));

echo "routes: auth + webcsrf-guarded writes\n";
$routes = (string) file_get_contents("$root/app/Config/Routes.php");
foreach (['CampaignController::create', 'CampaignController::submit/$1', 'CampaignController::approve/$1'] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
        chk("$needle auth-guarded", str_contains($mm[0], "'auth'"));
    } else {
        chk("$needle route present", false);
    }
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
