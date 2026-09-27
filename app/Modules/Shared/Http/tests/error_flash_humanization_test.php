<?php

declare(strict_types=1);

/**
 * ERROR-FLASH HUMANIZATION test — locks the FR-ARC-002 JSON-in-views sweep
 * (2026-09-24): NO browser-visible flow may render a raw machine key or JSON.
 *
 *   - WBS\Shared\Support\Messages::humanize resolves: catalog key -> lang(),
 *     dotted key -> prettified last segment, UPPER_SNAKE code -> prettified,
 *     human copy -> passthrough, empty -> ''.
 *   - BaseController::errText wraps it and EVERY flash site uses it (zero raw
 *     `with('error', (string) $…->message)` sites remain).
 *   - Silent-PRG controllers (contact book, integration) now flash failures.
 *   - data_page humanizes detail/title; respondRegistration uses humanizeError;
 *     Campaigns' raw fallback is gone.
 *   - Test LOCKS preserved: negotiation_test's literal `'result' =>
 *     $result->toArray()` + ≥2 `redirect()->to($redirectTo)->with('error'`
 *     sites, event humanizeError method name, committee/awards `with('error'`.
 *
 *   php app/Modules/Shared/Http/tests/error_flash_humanization_test.php
 */

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Messages.php';

use WBS\Shared\Support\Messages;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. Messages::humanize behavior (standalone: lang() absent -> fallback) ──
echo "Messages::humanize\n";
chk('dotted key -> prettified last segment', Messages::humanize('contact.name_required') === 'Name required', Messages::humanize('contact.name_required'));
chk('nested dotted key -> prettified', Messages::humanize('group.join_email_required') === 'Join email required', Messages::humanize('group.join_email_required'));
chk('UPPER_SNAKE code -> prettified', Messages::humanize('CSRF_FAILED') === 'Csrf failed', Messages::humanize('CSRF_FAILED'));
chk('human copy passthrough', Messages::humanize('Invalid email or password.') === 'Invalid email or password.');
chk('empty -> empty', Messages::humanize('') === '' && Messages::humanize(null) === '');
chk('whitespace trimmed', Messages::humanize('  ') === '');
chk('camel token prettified', Messages::humanize('Events.invite.errInviteRequired') === 'Err invite required', Messages::humanize('Events.invite.errInviteRequired'));

// ── 2. BaseController wiring ─────────────────────────────────────────────────
echo "BaseController\n";
$base = file_get_contents($root . '/app/Modules/Shared/Http/BaseController.php');
chk('errText helper exists (protected)', (bool) preg_match('/protected function errText\(/', $base));
chk('errText delegates to Messages::humanize', str_contains($base, 'Messages::humanize($message)'));
chk('respondWith failure redirect humanizes', (bool) preg_match("/redirect\(\)->to\(\\\$redirectTo\)->with\('error', \\\$this->errText\(/", $base));
chk('respondHtml failure redirect humanizes', substr_count($base, '$this->errText($result->message)') >= 2, (string) substr_count($base, '$this->errText($result->message)'));
chk("negotiation lock: literal 'result' => \$result->toArray() kept", str_contains($base, "'result' => \$result->toArray()"));
chk('negotiation lock: >=2 redirect()->to($redirectTo)->with(error sites', substr_count($base, "redirect()->to(\$redirectTo)->with('error'") >= 2, (string) substr_count($base, "redirect()->to(\$redirectTo)->with('error'"));
chk('wantsJson short-circuit kept', (bool) preg_match('/function respondWith\(.*?wantsJson\(\).*?respondJson/s', $base));
chk("FALLBACK_HTML_VIEW still Shared\\Views\\data_page", (bool) preg_match("/FALLBACK_HTML_VIEW\s*=\s*'.*data_page'/", $base));

