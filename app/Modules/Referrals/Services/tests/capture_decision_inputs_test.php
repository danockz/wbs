<?php

declare(strict_types=1);

/**
 * CAPTURE DECISION INPUTS test — locks the onboarding decision of 2026-09-24:
 *
 *   1. Integration decisions are OPTIONAL inputs on EVERY capture path
 *      (member add-contact, staff bulk, event-guest register, cloaked landing),
 *      offered only when the hierarchical group config
 *      (`referrals.integration_decisions` + `capture_inputs` type list,
 *      default OFF/empty) says so.
 *   2. Assisted submissions (member/staff) are born CONFIRMED immediately;
 *      guest/visitor submissions are SELF flavor (event_guest/landing source,
 *      born PENDING until the owner confirms).
 *   3. Temperature is AUTOMATED: the create form has no temperature control
 *      and create() never reads one; only the follow-up triage form may set it.
 *
 * Source-level (no DB).
 *
 *   php app/Modules/Referrals/Services/tests/capture_decision_inputs_test.php
 */

$root = dirname(__DIR__, 5);

$config     = $root . '/app/Modules/Referrals/Support/IntegrationConfig.php';
$service    = $root . '/app/Modules/Referrals/Services/IntegrationService.php';
$cbs        = $root . '/app/Modules/Referrals/Services/ContactBookService.php';
$rs         = $root . '/app/Modules/Referrals/Services/ReferralService.php';
$contactCtrl = $root . '/app/Modules/Referrals/Controllers/ContactBookController.php';
$refCtrl    = $root . '/app/Modules/Referrals/Controllers/ReferralController.php';
$eventCtrl  = $root . '/app/Modules/Events/Controllers/EventController.php';
$factories  = $root . '/app/Modules/Referrals/Config/Services.php';
$contactsView = $root . '/app/Modules/Referrals/Views/contacts_index.php';
$inviteView = $root . '/app/Modules/Referrals/Views/invite.php';
$seeder     = $root . '/app/Modules/Admin/Database/Seeds/AdminConfigSeeder.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** Slice from a function signature to the next top-level method (or EOF). */
function body_of(string $src, string $sig): string
{
    $start = strpos($src, $sig);
    if ($start === false) {
        return '';
    }
    $rest = substr($src, $start + strlen($sig));
    $next = strpos($rest, "\n    public function ");
    if ($next === false) {
        $next = strpos($rest, "\n    private function ");
    }

    return $next === false ? $rest : substr($rest, 0, $next);
}

$cfg  = file_get_contents($config);
$svc  = file_get_contents($service);
$cbsS = file_get_contents($cbs);
$rsS  = file_get_contents($rs);
$cc   = file_get_contents($contactCtrl);
$rc   = file_get_contents($refCtrl);
$ec   = file_get_contents($eventCtrl);
$fac  = file_get_contents($factories);
$cv   = file_get_contents($contactsView);
$iv   = file_get_contents($inviteView);
$sd   = file_get_contents($seeder);

// ── 1. Config shape ─────────────────────────────────────────────────────────
echo "IntegrationConfig::capture_inputs\n";
chk('DEFAULTS carry capture_inputs', (bool) preg_match("/'capture_inputs'\s*=>\s*\[\]/", $cfg));
chk('normalize intersects with IntegrationDecision::TYPES', str_contains($cfg, 'in_array($t, IntegrationDecision::TYPES, true)'));
chk('captureInputsOf is OFF-unless-enabled', (bool) preg_match('/function captureInputsOf.*?enabled.*?false.*?return \[\]/s', $cfg));
chk('seeder sample documents the key', str_contains($sd, "'capture_inputs'"));
chk('fail-closed message key exists in service', str_contains($svc, 'integration.capture_input_disabled'));

// ── 2. Service API ──────────────────────────────────────────────────────────
echo "IntegrationService capture API\n";
foreach (['captureInputsFor', 'captureInputAllowed', 'captureInputGate', 'recordAssistedForContact'] as $fn) {
    chk("{$fn} exists", str_contains($svc, "function {$fn}("));
}
chk('recordAssisted: source assisted + born confirmed', (bool) preg_match('/function recordAssistedForContact.*?SOURCE_ASSISTED.*?\'confirmed\'/s', $svc));
chk('recordAssisted: recorded_by carried', (bool) preg_match('/function recordAssistedForContact.*?\$actorId !== \'\' \? \$actorId : null/s', $svc));
$raBody = body_of($svc, 'function recordAssistedForContact(');
chk('recordAssisted: does NOT depend on allow_self_declaration', $raBody !== '' && ! str_contains($raBody, 'allow_self_declaration'));
chk('insertRow accepts recordedBy', str_contains($svc, '?string $recordedBy = null'));

