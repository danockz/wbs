<?php

declare(strict_types=1);

/**
 * CAMPAIGN REWARD-LADDER (TIER) MANAGEMENT CRUD wiring test — proves the tiered
 * campaign's reward ladder grew from a read-only data-page list (defineTier was
 * JSON-API-only, no browser add form, no delete at all) into a full browser
 * management console:
 *
 *   - a new GET /gamification/campaigns/{id}/tiers/manage list route →
 *     CampaignsController::manageCampaignTiers → campaign_tiers_manage view,
 *     rendering the ladder ascending by threshold + an inline add-tier form;
 *   - add-tier + delete-tier forms POST to webcsrf-guarded aliases
 *     (…/tiers, …/tiers/{tierId}/delete); PATCH/DELETE-style JSON API kept;
 *   - every tier write PRGs back to the manage page with a localized flash;
 *   - the console mirrors the service's lifecycle rules (tiered-only, draft-only),
 *     CSP-clean (no <script>/on*);
 *   - CampaignService::deleteTier re-packs tier_position to stay 1..N contiguous;
 *   - i18n parity for the new Gamification.campaignTiers.* block across 6 locales.
 *
 *   php app/Modules/Gamification/Views/tests/campaign_tiers_crud_test.php
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

// ── 1. i18n parity for the new campaignTiers block ───────────────────────────
echo "language parity (Gamification.campaignTiers.* — all locales)\n";
$enBlock = (require $langDir . '/en/Gamification.php')['campaignTiers'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en campaignTiers block non-trivial (>= 30 keys)', count($enKeys) >= 30, (string) count($enKeys));
foreach (['title', 'empty', 'notTiered', 'frozen', 'thresholdTag', 'pointsTag', 'backToCampaign'] as $k) {
    chk("en campaignTiers.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
foreach (['newTier', 'codeLabel', 'nameLabel', 'thresholdLabel', 'awardPointsLabel', 'badgeLabel',
    'saveNew', 'deleteTier', 'deleteConfirm', 'deleteYes', 'createdFlash', 'deletedFlash'] as $k) {
    chk("en campaignTiers.form.$k present", isset($enBlock['form'][$k]) && $enBlock['form'][$k] !== '');
}
foreach (['NOT_TIERED', 'BAD_STATE', 'BAD_TIER', 'TIER_EXISTS', 'TIER_NOT_FOUND'] as $k) {
    chk("en campaignTiers.errors.$k present", isset($enBlock['errors'][$k]) && $enBlock['errors'][$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $block = (require $langDir . "/$loc/Gamification.php")['campaignTiers'] ?? [];
    $keys  = $flatten($block);
    chk("$loc mirrors all en campaignTiers keys", $keys === $enKeys,
        'diff: ' . implode(',', array_slice(array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys)), 0, 8)));
}

// ── 2. Service: deleteTier + re-pack ─────────────────────────────────────────
echo "CampaignService::deleteTier (draft-only + re-pack)\n";
$svc = (string) file_get_contents($service);
chk('deleteTier() present', str_contains($svc, 'function deleteTier'));
chk('deleteTier draft-guarded', (bool) preg_match('/function deleteTier.*?BAD_STATE/s', $svc));
chk('deleteTier 404s a missing tier', (bool) preg_match('/function deleteTier.*?TIER_NOT_FOUND/s', $svc));
chk('deleteTier re-packs tier_position contiguously', (bool) preg_match('/function deleteTier.*?tier_position.*?=>\s*\$pos/s', $svc));

// ── 3. Management view ───────────────────────────────────────────────────────
echo "campaign_tiers_manage.php management console\n";
$src = (string) file_get_contents("$viewDir/campaign_tiers_manage.php");
chk('extends layouts/app', str_contains($src, "\$this->extend('layouts/app')"));
chk('add-tier form posts to …/tiers', (bool) preg_match('#action="/gamification/campaigns/<\?= esc\(\$ce[^)]*\)[^"]*/tiers"#', $src));
chk('delete-tier form posts to …/tiers/{id}/delete', str_contains($src, '/delete"'));
chk('threshold_value input present + required', (bool) preg_match('/name="threshold_value"[^>]*required/', $src));
chk('award_points input present', str_contains($src, 'name="award_points"'));
chk('badge_code input present', str_contains($src, 'name="badge_code"'));
chk('renders tier_position badge', str_contains($src, "\$t['tier_position']"));
chk('renders threshold via thresholdTag', str_contains($src, "thresholdTag"));
chk('add form gated to DRAFT only', str_contains($src, '$isDraft'));
chk('hides writes when not tiered', str_contains($src, '$isTiered') && str_contains($src, 'notTiered'));
chk('shows frozen note when not draft', str_contains($src, 'frozen'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('progressive-enhancement <details>', str_contains($src, '<details'));
$noComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script> tag', ! str_contains($noComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noComments));

// ── 4. Controller: manage list + PRG + JSON ──────────────────────────────────
echo "controller: manage list + PRG + csrf + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('manageCampaignTiers() present', str_contains($ctrl, 'function manageCampaignTiers'));
chk('renders campaign_tiers_manage view', str_contains($ctrl, 'WBS\Gamification\Views\campaign_tiers_manage'));
chk('manage passes tiers + isTiered + csrf', (bool) preg_match('/function manageCampaignTiers\(.*?isTiered.*?wbsCsrf/s', $ctrl));
chk('defineCampaignTier PRGs via respondTierDecision', (bool) preg_match('/function defineCampaignTier\(.*?respondTierDecision/s', $ctrl));
chk('deleteCampaignTier present + PRGs via respondTierDecision', (bool) preg_match('/function deleteCampaignTier\(.*?respondTierDecision/s', $ctrl));
chk('respondTierDecision redirects to tiers/manage', str_contains($ctrl, "/tiers/manage'"));
chk('respondTierDecision maps error codes to friendly copy', str_contains($ctrl, 'campaignTiers.errors.'));
chk('respondTierDecision falls back to raw message', (bool) preg_match('/function respondTierDecision\(.*?\$result->message/s', $ctrl));
chk('respondTierDecision uses created/deleted flashes', str_contains($ctrl, 'campaignTiers.form.'));
chk('JSON kept for API clients', (bool) preg_match('/function respondTierDecision\(.*?wantsJson\(\)/s', $ctrl));

// ── 5. Routes: manage GET gated; writes webcsrf-guarded ──────────────────────
echo "routes: manage GET gated; write aliases webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
$routeChecks = [
    'GET tiers/manage'          => "CampaignsController::manageCampaignTiers",
    'POST tiers (add)'          => "CampaignsController::defineCampaignTier",
    'POST tiers/{id}/delete'    => "post('campaigns/(:segment)/tiers/(:segment)/delete'",
    'DELETE tiers/{id} (API)'   => "delete('campaigns/(:segment)/tiers/(:segment)'",
];
foreach ($routeChecks as $label => $needle) {
    $ok = preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m) === 1;
    chk("$label route present", $ok, $needle);
    if ($ok) {
        chk("$label authorize:gamification.manage", str_contains($m[0], 'authorize:gamification.manage'));
    }
}
foreach (["CampaignsController::defineCampaignTier", "post('campaigns/(:segment)/tiers/(:segment)/delete'"] as $needle) {
    preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m);
    chk("$needle webcsrf-guarded", str_contains($m[0], 'webcsrf'));
}
// Ordering: manage GET must precede the generic tiers read.
$posManage = strpos($routes, "get('campaigns/(:segment)/tiers/manage'");
$posRead   = strpos($routes, "get('campaigns/(:segment)/tiers'");
chk('tiers/manage declared before generic tiers read', $posManage !== false && $posRead !== false && $posManage < $posRead);

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — tiered draft (fr) + non-tiered + frozen\n";
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
        include "$viewDir/campaign_tiers_manage.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = ['success' => 'Palier ajouté.'];
