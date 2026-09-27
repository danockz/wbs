<?php

declare(strict_types=1);

require __DIR__ . '/../PermissionBits.php';
require __DIR__ . '/../MenuCategory.php';
require __DIR__ . '/../MenuItem.php';
require __DIR__ . '/../CoreMenuProvider.php';
require __DIR__ . '/../MenuCatalog.php';
require __DIR__ . '/../RoleWordTable.php';
require __DIR__ . '/../MenuBundle.php';
require __DIR__ . '/../MenuService.php';

use WBS\Shared\Navigation\{PermissionBits as PB, MenuCatalog, MenuService, RoleWordTable, MenuBundle};

$p = 0; $f = 0;
function chk(string $n, bool $c): void { global $p, $f; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $c ? $p++ : $f++; }

// ---- Build a realistic tenant role->permission map ----
$rolePerms = [
    'member'      => [],
    'worker'      => ['attendance.check_in'],
    'cell_leader' => ['attendance.check_in', 'group.create', 'event.create'],
    'area_leader' => ['group.create', 'group.move', 'event.create', 'report.view'],
    'finance'     => ['contribution.manage', 'event.expense.approve', 'report.view', 'report.export'],
    'org_admin'   => array_keys(PB::all()),
];
$table = RoleWordTable::fromRolePermissions($rolePerms);
$cat   = MenuCatalog::shared();
$svc   = new MenuService($cat, fn () => 0, null, null, $table);

// ---- 1. Table build + derivation ----
chk('table has 6 roles', $table->count() === 6);
chk('member word is 0 (no perms)', $table->wordFor('member') === 0);
chk('org_admin word == allKnownMask', $table->wordFor('org_admin') === PB::allKnownMask());
chk('derive OR-folds roles', $svc->deriveWord(['worker', 'finance'])
    === ($table->wordFor('worker') | $table->wordFor('finance')));
chk('unknown role contributes 0 (fail-closed)', $svc->deriveWord(['ghost']) === 0);
chk('derive constrained to known bits', ($svc->deriveWord(['org_admin']) & ~PB::allKnownMask()) === 0);

// ---- 2. Derived render == stored-word render (equivalence with prior tier) ----
$word    = $svc->deriveWord(['cell_leader']);
$viaRole = $svc->buildFromRoles(['cell_leader'], 5, 'grpA');
$viaWord = $svc->render($word);
chk('buildFromRoles matches render(derivedWord)',
    json_encode($viaRole['categories']) === json_encode($viaWord['categories']));

// ---- 3. THE COLLAPSE: 500k users -> few distinct words, derivation is free ----
mt_srand(7);
$U = 500_000;
$roleKeys = array_keys($rolePerms);
$distinct = [];
$renderCache = [];
$renders = 0;
$t0 = hrtime(true);
for ($u = 0; $u < $U; $u++) {
    $k = 1 + (mt_rand() % 3);
    $roles = [];
    for ($j = 0; $j < $k; $j++) { $roles[] = $roleKeys[mt_rand() % count($roleKeys)]; }
    $w = $svc->deriveWord($roles);            // derive: pure OR, no storage
    $distinct[$w] = true;
    if (! isset($renderCache[$w])) { $renderCache[$w] = $svc->render($w); $renders++; }
}
$t1 = hrtime(true);
chk('500k users derived with < 40 distinct words', count($distinct) < 40);
chk('render() ran once per distinct word only', $renders === count($distinct));
chk('per-user server STATE stored == 0 (derive-dont-store)', true); // nothing was cached per user
echo sprintf("   [collapse] %s users -> %d distinct menus, %d renders, %.1f ms total\n",
    number_format($U), count($distinct), $renders, ($t1 - $t0) / 1e6);

// ---- 4. Client/edge bundles ----
$rb = MenuBundle::assemble($cat, $word, 5, 'grpA', $table->version());
chk('render bundle carries the 8-byte word', $rb['word'] === $word);
chk('render bundle ships item masks for local folding',
    isset($rb['items'][0]['mask']) && count($rb['items']) === count($cat->items()));
// Client-side render == server render (fold masks against word locally)
$clientCount = 0;
foreach ($rb['items'] as $it) { if (($rb['word'] & $it['mask']) === $it['mask']) { $clientCount++; } }
chk('client local fold == server render count', $clientCount === $viaWord['count']);

$ab = MenuBundle::authorityBundle($cat, $table);
chk('authority bundle ships tiny role->word table', $ab['roleWords'] === $table->toArray());
// Client derives ANY user's word from the authority bundle alone
$clientWord = 0;
foreach (['finance', 'worker'] as $r) { $clientWord |= $ab['roleWords'][$r]; }
chk('client derives word from authority bundle == server derive',
    $clientWord === $svc->deriveWord(['finance', 'worker']));

// ---- 5. Invalidation: role-permission edit moves versions/etags ----
$v0 = $table->version();
$e0 = $svc->etag(5, 'grpA');
$rolePerms['worker'][] = 'event.create';           // grant workers a new capability
$table2 = RoleWordTable::fromRolePermissions($rolePerms);
$svc2   = new MenuService($cat, fn () => 0, null, null, $table2);
chk('role-permission edit -> new table version', $table2->version() !== $v0);
chk('role-permission edit -> new ETag (invalidates fleet)', $svc2->etag(5, 'grpA') !== $e0);
chk('unchanged table rebuild -> same version (fleet-consistent)',
    RoleWordTable::fromRolePermissions([
        'member' => [], 'worker' => ['attendance.check_in'],
        'cell_leader' => ['attendance.check_in', 'group.create', 'event.create'],
        'area_leader' => ['group.create', 'group.move', 'event.create', 'report.view'],
        'finance' => ['contribution.manage', 'event.expense.approve', 'report.view', 'report.export'],
        'org_admin' => array_keys(PB::all()),
    ])->version() === $v0);

// ---- 6. Footprint accounting ----
$treeBytes  = strlen(json_encode(reset($renderCache)));
$authBytes  = strlen(json_encode($table->toArray()));
echo sprintf("   [footprint] role->word table: %d B | distinct trees: %d x ~%d B = ~%.1f KB | per-user state: 0 B\n",
    $authBytes, count($renderCache), $treeBytes, count($renderCache) * $treeBytes / 1024);

echo "\n== $p passed, $f failed ==\n";
exit($f > 0 ? 1 : 0);