// ── 3. Zero raw flash sites remain ───────────────────────────────────────────
echo "flash site sweep\n";
$raw = [];
$humanized = 0;
foreach (glob($root . '/app/Modules/*/Controllers/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    // The raw pattern: with('error', (string) $var->message) — no errText/lang/humanizeError wrapper.
    if (preg_match_all("/->with\('error',\s*\(string\)\s*(?!lang\(|\\\$this->errText|\\\$this->humanizeError)([^)]*)/", $src, $mm)) {
        foreach ($mm[0] as $hit) {
            $raw[] = basename($f) . ': ' . trim($hit);
        }
    }
    $humanized += substr_count($src, '$this->errText(');
}
chk('zero raw with(error,(string)$…->message) sites in controllers', $raw === [], implode(' | ', array_slice($raw, 0, 4)));
chk('errText used at >= 40 sites', $humanized >= 40, (string) $humanized);

// ── 4. Silent PRG failures now flash ─────────────────────────────────────────
echo "silent PRG fixes\n";
$contactCtrl = file_get_contents($root . '/app/Modules/Referrals/Controllers/ContactBookController.php');
chk('ContactBookController flashes failures (>=5)', substr_count($contactCtrl, "with('error', \$this->errText") >= 5, (string) substr_count($contactCtrl, "with('error', \$this->errText"));
$contactsView = file_get_contents($root . '/app/Modules/Referrals/Views/contacts_index.php');
chk('contacts_index renders the flash', str_contains($contactsView, "getFlashdata('error')"));
$integrationCtrl = file_get_contents($root . '/app/Modules/Referrals/Controllers/IntegrationController.php');
chk('IntegrationController declare/decide flash failures', substr_count($integrationCtrl, "with('error', \$this->errText") >= 2, (string) substr_count($integrationCtrl, "with('error', \$this->errText"));

// ── 5. Other root causes closed ──────────────────────────────────────────────
echo "remaining root causes\n";
$eventCtrl = file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
chk('respondRegistration humanizes (humanizeError lock kept)', (bool) preg_match('/function respondRegistration\(.*?humanizeError\(/s', $eventCtrl));
chk('humanizeError method exists', str_contains($eventCtrl, 'function humanizeError'));
$eventEdit = file_get_contents($root . '/app/Modules/Events/Controllers/EventController.php');
chk('humanizeError delegates to Messages (no raw return)', (bool) preg_match('/function humanizeError\(.*?Messages::humanize/s', $eventEdit));
$campaigns = file_get_contents($root . '/app/Modules/Gamification/Controllers/CampaignsController.php');
chk('Campaigns raw $friendly fallback gone', ! str_contains($campaigns, '$friendly = (string) $result->message') && str_contains($campaigns, '$friendly = $this->errText'));
$admin = file_get_contents($root . '/app/Modules/Admin/Controllers/AdminController.php');
chk('AdminController lang()-wraps via errText', substr_count($admin, '$this->errText') >= 2, (string) substr_count($admin, '$this->errText'));
$sessionCtrl = file_get_contents($root . '/app/Modules/Identity/Controllers/WebSessionController.php');
chk('profilePrg humanizes', (bool) preg_match('/function profilePrg\(.*?errText\(/s', $sessionCtrl));
$groupCtrl = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupPublicController.php');
chk('public join failure list humanized', str_contains($groupCtrl, "\$this->errText((string) \$result->message)"));
$dataPage = file_get_contents($root . '/app/Modules/Shared/Views/data_page.php');
chk('data_page humanizes problem title/detail', str_contains($dataPage, 'Messages::humanize') && str_contains($dataPage, "\$payload['detail']") && str_contains($dataPage, "\$payload['title']"));
chk('data_page contract kept (layouts/app + $isOk)', str_contains($dataPage, "extend('layouts/app')") && str_contains($dataPage, '$isOk'));

// ── 6. Flash-family catalogs shipped (lang() can actually translate) ────────
echo "onboarding family catalogs\n";
foreach ([
    ['Referrals', 'contact'],
    ['Referrals', 'integration'],
    ['Referrals', 'referral'],
    ['Referrals', 'sponsorship'],
    ['Identity', 'Identity'],
    ['Identity', 'token'],
    ['Groups', 'group'],
    ['Journey', 'Journey'],
    ['Events', 'event'],
] as [$mod, $ns]) {
    $en = $root . "/app/Modules/{$mod}/Language/en/{$ns}.php";
    $ok = is_file($en);
    $localesOk = $ok;
    if ($ok) {
        foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
            $localesOk = $localesOk && is_file($root . "/app/Modules/{$mod}/Language/{$loc}/{$ns}.php");
        }
    }
    chk("{$mod}/{$ns} catalog shipped x6", $ok && $localesOk);
}
$enContact = is_file($root . '/app/Modules/Referrals/Language/en/contact.php')
    ? (array) require $root . '/app/Modules/Referrals/Language/en/contact.php'
    : [];
$enIdentity = (array) require $root . '/app/Modules/Identity/Language/en/Identity.php';
chk('Identity.auth_failed merged into PascalCase catalog', ($enIdentity['auth_failed'] ?? '') !== '');
$enJourney = (array) require $root . '/app/Modules/Journey/Language/en/Journey.php';
chk('Journey.integration_required merged into PascalCase catalog', ($enJourney['integration_required'] ?? '') !== '');
chk('contact.name_required resolves from catalog', ($enContact['name_required'] ?? '') !== '', (string) ($enContact['name_required'] ?? 'MISSING'));
$enGroup = is_file($root . '/app/Modules/Groups/Language/en/group.php')
    ? (array) require $root . '/app/Modules/Groups/Language/en/group.php'
    : [];
chk('group.join_invalid_phone resolves from catalog', ($enGroup['join_invalid_phone'] ?? '') !== '');

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
