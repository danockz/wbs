<?php

declare(strict_types=1);

/**
 * GroupService core CRUD — update() + deleteHard() (hierarchy management).
 *
 * Proves the edit/delete half of the group-hierarchy CRUD:
 *
 *   update():
 *     - renames a group (name), and updates type + kind_code;
 *     - normalizes a supplied slug and rejects one already taken by ANOTHER
 *       group in the same org (self-collision is allowed);
 *     - rejects a blank name and an unknown kind_code;
 *     - clearing type/kind_code (empty string) writes NULL;
 *     - a no-op payload is rejected (NOTHING_TO_UPDATE);
 *     - never touches placement (parent_id/depth) or the closure table.
 *
 *   deleteHard():
 *     - hard-deletes an EMPTY LEAF (no children, members or cross-cut links)
 *       and removes its closure rows;
 *     - refuses a node WITH children (HAS_CHILDREN), WITH members (HAS_MEMBERS),
 *       or WITH a cross-cut link (HAS_CROSSCUTS);
 *     - 404s an unknown / other-org id.
 *
 * Tiny in-memory fake of the CI4 query builder (no DB, no framework), with a
 * stub GroupKindService whose valid codes are configurable.
 *
 *   php app/Modules/Groups/Services/tests/group_crud_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        /** @var list<string> */
        public array $existingTables = ['groups', 'group_members', 'group_closure', 'group_crosscut_links'];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function tableExists(string $t, bool $cached = true): bool
        {
            return in_array($t, $this->existingTables, true);
        }

        public function transStart(): void
        {
        }

        public function transComplete(): void
        {
        }

        public function transStatus(): bool
        {
            return true;
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    /**
     * Minimal query-builder fake supporting: where (with an optional operator
     * suffix such as 'id !='), an OR group via groupStart/orWhere/groupEnd,
     * get/countAllResults/insert/update/delete.
     */
    class QB
    {
        /** @var list<array{k:string,op:string,v:mixed}> */
        private array $and = [];
        /** @var list<array{k:string,op:string,v:mixed}> */
        private array $or = [];
        private bool $inGroup = false;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        public function limit($n)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            [$key, $op] = $this->splitOp((string) $k);
            // Inside a groupStart()..groupEnd() block the leading where() is the
            // first term of the OR group (CI4 semantics), not a top-level AND.
            if ($this->inGroup) {
                $this->or[] = ['k' => $key, 'op' => $op, 'v' => $v];
            } else {
                $this->and[] = ['k' => $key, 'op' => $op, 'v' => $v];
            }

            return $this;
        }

        public function groupStart()
        {
            $this->inGroup = true;

            return $this;
        }

        public function orWhere($k, $v = null)
        {
            [$key, $op] = $this->splitOp((string) $k);
            $this->or[] = ['k' => $key, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function groupEnd()
        {
            $this->inGroup = false;

            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matchingRows());
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        public function delete(): bool
        {
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));

            return true;
        }

        /** @return array{0:string,1:string} */
        private function splitOp(string $k): array
        {
            $k = trim($k);
            foreach (['!=', '>=', '<=', '>', '<'] as $op) {
                if (str_ends_with($k, ' ' . $op)) {
                    return [trim(substr($k, 0, -strlen($op))), $op];
                }
            }

            return [$k, '='];
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function cmp(array $r, array $c): bool
        {
            $left  = (string) ($r[$c['k']] ?? '');
            $right = (string) $c['v'];

            return $c['op'] === '!=' ? $left !== $right : $left === $right;
        }

        private function matches(array $r): bool
        {
            foreach ($this->and as $c) {
                if (! $this->cmp($r, $c)) {
                    return false;
                }
            }
            if ($this->or !== []) {
                $any = false;
                foreach ($this->or as $c) {
                    if ($this->cmp($r, $c)) {
                        $any = true;
                        break;
                    }
                }
                if (! $any) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Stub GroupKindService at its FQCN (real file not loaded).
namespace WBS\Groups\Services {
    if (! class_exists(GroupKindService::class)) {
        class GroupKindService
        {
            /** @var list<string> */
            public array $valid = ['ministry', 'team'];

            public function isValidCode(string $organizationId, string $code): bool
            {
                return in_array($code, $this->valid, true);
            }
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Groups\Services\GroupKindService;
    use WBS\Groups\Services\GroupService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/GroupService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        // root -> branch -> leaf ; plus sibling 'sib' with an existing slug.
        $db->rows['groups'] = [
            ['id' => 'root', 'organization_id' => $ORG, 'parent_id' => null, 'name' => 'Root', 'slug' => 'root', 'type' => 'national', 'kind_code' => null, 'depth' => 1, 'path' => '/root/', 'status' => 'active'],
            ['id' => 'branch', 'organization_id' => $ORG, 'parent_id' => 'root', 'name' => 'Branch', 'slug' => 'branch', 'type' => 'region', 'kind_code' => null, 'depth' => 2, 'path' => '/root/branch/', 'status' => 'active'],
            ['id' => 'leaf', 'organization_id' => $ORG, 'parent_id' => 'branch', 'name' => 'Leaf', 'slug' => 'leaf', 'type' => 'cell', 'kind_code' => null, 'depth' => 3, 'path' => '/root/branch/leaf/', 'status' => 'active'],
            ['id' => 'sib', 'organization_id' => $ORG, 'parent_id' => 'branch', 'name' => 'Sibling', 'slug' => 'taken-slug', 'type' => 'cell', 'kind_code' => null, 'depth' => 3, 'path' => '/root/branch/sib/', 'status' => 'active'],
        ];
        $db->rows['group_closure'] = [
            ['ancestor_id' => 'root', 'descendant_id' => 'root', 'distance' => 0],
            ['ancestor_id' => 'branch', 'descendant_id' => 'branch', 'distance' => 0],
            ['ancestor_id' => 'leaf', 'descendant_id' => 'leaf', 'distance' => 0],
            ['ancestor_id' => 'sib', 'descendant_id' => 'sib', 'distance' => 0],
            ['ancestor_id' => 'root', 'descendant_id' => 'branch', 'distance' => 1],
            ['ancestor_id' => 'root', 'descendant_id' => 'leaf', 'distance' => 2],
            ['ancestor_id' => 'branch', 'descendant_id' => 'leaf', 'distance' => 1],
            ['ancestor_id' => 'root', 'descendant_id' => 'sib', 'distance' => 2],
            ['ancestor_id' => 'branch', 'descendant_id' => 'sib', 'distance' => 1],
        ];
        $db->rows['group_members']       = [];
        $db->rows['group_crosscut_links'] = [];

        return $db;
    };

    $rowById = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['groups'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }

        return null;
    };
    $closureMentions = static function (BaseConnection $db, string $id): int {
        $n = 0;
        foreach ($db->rows['group_closure'] as $r) {
            if ($r['ancestor_id'] === $id || $r['descendant_id'] === $id) {
                $n++;
            }
        }

        return $n;
    };

    // ================= update() =================
    $db  = $seed();
    $svc = new GroupService($db, new Clock(), new GroupKindService());

    $r = $svc->update($ORG, 'leaf', ['name' => 'Renamed Leaf', 'type' => 'senior_cell', 'kind_code' => 'ministry']);
    $chk('update ok', $r->ok === true, (string) ($r->code ?? ''));
    $leaf = $rowById($db, 'leaf');
    $chk('update writes name', ($leaf['name'] ?? '') === 'Renamed Leaf');
    $chk('update writes type', ($leaf['type'] ?? '') === 'senior_cell');
    $chk('update writes kind_code', ($leaf['kind_code'] ?? '') === 'ministry');
    $chk('update leaves parent_id untouched', ($leaf['parent_id'] ?? '') === 'branch');
    $chk('update leaves depth untouched', (int) ($leaf['depth'] ?? 0) === 3);
    $chk('update leaves closure untouched', $closureMentions($db, 'leaf') === 3);

    // slug normalization + self-collision allowed
    $r = $svc->update($ORG, 'leaf', ['slug' => 'My New Slug!!']);
    $chk('slug normalized', $r->ok === true && ($rowById($db, 'leaf')['slug'] ?? '') === 'my-new-slug');
    $r = $svc->update($ORG, 'leaf', ['name' => 'Leaf', 'slug' => 'my-new-slug']); // same slug on self
    $chk('self slug collision allowed', $r->ok === true, (string) ($r->code ?? ''));

    // slug taken by another group -> 409
    $r = $svc->update($ORG, 'leaf', ['slug' => 'taken-slug']);
    $chk('slug taken by other rejected', $r->ok === false && $r->code === 'SLUG_TAKEN', (string) ($r->code ?? ''));

    // blank name rejected
    $r = $svc->update($ORG, 'leaf', ['name' => '   ']);
    $chk('blank name rejected', $r->ok === false && $r->code === 'NAME_REQUIRED', (string) ($r->code ?? ''));

    // unknown kind rejected
    $r = $svc->update($ORG, 'leaf', ['kind_code' => 'nope']);
    $chk('unknown kind rejected', $r->ok === false && $r->code === 'BAD_KIND', (string) ($r->code ?? ''));

    // clearing type + kind -> NULL
    $r = $svc->update($ORG, 'leaf', ['type' => '', 'kind_code' => '']);
    $leaf = $rowById($db, 'leaf');
    $chk('clear type -> null', $r->ok === true && $leaf['type'] === null);
    $chk('clear kind -> null', $leaf['kind_code'] === null);

    // no-op payload rejected
    $r = $svc->update($ORG, 'leaf', []);
    $chk('empty payload rejected', $r->ok === false && $r->code === 'NOTHING_TO_UPDATE', (string) ($r->code ?? ''));

    // unknown / other-org id -> 404
    $r = $svc->update($ORG, 'ghost', ['name' => 'x']);
    $chk('update unknown id 404', $r->ok === false && $r->status === 404, (string) ($r->status ?? ''));
    $r = $svc->update('other-org', 'leaf', ['name' => 'x']);
    $chk('update other-org 404', $r->ok === false && $r->status === 404);

    // ================= deleteHard() =================
    // empty leaf deletes + prunes closure
    $db  = $seed();
    $svc = new GroupService($db, new Clock(), new GroupKindService());
    $chk('leaf present before delete', $rowById($db, 'leaf') !== null);
    $r = $svc->deleteHard($ORG, 'leaf');
    $chk('deleteHard empty leaf ok', $r->ok === true, (string) ($r->code ?? ''));
    $chk('leaf row removed', $rowById($db, 'leaf') === null);
    $chk('leaf closure pruned', $closureMentions($db, 'leaf') === 0);
    $chk('sibling untouched', $rowById($db, 'sib') !== null && $closureMentions($db, 'sib') === 3);

    // node WITH children refused
    $db  = $seed();
    $svc = new GroupService($db, new Clock(), new GroupKindService());
    $r = $svc->deleteHard($ORG, 'branch');
    $chk('delete parent refused', $r->ok === false && $r->code === 'HAS_CHILDREN', (string) ($r->code ?? ''));
    $chk('branch still present', $rowById($db, 'branch') !== null);

    // node WITH members refused
    $db  = $seed();
    $db->rows['group_members'][] = ['group_id' => 'leaf', 'user_id' => 'u1'];
    $svc = new GroupService($db, new Clock(), new GroupKindService());
    $r = $svc->deleteHard($ORG, 'leaf');
    $chk('delete populated leaf refused', $r->ok === false && $r->code === 'HAS_MEMBERS', (string) ($r->code ?? ''));

    // node WITH cross-cut link refused
    $db  = $seed();
    $db->rows['group_crosscut_links'][] = ['id' => 'l1', 'organization_id' => $ORG, 'crosscut_group_id' => 'cc1', 'hierarchy_group_id' => 'leaf'];
    $svc = new GroupService($db, new Clock(), new GroupKindService());
    $r = $svc->deleteHard($ORG, 'leaf');
    $chk('delete crosscut-linked leaf refused', $r->ok === false && $r->code === 'HAS_CROSSCUTS', (string) ($r->code ?? ''));

    // unknown / other-org id -> 404
    $db  = $seed();
    $svc = new GroupService($db, new Clock(), new GroupKindService());
    $r = $svc->deleteHard($ORG, 'ghost');
    $chk('delete unknown id 404', $r->ok === false && $r->status === 404);
    $r = $svc->deleteHard('other-org', 'leaf');
    $chk('delete other-org 404', $r->ok === false && $r->status === 404);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