$h = $render([
    'campaignId' => 'camp-1',
    'campaign'   => ['name' => 'Harvest Push', 'status' => 'draft', 'award_mode' => 'tiered'],
    'status'     => 'draft', 'isTiered' => true,
    'tiers' => [
        ['id' => 't-1', 'code' => 'silver', 'name' => 'Silver', 'tier_position' => 1, 'threshold_value' => 100, 'award_points' => 50],
        ['id' => 't-2', 'code' => 'gold', 'name' => 'Gold', 'tier_position' => 2, 'threshold_value' => 250, 'badge_code' => 'gold_badge'],
    ],
    'csrf' => 'CT',
], 'fr');
chk('render: add-tier panel posts to /tiers', str_contains($h, 'action="/gamification/campaigns/camp-1/tiers"'));
chk('render: delete posts to /tiers/t-1/delete', str_contains($h, 'action="/gamification/campaigns/camp-1/tiers/t-1/delete"'));
chk('render: tier names shown', str_contains($h, 'Silver') && str_contains($h, 'Gold'));
chk('render: threshold rendered', str_contains($h, '100') && str_contains($h, '250'));
chk('render: badge_code shown', str_contains($h, 'gold_badge'));
chk('render: csrf token present', str_contains($h, 'value="CT"'));
chk('render: success flash rendered', str_contains($h, 'Palier ajouté.'));
chk('render: no untranslated campaignTiers keys leaked', ! str_contains($h, 'Gamification.campaignTiers'));

$GLOBALS['__flash'] = [];
$hNot = $render([
    'campaignId' => 'camp-2', 'campaign' => ['name' => 'Flat', 'status' => 'draft', 'award_mode' => 'single'],
    'status' => 'draft', 'isTiered' => false, 'tiers' => [], 'csrf' => 'CT',
], 'en');
chk('render: non-tiered shows note, no add form', str_contains($hNot, lang('Gamification.campaignTiers.notTiered')) && ! str_contains($hNot, 'action="/gamification/campaigns/camp-2/tiers"'));

$hFrozen = $render([
    'campaignId' => 'camp-3', 'campaign' => ['name' => 'Live', 'status' => 'active', 'award_mode' => 'tiered'],
    'status' => 'active', 'isTiered' => true,
    'tiers' => [['id' => 't-9', 'code' => 'bronze', 'name' => 'Bronze', 'tier_position' => 1, 'threshold_value' => 10]],
    'csrf' => 'CT',
], 'en');
chk('render: frozen shows note + hides delete forms', str_contains($hFrozen, lang('Gamification.campaignTiers.frozen')) && ! str_contains($hFrozen, '/tiers/t-9/delete"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
