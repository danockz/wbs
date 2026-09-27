<?php

declare(strict_types=1);

/**
 * CERTIFICATE consoles — the write face of CertificateController's
 * templatesConsole + eventConsole and the createTemplate / requestForEvent /
 * issue / revoke write actions.
 *
 * Two browser pages replace JSON-only endpoints:
 *   - GET certificates/templates    → org templates list + create form
 *   - GET events/{id}/certificates  → event certificates list + batch-request
 *     form + per-certificate issue (pending) / revoke (issued, needs reason) forms
 * Public verify + self-service download stay as-is (JSON / PDF stream). This test
 * covers key parity, both views' states, PRG wiring, and route guards.
 *
 *   php app/Modules/Events/Views/tests/certificate_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/CertificateController.php');
$svc    = file_get_contents($root . '/app/Modules/Events/Services/CertificateService.php');
$routes = file_get_contents($root . '/app/Config/Routes.php');

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

echo "language parity (Events.certificate.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['certificate'] ?? []);
chk('en defines Events.certificate.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['certificate'] ?? []));
    chk("$loc certificate.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);

// ---- templates console ---------------------------------------------------
$tfile = $viewDir . '/certificate_templates.php';
echo "\nview: templates console — empty\n";
$h = $render($tfile, ['templates' => [], 'csrf' => 'TKN'], 'en');
chk('empty templates hint', str_contains($h, 'No templates yet.'));
chk('create form posts to /certificates/templates', str_contains($h, 'action="/certificates/templates"'));
chk('create form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('required name field', str_contains($h, 'name="name" required'));
chk('required body_template field', str_contains($h, 'name="body_template" required'));
chk('event_type + version + signer fields', str_contains($h, 'name="event_type"') && str_contains($h, 'name="version"') && str_contains($h, 'name="signer_role"'));

echo "\nview: templates console — populated\n";
$h = $render($tfile, ['templates' => [
    ['id' => 't-1', 'name' => 'Completion', 'event_type' => 'course', 'version' => 2, 'signer_role' => 'Pastor', 'status' => 'active'],
    ['id' => 't-2', 'name' => 'Attendance', 'event_type' => null, 'version' => 1, 'signer_role' => null, 'status' => 'active'],
], 'csrf' => 'TKN'], 'en');
chk('renders template name', str_contains($h, 'Completion') && str_contains($h, 'Attendance'));
chk('null event_type shows Any type', str_contains($h, 'Any type'));
chk('no empty hint when populated', ! str_contains($h, 'No templates yet.'));

// ---- event console -------------------------------------------------------
$efile = $viewDir . '/certificate_event.php';
echo "\nview: event console — empty\n";
$h = $render($efile, ['certificates' => [], 'templates' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('batch-request form posts to /events/ev-1/certificates/request', str_contains($h, 'action="/events/ev-1/certificates/request"'));
chk('request form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('template picker has auto option', str_contains($h, 'name="template_id"'));
chk('empty certificates hint', str_contains($h, 'No certificates requested yet.'));

echo "\nview: event console — populated (pending + issued + revoked)\n";
$h = $render($efile, [
    'certificates' => [
        ['id' => 'c-1', 'user_id' => 'u-1', 'verification_id' => 'aaa111', 'status' => 'pending'],
        ['id' => 'c-2', 'user_id' => 'u-2', 'verification_id' => 'bbb222', 'status' => 'issued'],
        ['id' => 'c-3', 'user_id' => 'u-3', 'verification_id' => 'ccc333', 'status' => 'revoked', 'revoke_reason' => 'error'],
    ],
    'templates' => [['id' => 't-1', 'name' => 'Completion', 'version' => 2]],
    'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('pending row shows issue form', str_contains($h, 'action="/certificates/c-1/issue"'));
chk('issued row shows revoke form', str_contains($h, 'action="/certificates/c-2/revoke"'));
chk('revoke form requires reason', str_contains($h, 'name="reason" required'));
chk('revoke has confirm()', str_contains($h, 'onsubmit="return confirm('));
chk('pending has no revoke form', ! str_contains($h, 'action="/certificates/c-1/revoke"'));
chk('issued has no issue form', ! str_contains($h, 'action="/certificates/c-2/issue"'));
chk('revoked row shows reason not a form', str_contains($h, 'error') && ! str_contains($h, 'action="/certificates/c-3/issue"') && ! str_contains($h, 'action="/certificates/c-3/revoke"'));
chk('template picker lists template', str_contains($h, 'Completion v2'));
chk('status pills rendered', str_contains($h, 'pill pending') && str_contains($h, 'pill issued') && str_contains($h, 'pill revoked'));

echo "\nview: escaping + i18n + RTL\n";
$h = $render($efile, ['certificates' => [['id' => 'c-x', 'user_id' => '<b>u</b>', 'verification_id' => 'v', 'status' => 'pending']], 'templates' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('escapes user_id', ! str_contains($h, '<b>u</b>') && str_contains($h, '&lt;b&gt;'));
$h = $render($tfile, ['templates' => [], 'csrf' => 'TKN'], 'fr');
chk('fr localized templates heading', str_contains($h, 'Modèles de certificat'));
$h = $render($efile, ['certificates' => [], 'templates' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'ar');
chk('ar event console dir=rtl', str_contains($h, 'dir="rtl"'));

echo "\ncontroller wiring\n";
chk('templatesConsole action exists', str_contains($ctrl, 'function templatesConsole('));
chk('templatesConsole renders view', str_contains($ctrl, 'WBS\Events\Views\certificate_templates'));
chk('eventConsole action exists', str_contains($ctrl, 'function eventConsole('));
chk('eventConsole renders view', str_contains($ctrl, 'WBS\Events\Views\certificate_event'));
chk('both consoles serve JSON for API', substr_count($ctrl, 'wantsJson()') >= 4);
chk('createTemplate PRG redirects to /certificates/templates', str_contains($ctrl, "/certificates/templates'"));
chk('requestForEvent uses respondCertEvent', (bool) preg_match('/function requestForEvent.*?respondCertEvent/s', $ctrl));
chk('issue uses respondCertEvent', (bool) preg_match('/function issue.*?respondCertEvent/s', $ctrl));
chk('revoke uses respondCertEvent', (bool) preg_match('/function revoke.*?respondCertEvent/s', $ctrl));
chk('respondCertEvent redirects to /certificates event console', str_contains($ctrl, "/certificates'"));
chk('respondCertEvent keeps JSON for API', (bool) preg_match('/function respondCertEvent.*?wantsJson\(\)/s', $ctrl));
chk('flash keys used', str_contains($ctrl, 'templateCreatedFlash') && str_contains($ctrl, 'requestedFlash') && str_contains($ctrl, 'issuedFlash') && str_contains($ctrl, 'revokedFlash'));

echo "\nservice wiring\n";
chk('listTemplates() read helper exists', str_contains($svc, 'function listTemplates('));
chk('listForEvent() read helper exists', str_contains($svc, 'function listForEvent('));
chk('issue result carries event_id', (bool) preg_match("/'status'\s*=>\s*'issued',\s*\n\s*'verification_id'/s", $svc) && (bool) preg_match("/function issue.*?'event_id'\s*=>\s*\\\$cert\['event_id'\]/s", $svc));
chk('revoke result carries event_id', (bool) preg_match("/function revoke.*?'event_id'\s*=>\s*\\\$cert\['event_id'\]/s", $svc));

echo "\nroutes\n";
chk('GET event certificates console', (bool) preg_match('#get\([^\n]*\(:segment\)/certificates\x27[^\n]*eventConsole#', $routes));
chk('GET certificates/templates console', (bool) preg_match('#get\(\x27templates\x27[^\n]*templatesConsole#', $routes));
chk('certificates/request POST webcsrf', (bool) preg_match('#certificates/request\x27.*?requestForEvent.*?webcsrf#s', $routes));
chk('POST templates webcsrf', (bool) preg_match('#post\(\x27templates\x27.*?createTemplate.*?webcsrf#s', $routes));
chk('issue POST webcsrf', (bool) preg_match('#\(:segment\)/issue\x27.*?issue.*?webcsrf#s', $routes));
chk('revoke POST webcsrf', (bool) preg_match('#\(:segment\)/revoke\x27.*?revoke.*?webcsrf#s', $routes));
chk('public verify stays open (no webcsrf)', str_contains($routes, 'certificates/verify/(:segment)') && ! (bool) preg_match('#verify/\(:segment\).*?webcsrf#', $routes));
chk('self-service download stays GET (no webcsrf)', (bool) preg_match('#\(:segment\)/download\x27.*?download#', $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
