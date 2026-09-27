<?php

declare(strict_types=1);

/**
 * RELIABILITY (circuit breakers) + STREAMING OAUTH write-UI test.
 *
 * Two surfaces that previously served raw JSON to the browser now have bespoke
 * pages:
 *   - GET /integrations/circuits renders every provider breaker with its state,
 *     tallies, last error and probe time; a TRIPPED breaker (open/half-open) gets
 *     a webcsrf-guarded "reset" control (confirm()) that PRGs back with a flash.
 *   - GET /integrations/oauth lists oauth-capable stream/meeting connections; each
 *     ready provider gets a webcsrf-guarded "grant access" form that redirects the
 *     browser to the provider consent screen (authorize), and the callback PRGs
 *     back with a flash. A provider whose client creds are absent is disabled.
 * Plus i18n parity for the new reliability.* / oauth.* keys and headless render
 * smokes (fr across states + ar RTL).
 *
 *   php app/Modules/Integrations/Views/tests/integrations_reliability_oauth_view_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Integrations/Language';
$viewDir    = $root . '/app/Modules/Integrations/Views';
$relCtrl    = $root . '/app/Modules/Integrations/Controllers/ReliabilityController.php';
$oauthCtrl  = $root . '/app/Modules/Integrations/Controllers/StreamingOAuthController.php';
$cfgFile    = $root . '/app/Modules/Integrations/Providers/OAuthProviderConfig.php';
$routesFile = $root . '/app/Config/Routes.php';

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

// ── 1. i18n parity for reliability.* and oauth.* ─────────────────────────────
echo "language parity (reliability.* + oauth.*)\n";
$en    = require $langDir . '/en/Integrations.php';
$enRel = $flatten(['reliability' => $en['reliability']]);
$enOa  = $flatten(['oauth' => $en['oauth']]);
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Integrations.php";
    foreach (['reliability' => $enRel, 'oauth' => $enOa] as $blk => $enFlat) {
        $flat = $flatten([$blk => $l[$blk] ?? []]);
        chk("$loc mirrors all en $blk keys", array_diff($enFlat, $flat) === [],
            'missing: ' . implode(',', array_diff($enFlat, $flat)));
        chk("$loc no stray $blk keys", array_diff($flat, $enFlat) === [],
            'extra: ' . implode(',', array_diff($flat, $enFlat)));
    }
    // Spot-check state sub-keys exist.
    foreach (['closed', 'open', 'half_open'] as $st) {
        chk("$loc reliability.state.$st present", isset($l['reliability']['state'][$st]));
    }
}

// ── 2. Reliability view controls ─────────────────────────────────────────────
echo "reliability.php exposes reset controls\n";
$rv = (string) file_get_contents("$viewDir/reliability.php");
chk('reset form posts to /circuits/…/reset', (bool) preg_match('#action="/integrations/circuits/[^"]*/reset"#', $rv));
chk('reset asks confirm()', str_contains($rv, 'resetConfirm'));
chk('reset only for tripped ($tripped)', str_contains($rv, '$tripped') && str_contains($rv, "in_array(\$state, ['open', 'half_open']"));
chk('reset form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $rv));
chk('renders PRG flash messages', str_contains($rv, "session('success')") && str_contains($rv, "session('error')"));
chk('self-contained locale wiring', str_contains($rv, '_locale.php') && str_contains($rv, '_shell_open.php'));
chk('shows state label + tallies', str_contains($rv, 'stateLbl') && str_contains($rv, 'failuresLabel') && str_contains($rv, 'successesLabel'));
chk('no secret input/slot fields (health-only view)', ! str_contains($rv, 'type="password"') && ! str_contains($rv, 'name="secret"') && ! str_contains($rv, 'name="slot"') && ! str_contains($rv, 'api_key'));

// ── 3. OAuth view controls ───────────────────────────────────────────────────
echo "oauth.php exposes grant controls\n";
$ov = (string) file_get_contents("$viewDir/oauth.php");
chk('grant form posts to /oauth/…/authorize', (bool) preg_match('#action="/integrations/oauth/[^"]*/authorize#', $ov));
chk('grant form carries connection_id', str_contains($ov, 'name="connection_id"'));
chk('grant form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $ov));
chk('ready-gate on $ready (disabled when unconfigured)', str_contains($ov, '$ready') && str_contains($ov, 'disabled') && str_contains($ov, 'notConfigured'));
chk('renders PRG flash messages', str_contains($ov, "session('success')") && str_contains($ov, "session('error')"));
chk('self-contained locale wiring', str_contains($ov, '_locale.php') && str_contains($ov, '_shell_open.php'));
chk('no token/secret material referenced', ! str_contains($ov, 'refresh_token') && ! str_contains($ov, 'access_token') && ! str_contains($ov, 'client_secret'));

// ── 4. Controllers: render + PRG + JSON parity ───────────────────────────────
echo "controllers: bespoke render + PRG + JSON parity\n";
$rc = (string) file_get_contents($relCtrl);
chk('circuits() renders bespoke view', str_contains($rc, 'WBS\Integrations\Views\reliability'));
chk('circuits() keeps JSON for API', (bool) preg_match('/function circuits\(\).*?if \(\$this->wantsJson\(\)\)/s', $rc));
chk('resetCircuit PRGs with resetFlash', (bool) preg_match('/function resetCircuit\(.*?resetFlash/s', $rc));
chk('reliability PRG helper redirects to /circuits', str_contains($rc, 'respondReliabilityDecision') && str_contains($rc, "'/integrations/circuits'"));
chk('reliability PRG keeps JSON', (bool) preg_match('/respondReliabilityDecision.*?if \(\$this->wantsJson\(\)\)/s', $rc));

$oc = (string) file_get_contents($oauthCtrl);
chk('index() renders bespoke oauth view', str_contains($oc, 'WBS\Integrations\Views\oauth'));
chk('index() filters to oauth-capable providers', str_contains($oc, 'OAuthProviderConfig::supports'));
chk('index() annotates oauth_ready', str_contains($oc, "'oauth_ready'") && str_contains($oc, 'OAuthProviderConfig::isConfigured'));
chk('index() keeps JSON for API', (bool) preg_match('/function index\(\).*?if \(\$this->wantsJson\(\)\)/s', $oc));
chk('authorize redirects browser to authorize_url', str_contains($oc, "\$result->data['authorize_url']") && str_contains($oc, 'redirect()->to'));
chk('authorize keeps JSON (authorize_url) for API', (bool) preg_match('/respondAuthorize.*?if \(\$this->wantsJson\(\)\)/s', $oc));
chk('callback PRGs back to /oauth with flash', str_contains($oc, "'/integrations/oauth'") && str_contains($oc, 'connectedFlash'));
chk('callback still validates state (hash_equals)', str_contains($oc, 'hash_equals') && str_contains($oc, "session()->remove('oauth_stream')"));
chk('no token echoed to browser', ! str_contains($oc, 'refresh_token'));

// ── 5. OAuthProviderConfig helpers ───────────────────────────────────────────
echo "OAuthProviderConfig helpers\n";
require_once $cfgFile;
$P = 'WBS\Integrations\Providers\OAuthProviderConfig';
chk('providers() lists the 5 providers', $P::providers() === ['youtube', 'googlemeet', 'twitch', 'facebook', 'gotowebinar']);
chk('supports(youtube) true', $P::supports('YouTube') === true);
chk('supports(unknown) false', $P::supports('nope') === false);
chk('isConfigured false when env absent', $P::isConfigured('youtube') === false);
putenv('YOUTUBE_CLIENT_ID=x');
putenv('YOUTUBE_CLIENT_SECRET=y');
chk('isConfigured true when env present', $P::isConfigured('youtube') === true);
putenv('YOUTUBE_CLIENT_ID');
putenv('YOUTUBE_CLIENT_SECRET');

// ── 6. Routes: GET index + webcsrf-guarded writes ────────────────────────────
echo "routes: GET index + webcsrf-guarded writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET oauth index route present', (bool) preg_match("#get\\('oauth'.*StreamingOAuthController::index#", $routes));
chk('GET oauth index gated by provider.configure', (bool) preg_match("#get\\('oauth'.*authorize:provider\\.configure#", $routes));
chk('GET circuits route present', (bool) preg_match("#get\\('circuits'.*ReliabilityController::circuits#", $routes));
foreach ([
    'ReliabilityController::resetCircuit/$1',
    'StreamingOAuthController::authorize/$1',
] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
    } else {
        chk("$needle route present", false);
    }
}

// ── 7. Headless render smokes ────────────────────────────────────────────────
echo "render smoke — reliability + oauth (fr) + ar RTL\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            function getLocale() { return $GLOBALS['__cLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c) {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__cFlash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Integrations') { return $key; }
        $v = $GLOBALS['__cLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLoc']  = $loc;
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Integrations.php";
    extract($data);
    ob_start();
    include "$viewDir/$view";
    return (string) ob_get_clean();
};

