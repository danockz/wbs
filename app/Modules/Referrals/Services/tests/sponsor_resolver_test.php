<?php

declare(strict_types=1);

/**
 * SponsorResolver unit test — proves the automatic-sponsor fallback chain that
 * guarantees EVERY new registration gets an upline (FR-MEM-001):
 *
 *   1. an explicit, valid sponsor (referral referrer / ?sponsor=) wins;
 *   2. else the target group's leader (groups.leader_user_id);
 *   3. else the target group's earliest active leader/admin/coordinator member;
 *   4. else the nearest ANCESTOR group's leader (walk up the closure table);
 *   5. else the organization's root leader (earliest active leadership member);
 *   6. else null (a brand-new org with no leaders yet).
 *
 * Also asserts it never returns the new member themselves (exclude_user_id) and
 * never an invalid/foreign explicit sponsor.
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework).
 *
 *   php app/Modules/Referrals/Services/tests/sponsor_resolver_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        /** @var list<string> */
        public array $tables = ['users', 'groups', 'group_members', 'group_closure'];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function tableExists(string $t): bool
        {
            return in_array($t, $this->tables, true);
        }
    }
}

namespace Fake {
    class QB
    {
        private array $eq = [];
        private array $in = [];
        private array $gt = [];
        private array $order = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '>')) {
                $this->gt[trim(rtrim($k, '>'))] = $v;
            } elseif (str_ends_with($k, '!=')) {
                $this->eq['!=' . trim(rtrim($k, '!='))] = $v;
            } else {
                $this->eq[$k] = $v;
            }

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->in[$k] = $v;

            return $this;
        }

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [$k, strtoupper((string) $dir)];

            return $this;
        }

        public function get($limit = null): RS
        {
            return new RS($this->rowsFor());
        }

        public function countAllResults(): int
        {
            return count($this->rowsFor());
        }

        private function col(string $k): string
        {
            $p = explode('.', $k);

            return end($p);
        }

        private function rowsFor(): array
        {
            $rows = $this->db->rows[$this->baseTable()] ?? [];
            $out  = array_values(array_filter($rows, function ($r) {
                foreach ($this->eq as $k => $v) {
                    if (str_starts_with($k, '!=')) {
                        if (($r[$this->col(substr($k, 2))] ?? null) === $v) {
                            return false;
                        }
                        continue;
                    }
                    if (($r[$this->col($k)] ?? null) !== $v) {
                        return false;
                    }
                }
                foreach ($this->in as $k => $vs) {
                    if (! in_array($r[$this->col($k)] ?? null, $vs, true)) {
                        return false;
                    }
                }
                foreach ($this->gt as $k => $v) {
                    if (! (($r[$this->col($k)] ?? 0) > $v)) {
                        return false;
                    }
                }

                return true;
            }));

            foreach (array_reverse($this->order) as [$k, $dir]) {
                $c = $this->col($k);
                usort($out, static function ($a, $b) use ($c, $dir) {
                    $cmp = ($a[$c] ?? '') <=> ($b[$c] ?? '');

                    return $dir === 'DESC' ? -$cmp : $cmp;
                });
            }

            return $out;
        }

        private function baseTable(): string
        {
            return explode(' ', trim($this->t))[0];
        }
    }

    class RS
    {
        public function __construct(private array $r)
        {
        }

        public function getResultArray(): array
        {
            return $this->r;
        }

        public function getRowArray(): ?array
        {
            return $this->r[0] ?? null;
        }
    }
}

namespace {
    require __DIR__ . '/../SponsorResolver.php';

