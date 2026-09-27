<?php

declare(strict_types=1);

/**
 * CAMPAIGN AD HOC TEAM MANAGEMENT CRUD wiring test — proves the campaign teams
 * surface grew from a read-only data-page list (define/rename/delete team +
 * add/remove member were JSON-API-only, and the write routes lacked `webcsrf`)
 * into a full browser management console:
 *
 *   - a new GET /gamification/campaigns/{id}/teams/manage list route →
 *     CampaignsController::manageCampaignTeams → campaign_teams_manage view, with
 *     each team's resolved roster and an org-member add picker;
 *   - the create/rename/delete-team + add/remove-member forms POST to
 *     webcsrf-guarded no-JS aliases (…/teams, …/teams/{id}/update,
 *     …/teams/{id}/delete, …/teams/{id}/members, …/members/{uid}/remove);
 *   - every team write PRGs back to the manage page with a localized flash while
 *     keeping the JSON PATCH/DELETE routes for API clients;
 *   - the console mirrors the service's lifecycle rules (adhoc-only, delete
 *     draft-only, frozen when completed/cancelled), CSP-clean (no <script>/on*);
 *   - a resource-light service method (teamsWithRosters) resolves member names in
 *     ONE bounded users query;
 *   - i18n parity for the new Gamification.campaignTeams.* block across 6 locales.
 *
 *   php app/Modules/Gamification/Views/tests/campaign_teams_crud_test.php
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

// ── 1. i18n parity for the new campaignTeams block ───────────────────────────
echo "language parity (Gamification.campaignTeams.* — all locales)\n";
$enBlock = (require $langDir . '/en/Gamification.php')['campaignTeams'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en campaignTeams block non-trivial (>= 35 keys)', count($enKeys) >= 35, (string) count($enKeys));
foreach (['title', 'empty', 'notAdhoc', 'frozen', 'memberCount', 'noMembers'] as $k) {
    chk("en campaignTeams.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
foreach (['newTeam', 'saveNew', 'saveEdit', 'deleteTeam', 'deleteConfirm', 'addMember', 'removeMember',
    'createdFlash', 'updatedFlash', 'deletedFlash', 'memberAddedFlash', 'memberRemovedFlash'] as $k) {
    chk("en campaignTeams.form.$k present", isset($enBlock['form'][$k]) && $enBlock['form'][$k] !== '');
}
foreach (['NOT_ADHOC', 'BAD_STATE', 'TEAM_EXISTS', 'ALREADY_ON_A_TEAM', 'TEAM_NOT_FOUND'] as $k) {
    chk("en campaignTeams.errors.$k present", isset($enBlock['errors'][$k]) && $enBlock['errors'][$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $block = (require $langDir . "/$loc/Gamification.php")['campaignTeams'] ?? [];
    $keys  = $flatten($block);
    chk("$loc mirrors all en campaignTeams keys", $keys === $enKeys,
        'diff: ' . implode(',', array_slice(array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys)), 0, 8)));
}

// ── 2. Service resource-light roster method ──────────────────────────────────
echo "CampaignService::teamsWithRosters (resource-light)\n";
$svc = (string) file_get_contents($service);
chk('teamsWithRosters() present', str_contains($svc, 'function teamsWithRosters'));
chk('resolves names in ONE bounded users query (whereIn)', (bool) preg_match('/function teamsWithRosters.*?whereIn\(.id., \$ids\)/s', $svc));
chk('no per-member name lookup (no query inside member loop)',
    ! (bool) preg_match('/function teamsWithRosters.*?foreach \(\$members.*?->table\(.users.\)/s', $svc));
chk('attaches member_count + members roster', str_contains($svc, "'member_count'") && str_contains($svc, "\$t['members']"));

// ── 3. Management view ───────────────────────────────────────────────────────
echo "campaign_teams_manage.php management console\n";
$src = (string) file_get_contents("$viewDir/campaign_teams_manage.php");
chk('extends layouts/app', str_contains($src, "\$this->extend('layouts/app')"));
chk('create-team form posts to …/teams', (bool) preg_match('#action="/gamification/campaigns/<\?= esc\(\$ce[^)]*\)[^"]*/teams"#', $src));
chk('rename-team form posts to …/teams/{id}/update', str_contains($src, '/update"'));
chk('delete-team form posts to …/teams/{id}/delete', str_contains($src, '/delete"'));
chk('add-member form posts to …/teams/{id}/members', str_contains($src, '/members"'));
chk('remove-member form posts to …/members/{uid}/remove', str_contains($src, '/remove"'));
chk('member is an entity picker (<select name=user_id>)', (bool) preg_match('/<select name="user_id"/', $src));
chk('renders resolved display_name', str_contains($src, "\$m['display_name']"));
chk('delete gated to DRAFT only', str_contains($src, '$isDraft'));
chk('hides writes when not adhoc', str_contains($src, '$isAdhoc') && str_contains($src, 'notAdhoc'));
chk('hides writes when frozen (completed/cancelled)', str_contains($src, '$isEditable') && str_contains($src, 'frozen'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('progressive-enhancement <details>', str_contains($src, '<details'));
$noComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script> tag', ! str_contains($noComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noComments));

// ── 4. Controller list route + PRG + JSON ────────────────────────────────────
echo "controller: manage list + PRG + csrf + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('manageCampaignTeams() present', str_contains($ctrl, 'function manageCampaignTeams'));
chk('renders campaign_teams_manage view', str_contains($ctrl, 'WBS\Gamification\Views\campaign_teams_manage'));
chk('uses teamsWithRosters + roster picker + csrf', str_contains($ctrl, 'teamsWithRosters') && str_contains($ctrl, 'listMembers') && (bool) preg_match('/function manageCampaignTeams\(.*?wbsCsrf/s', $ctrl));
foreach (['defineCampaignTeam', 'updateCampaignTeam', 'deleteCampaignTeam', 'addCampaignTeamMember', 'removeCampaignTeamMember'] as $fn) {
    chk("$fn PRGs via respondTeamDecision", (bool) preg_match('/function ' . $fn . '\(.*?respondTeamDecision/s', $ctrl));
}
chk('respondTeamDecision redirects to teams/manage', str_contains($ctrl, "/teams/manage'"));
chk('respondTeamDecision maps error codes to friendly copy', str_contains($ctrl, 'campaignTeams.errors.'));
chk('respondTeamDecision falls back to raw message', str_contains($ctrl, '$result->message'));
chk('JSON kept for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

// ── 5. Routes gated + webcsrf ────────────────────────────────────────────────
echo "routes: manage GET gated; write aliases webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*teams/manage.*manageCampaignTeams.*$#m', $routes, $m)) {
    chk('GET teams/manage route present', true);
    chk('manage route authorize:gamification.manage', str_contains($m[0], 'authorize:gamification.manage'));
} else {
    chk('GET teams/manage route present', false);
}
$writeRoutes = [
    'defineCampaignTeam'       => "post('campaigns/(:segment)/teams'",
    'updateCampaignTeam (POST)' => "teams/(:segment)/update'",
    'deleteCampaignTeam (POST)' => "teams/(:segment)/delete'",
    'addCampaignTeamMember'    => "teams/(:segment)/members'",
    'removeCampaignTeamMember (POST)' => "members/(:segment)/remove'",
];
foreach ($writeRoutes as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
        chk("$label keeps authorize:gamification.manage", str_contains($m[0], 'authorize:gamification.manage'));
    } else {
        chk("$label route present", false);
    }
}

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — adhoc draft (fr) + non-adhoc + frozen\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
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
$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir, $view) {
        extract($data);
        ob_start();
        include "$viewDir/$view.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = ['success' => 'Équipe créée.'];
$h = $render('campaign_teams_manage', [
    'campaignId' => 'camp-1',
    'campaign'   => ['name' => 'Harvest Push', 'status' => 'draft', 'team_challenge' => 1, 'team_mode' => 'adhoc'],
    'status'     => 'draft', 'isAdhoc' => true,
    'teams' => [
        ['id' => 't-1', 'code' => 'red', 'name' => 'Red Team', 'icon' => '🔴', 'color' => '#ef4444', 'member_count' => 1,
            'members' => [['user_id' => 'u-1', 'display_name' => 'Ama Mensah']]],
    ],
    'roster' => [['id' => 'u-1', 'display_name' => 'Ama Mensah'], ['id' => 'u-2', 'display_name' => 'Kofi Boateng']],
    'csrf' => 'CT',
], 'fr');
chk('render: create-team panel posts to /teams', str_contains($h, 'action="/gamification/campaigns/camp-1/teams"'));
chk('render: rename posts to /teams/t-1/update', str_contains($h, 'action="/gamification/campaigns/camp-1/teams/t-1/update"'));
chk('render: add-member posts to /teams/t-1/members', str_contains($h, 'action="/gamification/campaigns/camp-1/teams/t-1/members"'));
chk('render: delete panel shown (draft)', str_contains($h, 'action="/gamification/campaigns/camp-1/teams/t-1/delete"'));
chk('render: remove-member posts to /members/u-1/remove', str_contains($h, 'action="/gamification/campaigns/camp-1/members/u-1/remove"'));
chk('render: resolved member name shown', str_contains($h, 'Ama Mensah'));
chk('render: add picker excludes already-rostered u-1', ! (bool) preg_match('/<option value="u-1"/', $h));
chk('render: add picker offers u-2', (bool) preg_match('/<option value="u-2"/', $h));
chk('render: csrf token present', str_contains($h, 'value="CT"'));
chk('render: success flash rendered', str_contains($h, 'Équipe créée.'));
chk('render: no untranslated campaignTeams keys leaked', ! str_contains($h, 'Gamification.campaignTeams'));

$GLOBALS['__flash'] = [];
$hNot = $render('campaign_teams_manage', [
    'campaignId' => 'camp-2', 'campaign' => ['name' => 'Solo', 'status' => 'active', 'team_challenge' => 0],
    'status' => 'active', 'isAdhoc' => false, 'teams' => [], 'roster' => [], 'csrf' => 'CT',
], 'en');
chk('render: non-adhoc shows explanatory note, no create form', str_contains($hNot, lang('Gamification.campaignTeams.notAdhoc')) && ! str_contains($hNot, 'action="/gamification/campaigns/camp-2/teams"'));

$hFrozen = $render('campaign_teams_manage', [
    'campaignId' => 'camp-3', 'campaign' => ['name' => 'Done', 'status' => 'completed', 'team_challenge' => 1, 'team_mode' => 'adhoc'],
    'status' => 'completed', 'isAdhoc' => true,
    'teams' => [['id' => 't-9', 'code' => 'blue', 'name' => 'Blue', 'member_count' => 0, 'members' => []]],
    'roster' => [], 'csrf' => 'CT',
], 'en');
chk('render: frozen shows note + hides write forms', str_contains($hFrozen, lang('Gamification.campaignTeams.frozen')) && ! str_contains($hFrozen, '/teams/t-9/delete"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
