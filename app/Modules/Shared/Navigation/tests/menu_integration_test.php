<?php

declare(strict_types=1);

// Fake the CI4 BaseConnection the provider type-hints, then load the real classes.
namespace CodeIgniter\Database {
    class BaseConnection
    {
        public array $rows = [];
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}

namespace Fake {
    class QB
    {
        private array $w = [];
        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}
        public function select($s) { return $this; }
        public function join($t, $c, $type = '') { return $this; }
        public function where($k, $v = null) { $this->w[] = [$k, $v]; return $this; }
        public function get() { return new RS($this->rowsFor()); }
        private function baseTable(): string
        {
            // strip alias ("roles r" -> "roles")
            return explode(' ', trim($this->t))[0];
        }
        private function rowsFor(): array
        {
            $rows = $this->db->rows[$this->baseTable()] ?? [];
            return array_values(array_filter($rows, function ($r) {
                foreach ($this->w as [$k, $v]) {
                    $col = explode('.', $k); $col = end($col);
                    if (($r[$col] ?? null) !== $v) { return false; }
                }
                return true;
            }));
        }
    }
    class RS
    {
        public function __construct(private array $r) {}
        public function getResultArray(): array { return $this->r; }
    }
}

namespace {
    require __DIR__ . '/../PermissionBits.php';
    require __DIR__ . '/../MenuCategory.php';
    require __DIR__ . '/../MenuItem.php';
    require __DIR__ . '/../CoreMenuProvider.php';
    require __DIR__ . '/../MenuCatalog.php';
    require __DIR__ . '/../RoleWordTable.php';
    require __DIR__ . '/../MenuBundle.php';
    require __DIR__ . '/../MenuService.php';
    require __DIR__ . '/../MenuWordProvider.php';

    use WBS\Shared\Navigation\{PermissionBits as PB, MenuCatalog, MenuService, MenuWordProvider, MenuBundle};

    $p = 0; $f = 0;
    function chk(string $n, bool $c): void { global $p, $f; echo ($c ? 'PASS' : 'FAIL') . " $n\n"; $c ? $p++ : $f++; }

    // -- Seed a fake ACL dataset (org 'o1') --
    $db = new \CodeIgniter\Database\BaseConnection();
    $db->rows['roles'] = [
        ['id' => 'R_admin', 'code' => 'org_admin', 'organization_id' => 'o1'],
        ['id' => 'R_lead',  'code' => 'cell_leader', 'organization_id' => 'o1'],
        ['id' => 'R_mem',   'code' => 'member', 'organization_id' => 'o1'],
    ];
    // role_permissions ⋈ permissions materialised as the joined rows the QB returns
    // (our fake join is a no-op, so we pre-join into 'roles' read? -> emulate via a
    // dedicated table the provider reads). The provider selects from 'roles r' with
    // joins; our fake ignores joins, so we instead put the joined shape in 'roles'.
    // To keep the join semantics testable we bypass the query wrapper and test the
    // PURE helpers directly (that is where the logic lives).

    // 1) buildTableFromRows (pure)
    $joinRows = [
        ['role_code' => 'org_admin', 'perm_code' => 'admin.manage'],
        ['role_code' => 'org_admin', 'perm_code' => 'event.create'],
        ['role_code' => 'cell_leader', 'perm_code' => 'event.create'],
        ['role_code' => 'cell_leader', 'perm_code' => 'group.create'],
        ['role_code' => 'member', 'perm_code' => null], // role with no perms
    ];
    $table = MenuWordProvider::buildTableFromRows($joinRows);
    chk('table built from join rows has 3 roles', $table->count() === 3);
    chk('member word == 0', $table->wordFor('member') === 0);
    chk('cell_leader word == event.create|group.create',
        $table->wordFor('cell_leader') === (PB::mask('event.create') | PB::mask('group.create')));
    chk('org_admin word includes admin.manage bit',
        ($table->wordFor('org_admin') & PB::mask('admin.manage')) === PB::mask('admin.manage'));

