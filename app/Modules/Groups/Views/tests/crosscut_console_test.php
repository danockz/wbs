<?php

declare(strict_types=1);

/**
 * CROSS-CUT links management console — the write face of GroupCrosscutController.
 *
 * The node page (GET groups/{id}/crosscuts) now carries a link form + per-row
 * unlink; the cross-cut page (GET groups/{id}/crosscut-nodes) carries per-row
 * unlink. Both are no-JS PRG forms posting to webcsrf-guarded routes with a
 * same-site return_to; unlink is confirm()-gated. This test covers:
 *   - Groups.crosscutNode.* / crosscutNodes.* console key parity across 6 locales
 *   - node view: link form (required field, webcsrf, return_to) + per-row unlink
 *   - crosscut view: per-row unlink posting to the ROW's node with the cross-cut
 *     id as payload; RTL for Arabic; flash banners
 *   - controller: PRG redirect for browsers / JSON for API; safeReturnTo rejects
 *     open redirects; csrf passed to both views; actor from session
 *   - routes: both crosscut POSTs webcsrf-guarded
 *
 *   php app/Modules/Groups/Views/tests/crosscut_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';
$ctrl    = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupCrosscutController.php');
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

echo "language parity (console keys across 6 locales)\n";
$en       = require $langDir . '/en/Groups.php';
$nodeNew  = ['linkHeading', 'linkHint', 'linkLabel', 'linkPh', 'linkBtn', 'unlinkBtn', 'unlinkConfirm', 'linkedFlash', 'unlinkedFlash'];
$nodesNew = ['unlinkBtn', 'unlinkConfirm'];
foreach ($nodeNew as $k) {
    chk("en crosscutNode.$k", array_key_exists($k, $en['crosscutNode'] ?? []));
}
foreach ($nodesNew as $k) {
    chk("en crosscutNodes.$k", array_key_exists($k, $en['crosscutNodes'] ?? []));
}
$enNode  = $flatten($en['crosscutNode'] ?? []);
$enNodes = $flatten($en['crosscutNodes'] ?? []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m = require $langDir . "/$loc/Groups.php";
    chk("$loc crosscutNode.* parity", array_diff($enNode, $flatten($m['crosscutNode'] ?? [])) === []);
    chk("$loc crosscutNodes.* parity", array_diff($enNodes, $flatten($m['crosscutNodes'] ?? [])) === []);
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

$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};

echo "\nnode view: link form + per-row unlink\n";
$h = $render('crosscut_for_node.php', [
    'links'  => [['crosscut_group_id' => 'cc-1', 'name' => 'Worship Team', 'type' => 'team', 'status' => 'active']],
    'nodeId' => 'node-9',
    'csrf'   => 'TKN',
], 'en');
chk('link form posts to node crosscuts route', str_contains($h, 'action="/groups/node-9/crosscuts"'));
chk('link form carries webcsrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('link form carries return_to', str_contains($h, 'name="return_to" value="/groups/node-9/crosscuts"'));
chk('link field required', str_contains($h, 'name="crosscut_group_id" required'));
chk('per-row unlink posts to node unlink route', str_contains($h, 'action="/groups/node-9/crosscuts/unlink"'));
chk('unlink carries the row cross-cut id', str_contains($h, 'name="crosscut_group_id" value="cc-1"'));
chk('unlink is confirm-gated', preg_match('/unlink"[^>]*onsubmit="return confirm/', $h) === 1);
chk('link is NOT confirm-gated', preg_match('#crosscuts"[^>]*onsubmit#', $h) === 0);

echo "\ncrosscut_group_id entity-reference picker\n";
$hp = $render('crosscut_for_node.php', [
    'links'  => [['crosscut_group_id' => 'g-linked', 'name' => 'Already Linked', 'type' => 'team', 'status' => 'active']],
    'nodeId' => 'node-9',
    'csrf'   => 'TKN',
    'groups' => [
        ['id' => 'node-9', 'name' => 'The Node Itself', 'depth' => 0],   // excluded (self)
        ['id' => 'g-linked', 'name' => 'Already Linked', 'depth' => 1],   // excluded (already linked)
        ['id' => 'g-free', 'name' => 'Choir', 'depth' => 1],              // offered
    ],
], 'en');
chk('cc picker: renders <select id="cc-id">', str_contains($hp, '<select id="cc-id" name="crosscut_group_id" required>'));
chk('cc picker: offers an unlinked group', str_contains($hp, 'value="g-free"') && str_contains($hp, 'Choir'));
chk('cc picker: excludes the node itself', ! preg_match('/<option value="node-9"/', $hp));
chk('cc picker: excludes already-linked groups', ! preg_match('/<option value="g-linked"/', $hp));
chk('cc picker: none option present', str_contains($hp, lang('Groups.crosscutNode.linkNone')));
// no groups → bounded text fallback (unchanged behaviour)
$hpNo = $render('crosscut_for_node.php', ['links' => [], 'nodeId' => 'node-9', 'csrf' => 'T'], 'en');
chk('cc picker: bounded text fallback when no groups', str_contains($hpNo, 'type="text" id="cc-id" name="crosscut_group_id" required maxlength="64"'));

echo "\ncrosscut view: per-row unlink to the row's node\n";
$h = $render('crosscut_nodes.php', [
    'nodes'      => [['hierarchy_group_id' => 'g1', 'name' => 'Accra Central', 'type' => 'local_assembly', 'depth' => 3, 'path' => 'a/b/c']],
    'crosscutId' => 'cc-7',
    'csrf'       => 'TKN',
], 'es');
chk('unlink posts to the ROW node unlink route', str_contains($h, 'action="/groups/g1/crosscuts/unlink"'));
chk('unlink payload is the cross-cut id', str_contains($h, 'name="crosscut_group_id" value="cc-7"'));
chk('unlink return_to is this page', str_contains($h, 'name="return_to" value="/groups/cc-7/crosscut-nodes"'));
chk('unlink confirm-gated (es)', preg_match('/unlink"[^>]*onsubmit="return confirm/', $h) === 1);
$h = $render('crosscut_nodes.php', ['nodes' => [], 'crosscutId' => 'z', 'csrf' => 'T'], 'ar');
chk('crosscut_nodes ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));

echo "\ncontroller behaviour (source)\n";
chk('forNode passes csrf', preg_match('/forNode.*wbsCsrf/s', $ctrl) === 1);
chk('forCrosscut passes csrf', preg_match('/forCrosscut.*wbsCsrf/s', $ctrl) === 1);
chk('browser writes PRG redirect', str_contains($ctrl, 'redirect()->to(') && str_contains($ctrl, 'respondCrosscut'));
chk('API writes return JSON', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, 'respondWith($result)'));
chk('success flash localized', str_contains($ctrl, "lang('Groups.crosscutNode."));
chk('safeReturnTo rejects scheme-relative //', str_contains($ctrl, "str_starts_with(\$rt, '//')"));
chk('safeReturnTo requires leading slash', str_contains($ctrl, "\$rt[0] === '/'"));
chk('actor from session (issued_by)', str_contains($ctrl, "currentUserId('issued_by')"));

echo "\nroutes: crosscut POSTs webcsrf-guarded\n";
chk('link route webcsrf', (bool) preg_match("#/crosscuts'.*::link.*webcsrf#", $routes));
chk('unlink route webcsrf', (bool) preg_match("#/crosscuts/unlink'.*::unlink.*webcsrf#", $routes));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
