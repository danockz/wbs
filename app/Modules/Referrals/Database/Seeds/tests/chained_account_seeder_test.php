<?php

declare(strict_types=1);

/**
 * ChainedAccountSeeder logic test. The seeder itself resolves live services
 * (GroupService / AccountService / SponsorshipService) which need the framework
 * + DB, so this test proves the two things that make the seeder CORRECT, in
 * isolation:
 *
 *   1. The configured chain constant is well-formed and honours the group
 *      hierarchy order — National → Region → Area → Assembly → Fellowship →
 *      Senior Cell → Cell — with fellowship and senior_cell BEFORE cell, unique
 *      leader emails, and four fields per level.
 *
 *   2. The chaining ALGORITHM the seeder runs (walk the chain top-down; each
 *      level's leader is sponsored by the previous level's leader; the root has
 *      no sponsor) produces a correct, acyclic upline when driven through the
 *      real SponsorResolver + a faithful in-memory sponsorships store. This
 *      mirrors exactly what register(sponsor_id=parent_leader, group_id) does.
 *
 * Pure: no DB, no framework boot.
 *
 *   php app/Modules/Referrals/Database/Seeds/tests/chained_account_seeder_test.php
 */

namespace CodeIgniter\Database {
    class Seeder
    {
        protected $db;
    }

    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public array $tables = ['users', 'groups', 'group_members', 'group_closure', 'sponsorships'];

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