    // 2) roleCodesFromRows (pure) — scope filtering
    $assign = [
        ['role_code' => 'member', 'scope_group_id' => null],        // org-wide
        ['role_code' => 'cell_leader', 'scope_group_id' => 'gCell'], // only in gCell
        ['role_code' => 'org_admin', 'scope_group_id' => 'gOther'],  // only in gOther
    ];
    chk('org view => only org-wide roles',
        MenuWordProvider::roleCodesFromRows($assign, null) === ['member']);
    chk('scope gCell => org-wide + gCell roles',
        MenuWordProvider::roleCodesFromRows($assign, 'gCell') === ['member', 'cell_leader']);
    chk('scope gOther => org-wide + gOther roles',
        MenuWordProvider::roleCodesFromRows($assign, 'gOther') === ['member', 'org_admin']);

    // 3) versionFromAssignmentRows (pure) — stateless, order-independent, change-sensitive
    $vA = MenuWordProvider::versionFromAssignmentRows([
        ['role_id' => 'R1', 'scope_group_id' => 'g1'],
        ['role_id' => 'R2', 'scope_group_id' => null],
    ]);
    $vA_reordered = MenuWordProvider::versionFromAssignmentRows([
        ['role_id' => 'R2', 'scope_group_id' => null],
        ['role_id' => 'R1', 'scope_group_id' => 'g1'],
    ]);
    $vB = MenuWordProvider::versionFromAssignmentRows([
        ['role_id' => 'R1', 'scope_group_id' => 'g1'],
    ]);
    chk('grant version stable under row reordering', $vA === $vA_reordered);
    chk('grant version changes when an assignment is removed', $vA !== $vB);

    // 4) End-to-end derive -> render -> bundle via MenuService wired with the table
    $cat = MenuCatalog::shared();
    $svc = new MenuService($cat, static fn (): int => 0, null, null, $table);

    // A cell leader in scope gCell (org-wide member + gCell cell_leader)
    $roles = MenuWordProvider::roleCodesFromRows($assign, 'gCell'); // [member, cell_leader]
    $tree  = $svc->buildFromRoles($roles, 42, 'gCell');
    $cats  = array_column($tree['categories'], 'key');
    chk('leader menu includes groups + events', in_array('groups', $cats) && in_array('events', $cats));
    chk('leader menu excludes admin/access', !in_array('admin', $cats) && !in_array('access', $cats));
    chk('tree carries etag + scope + version', isset($tree['etag']) && $tree['scope'] === 'gCell' && $tree['version'] === 42);

    // 5) ETag semantics used by the controller's 304 path
    $etag1 = $svc->etag(42, 'gCell');
    $etag2 = $svc->etag(42, 'gCell');
    $etag3 = $svc->etag(43, 'gCell'); // grant version changed
    $etag4 = $svc->etag(42, 'gOther'); // scope changed
    chk('etag deterministic for same inputs (=> 304 works)', $etag1 === $etag2);
    chk('etag changes with grant version', $etag1 !== $etag3);
    chk('etag changes with scope', $etag1 !== $etag4);

    // 6) Client render bundle equals server render (local fold)
    $word   = $svc->deriveWord($roles) ?? 0;
    $bundle = MenuBundle::assemble($cat, $word, 42, 'gCell', $svc->roleTableVersion());
    $localCount = 0;
    foreach ($bundle['items'] as $it) { if (($bundle['word'] & $it['mask']) === $it['mask']) { $localCount++; } }
    chk('client local fold count == server render count', $localCount === $tree['count']);

    // 7) Role-permission edit -> table version moves -> etag moves (fleet invalidation)
    $joinRows[] = ['role_code' => 'cell_leader', 'perm_code' => 'report.view'];
    $table2 = MenuWordProvider::buildTableFromRows($joinRows);
    $svc2   = new MenuService($cat, static fn (): int => 0, null, null, $table2);
    chk('role-permission edit changes role-table version', $svc2->roleTableVersion() !== $svc->roleTableVersion());
    chk('role-permission edit changes etag', $svc2->etag(42, 'gCell') !== $svc->etag(42, 'gCell'));

    echo "\n== $p passed, $f failed ==\n";
    exit($f > 0 ? 1 : 0);
}