    use WBS\Referrals\Services\SponsorResolver;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    // Hierarchy:  root(G0, leader U_root) > region(G1, leader U_reg) > cell(G2, no leader, member-leader U_cell)
    function seed(): \CodeIgniter\Database\BaseConnection
    {
        $db = new \CodeIgniter\Database\BaseConnection();
        $db->rows['users'] = [
            ['id' => 'U_root', 'organization_id' => 'o1'],
            ['id' => 'U_reg', 'organization_id' => 'o1'],
            ['id' => 'U_cell', 'organization_id' => 'o1'],
            ['id' => 'U_new', 'organization_id' => 'o1'],
            ['id' => 'U_explicit', 'organization_id' => 'o1'],
            ['id' => 'U_foreign', 'organization_id' => 'o2'],
        ];
        $db->rows['groups'] = [
            ['id' => 'G0', 'organization_id' => 'o1', 'leader_user_id' => 'U_root', 'path' => '/G0/'],
            ['id' => 'G1', 'organization_id' => 'o1', 'leader_user_id' => 'U_reg', 'path' => '/G0/G1/'],
            ['id' => 'G2', 'organization_id' => 'o1', 'leader_user_id' => null, 'path' => '/G0/G1/G2/'],
        ];
        $db->rows['group_members'] = [
            ['user_id' => 'U_cell', 'organization_id' => 'o1', 'group_id' => 'G2', 'status' => 'active', 'role' => 'leader', 'joined_at' => '2024-01-01'],
        ];
        // closure: descendant -> ancestors (distance>0), plus self rows (distance 0)
        $db->rows['group_closure'] = [
            ['ancestor_id' => 'G0', 'descendant_id' => 'G0', 'distance' => 0],
            ['ancestor_id' => 'G1', 'descendant_id' => 'G1', 'distance' => 0],
            ['ancestor_id' => 'G2', 'descendant_id' => 'G2', 'distance' => 0],
            ['ancestor_id' => 'G0', 'descendant_id' => 'G1', 'distance' => 1],
            ['ancestor_id' => 'G0', 'descendant_id' => 'G2', 'distance' => 2],
            ['ancestor_id' => 'G1', 'descendant_id' => 'G2', 'distance' => 1],
        ];

        return $db;
    }

    // 1) explicit sponsor wins
    $r = new SponsorResolver(seed());
    chk('explicit valid sponsor wins', $r->resolve('o1', ['explicit_sponsor_id' => 'U_explicit', 'group_id' => 'G2']) === 'U_explicit');

    // explicit foreign/invalid sponsor is ignored -> falls through to group chain
    chk('foreign explicit sponsor ignored', $r->resolve('o1', ['explicit_sponsor_id' => 'U_foreign', 'group_id' => 'G1']) === 'U_reg');
    chk('unknown explicit sponsor ignored', $r->resolve('o1', ['explicit_sponsor_id' => 'ZZZ', 'group_id' => 'G1']) === 'U_reg');

    // 2) group leader (leader_user_id)
    chk('group leader_user_id', $r->resolve('o1', ['group_id' => 'G1']) === 'U_reg');

    // 3) group membership leader when no leader_user_id
    chk('group membership leader', $r->resolve('o1', ['group_id' => 'G2']) === 'U_cell');

    // 4) nearest ancestor leader when the group itself has none
    $db = seed();
    $db->rows['group_members'] = []; // G2 now has no member-leader either
    $r2 = new SponsorResolver($db);
    chk('nearest ancestor leader (G2 -> G1)', $r2->resolve('o1', ['group_id' => 'G2']) === 'U_reg');

    // 4b) path-based ancestor fallback when closure table absent
    $db2 = seed();
    $db2->rows['group_members'] = [];
    $db2->tables = ['users', 'groups', 'group_members']; // no group_closure
    $r3 = new SponsorResolver($db2);
    chk('ancestor via path fallback (no closure)', $r3->resolve('o1', ['group_id' => 'G2']) === 'U_reg');

    // 5) org root leader when no group context
    $db3 = seed();
    $db3->rows['groups'] = [['id' => 'G9', 'organization_id' => 'o1', 'leader_user_id' => null, 'path' => '/G9/']];
    $db3->rows['group_members'] = [
        ['user_id' => 'U_first', 'organization_id' => 'o1', 'group_id' => 'G9', 'status' => 'active', 'role' => 'admin', 'joined_at' => '2020-01-01'],
        ['user_id' => 'U_late', 'organization_id' => 'o1', 'group_id' => 'G9', 'status' => 'active', 'role' => 'leader', 'joined_at' => '2025-01-01'],
    ];
    $r4 = new SponsorResolver($db3);
    chk('org root = earliest leadership member', $r4->resolve('o1', []) === 'U_first');

    // 6) nobody -> null
    $db4 = new \CodeIgniter\Database\BaseConnection();
    $db4->rows['users'] = [['id' => 'U_new', 'organization_id' => 'o1']];
    $r5 = new SponsorResolver($db4);
    chk('no leaders -> null', $r5->resolve('o1', ['group_id' => 'GX']) === null);

    // never sponsor yourself
    $db5 = seed();
    $r6 = new SponsorResolver($db5);
    chk('never self-sponsor (explicit)', $r6->resolve('o1', ['explicit_sponsor_id' => 'U_reg', 'group_id' => 'G1', 'exclude_user_id' => 'U_reg']) !== 'U_reg');
    chk('excludes self from group leader', $r6->resolve('o1', ['group_id' => 'G2', 'exclude_user_id' => 'U_cell']) === 'U_reg', 'should skip U_cell and climb to nearest ancestor leader');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