// reliability — closed (no reset), open (reset), half_open (reset)
$GLOBALS['__cFlash'] = [];
$rh = $render('reliability.php', ['csrf' => 'RZ', 'circuits' => [
    ['scope' => 'stripe', 'state' => 'closed', 'consecutive_failures' => 0, 'failure_count' => 2, 'success_count' => 40, 'last_error' => '', 'opened_at' => '', 'next_probe_at' => ''],
    ['scope' => 'twilio', 'state' => 'open', 'consecutive_failures' => 5, 'failure_count' => 9, 'success_count' => 3, 'last_error' => 'HTTP 503 upstream', 'opened_at' => '2026-09-12 10:00:00', 'next_probe_at' => '2026-09-12 10:01:00'],
    ['scope' => 'zoom', 'state' => 'half_open', 'consecutive_failures' => 5, 'failure_count' => 6, 'success_count' => 1, 'last_error' => 'timeout', 'opened_at' => '2026-09-12 09:00:00', 'next_probe_at' => ''],
]], 'fr');
chk('rel smoke: lang=fr dir=ltr', str_contains($rh, 'lang="fr"') && str_contains($rh, 'dir="ltr"'));
chk('rel smoke: closed has NO reset form', ! str_contains($rh, 'circuits/stripe/reset'));
chk('rel smoke: open HAS reset form', str_contains($rh, 'action="/integrations/circuits/twilio/reset"'));
chk('rel smoke: half_open HAS reset form', str_contains($rh, 'action="/integrations/circuits/zoom/reset"'));
chk('rel smoke: last error shown', str_contains($rh, 'HTTP 503 upstream'));
chk('rel smoke: state localized (fr Fermé/Ouvert)', str_contains($rh, 'Fermé') && str_contains($rh, 'Ouvert'));
chk('rel smoke: count + tripped interpolated', str_contains($rh, '3 circuits') && str_contains($rh, '2 déclenchés'));
chk('rel smoke: reset forms carry csrf', substr_count($rh, 'name="_csrf" value="RZ"') === 2);

