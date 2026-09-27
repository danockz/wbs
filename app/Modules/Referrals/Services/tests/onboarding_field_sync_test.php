<?php

declare(strict_types=1);

/**
 * ONBOARDING FIELD-SYNC test — locks the field-sync decision of 2026-09-24:
 * every person-writing path writes the SAME canonical field set (with the one
 * documented graded subset on the cloaked-link landing), every users writer
 * carries status evidence + org-default locale/timezone, the journey_stage
 * vocabulary is the seeder's canonical 8, and the `users` schema carries the
 * per-column justification (migration 000092) surfaced by /me/profile.
 *
 * Source-level (no DB): greps the exact writers the matrix documents.
 *
 *   php app/Modules/Referrals/Services/tests/onboarding_field_sync_test.php
 */

$root = dirname(__DIR__, 5);

$referralService = $root . '/app/Modules/Referrals/Services/ReferralService.php';
$contactService  = $root . '/app/Modules/Referrals/Services/ContactBookService.php';
$referralCtrl    = $root . '/app/Modules/Referrals/Controllers/ReferralController.php';
$contactCtrl     = $root . '/app/Modules/Referrals/Controllers/ContactBookController.php';
$inviteView      = $root . '/app/Modules/Referrals/Views/invite.php';
$contactsView    = $root . '/app/Modules/Referrals/Views/contacts_index.php';
$eventCtrl       = $root . '/app/Modules/Events/Controllers/EventController.php';
$groupService    = $root . '/app/Modules/Groups/Services/GroupPublicService.php';
$groupCtrl       = $root . '/app/Modules/Groups/Controllers/GroupPublicController.php';
$accountService  = $root . '/app/Modules/Identity/Services/AccountService.php';
$authCtrl        = $root . '/app/Modules/Identity/Controllers/AuthController.php';
$sessionCtrl     = $root . '/app/Modules/Identity/Controllers/WebSessionController.php';
$profileView     = $root . '/app/Modules/Identity/Views/profile.php';
$seeder          = $root . '/app/Modules/Journey/Database/Seeds/JourneyStageSeeder.php';
$migration       = $root . '/app/Modules/Identity/Database/Migrations/2026-09-24-000092_JustifyUserProfileColumns.php';
$servicesFact    = $root . '/app/Modules/Referrals/Config/Services.php';
$groupFact       = $root . '/app/Modules/Groups/Config/Services.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$rs   = file_get_contents($referralService);
$cs   = file_get_contents($contactService);
$rc   = file_get_contents($referralCtrl);
$cc   = file_get_contents($contactCtrl);
$iv   = file_get_contents($inviteView);
$cv   = file_get_contents($contactsView);
$ec   = file_get_contents($eventCtrl);
$gs   = file_get_contents($groupService);
$gc   = file_get_contents($groupCtrl);
$as   = file_get_contents($accountService);
$ac   = file_get_contents($authCtrl);
$sc   = file_get_contents($sessionCtrl);
$pv   = file_get_contents($profileView);
$sd   = file_get_contents($seeder);
$mg   = file_get_contents($migration);
$sf   = file_get_contents($servicesFact);
$gcf  = file_get_contents($groupFact);

// ── 1. Journey stage vocabulary: controller STAGES == seeder's canonical 8 ───
echo "journey stage vocabulary\n";
preg_match('/private const STAGES = \[(.*?)\];/s', $cc, $m);
$stages = [];
if (isset($m[1])) {
    preg_match_all("/'([a-z_]+)'/", $m[1], $sm);
    $stages = $sm[1];
}
preg_match('/private const STAGES = \[(.*?)\n    \];/s', $sd, $ms);
$seederKeys = [];
if (isset($ms[1])) {
    preg_match_all("/^\s*'([a-z_]+)'/m", $ms[1], $sm);
    $seederKeys = $sm[1];
}
chk('STAGES extracted from ContactBookController', $stages !== [], implode(',', $stages));
chk(
    'STAGES matches JourneyStageSeeder codes exactly',
    $stages !== [] && $seederKeys !== [] && $stages === $seederKeys,
    'ctrl=' . implode(',', $stages) . ' seeder=' . implode(',', $seederKeys),
);
chk('STAGES has exactly the canonical 8', count($stages) === 8, (string) count($stages));
chk('no bogus foundation/member stage values in STAGES', ! in_array('foundation', $stages, true) && ! in_array('member', $stages, true));