        public function insert(array $row): bool
        {
            $this->db->rows[$this->baseTable()][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            $t = $this->baseTable();
            foreach (($this->db->rows[$t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function col(string $k): string
        {
            $p = explode('.', $k);

            return end($p);
        }

        private function matches(array $r): bool
        {
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
        }

        private function rowsFor(): array
        {
            $out = array_values(array_filter($this->db->rows[$this->baseTable()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$k, $dir]) {
                $c = $this->col($k);
                usort($out, static fn ($a, $b) => $dir === 'DESC' ? (($b[$c] ?? '') <=> ($a[$c] ?? '')) : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
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
    require __DIR__ . '/../../../Services/SponsorResolver.php';
    require __DIR__ . '/../ChainedAccountSeeder.php';

    use WBS\Referrals\Services\SponsorResolver;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    // ---- 1) Chain constant shape + ordering --------------------------------
    $ref   = new ReflectionClass(\WBS\Referrals\Database\Seeds\ChainedAccountSeeder::class);
    $chain = $ref->getConstant('CHAIN');
    chk('chain is a non-empty list', is_array($chain) && $chain !== []);
    chk('every level has 4 fields', array_reduce($chain, static fn ($c, $n) => $c && is_array($n) && count($n) === 4, true));

    $types  = array_column($chain, 0);
    $emails = array_column($chain, 3);
    $fi = array_search('fellowship', $types, true);
    $si = array_search('senior_cell', $types, true);
    $ci = array_search('cell', $types, true);
    chk('contains fellowship, senior_cell, cell', $fi !== false && $si !== false && $ci !== false);
    chk('fellowship before senior_cell', $fi < $si);
    chk('senior_cell before cell', $si < $ci);
    chk('national is the root (index 0)', ($types[0] ?? null) === 'national');
    chk('leader emails are unique', count($emails) === count(array_unique($emails)));

    // ---- 2) The chaining algorithm produces a correct upline ---------------
    // Reproduce what the seeder does per level: resolve the sponsor (explicit =
    // parent leader, else the group leader chain) and assign it. Then assert the
    // resulting sponsorships form the exact top-down chain.
    $db = new \CodeIgniter\Database\BaseConnection();

    // A faithful stand-in for SponsorshipService::assign()/activeSponsor()/upline()
    // over the fake DB (single-active per member; acyclic by construction here).
    $assign = static function (string $org, string $member, string $sponsor) use ($db): void {
        foreach (($db->rows['sponsorships'] ?? []) as $i => $r) {
            if (($r['member_id'] ?? null) === $member && (int) ($r['active'] ?? 0) === 1) {
                $db->rows['sponsorships'][$i]['active'] = 0;
            }
        }
        $db->rows['sponsorships'][] = [
            'organization_id' => $org, 'member_id' => $member, 'sponsor_id' => $sponsor, 'active' => 1,
        ];
    };
    $activeSponsor = static function (string $member) use ($db): ?string {
        foreach (($db->rows['sponsorships'] ?? []) as $r) {
            if (($r['member_id'] ?? null) === $member && (int) ($r['active'] ?? 0) === 1) {
                return (string) $r['sponsor_id'];
            }
        }

        return null;
    };

    $resolver = new SponsorResolver($db);
    $org      = 'o1';
    $leaders  = [];
    $groupIds = [];
    $parentGroup  = null;
    $parentLeader = null;

    foreach ($chain as $depth => [$type, $name, $leaderName, $local]) {
        $gid = 'G' . $depth;
        $lid = 'U' . $depth;
        $path = '/' . implode('/', array_map(static fn ($d) => 'G' . $d, range(0, $depth))) . '/';

        // Create the group node + closure rows (self + ancestors), like GroupService.
        $db->rows['groups'][] = ['id' => $gid, 'organization_id' => $org, 'leader_user_id' => null, 'path' => $path];
        $db->rows['group_closure'][] = ['ancestor_id' => $gid, 'descendant_id' => $gid, 'distance' => 0];
        for ($d = 0; $d < $depth; $d++) {
            $db->rows['group_closure'][] = ['ancestor_id' => 'G' . $d, 'descendant_id' => $gid, 'distance' => $depth - $d];
        }

        // Register the leader user, then link the sponsor exactly as the seeder:
        // explicit sponsor_id = parent leader, group_id = this group.
        $db->rows['users'][] = ['id' => $lid, 'organization_id' => $org, 'display_name' => $leaderName];
        $sponsor = $resolver->resolve($org, [
            'explicit_sponsor_id' => $parentLeader,
            'group_id'            => $gid,
            'exclude_user_id'     => $lid,
        ]);
        if ($sponsor !== null && $sponsor !== $lid) {
            $assign($org, $lid, $sponsor);
        }

        // Make them the group leader (so a would-be child could resolve them too).
        foreach ($db->rows['groups'] as $i => $g) {
            if ($g['id'] === $gid) {
                $db->rows['groups'][$i]['leader_user_id'] = $lid;
            }
        }

        $leaders[$depth]  = $lid;
        $groupIds[$depth] = $gid;
        $parentGroup      = $gid;
        $parentLeader     = $lid;
    }

    // Root leader has no sponsor.
    chk('root leader is sponsor-less', $activeSponsor($leaders[0]) === null);

    // Every non-root leader is sponsored by the level directly above.
    $chainOk = true;
    for ($i = 1, $n = count($leaders); $i < $n; $i++) {
        if ($activeSponsor($leaders[$i]) !== $leaders[$i - 1]) {
            $chainOk = false;
            break;
        }
    }
    chk('each leader sponsored by the one above (chained)', $chainOk);

    // The deepest leader's full upline is every ancestor leader, nearest-first.
    $deep   = $leaders[count($leaders) - 1];
    $upline = [];
    $cur    = $deep;
    $guard  = 0;
    while (($s = $activeSponsor($cur)) !== null && $guard++ < 50) {
        $upline[] = $s;
        $cur      = $s;
    }
    $expected = array_reverse(array_slice($leaders, 0, count($leaders) - 1));
    chk('deepest upline spans all ancestors', $upline === $expected,
        'got [' . implode(',', $upline) . '] want [' . implode(',', $expected) . ']');
    chk('upline length = depth', count($upline) === count($leaders) - 1);

    // Fallback: if the explicit sponsor were missing, the resolver still finds
    // the parent group's leader (proves the chain self-heals).
    $sponsorNoExplicit = $resolver->resolve($org, ['group_id' => $groupIds[count($groupIds) - 1], 'exclude_user_id' => 'NEWBIE']);
    chk('new member under deepest group resolves its leader', $sponsorNoExplicit === $deep);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
