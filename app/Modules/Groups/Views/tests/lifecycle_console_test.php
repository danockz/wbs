<?php

declare(strict_types=1);

/**
 * GROUP lifecycle governance console — the write face of GroupLifecycleController.
 *
 * The lifecycle history page (GET groups/{id}/lifecycle) is now a governance
 * console: below the transition timeline it renders the lifecycle controls
 * ALLOWED FROM THE GROUP'S CURRENT STATE (from the pure transition matrix), each
 * a no-JS PRG form posting a stated reason (+ optional approval ref; a survivor id
 * on merge) to a webcsrf-guarded route. This test covers:
 *   - Groups.lifecycle.* key parity across all 6 locales (incl. the new console keys)
 *   - view state-gating: active shows archive/dissolve/merge (no reactivate);
 *     archived shows reactivate/dissolve/merge (no archive); terminal shows none
 *   - the forms carry webcsrf, a required reason, confirm() on destructive actions,
 *     and post to the correct routes; RTL for Arabic; flash banners
 *   - controller: PRG redirect for browsers, JSON for API, per-group authz on all
 *     four writes (merge authorises survivor too), actor id from session
 *   - routes: the four lifecycle POSTs are webcsrf-guarded
 *   - service: canTransition matrix + current() helper exist
 *
 *   php app/Modules/Groups/Views/tests/lifecycle_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';
$ctrl    = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupLifecycleController.php');
$svc     = file_get_contents($root . '/app/Modules/Groups/Services/GroupLifecycleService.php');
$routes  = file_get_contents($root . '/app/Config/Routes.php');

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

echo "language parity (Groups.lifecycle.* across 6 locales)\n";
$en     = require $langDir . '/en/Groups.php';
$enKeys = $flatten($en['lifecycle'] ?? []);
$newKeys = [
    'currentLabel', 'actionsHeading', 'terminalNote', 'reasonLabel', 'reasonPh',
    'approvalLabel', 'approvalPh', 'archiveTitle', 'archiveHint', 'archiveBtn',
    'reactivateTitle', 'reactivateHint', 'reactivateBtn', 'dissolveTitle',
    'dissolveHint', 'dissolveBtn', 'dissolveConfirm', 'mergeTitle', 'mergeHint',
    'mergeBtn', 'mergeConfirm', 'survivorLabel', 'survivorPh', 'archivedFlash',
    'reactivatedFlash', 'dissolvedFlash', 'mergedFlash',
];
foreach ($newKeys as $k) {
    chk("en defines lifecycle.$k", in_array($k, $enKeys, true));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Groups.php";
    $keys = $flatten($m['lifecycle'] ?? []);
    $miss = array_diff($enKeys, $keys);
    chk("$loc lifecycle.* parity", $miss === [], implode(',', $miss));
}

// ---- view render harness (mirrors admin_views_test) --------------------------
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
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
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Groups') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/lifecycle_history.php";
    return (string) ob_get_clean();
};

$base = ['transitions' => [], 'groupId' => 'grp-1', 'groupName' => 'Youth Cell', 'csrf' => 'TKN123'];

echo "\nview: active state controls\n";
$h = $render($base + ['status' => 'active', 'allowed' => ['archived', 'dissolved', 'merged']], 'en');
chk('active shows current status badge', str_contains($h, 'Active'));
chk('active shows archive form', str_contains($h, 'action="/groups/grp-1/archive"'));
chk('active shows dissolve form', str_contains($h, 'action="/groups/grp-1/dissolve"'));
chk('active shows merge form', str_contains($h, 'action="/groups/grp-1/merge"'));
chk('active hides reactivate form', ! str_contains($h, 'action="/groups/grp-1/reactivate"'));
chk('forms carry webcsrf token', substr_count($h, 'name="_csrf" value="TKN123"') >= 3);
chk('reason field is required', str_contains($h, 'name="reason" required'));
chk('merge asks for survivor id', str_contains($h, 'name="survivor_id" required'));
chk('dissolve has confirm()', preg_match('/dissolve"[^>]*onsubmit="return confirm/', $h) === 1);
chk('merge has confirm()', preg_match('/merge"[^>]*onsubmit="return confirm/', $h) === 1);
chk('archive is NOT confirm-gated', preg_match('/archive"[^>]*onsubmit/', $h) === 0);
chk('shows group name', str_contains($h, 'Youth Cell'));

echo "\nmerge survivor_id entity-reference picker\n";
$hp = $render($base + [
    'status'  => 'active',
    'allowed' => ['archived', 'dissolved', 'merged'],
    'groups'  => [
        ['id' => 'grp-1', 'name' => 'Youth Cell', 'depth' => 0],      // excluded (self — being merged away)
        ['id' => 'grp-2', 'name' => 'Adult Cell', 'depth' => 0],      // offered
        ['id' => 'grp-3', 'name' => 'Accra Central', 'depth' => 1],   // offered
    ],
], 'en');
chk('survivor picker: renders <select id="mg-surv">', str_contains($hp, '<select id="mg-surv" name="survivor_id" required>'));
chk('survivor picker: offers other groups', str_contains($hp, 'value="grp-2"') && str_contains($hp, 'Adult Cell'));
chk('survivor picker: excludes the group being merged away', ! preg_match('/<option value="grp-1"/', $hp));
chk('survivor picker: none option present', str_contains($hp, lang('Groups.lifecycle.survivorNone')));
// no groups → bounded text fallback (unchanged behaviour)
$hpNo = $render($base + ['status' => 'active', 'allowed' => ['archived', 'dissolved', 'merged']], 'en');
chk('survivor picker: bounded text fallback when no groups', str_contains($hpNo, 'type="text" id="mg-surv" name="survivor_id" required maxlength="64"'));

echo "\nview: archived state controls\n";
$h = $render($base + ['status' => 'archived', 'allowed' => ['active', 'dissolved', 'merged']], 'en');
chk('archived shows reactivate form', str_contains($h, 'action="/groups/grp-1/reactivate"'));
chk('archived hides archive form', ! str_contains($h, 'action="/groups/grp-1/archive"'));
chk('archived shows dissolve form', str_contains($h, 'action="/groups/grp-1/dissolve"'));

echo "\nview: terminal state (no controls)\n";
$h = $render($base + ['status' => 'dissolved', 'allowed' => []], 'en');
chk('terminal shows note', str_contains($h, 'terminal state'));
chk('terminal has no forms', ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $h), '<form'));

echo "\nview: flash + RTL\n";
$h = $render($base + ['status' => 'active', 'allowed' => ['archived', 'dissolved', 'merged']], 'ar');
chk('ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar localizes archive button', str_contains($h, 'أرشفة'));

echo "\ncontroller behaviour (source)\n";
chk('history passes status to view', str_contains($ctrl, "'status'") && str_contains($ctrl, '->current('));
chk('history computes allowed transitions', str_contains($ctrl, 'canTransition('));
chk('history passes csrf to view', str_contains($ctrl, "'csrf'") && str_contains($ctrl, 'wbsCsrf'));
chk('browser writes PRG redirect', str_contains($ctrl, "redirect()->to(") && str_contains($ctrl, "/lifecycle"));
chk('API writes return JSON', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, 'respondWith($result)'));
chk('success flash uses localized key', str_contains($ctrl, "lang('Groups.lifecycle."));
chk('archive authorizes group scope', preg_match('/function archive.*authorizeGroupScope/s', $ctrl) === 1);
chk('reactivate authorizes group scope', preg_match('/function reactivate.*authorizeGroupScope/s', $ctrl) === 1);
chk('dissolve authorizes group scope', preg_match('/function dissolve.*authorizeGroupScope/s', $ctrl) === 1);
chk('merge authorizes BOTH group and survivor', substr_count(
    substr($ctrl, strpos($ctrl, 'function merge')),
    'authorizeGroupScope'
) >= 2 || preg_match('/function merge.*authorizeGroupScope.*survivorId.*authorizeGroupScope/s', $ctrl) === 1);
chk('merge reads survivor_id (or merged_into_id)', str_contains($ctrl, "field('survivor_id'"));
chk('actor id sourced from session', str_contains($ctrl, "'actor_id' => \$this->actorId()"));

echo "\nroutes: lifecycle POSTs webcsrf-guarded\n";
foreach (['archive', 'reactivate', 'dissolve', 'merge'] as $act) {
    chk("$act route has webcsrf", (bool) preg_match(
        '#/' . $act . "'.*GroupLifecycleController::" . $act . ".*webcsrf#",
        $routes
    ));
}

echo "\nservice: matrix + current() helper\n";
chk('canTransition exists', str_contains($svc, 'function canTransition'));
chk('current() helper exists', str_contains($svc, 'public function current('));
chk('active->archived allowed', str_contains($svc, "'active'    => ['archived', 'dissolved', 'merged']"));
chk('dissolved terminal', str_contains($svc, "'dissolved' => []"));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