// ── 2. Landing capture (captureProspect) = canonical prospect insert + subset ─
echo "landing capture (ReferralService::captureProspect)\n";
chk('captureProspect exists', str_contains($rs, 'function captureProspect'));
chk('consent gate kept (test lock)', (bool) preg_match('/function captureProspect.*?CONSENT_REQUIRED/s', $rs));
chk('name gate added (parity with every path)', (bool) preg_match('/function captureProspect.*?contact\.name_required/s', $rs));
foreach ([
    "'source'                  => 'link'" => 'source=link written explicitly',
    "'owner_user_id'"          => 'owner = referrer',
    "'created_by'"             => 'created_by = referrer',
    "'full_name'"              => 'full_name (both name cols)',
    "'phone'"                  => 'phone carried through',
    "'assigned_group_id'"      => 'mentor placement',
    'placementFor'             => 'placementFor() used',
    "'journey_stage'"          => 'journey_stage explicit',
    "'temperature'"            => 'temperature explicit',
    "'updated_at'"             => 'updated_at written',
    "'address_id'"             => 'address_id key written',
    "'notes'"                  => 'notes key written',
    "'invite_context_type'"    => 'invite context keys written',
    "'coords_consent_verbal'"  => 'GPS consent flags written',
    "'email_hash'"             => 'email_hash written',
] as $needle => $label) {
    chk($label, str_contains($rs, $needle));
}
chk("plaintext 'email' => NEVER written on landing (privacy lock)", ! (bool) preg_match("/function captureProspect.*?'email'\s*=>/s", $rs));
chk('prospectGroups factory wired into referrals()', (bool) preg_match('/function referrals\(.*?self::prospectGroups\(/s', $sf));

// ── 3. Landing controller + view carry phone; consent stays required ─────────
echo "landing controller + invite view\n";
chk('ReferralController passes phone to captureProspect', (bool) preg_match("/captureProspect\(.*?'phone'/s", $rc));
chk('invite view renders phone input', str_contains($iv, 'name="phone"'));
chk('invite view keeps required consent checkbox', (bool) preg_match('/name="consent"[^>]*required/', $iv));
chk('invite i18n: phoneLbl + phonePh exist (en)', (bool) preg_match("/'phoneLbl'\s*=>/", file_get_contents($root . '/app/Modules/Referrals/Language/en/Referrals.php')));

// ── 4. Member address book: consent checkbox + flash banner ──────────────────
echo "member address book\n";
chk('contacts_index sends name="consent" (required)', (bool) preg_match('/name="consent"[^>]*required/', $cv) || (bool) preg_match('/type="checkbox"\s+name="consent"\s+value="1"\s+required/', $cv));
chk('contacts_index renders PRG flash', str_contains($cv, "getFlashdata('error')"));
chk('ContactBookController create() passes consent', (bool) preg_match('/function create\(.*?\'consent\'\s*=>/s', $cc));
chk('ContactBookController flashes PRG failures (no silent redirect)', substr_count($cc, "with('error', \$this->errText") >= 5, (string) substr_count($cc, "with('error', \$this->errText"));

// ── 5. Guest capture passes consent ──────────────────────────────────────────
echo "event guest capture\n";
chk('registerGuest passes consent input', (bool) preg_match("/captureGuestFromInvite\(.*?'consent'/s", $ec));
chk('captureGuestFromInvite forwards consent to createContact', (bool) preg_match('/function captureGuestFromInvite.*?\'consent\'\s*=>\s*! empty\(\$data\[\'consent\'\]\)/s', $cs));

// ── 6. users writers: register (canonical) ───────────────────────────────────
echo "users writers\n";
chk('register reads organizations for locale/timezone fallback', (bool) preg_match("/function register\(.*?organizations/s", $as));
chk('register org-defaults locale', str_contains($as, "\$org['default_locale'] ?? 'en'"));
chk('register org-defaults timezone', str_contains($as, "\$org['timezone'] ?? 'UTC'"));
chk('AuthController passes locale/timezone through (null -> org default)', str_contains($ac, "'locale'        => \$in['locale'] ?? null") || (bool) preg_match("/'locale'\s*=>\s*\\\$in\['locale'\]\s*\?\?\s*null/", $ac));

