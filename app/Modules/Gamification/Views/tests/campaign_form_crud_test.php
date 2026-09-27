<?php

declare(strict_types=1);

/**
 * CAMPAIGN CREATE/EDIT BROWSER FORM CRUD wiring test — proves the campaign
 * create/update surface grew from JSON-API-only (POST campaigns had no browser
 * form + no webcsrf; PATCH campaigns/{id} JSON-only) into a full no-JS, CSP-safe
 * bespoke browser form:
 *
 *   - GET /gamification/campaigns/new  → createCampaignForm → campaign_form view;
 *   - GET /gamification/campaigns/{id}/edit → editCampaignForm (guards via show(),
 *     redirects to detail on failure) → campaign_form view (edit mode);
 *   - the form POSTs to webcsrf-guarded routes: create → POST campaigns, edit →
 *     POST campaigns/{id}/update (a no-JS PRG alias; the PATCH route is kept for
 *     API clients);
 *   - createCampaign/updateCampaign PRG via respondCampaignDecision — JSON for API,
 *     else success → redirect to /gamification/campaigns/{id} with a localized
 *     flash, failure → re-render the form with submitted values + friendly error;
 *   - identity fields (group_id/code/award_mode) are immutable on edit (read-only
 *     + hidden inputs);
 *   - CampaignService::vocab() exposes the metric/award-mode/team-mode pickers;
 *   - CSP-clean (no <script>/on*); i18n parity for Gamification.campaignForm.*.
 *
 *   php app/Modules/Gamification/Views/tests/campaign_form_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$controller = $root . '/app/Modules/Gamification/Controllers/CampaignsController.php';
$service    = $root . '/app/Modules/Gamification/Services/CampaignService.php';
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

// ── 1. i18n parity for the new campaignForm block ────────────────────────────
echo "language parity (Gamification.campaignForm.* — all locales)\n";
$enBlock = (require $langDir . '/en/Gamification.php')['campaignForm'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en campaignForm block non-trivial (>= 40 keys)', count($enKeys) >= 40, (string) count($enKeys));
foreach (['headingNew', 'headingEdit', 'sub', 'back', 'cancel', 'lockedHint',
    'secIdentity', 'secTarget', 'secWindow', 'secTeam', 'groupLabel', 'groupNone',
    'codeLabel', 'nameLabel', 'metricLabel', 'awardModeLabel', 'targetLabel',
    'startsAtLabel', 'endsAtLabel', 'teamChallengeLabel', 'teamModeLabel',
    'saveNew', 'saveEdit', 'createdFlash', 'updatedFlash',
    'teamTargetLabel', 'teamTargetHint', 'teamAwardPointsLabel', 'teamBadgeLabel', 'recognizeTopTeamsLabel'] as $k) {
    chk("en campaignForm.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
foreach (['points', 'amount', 'volume', 'count'] as $k) {
    chk("en campaignForm.metric.$k present", isset($enBlock['metric'][$k]) && $enBlock['metric'][$k] !== '');
}
foreach (['single', 'repeatable', 'tiered'] as $k) {
    chk("en campaignForm.mode.$k present", isset($enBlock['mode'][$k]) && $enBlock['mode'][$k] !== '');
}
foreach (['subtree', 'adhoc'] as $k) {
    chk("en campaignForm.teamMode.$k present", isset($enBlock['teamMode'][$k]) && $enBlock['teamMode'][$k] !== '');
}
foreach (['MISSING_FIELDS', 'BAD_METRIC', 'BAD_AWARD_MODE', 'BAD_TARGET', 'BAD_WINDOW', 'BAD_TEAM_MODE', 'CAMPAIGN_EXISTS'] as $k) {
    chk("en campaignForm.errors.$k present", isset($enBlock['errors'][$k]) && $enBlock['errors'][$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $block = (require $langDir . "/$loc/Gamification.php")['campaignForm'] ?? [];
    $keys  = $flatten($block);
    chk("$loc mirrors all en campaignForm keys", $keys === $enKeys,
        'diff: ' . implode(',', array_slice(array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys)), 0, 8)));
}

// ── 2. Service vocab() picker source ─────────────────────────────────────────
echo "CampaignService::vocab (picker vocabulary)\n";
$svc = (string) file_get_contents($service);
chk('vocab() present', str_contains($svc, 'function vocab'));
chk('vocab exposes metrics/modes/teamModes', str_contains($svc, "'metrics'") && str_contains($svc, "'modes'") && str_contains($svc, "'teamModes'"));
chk('vocab wraps the private consts', str_contains($svc, 'self::METRICS') && str_contains($svc, 'self::MODES') && str_contains($svc, 'self::TEAM_MODES'));
chk('create() returns campaign_id in the Result payload', (bool) preg_match('/function create\(.*?Result::created\(\[.*?.campaign_id./s', $svc));

// ── 3. Form view ─────────────────────────────────────────────────────────────
echo "campaign_form.php browser form\n";
$src = (string) file_get_contents("$viewDir/campaign_form.php");
chk('extends layouts/app', str_contains($src, "\$this->extend('layouts/app')"));
chk('create action posts to /gamification/campaigns', str_contains($src, "'/gamification/campaigns'"));
chk('edit action posts to …/{id}/update', str_contains($src, "rawurlencode(\$id) . '/update'"));
chk('form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('group picker <select name=group_id>', (bool) preg_match('/<select id="group_id" name="group_id"/', $src));
chk('metric picker <select name=metric>', (bool) preg_match('/<select id="metric" name="metric"/', $src));
chk('award_mode picker <select name=award_mode>', (bool) preg_match('/<select id="award_mode" name="award_mode"/', $src));
chk('team_mode picker <select name=team_mode>', (bool) preg_match('/<select id="team_mode" name="team_mode"/', $src));
chk('datetime-local for starts/ends', str_contains($src, 'type="datetime-local"') && str_contains($src, 'name="starts_at"') && str_contains($src, 'name="ends_at"'));
// Team-milestone overlay fields (accepted by CampaignService create/update).
chk('team_target_value field present', str_contains($src, 'name="team_target_value"'));
chk('team_award_points field present', str_contains($src, 'name="team_award_points"'));
chk('team_badge_code field present', str_contains($src, 'name="team_badge_code"'));
chk('recognize_top_teams field present', str_contains($src, 'name="recognize_top_teams"'));
chk('datetime-local converter (Y-m-d H:i:s → …T…, 16 chars)', str_contains($src, "str_replace(' ', 'T'") && str_contains($src, 'substr($v, 0, 16)'));
chk('EDIT locks group_id (readonly + hidden)', (bool) preg_match('/\$isEdit.*?readonly.*?name="group_id".*?type="hidden"/s', $src));
chk('EDIT locks code (readonly + hidden)', str_contains($src, "value=\"<?= \$ov('code') ?>\" readonly"));
chk('EDIT locks award_mode (hidden)', (bool) preg_match('/name="award_mode" value="<\?= esc\(\$curMode/', $src));
chk('renders $error banner', str_contains($src, "if (\$error !== '')"));
chk('lockedHint shown only on edit', str_contains($src, "lang((\$cf)('lockedHint'))"));
$noComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script> tag', ! str_contains($noComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noComments));

// ── 4. Controller: form GETs + PRG + JSON ────────────────────────────────────
echo "controller: form GETs + PRG + csrf + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('createCampaignForm() present', str_contains($ctrl, 'function createCampaignForm'));
chk('editCampaignForm() present', str_contains($ctrl, 'function editCampaignForm'));
chk('both render the campaign_form view', substr_count($ctrl, 'WBS\\Gamification\\Views\\campaign_form') >= 2);
chk('editCampaignForm guards via show()', (bool) preg_match('/function editCampaignForm\(.*?->show\(/s', $ctrl));
chk('editCampaignForm redirects to detail on failure', (bool) preg_match('/function editCampaignForm\(.*?redirect\(\)->to\(.\/gamification\/campaigns\//s', $ctrl));
chk('campaignFormData() builds pickers (groups + vocab)', (bool) preg_match('/function campaignFormData\(.*?vocab\(\).*?listForOrg\(/s', $ctrl));
chk('createCampaign PRGs via respondCampaignDecision', (bool) preg_match('/function createCampaign\(.*?respondCampaignDecision/s', $ctrl));
chk('updateCampaign PRGs via respondCampaignDecision', (bool) preg_match('/function updateCampaign\(.*?respondCampaignDecision/s', $ctrl));
chk('respondCampaignDecision reads campaign_id from Result', str_contains($ctrl, "\$result->data['campaign_id']"));
chk('respondCampaignDecision redirects to campaign detail', str_contains($ctrl, "'/gamification/campaigns/' . rawurlencode(\$campaignId)"));
chk('respondCampaignDecision uses created/updated flashes', str_contains($ctrl, 'createdFlash') && str_contains($ctrl, 'updatedFlash'));
chk('respondCampaignDecision maps error codes to friendly copy', str_contains($ctrl, 'campaignForm.errors.'));
chk('respondCampaignDecision falls back to raw message', str_contains($ctrl, '$result->message'));
chk('respondCampaignDecision re-renders form with submitted values', (bool) preg_match('/respondCampaignDecision.*?campaignFormData\(\$mode, \$submitted\)/s', $ctrl));
chk('JSON kept for API clients', (bool) preg_match('/function respondCampaignDecision\(.*?wantsJson\(\)/s', $ctrl));
chk('imports Group Services', str_contains($ctrl, 'use WBS\\Groups\\Config\\Services'));

// ── 5. Routes: form GETs + webcsrf writes ────────────────────────────────────
echo "routes: form GETs gated; write routes webcsrf-guarded; PATCH kept\n";
$routes = (string) file_get_contents($routesFile);
// Anchor to the Gamification CampaignsController — a Notifications controller
// also owns a `post('campaigns'` route, so match on the target class/method.
$routeChecks = [
    'GET campaigns/new'          => "CampaignsController::createCampaignForm",
    'GET campaigns/{id}/edit'    => "CampaignsController::editCampaignForm",
    'POST campaigns (create)'    => "CampaignsController::createCampaign'",
    'POST campaigns/{id}/update' => "post('campaigns/(:segment)/update'",
    'PATCH campaigns/{id}'       => "patch('campaigns/(:segment)'",
];
foreach ($routeChecks as $label => $needle) {
    $ok = preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m) === 1;
    chk("$label route present", $ok, $needle);
    if ($ok) {
        chk("$label authorize:gamification.manage", str_contains($m[0], 'authorize:gamification.manage'));
    }
}
// The two browser-write routes must carry webcsrf; PATCH (API) must NOT.
foreach (["CampaignsController::createCampaign'", "post('campaigns/(:segment)/update'"] as $needle) {
    preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m);
    chk("$needle webcsrf-guarded", str_contains($m[0], 'webcsrf'));
}
// §C/G6: PATCH campaigns/{id} now ALSO carries webcsrf. The webcsrf filter is
// header-exempt for Bearer / X-WBS-Session callers, so API-first clients are
// unaffected, while any browser that emits PATCH is CSRF-protected too.
preg_match('#^.*' . preg_quote("patch('campaigns/(:segment)'", '#') . '.*$#m', $routes, $mp);
chk('PATCH campaigns/{id} carries webcsrf (Bearer/API header-exempt)', str_contains($mp[0], 'webcsrf'));
// Ordering: the form GETs must be declared BEFORE the generic read route.
$posNew  = strpos($routes, "get('campaigns/new'");
$posEdit = strpos($routes, "get('campaigns/(:segment)/edit'");
$posRead = strpos($routes, "get('campaigns/(:segment)'");
chk('campaigns/new declared before generic read', $posNew !== false && $posRead !== false && $posNew < $posRead);
chk('campaigns/{id}/edit declared before generic read', $posEdit !== false && $posRead !== false && $posEdit < $posRead);

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — create (fr) + edit locks identity (en) + error banner\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Gamification') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/campaign_form.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$vocab = ['metrics' => ['points', 'amount', 'volume', 'count'], 'modes' => ['single', 'repeatable', 'tiered'], 'teamModes' => ['subtree', 'adhoc']];

// create mode
$hNew = $render([
    'mode' => 'create', 'campaign' => [], 'error' => '', 'csrf' => 'CF',
    'groups' => [['id' => 'g-1', 'name' => 'Accra Central'], ['id' => 'g-2', 'name' => 'Kumasi Area']],
    'metrics' => $vocab['metrics'], 'modes' => $vocab['modes'], 'teamModes' => $vocab['teamModes'],
], 'fr');
chk('render create: posts to /gamification/campaigns', str_contains($hNew, 'action="/gamification/campaigns"'));
chk('render create: group picker is a live <select>', (bool) preg_match('/<select id="group_id" name="group_id"/', $hNew));
chk('render create: group options rendered', str_contains($hNew, 'Accra Central') && str_contains($hNew, 'Kumasi Area'));
chk('render create: award_mode is a live <select>', (bool) preg_match('/<select id="award_mode" name="award_mode"/', $hNew));
chk('render create: csrf present', str_contains($hNew, 'value="CF"'));
chk('render create: starts_at required', (bool) preg_match('/name="starts_at"[^>]*required/', $hNew));
chk('render create: no untranslated campaignForm keys leaked', ! str_contains($hNew, 'Gamification.campaignForm'));

// edit mode — identity locked
$hEdit = $render([
    'mode' => 'edit',
    'campaign' => [
        'id' => 'camp-1', 'group_id' => 'g-1', 'code' => 'harvest_push', 'name' => 'Harvest Push',
        'metric' => 'amount', 'award_mode' => 'tiered', 'target_value' => 500,
        'starts_at' => '2026-01-01 08:00:00', 'ends_at' => '2026-03-31 20:00:00',
        'team_challenge' => 1, 'team_mode' => 'adhoc',
    ],
    'error' => '', 'csrf' => 'CF',
    'groups' => [['id' => 'g-1', 'name' => 'Accra Central']],
    'metrics' => $vocab['metrics'], 'modes' => $vocab['modes'], 'teamModes' => $vocab['teamModes'],
], 'en');
chk('render edit: posts to …/camp-1/update', str_contains($hEdit, 'action="/gamification/campaigns/camp-1/update"'));
chk('render edit: group_id NOT a select (locked)', ! (bool) preg_match('/<select id="group_id"/', $hEdit));
chk('render edit: group_id preserved as hidden', (bool) preg_match('/<input type="hidden" name="group_id" value="g-1"/', $hEdit));
chk('render edit: code locked (readonly + hidden)', str_contains($hEdit, 'value="harvest_push" readonly') && (bool) preg_match('/<input type="hidden" name="code" value="harvest_push"/', $hEdit));
chk('render edit: award_mode locked as hidden', (bool) preg_match('/<input type="hidden" name="award_mode" value="tiered"/', $hEdit));
chk('render edit: metric preselects amount', (bool) preg_match('/<option value="amount" selected/', $hEdit));
chk('render edit: datetime-local converted to T-form', str_contains($hEdit, 'value="2026-01-01T08:00"') && str_contains($hEdit, 'value="2026-03-31T20:00"'));
chk('render edit: team_challenge checked', (bool) preg_match('/name="team_challenge"[^>]*checked/', $hEdit));
chk('render edit: team_mode preselects adhoc', (bool) preg_match('/<option value="adhoc" selected/', $hEdit));
chk('render edit: save button uses saveEdit copy', str_contains($hEdit, lang('Gamification.campaignForm.saveEdit')));

// error re-render
$hErr = $render([
    'mode' => 'create',
    'campaign' => ['code' => 'dup', 'name' => 'Dup', 'group_id' => 'g-1'],
    'error' => 'A campaign with that code already exists for this group.', 'csrf' => 'CF',
    'groups' => [['id' => 'g-1', 'name' => 'Accra Central']],
    'metrics' => $vocab['metrics'], 'modes' => $vocab['modes'], 'teamModes' => $vocab['teamModes'],
], 'en');
chk('render error: banner shown', str_contains($hErr, 'A campaign with that code already exists for this group.'));
chk('render error: submitted code preserved', str_contains($hErr, 'value="dup"'));
chk('render error: submitted group preselected', (bool) preg_match('/<option value="g-1" selected/', $hErr));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
