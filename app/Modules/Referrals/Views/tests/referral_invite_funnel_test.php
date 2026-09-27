<?php

declare(strict_types=1);

/**
 * PUBLIC referral invitation + prospect-capture funnel test.
 *
 * Proves the previously JSON-only cloaked-link endpoints now have a bespoke,
 * no-JS, CSP-safe, consent-gated browser funnel:
 *   - GET  r/{code}          -> ReferralController::land renders `invite`
 *                               (branded welcome + typed-redirect CTA +
 *                               consent-gated capture form) or `invite_expired`;
 *   - POST r/{code}/prospect -> captureProspect, webcsrf-guarded, PRGs the
 *                               browser onward to the branded destination.
 *
 * Also asserts the privacy hard constraints (no sponsor id leaked; capture only
 * after consent), the service redirect/campaign contract, 6-locale i18n parity
 * for the new invite.* / inviteExpired.* blocks, and headless renders.
 *
 *   php app/Modules/Referrals/Views/tests/referral_invite_funnel_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Referrals/Language';
$viewDir    = $root . '/app/Modules/Referrals/Views';
$controller = $root . '/app/Modules/Referrals/Controllers/ReferralController.php';
$service    = $root . '/app/Modules/Referrals/Services/ReferralService.php';
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
    sort($o);

    return $o;
};

// ── 1. i18n parity for invite.* + inviteExpired.* ────────────────────────────
echo "language parity (Referrals.invite.* + inviteExpired.*)\n";
$en      = require $langDir . '/en/Referrals.php';
$enInv   = $flatten($en['invite'] ?? []);
$enExp   = $flatten($en['inviteExpired'] ?? []);
chk('en invite block present (>= 20 keys)', count($enInv) >= 20, (string) count($enInv));
chk('en inviteExpired block present (>= 4 keys)', count($enExp) >= 4, (string) count($enExp));
chk('en has all 5 dest* variants', isset($en['invite']['destMember'], $en['invite']['destEvent'], $en['invite']['destGiving'], $en['invite']['destCourse'], $en['invite']['destStreaming']));
chk('en has consentLbl + capturedFlash', isset($en['invite']['consentLbl'], $en['invite']['capturedFlash']));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $langDir . "/$loc/Referrals.php";
    $inv = $flatten($arr['invite'] ?? []);
    $exp = $flatten($arr['inviteExpired'] ?? []);
    chk("$loc mirrors invite keys", $inv === $enInv, 'missing: ' . implode(',', array_diff($enInv, $inv)) . ' extra: ' . implode(',', array_diff($inv, $enInv)));
    chk("$loc mirrors inviteExpired keys", $exp === $enExp, 'missing: ' . implode(',', array_diff($enExp, $exp)) . ' extra: ' . implode(',', array_diff($exp, $enExp)));
}

// ── 2. Service contract: redirect + campaign, consent gate ───────────────────
echo "service: recordClick/captureProspect contract\n";
$svc = (string) file_get_contents($service);
chk('recordClick surfaces campaign label', (bool) preg_match("/function recordClick.*?'campaign'\s*=>/s", $svc));
chk('recordClick surfaces typed redirect', (bool) preg_match('/function recordClick.*?resolveRedirectUrl/s', $svc));
chk('captureProspect enforces consent gate', (bool) preg_match("/function captureProspect.*?CONSENT_REQUIRED/s", $svc));
chk('captureProspect returns redirect for the PRG hop', (bool) preg_match("/function captureProspect.*?'redirect'\s*=>\s*\\\$this->resolveRedirectUrl/s", $svc));
chk('captureProspect stores only hashed email (no raw email column)', (bool) preg_match("/function captureProspect.*?'email_hash'/s", $svc) && ! (bool) preg_match("/function captureProspect.*?'email'\s*=>/s", $svc));

// ── 3. Controller: land renders invite/expired, capture PRGs ─────────────────
echo "controller: land + captureProspect\n";
$ctrl = (string) file_get_contents($controller);
chk('land renders bespoke invite view for browsers', str_contains($ctrl, 'Views\\\\invite') || str_contains($ctrl, "Views\\invite'"));
chk('land renders expired view on not-found', str_contains($ctrl, 'invite_expired'));
chk('land keeps JSON for API', (bool) preg_match('/function land\(.*?wantsJson\(\)/s', $ctrl));
// Narrow to just the land() method body (signature -> next `public function`),
// then strip comments so a "no sponsor leak" note doesn't count as a leak.
$landBody = '';
$ls       = (int) strpos($ctrl, 'public function land(');
if ($ls > 0) {
    $rest = substr($ctrl, $ls + 1);
    $ne   = strpos($rest, 'public function ');
    $landBody = $ne !== false ? substr($rest, 0, $ne) : $rest;
    $landBody = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $landBody);
}
chk('land does NOT pass a sponsor/referrer id to the invite view', $landBody !== '' && ! str_contains($landBody, 'referrer') && ! str_contains($landBody, 'sponsor'));
chk('land click uses consent=false (a visit is not consent)', (bool) preg_match("/function land\(.*?'consent'\s*=>\s*false/s", $ctrl));
chk('captureProspect PRGs onward to redirect on success', (bool) preg_match("/function captureProspect.*?redirect\(\)->to\(\\\$redirect\)/s", $ctrl));
chk('captureProspect returns to invite page on failure', (bool) preg_match("/function captureProspect.*?->to\('\/r\/'/s", $ctrl));
chk('captureProspect keeps JSON for API', (bool) preg_match('/function captureProspect.*?wantsJson\(\)/s', $ctrl));
chk('capturedFlash localized key used', str_contains($ctrl, "lang('Referrals.invite.capturedFlash')"));

// ── 4. Routes: GET landing + webcsrf-guarded prospect POST ───────────────────
echo "routes: r/{code} + r/{code}/prospect\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#\$routes->get\([^\n]*ReferralController::land[^\n]*#', $routes, $mg)) {
    chk('GET r/{code} landing present', true);
    chk('landing rate-limited', str_contains($mg[0], 'ratelimit:referral.click'));
    chk('landing is a GET read (no webcsrf)', ! str_contains($mg[0], 'webcsrf'));
} else {
    chk('GET r/{code} landing present', false);
}
if (preg_match('#\$routes->post\([^\n]*ReferralController::captureProspect[^\n]*#', $routes, $mp)) {
    chk('POST r/{code}/prospect present', true);
    chk('prospect POST webcsrf-guarded (guest double-submit)', str_contains($mp[0], 'webcsrf'));
    chk('prospect POST still rate-limited', str_contains($mp[0], 'ratelimit:referral.click'));
    chk('prospect POST has NO auth (guests may submit)', ! preg_match("/'auth'/", $mp[0]));
} else {
    chk('POST r/{code}/prospect present', false);
}

// ── 5. View controls: CSP-clean, consent gate, csrf, no sponsor leak ─────────
echo "invite.php view controls\n";
$src = (string) file_get_contents("$viewDir/invite.php");
chk('form posts to /r/{code}/prospect', str_contains($src, 'action="/r/<?= esc(rawurlencode($code)') && str_contains($src, '/prospect"'));
chk('form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('consent checkbox is required (server also enforces)', (bool) preg_match('/name="consent"[^>]*required/', $src) || (bool) preg_match('/type="checkbox"[^>]*name="consent"[^>]*required/', $src));
chk('collects display_name + email (+ optional phone — graded subset)', str_contains($src, 'name="display_name"') && str_contains($src, 'name="email"'));
chk('optional phone input present (field-sync decision)', (bool) preg_match('/name="phone"/', $src) && ! (bool) preg_match('/name="phone"[^>]*required/', $src));
chk('NO precise GPS fields on the public funnel', ! str_contains($src, 'name="latitude"') && ! str_contains($src, 'name="longitude"'));
chk('does NOT render a sponsor/referrer id', ! str_contains($src, '$referrer') && ! str_contains($src, 'referrer_id'));
chk('branded CTA to typed redirect', str_contains($src, 'href="<?= esc($redirect'));
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('noindex for a public capture page', str_contains($src, 'noindex'));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));

$exp = (string) file_get_contents("$viewDir/invite_expired.php");
chk('expired page has home CTA', str_contains($exp, "lang('Referrals.inviteExpired.homeBtn')"));
chk('expired page is self-contained + noindex', str_contains($exp, '_locale.php') && str_contains($exp, 'noindex'));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — invite (event, fr), invite (member, no campaign), expired (ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return 'https://public.test/' . ltrim((string) $p, '/'); }
}
// Framework stubs so _locale.php resolves a locale + rtl set (mirrors the i18n test).
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale() { return $GLOBALS['__refLoc'] ?? 'en'; }
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
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__sess'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Referrals') { return $key; }
        $v = $GLOBALS['__riLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__sess'] = [];
$render = static function (string $file, array $data, array $lang, string $loc = 'en') use ($viewDir): string {
    $GLOBALS['__riLang'] = $lang;
    $GLOBALS['__refLoc'] = $loc;
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};
$fr = require $langDir . '/fr/Referrals.php';
$ar = require $langDir . '/ar/Referrals.php';

// invite — event link, campaign present, fr
$h = $render('invite.php', [
    'code' => 'AbC123', 'campaign' => 'Easter2026', 'linkType' => 'event',
    'redirect' => 'https://public.test/events/register/ev1', 'csrf' => 'TKN',
], $fr, 'fr');
chk('invite: form action has the code', str_contains($h, 'action="/r/AbC123/prospect"'));
chk('invite: csrf token rendered', str_contains($h, 'name="_csrf" value="TKN"'));
chk('invite: event destination copy (fr)', str_contains($h, esc($fr['invite']['destEvent'])));
chk('invite: campaign label shown', str_contains($h, 'Easter2026'));
chk('invite: CTA links to typed redirect', str_contains($h, 'href="https://public.test/events/register/ev1"'));
chk('invite: consent required checkbox present', (bool) preg_match('/name="consent"[^>]*required/', $h));
chk('invite: fr html dir=ltr', str_contains($h, 'dir="ltr"'));
chk('invite: no leaked lang keys', ! str_contains($h, 'Referrals.invite.'));

// invite — member link, NO campaign
$h2 = $render('invite.php', [
    'code' => 'xy', 'campaign' => null, 'linkType' => 'member',
    'redirect' => 'https://public.test/register?sponsor=s1', 'csrf' => 'T2',
], $fr, 'fr');
chk('invite(no campaign): no campaign chip', ! str_contains($h2, $fr['invite']['campaignPrefix'] . ':'));
chk('invite(member): member destination copy', str_contains($h2, esc($fr['invite']['destMember'])));

// flash render
$GLOBALS['__sess'] = ['success' => 'Yay done'];
$h3 = $render('invite.php', ['code' => 'z', 'campaign' => null, 'linkType' => 'member', 'redirect' => 'https://public.test/', 'csrf' => 'T'], $fr, 'fr');
chk('invite: success flash rendered', str_contains($h3, 'Yay done'));
$GLOBALS['__sess'] = [];

// expired — ar (RTL)
$he = $render('invite_expired.php', ['code' => 'nope'], $ar, 'ar');
chk('expired: ar html dir=rtl', str_contains($he, 'dir="rtl"'));
chk('expired: home CTA to base_url', str_contains($he, 'href="https://public.test/"'));
chk('expired: no leaked lang keys', ! str_contains($he, 'Referrals.inviteExpired.'));

// ── 7. Field-sync: captureProspect writes the canonical prospect set ────────
echo "captureProspect field-sync (landing graded subset)\n";
chk('controller passes phone through', (bool) preg_match("/captureProspect\(.*?'phone'/s", $rc0 = (string) file_get_contents($controller)));
chk("source='link' written explicitly", (bool) preg_match('/function captureProspect.*?\'source\'\s*=>\s*\'link\'/s', $svc));
chk('owner/created_by = referrer', (bool) preg_match('/function captureProspect.*?\'owner_user_id\'/s', $svc) && (bool) preg_match('/function captureProspect.*?\'created_by\'/s', $svc));
chk('full_name written (both name cols)', (bool) preg_match('/function captureProspect.*?\'full_name\'/s', $svc));
chk("no plaintext 'email' => key in captureProspect", ! (bool) preg_match("/function captureProspect.*?'email'\s*=>/s", $svc));
chk('name gate on landing (parity)', (bool) preg_match('/function captureProspect.*?contact\.name_required/s', $svc));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