// ── 3. Assisted create path (member + bulk) ─────────────────────────────────
echo "assisted create/bulk path\n";
chk('createContact gates BEFORE insert (stale form cannot partial-write)', (bool) preg_match('/captureInputGate\(.*?\n.*?\n.*?\n\s*\$this->db->table\(\'prospects\'\)->insert/s', $cbsS) || (bool) preg_match('/captureInputGate.*?return \$gate;\s*\}\s*\}\s*\n\s*\$this->db->table\(\'prospects\'\)->insert/s', $cbsS));
chk('createContact records assisted decision after insert', (bool) preg_match('/function createContact.*?recordAssistedForContact/s', $cbsS));
chk('ContactBookService carries optional IntegrationService dep', str_contains($cbsS, '?IntegrationService $integration = null'));
chk('factory wires integration into contactBook', (bool) preg_match('/function contactBook\(.*?self::integration\(/s', $fac));
chk('controller create passes decision_type/date/note', (bool) preg_match('/function create\(.*?\'decision_type\'/s', $cc) && (bool) preg_match('/function create\(.*?\'decision_date\'/s', $cc) && (bool) preg_match('/function create\(.*?\'decision_note\'/s', $cc));
$bulkBody = body_of($cbsS, 'function bulkCreate(');
chk('bulkCreate strips manual temperature (automated)', $bulkBody !== '' && str_contains($bulkBody, "unset(\$payload['temperature'])"));
chk('bulkCreate rows flow through createContact (decision fields included)', $bulkBody !== '' && str_contains($bulkBody, 'createContact($organizationId, $payload)'));

// ── 4. Public paths: guest + landing (SELF flavor, skip-silently) ───────────
echo "public capture paths (guest + landing)\n";
chk('registerGuest passes decision fields', (bool) preg_match("/captureGuestFromInvite\(.*?'decision_type'/s", $ec));
chk('guest decision gated by captureInputAllowed', (bool) preg_match('/function captureGuestFromInvite.*?captureInputAllowed/s', $cbsS));
chk('guest decision uses declareForContact with event_guest source', (bool) preg_match('/function captureGuestFromInvite.*?\'source\'\s*=>\s*\'event_guest\'/s', $cbsS));
chk('guest decision is try/catch best-effort (never blocks capture)', (bool) preg_match('/guestDecision.*?try \{/s', $cbsS));
chk('landing controller passes decision fields', (bool) preg_match("/captureProspect\(.*?'decision_type'/s", $rc));
$capBody = body_of($rsS, 'function captureProspect(');
chk('captureProspect gates via captureInputAllowed on placement group', $capBody !== '' && str_contains($capBody, 'captureInputAllowed($groupId'));
chk('landing decision uses declareForContact with landing source (pending)', $capBody !== '' && (bool) preg_match("/'source'\s*=>\s*'landing'/", $capBody));
chk('landing decision is try/catch best-effort', $capBody !== '' && (bool) preg_match('/captureInputAllowed.*?try \{/s', $capBody));
chk('factory wires integration into referrals() (landing)', (bool) preg_match('/function referrals\(.*?self::integration\(/s', $fac));

// ── 5. Views: inputs render ONLY when configured ────────────────────────────
echo "views\n";
chk('contacts_index create form offers decision block (config-gated)', (bool) preg_match('/if \(\$decisionInputs !== \[\]\):.*?name="decision_type"/s', $cv));
chk('contacts_index passes decision fields on create POST', str_contains($cv, 'name="decision_type"') && str_contains($cv, 'name="decision_date"') && str_contains($cv, 'name="decision_note"'));
$idxBody = body_of($cc, 'function index(');
chk('index() resolves decisionInputs from placement group config', $idxBody !== '' && str_contains($idxBody, 'captureInputsFor($placementGroup)') && str_contains($idxBody, "'decisionInputs'"));
chk('invite landing offers decision block (config-gated)', (bool) preg_match('/if \(\$decisionInputs !== \[\]\):.*?name="decision_type"/s', $iv));
chk('land() resolves decisionInputs via captureInputsForLink (placement stays in the service)', (bool) preg_match('/function land\(.*?captureInputsForLink\(\$link\)/s', $rc) && ! str_contains(body_of($rc, 'function land('), 'referrer'));
chk('captureInputsForLink mirrors captureProspect placement (org + owner, null request)', (bool) preg_match('/function captureInputsForLink\(.*?placementFor\(.*?organization_id.*?referrer_id.*?null,/s', $rsS));

// ── 6. Temperature automation ───────────────────────────────────────────────
echo "temperature automation\n";
$createBody = body_of($cc, 'function create(');
chk('create controller never reads temperature', $createBody !== '' && ! str_contains($createBody, "'temperature'"));
chk('create form has NO temperature control (exactly 1 name=temperature left — follow-up triage)', substr_count($cv, 'name="temperature"') === 1, (string) substr_count($cv, 'name="temperature"'));
chk('follow-up triage still adjusts temperature', (bool) preg_match('/function followUp\(.*?\'temperature\'/s', $cc));
chk('temperature filter retained', str_contains($cc, "'temperature'   => \$this->field('temperature')"));

// ── 7. i18n for the new inputs (6 locales) ──────────────────────────────────
echo "i18n\n";
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $en = (array) require $root . "/app/Modules/Referrals/Language/{$loc}/Referrals.php";
    $ok = isset($en['decisionSection'], $en['decisionTypeLbl'], $en['decisionNone'], $en['decisionDateLbl'], $en['decisionHint'])
        && isset($en['invite']['decisionSection'], $en['invite']['decisionLbl'], $en['invite']['decisionDateLbl'], $en['invite']['decisionHint']);
    $int = (array) require $root . "/app/Modules/Referrals/Language/{$loc}/integration.php";
    $ok = $ok && isset($int['capture_input_disabled']);
    chk("Referrals + integration decision keys present ({$loc})", $ok);
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