$GLOBALS['__cFlash'] = ['success' => 'Circuit réinitialisé.'];
$rhe = $render('reliability.php', ['csrf' => 'RZ', 'circuits' => []], 'fr');
chk('rel smoke: flash + empty state', str_contains($rhe, 'Circuit réinitialisé.') && str_contains($rhe, lang('Integrations.reliability.empty')));
$GLOBALS['__cFlash'] = [];

$rha = $render('reliability.php', ['csrf' => 'RZ', 'circuits' => [
    ['scope' => 'twitch', 'state' => 'open', 'consecutive_failures' => 5, 'failure_count' => 5, 'success_count' => 0, 'last_error' => 'x', 'opened_at' => '', 'next_probe_at' => ''],
]], 'ar');
chk('rel smoke(ar): dir=rtl + state localized', str_contains($rha, 'dir="rtl"') && str_contains($rha, 'مفتوح'));

// oauth — ready connection (grant enabled), unconfigured (disabled)
$oh = $render('oauth.php', ['csrf' => 'OZ', 'connections' => [
    ['id' => 'c1', 'adapter_code' => 'youtube', 'display_name' => 'Main channel', 'status' => 'active', 'oauth_provider' => 'youtube', 'oauth_ready' => true],
    ['id' => 'c2', 'adapter_code' => 'twitch', 'display_name' => 'Backup', 'status' => 'tested', 'oauth_provider' => 'twitch', 'oauth_ready' => false],
]], 'fr');
chk('oauth smoke: lang=fr dir=ltr', str_contains($oh, 'lang="fr"') && str_contains($oh, 'dir="ltr"'));
chk('oauth smoke: ready conn has enabled grant form', str_contains($oh, 'action="/integrations/oauth/youtube/authorize') && substr_count($oh, 'name="connection_id" value="c1"') === 1);
chk('oauth smoke: unconfigured conn is disabled + note', str_contains($oh, 'disabled') && str_contains($oh, lang('Integrations.oauth.notConfigured')));
chk('oauth smoke: no authorize form for unconfigured', ! str_contains($oh, '/oauth/twitch/authorize'));
chk('oauth smoke: count interpolated', str_contains($oh, '2 connexions'));
chk('oauth smoke: grant form carries csrf', str_contains($oh, 'name="_csrf" value="OZ"'));

$GLOBALS['__cFlash'] = ['success' => 'Accès au fournisseur accordé.'];
$ohe = $render('oauth.php', ['csrf' => 'OZ', 'connections' => []], 'fr');
chk('oauth smoke: flash + empty state', str_contains($ohe, 'Accès au fournisseur accordé.') && str_contains($ohe, lang('Integrations.oauth.empty')));
$GLOBALS['__cFlash'] = [];

$oha = $render('oauth.php', ['csrf' => 'OZ', 'connections' => [
    ['id' => 'c3', 'adapter_code' => 'facebook', 'display_name' => 'Page', 'status' => 'active', 'oauth_provider' => 'facebook', 'oauth_ready' => true],
]], 'ar');
chk('oauth smoke(ar): dir=rtl + grant localized', str_contains($oha, 'dir="rtl"') && str_contains($oha, lang('Integrations.oauth.grantBtn')));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
