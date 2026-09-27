<?php

declare(strict_types=1);

/**
 * GROUP ROSTER management console — the write face of
 * GroupMembershipController::listForGroup + add/changeRole/leave.
 *
 * The group memberships page (GET groups/{id}/memberships) is now a roster
 * console: an add-member form at the top and per-row change-role + leave controls.
 * All are no-JS PRG forms posting to webcsrf-guarded routes using the `_csrf`
 * field (matches WebCsrfFilter); leave is confirm()-gated. This test covers:
 *   - Groups.roster.* key parity across all 6 locales
 *   - add form (required user, type select from service TYPES, role, approval
 *     checkbox, _csrf) posts to the nested memberships route
 *   - per-row role form (posts to /memberships/{id}/role with return=) + leave
 *     form (confirm-gated, /memberships/{id}/leave) — only for active rows
 *   - controls use _csrf NOT webcsrf; RTL for Arabic; flash banners; count plural
 *   - controller: listForGroup renders the view + passes TYPES/csrf; add PRG to
 *     roster; leave/changeRole PRG via respondMembershipDecision with roster keys
 *   - routes: add/leave/role POSTs webcsrf-guarded
 *   - regression: prior Groups consoles use _csrf (not webcsrf)
 *
 *   php app/Modules/Groups/Views/tests/roster_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';
$ctrl    = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupMembershipController.php');
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

echo "language parity (Groups.roster.* across 6 locales)\n";
$en     = require $langDir . '/en/Groups.php';
$enKeys = $flatten($en['roster'] ?? []);
chk('en defines Groups.roster.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Groups.php";
    $miss = array_diff($enKeys, $flatten($m['roster'] ?? []));
    chk("$loc roster.* parity", $miss === [], implode(',', $miss));
}

// ---- view render harness -----------------------------------------------------
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
if (! function_exists('base_url')) {
    function base_url($p = '')
    {
        return 'https://public.test/' . ltrim((string) $p, '/');
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
    include "$viewDir/memberships_manage.php";
    return (string) ob_get_clean();
};

$types = ['member', 'leader', 'activity', 'department', 'team', 'guest'];

echo "\nview: add form\n";
$h = $render([
    'memberships' => [],
    'groupId'     => 'grp-1',
    'status'      => 'active',
    'types'       => $types,
    'csrf'        => 'TKN',
], 'en');
chk('add form posts to nested memberships route', str_contains($h, 'action="/groups/grp-1/memberships"'));
chk('add form uses _csrf field (not webcsrf)', str_contains($h, 'name="_csrf" value="TKN"') && ! str_contains($h, 'name="webcsrf"'));
chk('user id required', str_contains($h, 'name="user_id" required'));
chk('type select lists service TYPES', str_contains($h, 'name="membership_type"') && str_contains($h, '>leader<') && str_contains($h, '>guest<'));
chk('role field present', str_contains($h, 'name="role"'));
chk('requires_approval checkbox', str_contains($h, 'name="requires_approval"'));
chk('empty roster state', str_contains($h, 'No members match'));

echo "\nadd-member user_id entity-reference picker\n";
$hp = $render([
    'memberships' => [
        ['id' => 'm-1', 'user_id' => 'u-existing', 'display_name' => 'Existing Member', 'membership_type' => 'member', 'role' => 'member', 'status' => 'active'],
    ],
    'groupId' => 'grp-1',
    'status'  => 'active',
    'types'   => $types,
    'csrf'    => 'TKN',
    'roster'  => [
        ['id' => 'u-existing', 'display_name' => 'Existing Member'], // excluded (already a member)
        ['id' => 'u-new', 'display_name' => 'Kwame Boateng'],        // offered
    ],
], 'en');
chk('user picker: renders <select id="a-user">', str_contains($hp, '<select id="a-user" name="user_id" required>'));
chk('user picker: offers a non-member', str_contains($hp, 'value="u-new"') && str_contains($hp, 'Kwame Boateng'));
chk('user picker: excludes current members', ! preg_match('/<option value="u-existing"/', $hp));
chk('user picker: none option present', str_contains($hp, lang('Groups.roster.fUserNone')));
// no roster → bounded text fallback (unchanged behaviour)
$hpNo = $render(['memberships' => [], 'groupId' => 'grp-1', 'status' => 'active', 'types' => $types, 'csrf' => 'T'], 'en');
chk('user picker: bounded text fallback when no roster', str_contains($hpNo, 'type="text" id="a-user" name="user_id" required maxlength="64"'));

echo "\nview: per-row role + leave (active only)\n";
$h = $render([
    'memberships' => [
        ['id' => 'm-1', 'user_id' => 'u-1', 'display_name' => 'Ama Owusu', 'membership_type' => 'leader', 'role' => 'coordinator', 'status' => 'active'],
        ['id' => 'm-2', 'user_id' => 'u-2', 'display_name' => '', 'membership_type' => 'member', 'role' => 'member', 'status' => 'pending'],
    ],
    'groupId' => 'grp-1',
    'status'  => 'active',
    'types'   => $types,
    'csrf'    => 'TKN',
], 'en');
chk('shows display name', str_contains($h, 'Ama Owusu'));
chk('unnamed fallback for blank display name', str_contains($h, '(unnamed)'));
chk('role form posts to membership role route', str_contains($h, 'action="https://public.test/memberships/m-1/role"'));
chk('role form carries return path', str_contains($h, 'name="return" value="/groups/grp-1/memberships"'));
chk('leave form posts to membership leave route', str_contains($h, 'action="https://public.test/memberships/m-1/leave"'));
chk('leave is confirm-gated', preg_match('/leave"[^>]*onsubmit="return confirm/', $h) === 1);
chk('role NOT confirm-gated', preg_match('#/role"[^>]*onsubmit#', $h) === 0);
chk('active row has controls', str_contains($h, 'memberships/m-1/role'));
chk('pending row has NO controls', ! str_contains($h, 'memberships/m-2/role'));
chk('plural count interpolated', str_contains($h, '2 members'));

echo "\nview: RTL\n";
$h = $render(['memberships' => [], 'groupId' => 'g', 'status' => 'active', 'types' => $types, 'csrf' => 'T'], 'ar');
chk('ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar localizes add button', str_contains($h, 'إضافة عضو'));

echo "\ncontroller behaviour (source)\n";
chk('listForGroup renders roster view', str_contains($ctrl, 'WBS\Groups\Views\memberships_manage'));
chk('listForGroup passes TYPES + csrf', str_contains($ctrl, 'GroupMembershipService::TYPES') && str_contains($ctrl, 'wbsCsrf'));
chk('add PRG to roster', str_contains($ctrl, "respondRoster(\$result, \$groupId, 'addedFlash')"));
chk('leave PRG roster key', str_contains($ctrl, "respondMembershipDecision(\$result, 'Groups.roster.leftFlash')"));
chk('changeRole PRG roster key', str_contains($ctrl, "respondMembershipDecision(\$result, 'Groups.roster.roleChangedFlash')"));
chk('respondRoster helper exists', str_contains($ctrl, 'private function respondRoster'));
chk('add API path returns JSON', str_contains($ctrl, 'wantsJson()'));
chk('actor from session', str_contains($ctrl, "currentUserId('actor_id')"));

echo "\nroutes: membership write POSTs webcsrf-guarded\n";
chk('add route webcsrf', (bool) preg_match('#::add/\\$1.*webcsrf#', $routes));
chk('leave route webcsrf', (bool) preg_match('#::leave/\\$1.*webcsrf#', $routes));
chk('role route webcsrf', (bool) preg_match('#::changeRole/\\$1.*webcsrf#', $routes));

echo "\nregression: prior Groups consoles use _csrf (not webcsrf)\n";
foreach (['lifecycle_history', 'crosscut_for_node', 'crosscut_nodes', 'kinds_index', 'kind_show'] as $v) {
    $src = file_get_contents("$viewDir/$v.php");
    chk("$v uses _csrf not webcsrf", str_contains($src, 'name="_csrf"') && ! str_contains($src, 'name="webcsrf"'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