// users writers: public join
chk('findOrCreateUser writes phone trio keys', (bool) preg_match('/function findOrCreateUser.*?\'phone_input\'/s', $gs) && (bool) preg_match('/function findOrCreateUser.*?\'phone_region\'/s', $gs));
chk('findOrCreateUser writes updated_at', (bool) preg_match('/function findOrCreateUser.*?\'updated_at\'/s', $gs));
chk('findOrCreateUser writes mfa_enabled', (bool) preg_match('/function findOrCreateUser.*?\'mfa_enabled\'/s', $gs));
chk('findOrCreateUser writes status_reason=group_join', str_contains($gs, "'status_reason'     => 'group_join'"));
chk('findOrCreateUser writes transition evidence row', (bool) preg_match('/function findOrCreateUser.*?account_state_transitions/s', $gs));
chk('findOrCreateUser uses ORG locale/timezone (not column defaults)', (bool) preg_match('/function findOrCreateUser.*?default_locale/s', $gs));
chk('selfJoin validates optional phone (INVALID_PHONE)', (bool) preg_match('/function selfJoin.*?INVALID_PHONE/s', $gs));
chk('join failure keeps phone sticky in old input', str_contains($gc, "'phone' => \$in['phone'] ?? ''"));
chk('groupPublic factory wires PhoneNormalizer + policies', (bool) preg_match('/function groupPublic\(.*?phoneNormalizer\(\)/s', $gcf) && (bool) preg_match('/function groupPublic\(.*?identityPolicies\(\)/s', $gcf));

// users writers: contact -> user promotion
chk('ensureContactUser reads organizations (no hardcoded region)', (bool) preg_match('/function ensureContactUser.*?table\(\'organizations\'\)/s', $cs) && (bool) preg_match('/function ensureContactUser.*?\'locale\'\s*=>\s*\$orgLocale/s', $cs) && (bool) preg_match('/function ensureContactUser.*?\'timezone\'\s*=>\s*\$orgTz/s', $cs));
chk('ensureContactUser copies phone via PhoneNormalizer', (bool) preg_match('/function ensureContactUser.*?phoneInput/s', $cs) && (bool) preg_match('/function ensureContactUser.*?phones->normalize/s', $cs));
chk('ensureContactUser writes status_reason=contact_promotion', str_contains($cs, "'status_reason'     => 'contact_promotion'"));
chk('ensureContactUser writes transition evidence row', (bool) preg_match('/function ensureContactUser.*?account_state_transitions/s', $cs));
chk('contactBook factory wires PhoneNormalizer + policies', (bool) preg_match('/function contactBook\(.*?phoneNormalizer\(\)/s', $sf) && (bool) preg_match('/function contactBook\(.*?identityPolicies\(\)/s', $sf));

// ── 7. Profile: JSON exposes identity fields; view renders read-only block ───
echo "profile surfacing\n";
foreach (['phone', 'date_of_birth', 'country_code'] as $k) {
    chk("profile() JSON exposes {$k}", (bool) preg_match('/function profile\(.*?wantsJson\(.*?\'page\'|/s', $sc) ? str_contains($sc, "'{$k}'") : str_contains($sc, "'{$k}'"));
}
chk('view renders p-phone readonly', (bool) preg_match('/id="p-phone".*?readonly/s', $pv));
chk('view renders p-dob readonly', (bool) preg_match('/id="p-dob".*?readonly/s', $pv));
chk('view renders p-country readonly', (bool) preg_match('/id="p-country".*?readonly/s', $pv));
chk('view explains read-only identity fields', str_contains($pv, 'identityReadonlyNote'));
chk('updateProfile does NOT accept phone/dob/country', ! (bool) preg_match('/function updateProfile\(.*?(\'phone\'|\'date_of_birth\'|\'country_code\')/s', $sc));

// ── 8. Schema justification migration ────────────────────────────────────────
echo "users schema justification (migration 000092)\n";
chk('000092 migration exists', is_file($migration));
chk('justifies SELF-SERVICE columns', str_contains($mg, 'SELF-SERVICE'));
chk('justifies VERIFICATION columns', str_contains($mg, 'VERIFICATION'));
chk('justifies REGISTRATION columns', str_contains($mg, 'REGISTRATION'));
chk('justifies SECURITY columns', str_contains($mg, 'SECURITY'));
chk('justifies ADMIN columns', str_contains($mg, 'ADMIN'));
chk('all 30 users columns get a COMMENT', substr_count($mg, "COMMENT '") >= 30, (string) substr_count($mg, "COMMENT '"));
chk('resets data cache (Part A conformance rule)', str_contains($mg, 'resetDataCache()'));
chk('restates status VARCHAR(24)', str_contains($mg, "VARCHAR(24) NOT NULL DEFAULT 'active'"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
