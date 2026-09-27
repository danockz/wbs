<?php

declare(strict_types=1);

/**
 * GROUP admin write consoles — closing the Groups sweep:
 *   - conflict rules  (GET/POST memberships/conflicts) → conflicts_manage view
 *   - group detail    (GET groups/{id}) → detail view now has set-kind + profile
 *
 * Both use no-JS PRG forms posting to webcsrf-guarded routes with the `_csrf`
 * field. This test covers key parity, form wiring, controller PRG/JSON split, and
 * route webcsrf upgrades.
 *
 *   php app/Modules/Groups/Views/tests/group_admin_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';
$mCtrl   = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupMembershipController.php');
$gCtrl   = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupController.php');
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

echo "language parity (conflicts.* + detail.* additions across 6 locales)\n";
$en          = require $langDir . '/en/Groups.php';
$enConflicts = $flatten($en['conflicts'] ?? []);
$detailNew   = ['manageHeading', 'kindHeading', 'kindLabel', 'kindNone', 'kindBtn', 'profileHeading', 'themeLabel', 'joinLabel', 'joinOpen', 'joinApproval', 'taglineLabel', 'emailLabel', 'profileBtn', 'kindSetFlash', 'profileSavedFlash'];
chk('en defines conflicts.* (incl scope.*)', in_array('scope.global', $enConflicts, true) && in_array('defineBtn', $enConflicts, true));
foreach ($detailNew as $k) {
    chk("en detail.$k", array_key_exists($k, $en['detail'] ?? []));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m = require $langDir . "/$loc/Groups.php";
    chk("$loc conflicts.* parity", array_diff($enConflicts, $flatten($m['conflicts'] ?? [])) === []);
    chk("$loc detail additions parity", array_diff($detailNew, array_keys($m['detail'] ?? [])) === []);
}

// ---- render harness ----------------------------------------------------------
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

$renderSelf = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};

echo "\nconflicts_manage view\n";
$h = $renderSelf('conflicts_manage.php', [
    'conflicts' => [['type_a' => 'leader', 'type_b' => 'member', 'scope' => 'global', 'reason' => 'SoD']],
    'types'     => ['member', 'leader', 'guest'],
    'scopes'    => ['global', 'same_group', 'same_branch'],
    'csrf'      => 'TKN',
], 'en');
chk('define form posts to conflicts route', str_contains($h, 'action="/memberships/conflicts"'));
chk('define form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"') && ! str_contains($h, 'name="webcsrf"'));
chk('type_a required select', str_contains($h, 'name="type_a" required'));
chk('type_b required select', str_contains($h, 'name="type_b" required'));
chk('scope select present', str_contains($h, 'name="scope"'));
chk('lists existing rule pair', str_contains($h, '>leader<') && str_contains($h, '>member<'));
chk('localizes scope global (en)', str_contains($h, 'Global'));
chk('shows rule reason', str_contains($h, 'SoD'));
chk('singular count interpolated', str_contains($h, '1 rule'));
$h = $renderSelf('conflicts_manage.php', ['conflicts' => [], 'types' => ['member'], 'scopes' => ['global'], 'csrf' => 'T'], 'ar');
chk('conflicts ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('conflicts empty (ar)', str_contains($h, 'لم يتم تعريف'));

echo "\ndetail view: set-kind + profile console\n";
// detail extends layouts/app; stub the view-layout methods on a renderer object.
$viewStub = new class {
    public string $captured = '';
    public function extend($x) { return ''; }
    public function section($x) { ob_start(); }
    public function endSection() { $this->captured = (string) ob_get_clean(); }
    public function render($x) { return ''; }
    public function renderFile(string $file, array $data): string
    {
        (function () use ($file, $data) {
            extract($data);
            ob_start();
            include $file;
            ob_end_clean();
        })->call($this);
        return $this->captured;
    }
};
$renderDetail = static function (array $data, string $loc) use ($langDir, $viewDir, $viewStub): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    return $viewStub->renderFile("$viewDir/detail.php", $data);
};
$h = $renderDetail([
    'result'  => ['group_id' => 'grp-1', 'ancestors' => [], 'descendants' => []],
    'title'   => 'Group',
    'groupId' => 'grp-1',
    'kinds'   => [['code' => 'ministry', 'name' => 'Ministry']],
    'themes'  => ['aurora', 'slate'],
    'csrf'    => 'TKN',
], 'en');
chk('set-kind form posts to kind route', str_contains($h, 'action="/groups/grp-1/kind"'));
chk('set-kind form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('kind picker lists active kinds', str_contains($h, 'value="ministry"') && str_contains($h, 'Ministry'));
chk('kind picker has none option', str_contains($h, 'value=""'));
chk('profile form posts to profile route', str_contains($h, 'action="/groups/grp-1/profile"'));
chk('profile theme select from themes', str_contains($h, 'name="hero_theme"') && str_contains($h, '>aurora<'));
chk('profile join policy select', str_contains($h, 'name="join_policy"'));
chk('profile contact email field', str_contains($h, 'name="contact_email"'));

echo "\ncontrollers (source)\n";
chk('listConflicts renders conflicts_manage', str_contains($mCtrl, 'WBS\Groups\Views\conflicts_manage'));
chk('listConflicts passes TYPES + scopes + csrf', str_contains($mCtrl, 'GroupMembershipService::TYPES') && str_contains($mCtrl, "'scopes'") && str_contains($mCtrl, 'wbsCsrf'));
chk('defineConflict PRG for browser', str_contains($mCtrl, "redirect()->to('/memberships/conflicts')") && str_contains($mCtrl, "Groups.conflicts.definedFlash"));
chk('defineConflict JSON for API', str_contains($mCtrl, 'wantsJson()'));
chk('show passes kinds + themes + csrf', str_contains($gCtrl, "'kinds'") && str_contains($gCtrl, 'PUBLIC_THEMES') && str_contains($gCtrl, 'wbsCsrf'));
chk('setKind PRG helper', str_contains($gCtrl, "respondGroupWrite(\$result, \$groupId, 'kindSetFlash')"));
chk('updateProfile PRG helper', str_contains($gCtrl, "respondGroupWrite(\$result, \$groupId, 'profileSavedFlash')"));
chk('respondGroupWrite exists', str_contains($gCtrl, 'private function respondGroupWrite'));

echo "\nroutes: writes webcsrf-guarded\n";
chk('conflicts route webcsrf', (bool) preg_match('#::defineConflict.*webcsrf#', $routes));
chk('kind route webcsrf', (bool) preg_match('#::setKind/\\$1.*webcsrf#', $routes));
chk('profile route webcsrf', (bool) preg_match('#::updateProfile/\\$1.*webcsrf#', $routes));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
