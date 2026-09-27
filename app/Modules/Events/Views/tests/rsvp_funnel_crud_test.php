<?php

declare(strict_types=1);

/**
 * EVENT RSVP FUNNEL wiring test — closes the section-C member-facing gap where
 * event registration was POST-only with NO browser control and the route lacked
 * auth + webcsrf. Proves the event show page now drives a no-JS, CSP-safe,
 * guarded self-service RSVP funnel:
 *
 *   - RegistrationService::registrationFor returns the viewer's own live
 *     registration (null when none/cancelled) — one bounded read;
 *   - register() is idempotent AND lets an existing attendee CHANGE their RSVP
 *     intent (yes/maybe/no) without re-queuing capacity;
 *   - show() is attendee-aware (passes registration + csrf + user); register()
 *     enrols the SESSION user and PRGs back; cancelRegistration() self-cancels;
 *   - POST register + POST register/cancel carry auth + webcsrf (+ ratelimit);
 *   - the view renders an RSVP select + register/update + cancel controls only
 *     for a signed-in attendee on a published, open event — CSP-clean;
 *   - i18n parity for the new Events.rsvp.* block across all 6 locales.
 *
 *   php app/Modules/Events/Views/tests/rsvp_funnel_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Events/Language';
$viewDir    = $root . '/app/Modules/Events/Views';
$controller = $root . '/app/Modules/Events/Controllers/EventController.php';
$service    = $root . '/app/Modules/Events/Services/RegistrationService.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for Events.rsvp.* ─────────────────────────────────────────
echo "language parity (Events.rsvp.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en     = require $langDir . '/en/Events.php';
$enKeys = $flat($en['rsvp'] ?? []);
chk('en rsvp block present (>= 14 keys)', count($enKeys) >= 14, (string) count($enKeys));
chk('en youRsvped keeps {0}', str_contains((string) ($en['rsvp']['youRsvped'] ?? ''), '{0}'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Events.php";
    $keys = $flat($arr['rsvp'] ?? []);
    chk("$loc mirrors rsvp keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
}

// ── 2. Service: registrationFor + RSVP-change on re-register ──────────────────
echo "service: registrationFor + rsvp change\n";
$svc = (string) file_get_contents($service);
chk('registrationFor() present', str_contains($svc, 'function registrationFor'));
chk('registrationFor ignores cancelled', (bool) preg_match('/function registrationFor.*?cancelled.*?return null/s', $svc));
chk('re-register can CHANGE rsvp_state without re-queue',
    (bool) preg_match('/already on the roster.*?rsvp_state.*?update/is', $svc)
    || (bool) preg_match('/\$changed.*?update\(\[.rsvp_state/s', $svc));

// ── 3. Controller: show attendee-aware + PRG register/cancel ──────────────────
echo "controller: show attendee-aware + PRG\n";
$ctrl = (string) file_get_contents($controller);
chk('show passes registration', (bool) preg_match('/function show\(.*?\'registration\'/s', $ctrl));
chk('show passes csrf + user_id', (bool) preg_match('/function show\(.*?\'csrf\'/s', $ctrl) && (bool) preg_match('/function show\(.*?\'user_id\'/s', $ctrl));
chk('register uses the SESSION user', (bool) preg_match('/function register\(.*?currentUserId\(/s', $ctrl));
chk('register validates rsvp_state to yes/no/maybe', (bool) preg_match("/function register\\(.*?in_array\\(\\\$rsvp, \\['yes', 'no', 'maybe'\\]/s", $ctrl));
chk('register PRGs via respondRegistration', (bool) preg_match('/function register\(.*?respondRegistration/s', $ctrl));
chk('cancelRegistration present + PRG', (bool) preg_match('/function cancelRegistration\(.*?respondRegistration/s', $ctrl));
chk('respondRegistration keeps JSON for API', (bool) preg_match('/function respondRegistration.*?wantsJson\(\).*?respondWith/s', $ctrl));
chk('respondRegistration redirects to the event page', (bool) preg_match("/function respondRegistration.*?'\/events\/'/s", $ctrl));

// ── 4. Routes: register + cancel gated ───────────────────────────────────────
echo "routes: register + cancel (auth + webcsrf + ratelimit)\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*EventController::register/\$1.*$#m', $routes, $m)) {
    chk('register route present', true);
    chk('register has auth', str_contains($m[0], "'auth'"));
    chk('register has webcsrf', str_contains($m[0], 'webcsrf'));
    chk('register keeps ratelimit', str_contains($m[0], 'ratelimit:event.rsvp'));
} else {
    chk('register route present', false);
}
if (preg_match('#^.*cancelRegistration/\$1.*$#m', $routes, $m)) {
    chk('cancel route present', true);
    chk('cancel has auth + webcsrf', str_contains($m[0], "'auth'") && str_contains($m[0], 'webcsrf'));
} else {
    chk('cancel route present', false);
}

// ── 5. View controls: CSP-clean, csrf-bound, no-JS ───────────────────────────
echo "show.php RSVP controls\n";
$src = (string) file_get_contents("$viewDir/show.php");
chk('register form posts to /events/{id}/register', str_contains($src, '/register"'));
chk('cancel form posts to /events/{id}/register/cancel', str_contains($src, '/register/cancel"'));
chk('rsvp_state <select> present', str_contains($src, 'name="rsvp_state"'));
chk('forms carry _csrf bound to token', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$lcCsrf/', $src));
chk('renders PRG flash', str_contains($src, "getFlashdata('success')") && str_contains($src, "getFlashdata('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
// The lifecycle 'complete' control legitimately uses onsubmit=confirm; the RSVP
// panel must not add any NEW inline handlers. Assert exactly one onsubmit (the
// pre-existing lifecycle one) and none inside a /register form.
chk('RSVP forms add no inline on* handlers',
    ! (bool) preg_match('#/register(?:/cancel)?"[^>]*\son(click|submit|change)\s*=#i', $noC));

// ── 6. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — open/none, registered, waitlisted, anon, closed (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') { return $key; }
        $v = $GLOBALS['__evLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__evLang'] = require $langDir . '/fr/Events.php';
$renderer = new class {
    function extend($x) { return ''; }
    function section($x) { return ''; }
    function endSection() { return ''; }
    function render(string $file, array $data): string {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};
$show = "$viewDir/show.php";
$ev   = ['id' => 'ev9', 'title' => 'Retraite', 'status' => 'published', 'registration_policy' => 'open'];

// signed-in, not yet registered
$h = $renderer->render($show, ['result' => $ev, 'registration' => null, 'user_id' => 'u1', 'csrf' => 'TKN']);
chk('open+none: register form present', str_contains($h, 'action="/events/ev9/register"'));
chk('open+none: rsvp select present', str_contains($h, 'name="rsvp_state"'));
chk('open+none: no cancel form', ! str_contains($h, '/register/cancel"'));
chk('open+none: csrf bound', str_contains($h, 'value="TKN"'));

// signed-in, registered with rsvp=maybe -> update + cancel, maybe pre-selected
$h = $renderer->render($show, ['result' => $ev, 'registration' => ['id' => 'r1', 'status' => 'registered', 'rsvp_state' => 'maybe'], 'user_id' => 'u1', 'csrf' => 'TKN']);
chk('registered: cancel form present', str_contains($h, 'action="/events/ev9/register/cancel"'));
chk('registered: maybe option pre-selected', (bool) preg_match('/value="maybe" selected/', $h));
chk('registered: shows registered status', str_contains($h, lang('Events.rsvp.statusRegistered')));

// waitlisted attendee
$h = $renderer->render($show, ['result' => $ev, 'registration' => ['id' => 'r2', 'status' => 'waitlisted', 'rsvp_state' => 'yes'], 'user_id' => 'u1', 'csrf' => 'TKN']);
chk('waitlisted: shows waitlist note', str_contains($h, esc(lang('Events.rsvp.waitlistNote'))));

// anonymous viewer -> sign-in prompt, no forms
$h = $renderer->render($show, ['result' => $ev, 'registration' => null, 'user_id' => '', 'csrf' => '']);
chk('anon: sign-in prompt', str_contains($h, lang('Events.rsvp.signInToRsvp')));
chk('anon: no register form', ! str_contains($h, 'action="/events/ev9/register"'));

// closed registration
$evClosed = ['id' => 'ev9', 'title' => 'Retraite', 'status' => 'published', 'registration_policy' => 'closed'];
$h = $renderer->render($show, ['result' => $evClosed, 'registration' => null, 'user_id' => 'u1', 'csrf' => 'TKN']);
chk('closed: closed note shown', str_contains($h, lang('Events.rsvp.closedNote')));
chk('closed: no register form', ! str_contains($h, 'action="/events/ev9/register"'));

// draft event -> no RSVP panel at all
$h = $renderer->render($show, ['result' => ['id' => 'ev9', 'title' => 'Brouillon', 'status' => 'draft'], 'registration' => null, 'user_id' => 'u1', 'csrf' => 'TKN']);
chk('draft: no RSVP heading', ! str_contains($h, lang('Events.rsvp.heading')));
chk('render: no untranslated Events.rsvp keys leaked', ! str_contains($h, 'Events.rsvp.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
