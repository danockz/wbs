<?php

declare(strict_types=1);

/**
 * Admin READ views i18n + render smoke — the browser faces that replaced the
 * generic admin console on six high-traffic Groups read endpoints:
 *   crosscut_for_node   (GET groups/{id}/crosscuts)
 *   crosscut_nodes      (GET groups/{id}/crosscut-nodes)
 *   kinds_index         (GET group-kinds)
 *   kind_show           (GET group-kinds/{id})
 *   lifecycle_history   (GET groups/{id}/lifecycle/history)
 *   memberships_pending (GET groups/{id}/memberships/pending)
 *
 * Asserts, per block: Groups.<block>.* key parity across all 6 locales,
 * self-contained locale wiring (includes _locale.php, dynamic <html lang dir>,
 * lang('Groups.<block>.*'), no hardcoded lang="en"), and a render smoke covering
 * populated + empty states, the {0} count interpolation, RTL for Arabic, the
 * not-found panel (kind_show), and vocabulary raw-value fallback.
 *
 *   php app/Modules/Groups/Views/tests/admin_views_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';

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

$blocks = [
    'crosscutNode'  => 'crosscut_for_node.php',
    'crosscutNodes' => 'crosscut_nodes.php',
    'kinds'         => 'kinds_index.php',
    'kindShow'      => 'kind_show.php',
    'lifecycle'     => 'lifecycle_history.php',
    'pending'       => 'memberships_pending.php',
];

echo "language file completeness (parity across 6 locales)\n";
$en = require $langDir . '/en/Groups.php';
foreach ($blocks as $block => $_file) {
    $enKeys = $flatten($en[$block] ?? []);
    chk("en defines Groups.$block.*", $enKeys !== []);
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $m    = require $langDir . "/$loc/Groups.php";
        $keys = $flatten($m[$block] ?? []);
        chk("$loc mirrors all en $block keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
        chk("$loc has no stray $block keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

echo "view localized + self-contained wiring\n";
foreach ($blocks as $block => $file) {
    $src = (string) file_get_contents("$viewDir/$file");
    chk("$file calls lang('Groups.$block.", str_contains($src, "lang('Groups.$block."));
    chk("$file has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$file includes _locale.php", str_contains($src, '_locale.php'));
    chk("$file emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
}

echo "render smoke\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
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

$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};

// ---- crosscut_for_node ----
$h = $render('crosscut_for_node.php', ['links' => [
    ['crosscut_group_id' => 'cc-1', 'name' => 'Worship Team', 'type' => 'team', 'status' => 'active'],
], 'nodeId' => 'node-9'], 'fr');
chk('crosscut_for_node fr lang/dir', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('crosscut_for_node shows node id', str_contains($h, 'node-9'));
chk('crosscut_for_node shows group name', str_contains($h, 'Worship Team'));
chk('crosscut_for_node localizes active status (fr)', str_contains($h, 'Actif'));
chk('crosscut_for_node singular count interpolated', str_contains($h, '1 groupe transversal'));
$h = $render('crosscut_for_node.php', ['links' => [], 'nodeId' => 'n'], 'ar');
chk('crosscut_for_node ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('crosscut_for_node empty state (ar)', str_contains($h, 'لا توجد مجموعات عرضية مرتبطة بهذه العقدة.'));
$h = $render('crosscut_for_node.php', ['links' => [
    ['crosscut_group_id' => 'x', 'name' => 'N', 'type' => 't', 'status' => 'weirdstate'],
], 'nodeId' => 'n'], 'en');
chk('crosscut_for_node vocab raw-value fallback', str_contains($h, 'weirdstate'));

// ---- crosscut_nodes ----
$h = $render('crosscut_nodes.php', ['nodes' => [
    ['hierarchy_group_id' => 'g1', 'name' => 'Accra Central', 'type' => 'local_assembly', 'depth' => 3, 'path' => 'a/b/c'],
    ['hierarchy_group_id' => 'g2', 'name' => 'Tema', 'type' => 'area', 'depth' => 2, 'path' => 'a/b'],
], 'crosscutId' => 'cc-7'], 'es');
chk('crosscut_nodes es lang', str_contains($h, 'lang="es"'));
chk('crosscut_nodes shows crosscut id', str_contains($h, 'cc-7'));
chk('crosscut_nodes shows node name + path', str_contains($h, 'Accra Central') && str_contains($h, 'a/b/c'));
chk('crosscut_nodes plural count interpolated (es)', str_contains($h, '2 nodos jerárquicos'));
$h = $render('crosscut_nodes.php', ['nodes' => [], 'crosscutId' => 'z'], 'en');
chk('crosscut_nodes empty state', str_contains($h, 'not attached to any hierarchy node'));

// ---- kinds_index ----
$h = $render('kinds_index.php', ['kinds' => [
    ['code' => 'dept', 'name' => 'Department', 'default_placement' => 'nested', 'status' => 'active', 'color' => '#ff0000', 'sort_order' => 1, 'group_count' => 3],
    ['code' => 'team', 'name' => 'Activity Team', 'default_placement' => 'crosscut', 'status' => 'inactive', 'sort_order' => 2, 'group_count' => 1],
], ], 'pt');
chk('kinds_index pt lang', str_contains($h, 'lang="pt"'));
chk('kinds_index shows code + name', str_contains($h, 'dept') && str_contains($h, 'Department'));
chk('kinds_index localizes placement nested (pt)', str_contains($h, 'Aninhado'));
chk('kinds_index localizes placement crosscut (pt)', str_contains($h, 'Transversal'));
chk('kinds_index renders colour swatch', str_contains($h, 'background:#ff0000'));
chk('kinds_index plural count interpolated (pt)', str_contains($h, '2 tipos'));
// group↔kind relation: per-kind group count badge (plural + singular).
chk('kinds_index shows group count badge (3 grupos)', str_contains($h, '3 grupos'));
chk('kinds_index shows singular group count (1 grupo)', str_contains($h, '1 grupo'));
$h = $render('kinds_index.php', ['kinds' => []], 'zh');
chk('kinds_index empty (zh)', str_contains($h, '尚未定义任何小组类型。'));

// ---- kind_show ----
$h = $render('kind_show.php', ['kind' => [
    'code' => 'ministry', 'name' => 'Ministry', 'description' => 'A serving ministry',
    'default_placement' => 'either', 'sort_order' => 5, 'status' => 'active', 'color' => '#00ff00',
    'group_count' => 2, 'groups' => [
        ['id' => 'g1', 'name' => 'Worship Team', 'type' => 'cell', 'depth' => 3],
        ['id' => 'g2', 'name' => 'Ushering Team', 'type' => 'cell', 'depth' => 3],
    ],
]], 'fr');
chk('kind_show fr lang', str_contains($h, 'lang="fr"'));
chk('kind_show shows name + code', str_contains($h, 'Ministry') && str_contains($h, 'ministry'));
chk('kind_show shows description', str_contains($h, 'A serving ministry'));
chk('kind_show localizes placement either (fr)', str_contains($h, 'Indifférent'));
chk('kind_show shows scope note (fr)', str_contains($h, 'ne change jamais la portée'));
// group↔kind drill-down: the groups classified under this kind + count.
chk('kind_show drill-down lists member groups', str_contains($h, 'Worship Team') && str_contains($h, 'Ushering Team'));
chk('kind_show drill-down links to group detail', str_contains($h, '/groups/g1'));
chk('kind_show drill-down shows count (2 groupes)', str_contains($h, '2 groupes'));
// empty drill-down state
$h2 = $render('kind_show.php', ['kind' => [
    'code' => 'committee', 'name' => 'Committee', 'default_placement' => 'either', 'status' => 'active',
    'group_count' => 0, 'groups' => [],
]], 'fr');
chk('kind_show empty drill-down state (fr)', str_contains($h2, 'Aucun groupe n’utilise encore ce type.'));
$h = $render('kind_show.php', ['kind' => null], 'ar');
chk('kind_show not-found panel (ar)', str_contains($h, 'تعذّر العثور على نوع المجموعة هذا.'));
chk('kind_show not-found ar RTL', str_contains($h, 'dir="rtl"'));

// ---- lifecycle_history ----
$h = $render('lifecycle_history.php', ['transitions' => [
    ['from_status' => 'active', 'to_status' => 'archived', 'reason' => 'Season ended', 'actor_id' => 'u1', 'approval_ref' => 'AP-1', 'created_at' => '2026-09-01'],
    ['from_status' => null, 'to_status' => 'active', 'reason' => 'Formed', 'created_at' => '2026-01-01'],
], 'groupId' => 'grp-5'], 'es');
chk('lifecycle_history es lang', str_contains($h, 'lang="es"'));
chk('lifecycle_history shows group id', str_contains($h, 'grp-5'));
chk('lifecycle_history shows reason', str_contains($h, 'Season ended'));
chk('lifecycle_history localizes to_status archived (es)', str_contains($h, 'Archivado'));
chk('lifecycle_history shows actor + approval', str_contains($h, 'u1') && str_contains($h, 'AP-1'));
chk('lifecycle_history plural count interpolated (es)', str_contains($h, '2 transiciones'));
$h = $render('lifecycle_history.php', ['transitions' => [], 'groupId' => 'g'], 'en');
chk('lifecycle_history empty state', str_contains($h, 'No lifecycle transitions'));

// ---- memberships_pending ----
$h = $render('memberships_pending.php', ['pending' => [
    ['user_id' => 'usr-1', 'membership_type' => 'member', 'role' => 'member', 'source' => 'self_join', 'joined_at' => '2026-09-10'],
], 'groupId' => 'grp-2'], 'zh');
chk('memberships_pending zh lang', str_contains($h, 'lang="zh"'));
chk('memberships_pending shows group + user', str_contains($h, 'grp-2') && str_contains($h, 'usr-1'));
chk('memberships_pending localizes source self_join (zh)', str_contains($h, '自助加入'));
chk('memberships_pending singular count interpolated (zh)', str_contains($h, '1 项待审申请'));
$h = $render('memberships_pending.php', ['pending' => [
    ['user_id' => 'u', 'membership_type' => 'member', 'role' => 'member', 'source' => 'mystery', 'joined_at' => 'x'],
], 'groupId' => 'g'], 'en');
chk('memberships_pending source raw-value fallback', str_contains($h, 'mystery'));
$h = $render('memberships_pending.php', ['pending' => [], 'groupId' => 'g'], 'ar');
chk('memberships_pending empty (ar) RTL', str_contains($h, 'dir="rtl"') && str_contains($h, 'لا توجد طلبات عضوية بانتظار المراجعة.'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
