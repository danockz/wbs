<?php

declare(strict_types=1);

/**
 * CONNECTOR-PROFILE authoring console write-UI test — proves GET
 * /integrations/profiles is now a bespoke page (previously `POST profiles` was a
 * JSON-only create endpoint with NO view / no GET route): a "define a profile"
 * form (routing constrained to the reviewed canonical enums) plus every profile
 * with stage-appropriate advance/revoke controls. Each control POSTs to a
 * webcsrf-guarded route; the controller PRGs browser writes back with a localized
 * flash (JSON kept for API). Plus service list contract, i18n parity for the new
 * Integrations.profiles.* keys, and a headless render smoke.
 *
 *   php app/Modules/Integrations/Views/tests/integrations_profiles_view_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Integrations/Language';
$viewDir    = $root . '/app/Modules/Integrations/Views';
$controller = $root . '/app/Modules/Integrations/Controllers/ProfileController.php';
$service    = $root . '/app/Modules/Integrations/Services/ProfileService.php';
$routesFile = $root . '/app/Config/Routes.php';
$coverageF  = $root . '/app/Modules/Shared/Navigation/MenuCoverage.php';

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
    sort($o);

    return $o;
};

// ── 1. i18n parity for profiles.* ────────────────────────────────────────────
echo "language parity (Integrations.profiles.*)\n";
$en    = require $langDir . '/en/Integrations.php';
$enKeys = $flatten($en['profiles'] ?? []);
chk('en profiles block present (>= 30 keys)', count($enKeys) >= 30, (string) count($enKeys));
chk('en count keeps {0}', str_contains((string) ($en['profiles']['count'] ?? ''), '{0}'));
chk('en advanceBtn keeps {0}', str_contains((string) ($en['profiles']['advanceBtn'] ?? ''), '{0}'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Integrations.php";
    $keys = $flatten($arr['profiles'] ?? []);
    chk("$loc mirrors profiles keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
}

// ── 2. Service: listForOrg contract ──────────────────────────────────────────
echo "service: listForOrg\n";
$svc = (string) file_get_contents($service);
chk('listForOrg() present', str_contains($svc, 'function listForOrg'));
chk('listForOrg omits the big JSON mapping blobs', ! (bool) preg_match('/function listForOrg.*?request_mapping/s', $svc));
chk('listForOrg annotates next_status + is_terminal', (bool) preg_match('/function listForOrg.*?next_status/s', $svc) && (bool) preg_match('/function listForOrg.*?is_terminal/s', $svc));
chk('listForOrg newest-first + bounded', (bool) preg_match("/function listForOrg.*?orderBy\('created_at', 'DESC'\)/s", $svc) && (bool) preg_match('/function listForOrg.*?limit\(/s', $svc));

// ── 3. Controller: index(GET) renders view + PRG writes ──────────────────────
echo "controller: index/create/advance/revoke\n";
$ctrl = (string) file_get_contents($controller);
chk('index renders bespoke view + csrf', str_contains($ctrl, 'Views\\\\profiles') && str_contains($ctrl, 'wbsCsrf'));
chk('index passes the reviewed enums', str_contains($ctrl, 'Canonical::OPERATIONS') && str_contains($ctrl, 'Canonical::FAMILIES') && str_contains($ctrl, 'Canonical::SIGNATURE_ALGOS'));
chk('index keeps JSON for API', (bool) preg_match('/function index\(\).*?wantsJson\(\)/s', $ctrl));
chk('create PRGs with createdFlash', (bool) preg_match('/function create\(.*?createdFlash/s', $ctrl));
chk('create owner defaults to the actor', (bool) preg_match('/function create\(.*?actorId\(\)/s', $ctrl));
chk('advance PRGs with advancedFlash', (bool) preg_match('/function advance\(.*?advancedFlash/s', $ctrl));
chk('revoke PRGs with revokedFlash', (bool) preg_match('/function revoke\(.*?revokedFlash/s', $ctrl));
chk('respondProfile keeps JSON + redirects to console', (bool) preg_match("/function respondProfile.*?wantsJson\(\).*?'\/integrations\/profiles'/s", $ctrl));

// ── 4. Routes: GET console + create/advance/revoke (authorize + webcsrf) ─────
echo "routes: profiles console + writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET profiles console (provider.configure)', (bool) preg_match("#get\\('profiles'.*?ProfileController::index.*?authorize:provider.configure#", $routes));
foreach ([
    "post\\('profiles'.*?ProfileController::create" => 'create',
    "post\\('profiles/\\(:segment\\)/advance'.*?ProfileController::advance" => 'advance',
    "post\\('profiles/\\(:segment\\)/revoke'.*?ProfileController::revoke" => 'revoke',
] as $rx => $name) {
    if (preg_match('#' . $rx . '.*#', $routes, $m)) {
        chk("$name route present", true);
        chk("$name has provider.configure", str_contains($m[0], 'authorize:provider.configure'));
        chk("$name has webcsrf", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$name route present", false);
    }
}

// coverage: the console is a triaged (excluded) secondary surface.
$cov = (string) file_get_contents($coverageF);
chk('menu coverage triages integrations/profiles', str_contains($cov, "'integrations/profiles'"));

// ── 5. View controls: CSP-clean, csrf-bound, no-JS, enums rendered ───────────
echo "profiles.php view controls\n";
$src = (string) file_get_contents("$viewDir/profiles.php");
chk('define form posts to /integrations/profiles', str_contains($src, 'action="/integrations/profiles"'));
chk('define form has code + family + canonical_op + approved_host', str_contains($src, 'name="code"') && str_contains($src, 'name="family"') && str_contains($src, 'name="canonical_op"') && str_contains($src, 'name="approved_host"'));
chk('advance form posts to /advance with to_status', str_contains($src, '/advance"') && str_contains($src, 'name="to_status"'));
chk('revoke form posts to /revoke', str_contains($src, '/revoke"'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers (incl. no onsubmit confirm)', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input)\s*=/i', $noC));
chk('no request/response mapping blob shown', ! str_contains($src, 'request_mapping') && ! str_contains($src, 'response_mapping'));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — empty, draft (advance+revoke), active, revoked (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Integrations') { return $key; }
        $v = $GLOBALS['__ipLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__ipLang'] = require $langDir . '/fr/Integrations.php';
$render = static function (array $data) use ($viewDir): string {
    extract($data);
    ob_start();
    include "$viewDir/profiles.php";
    return (string) ob_get_clean();
};
$enums = [
    'operations' => ['sendNotification', 'createCheckout'],
    'families'   => ['smtp_email', 'rest_json_notification'],
    'methods'    => ['GET', 'POST'],
    'algos'      => ['hmac_sha256', 'none'],
    'csrf'       => 'TKN',
];

// empty
$h = $render($enums + ['profiles' => []]);
chk('empty: define form present', str_contains($h, 'action="/integrations/profiles"'));
chk('empty: empty note shown', str_contains($h, lang('Integrations.profiles.empty')));
chk('empty: enums rendered as options', str_contains($h, '>smtp_email<') && str_contains($h, '>createCheckout<') && str_contains($h, '>hmac_sha256<'));

// draft profile -> advance (to sandbox_verified) + revoke
$draft = ['id' => 'p1', 'code' => 'acme_pay', 'version' => 1, 'family' => 'hosted_payment_checkout', 'canonical_op' => 'createCheckout', 'http_method' => 'POST', 'approved_host' => 'api.acme.com', 'signature_algo' => 'hmac_sha256', 'status' => 'draft', 'next_status' => 'sandbox_verified', 'is_terminal' => false];
$h = $render($enums + ['profiles' => [$draft]]);
chk('draft: advance form present', str_contains($h, 'action="/integrations/profiles/p1/advance"'));
chk('draft: advance carries next to_status', str_contains($h, 'name="to_status" value="sandbox_verified"'));
chk('draft: revoke form present', str_contains($h, 'action="/integrations/profiles/p1/revoke"'));
chk('draft: code + host shown', str_contains($h, 'acme_pay') && str_contains($h, 'api.acme.com'));

// active profile -> still advanceable? active is last in FLOW so next_status null
$active = ['id' => 'p2', 'code' => 'acme_pay', 'version' => 2, 'family' => 'hosted_payment_checkout', 'canonical_op' => 'createCheckout', 'http_method' => 'POST', 'approved_host' => 'api.acme.com', 'signature_algo' => 'hmac_sha256', 'status' => 'active', 'next_status' => null, 'is_terminal' => false];
$h = $render($enums + ['profiles' => [$active]]);
chk('active: no advance form (terminal step of flow)', ! str_contains($h, '/p2/advance"'));
chk('active: revoke still available', str_contains($h, 'action="/integrations/profiles/p2/revoke"'));

// revoked profile -> no actions, terminal note
$revoked = ['id' => 'p3', 'code' => 'old', 'version' => 1, 'family' => 'smtp_email', 'canonical_op' => 'sendNotification', 'http_method' => '', 'approved_host' => 'smtp.old.com', 'signature_algo' => '', 'status' => 'revoked', 'next_status' => null, 'is_terminal' => true];
$h = $render($enums + ['profiles' => [$revoked]]);
chk('revoked: no advance/revoke forms', ! str_contains($h, '/p3/advance"') && ! str_contains($h, '/p3/revoke"'));
chk('revoked: terminal note shown', str_contains($h, lang('Integrations.profiles.terminalNote')));
chk('revoked: empty method/signature shown as none', str_contains($h, lang('Integrations.profiles.none')));
chk('render: no untranslated Integrations.profiles keys leaked', ! str_contains($h, 'Integrations.profiles.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
