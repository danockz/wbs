<?php

declare(strict_types=1);

/**
 * PROVIDER CONNECTIONS dashboard write-UI test — proves GET
 * /integrations/connections is now a bespoke page (previously it had NO view /
 * no route): a connect-a-provider form plus every connection with the STAGE-
 * appropriate controls — store credential (pre-active), record test (draft/
 * tested), submit for approval (tested), activate (pending_approval, or tested for
 * non-approval categories). Each control POSTs to a webcsrf-guarded route; the
 * controller PRGs browser writes back with a localized flash (JSON kept for API
 * clients) and the requester/approver come from the session (SoD by identity).
 * Plus i18n parity for the new connections.* keys and a headless render smoke.
 *
 *   php app/Modules/Integrations/Views/tests/integrations_connections_view_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Integrations/Language';
$viewDir    = $root . '/app/Modules/Integrations/Views';
$controller = $root . '/app/Modules/Integrations/Controllers/ConnectionController.php';
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

// ── 1. i18n parity for connections.* ─────────────────────────────────────────
echo "language parity (connections.*)\n";
$en      = require $langDir . '/en/Integrations.php';
$enConn  = $flatten(['connections' => $en['connections']]);
$need = ['metaTitle', 'heading', 'sub', 'empty', 'connectHeading', 'adapterLabel', 'connectBtn', 'noAdapters',
    'slotLabel', 'secretPh', 'storeBtn', 'testBtn', 'submitBtn', 'activateBtn', 'activateConfirm', 'sodHint',
    'createdFlash', 'credentialFlash', 'testedFlash', 'submittedFlash', 'activatedFlash',
    'status.draft', 'status.tested', 'status.pending_approval', 'status.active', 'status.disabled'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Integrations.php";
    $c = $l['connections'] ?? [];
    $flat = $flatten($c);
    foreach ($need as $k) {
        chk("$loc connections.$k present", in_array($k, $flat, true));
    }
    chk("$loc mirrors all en connections keys", array_diff($enConn, $flatten(['connections' => $c])) === [],
        'missing: ' . implode(',', array_diff($enConn, $flatten(['connections' => $c]))));
    chk("$loc no stray connections keys", array_diff($flatten(['connections' => $c]), $enConn) === [],
        'extra: ' . implode(',', array_diff($flatten(['connections' => $c]), $enConn)));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "connections.php exposes lifecycle controls\n";
$src = (string) file_get_contents("$viewDir/connections.php");
chk('connect form posts to /integrations/connections', str_contains($src, 'action="/integrations/connections"'));
chk('connect form has adapter_code + category', str_contains($src, 'name="adapter_code"') && str_contains($src, 'name="category"'));
chk('credential form present (write-only secret)', str_contains($src, '/credentials"') && str_contains($src, 'type="password" name="secret"'));
chk('test form present', str_contains($src, '/test"'));
chk('submit form present', str_contains($src, '/submit"'));
chk('activate form present', str_contains($src, '/activate"'));
chk('activate asks confirm()', str_contains($src, 'activateConfirm'));
chk('SoD hint present', str_contains($src, 'sodHint'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
chk('no secret material shown back (only password input)', ! str_contains($src, 'secret_value') && substr_count($src, 'name="secret"') === 1);

// ── 3. Controller PRG + csrf + SoD ───────────────────────────────────────────
echo "controller PRG + csrf + SoD-by-identity\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondConnectionDecision PRG helper', str_contains($ctrl, 'private function respondConnectionDecision'));
chk('index renders bespoke view + csrf', str_contains($ctrl, 'WBS\Integrations\Views\connections') && str_contains($ctrl, 'wbsCsrf'));
chk('index passes catalogue adapters', str_contains($ctrl, "'adapters'    => IntegrationServices::adapterCatalog()->activeAdapters()"));
chk('index keeps JSON for API clients', (bool) preg_match('/function index\(\).*?if \(\$this->wantsJson\(\)\)/s', $ctrl));
chk('create PRGs with createdFlash', (bool) preg_match('/function create\(.*?createdFlash/s', $ctrl));
chk('setCredential PRGs with credentialFlash', (bool) preg_match('/function setCredential\(.*?credentialFlash/s', $ctrl));
chk('test PRGs with testedFlash', (bool) preg_match('/function test\(.*?testedFlash/s', $ctrl));
chk('submit PRGs with submittedFlash', (bool) preg_match('/function submit\(.*?submittedFlash/s', $ctrl));
chk('activate PRGs with activatedFlash', (bool) preg_match('/function activate\(.*?activatedFlash/s', $ctrl));
chk('requester defaults to authenticated actor', str_contains($ctrl, '$this->actorId() ?? (string) ($in[\'requested_by\']'));
chk('approver defaults to authenticated actor', str_contains($ctrl, '$this->actorId() ?? (string) $this->field(\'approver_id\''));
chk('PRG keeps JSON + redirects to dashboard', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, "'/integrations/connections'"));

// ── 4. Routes: GET index + webcsrf-guarded writes ────────────────────────────
echo "routes: GET index + webcsrf-guarded writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET connections index route present', (bool) preg_match("#\\\$routes->get\\('connections'.*ConnectionController::index#", $routes));
chk('GET index gated by provider.configure', (bool) preg_match("#get\\('connections'.*authorize:provider\\.configure#", $routes));
foreach ([
    'ConnectionController::create',
    'ConnectionController::setCredential/$1',
    'ConnectionController::test/$1',
    'ConnectionController::submit/$1',
    'ConnectionController::activate/$1',
] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
    } else {
        chk("$needle route present", false);
    }
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — connections (fr) across stages + ar RTL\n";
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
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLoc']  = $loc;
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Integrations.php";
    extract($data);
    ob_start();
    include "$viewDir/connections.php";
    return (string) ob_get_clean();
};

$mk = static fn (string $id, string $status, string $cat): array => [
    'id' => $id, 'adapter_code' => 'acme_' . $cat, 'display_name' => 'Acme ' . $id,
    'category' => $cat, 'status' => $status, 'sender_identity' => '', 'tested_at' => '',
];

$GLOBALS['__cFlash'] = [];
$h = $render([
    'csrf'     => 'CN1',
    'adapters' => [
        ['code' => 'stripe', 'display_name' => 'Stripe', 'category' => 'payment'],
        ['code' => 'twilio', 'display_name' => 'Twilio', 'category' => 'notification'],
        ['code' => 'mapbox', 'display_name' => 'Mapbox', 'category' => 'geocoding'],
    ],
    'connections' => [
        $mk('draft1', 'draft', 'payment'),
        $mk('test1', 'tested', 'payment'),
        $mk('testg', 'tested', 'geocoding'),
        $mk('pend1', 'pending_approval', 'payment'),
        $mk('act1', 'active', 'payment'),
        $mk('dis1', 'disabled', 'payment'),
    ],
], 'fr');
chk('smoke: lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('smoke: connect form lists adapters', str_contains($h, '>Stripe — Paiement</option>') || str_contains($h, 'Stripe'));
chk('smoke: draft shows credential + test (no submit)', str_contains($h, 'connections/draft1/credentials') && str_contains($h, 'connections/draft1/test') && ! str_contains($h, 'connections/draft1/submit'));
chk('smoke: tested(payment) shows submit, NOT direct activate', str_contains($h, 'connections/test1/submit') && ! str_contains($h, 'connections/test1/activate'));
chk('smoke: tested(geocoding) shows direct activate (no approval)', str_contains($h, 'connections/testg/activate'));
chk('smoke: pending shows activate + SoD hint', str_contains($h, 'connections/pend1/activate') && str_contains($h, 'approbateur différent'));
chk('smoke: active shows no lifecycle actions', ! str_contains($h, 'connections/act1/test') && ! str_contains($h, 'connections/act1/submit') && ! str_contains($h, 'connections/act1/activate'));
chk('smoke: disabled shows no actions', ! str_contains($h, 'connections/dis1/credentials') && ! str_contains($h, 'connections/dis1/activate'));
chk('smoke: status label localized (fr Brouillon)', str_contains($h, 'Brouillon'));
chk('smoke: count interpolated (fr)', str_contains($h, '6 connexions'));
chk('smoke: every write form carries csrf', substr_count($h, 'name="_csrf" value="CN1"') >= 6);
chk('smoke: secret input is password type (write-only)', str_contains($h, 'type="password" name="secret"'));

// flash + empty
$GLOBALS['__cFlash'] = ['success' => 'Connexion créée.'];
$hs = $render(['csrf' => 'CN1', 'adapters' => [], 'connections' => []], 'fr');
chk('smoke: success flash rendered', str_contains($hs, 'Connexion créée.') && str_contains($hs, 'flash ok'));
chk('smoke: empty state shown', str_contains($hs, lang('Integrations.connections.empty')));
chk('smoke: no-adapters hint shown', str_contains($hs, lang('Integrations.connections.noAdapters')));
$GLOBALS['__cFlash'] = ['error' => 'Champs manquants.'];
$he = $render(['csrf' => 'CN1', 'adapters' => [], 'connections' => []], 'fr');
chk('smoke: error flash rendered', str_contains($he, 'Champs manquants.') && str_contains($he, 'flash err'));
$GLOBALS['__cFlash'] = [];

// ar RTL
$ha = $render(['csrf' => 'CN1', 'adapters' => [], 'connections' => [$mk('p', 'pending_approval', 'payment')]], 'ar');
chk('smoke(ar): lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('smoke(ar): activate button localized', str_contains($ha, lang('Integrations.connections.activateBtn')));
chk('smoke(ar): pending status localized', str_contains($ha, 'بانتظار الموافقة'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
