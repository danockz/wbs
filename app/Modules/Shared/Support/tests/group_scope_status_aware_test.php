<?php

declare(strict_types=1);

/**
 * GroupScopeResolver status-aware resolution (Theme B foundation — GR3).
 *
 * A dissolved / merged / archived group must not participate in scope
 * inheritance: it can neither be inherited FROM (ancestors) nor cascaded INTO
 * (descendants), so grants, configs, rollups and rank ladders stop resolving a
 * dead or hidden node. This proves:
 *
 *   - ancestors() drops a terminal/archived ancestor but keeps active ones;
 *   - descendants() drops a terminal/archived descendant but keeps active ones;
 *   - chain() (self -> ancestors -> org-wide) omits dead ancestors, so a
 *     most-specific-wins resolve never lands on a dead scope;
 *   - grantCoversScoped(self_and_descendants) no longer covers a dissolved
 *     descendant;
 *   - FAIL-OPEN: when the `groups` table is empty (resolver-only fakes), the
 *     closure ids pass through unchanged (regression guard for existing tests).
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework).
 *
 *   php app/Modules/Shared/Support/tests/group_scope_status_aware_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function tableExists(string $t): bool
        {
            return isset($this->rows[$t]);
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
                $this->gt[trim(substr($k, 0, -1))] = $v;

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [trim((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        public function get(): RS
        {
            $rows = $this->matchingRows();
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? 0) <=> ($a[$c] ?? 0))
                    : (($a[$c] ?? 0) <=> ($b[$c] ?? 0)));
            }

            return new RS($rows);
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }
            foreach ($this->gt as $k => $v) {
                if (! ((float) ($r[$k] ?? 0) > (float) $v)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Shared\Support\GroupScopeResolver;
    use WBS\Shared\Support\ScopeMode;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    // Tree:  root(active) -> mid(ARCHIVED) -> leaf(active)
    //        root(active) -> gone(DISSOLVED, a child of root)
    //        leaf(active) has children: kidA(active), kidB(MERGED)
    // Transitively-complete closure for: root -> mid -> leaf -> {kidA, kidB}
    // and root -> gone.
    $db = new BaseConnection();
    $db->rows['group_closure'] = [
        // self rows
        ['ancestor_id' => 'root', 'descendant_id' => 'root', 'distance' => 0],
        ['ancestor_id' => 'mid', 'descendant_id' => 'mid', 'distance' => 0],
        ['ancestor_id' => 'leaf', 'descendant_id' => 'leaf', 'distance' => 0],
        ['ancestor_id' => 'kidA', 'descendant_id' => 'kidA', 'distance' => 0],
        ['ancestor_id' => 'kidB', 'descendant_id' => 'kidB', 'distance' => 0],
        ['ancestor_id' => 'gone', 'descendant_id' => 'gone', 'distance' => 0],
        // root's descendants
        ['ancestor_id' => 'root', 'descendant_id' => 'mid', 'distance' => 1],
        ['ancestor_id' => 'root', 'descendant_id' => 'leaf', 'distance' => 2],
        ['ancestor_id' => 'root', 'descendant_id' => 'kidA', 'distance' => 3],
        ['ancestor_id' => 'root', 'descendant_id' => 'kidB', 'distance' => 3],
        ['ancestor_id' => 'root', 'descendant_id' => 'gone', 'distance' => 1],
        // mid's descendants
        ['ancestor_id' => 'mid', 'descendant_id' => 'leaf', 'distance' => 1],
        ['ancestor_id' => 'mid', 'descendant_id' => 'kidA', 'distance' => 2],
        ['ancestor_id' => 'mid', 'descendant_id' => 'kidB', 'distance' => 2],
        // leaf's descendants
        ['ancestor_id' => 'leaf', 'descendant_id' => 'kidA', 'distance' => 1],
        ['ancestor_id' => 'leaf', 'descendant_id' => 'kidB', 'distance' => 1],
    ];
    $db->rows['groups'] = [
        ['id' => 'root', 'status' => 'active'],
        ['id' => 'mid', 'status' => 'archived'],
        ['id' => 'leaf', 'status' => 'active'],
        ['id' => 'kidA', 'status' => 'active'],
        ['id' => 'kidB', 'status' => 'merged'],
        ['id' => 'gone', 'status' => 'dissolved'],
    ];

    $r = new GroupScopeResolver($db);

    // ancestors(leaf): closure gives [mid, root]; mid is archived -> dropped.
    $anc = $r->ancestors('leaf');
    $chk('ancestors drops archived mid', ! in_array('mid', $anc, true), implode(',', $anc));
    $chk('ancestors keeps active root', in_array('root', $anc, true));
    $chk('ancestors returns only the active root', $anc === ['root'], implode(',', $anc));

    // descendants(leaf): closure gives [kidA, kidB]; kidB merged -> dropped.
    $desc = $r->descendants('leaf');
    $chk('descendants drops merged kidB', ! in_array('kidB', $desc, true), implode(',', $desc));
    $chk('descendants keeps active kidA', $desc === ['kidA'], implode(',', $desc));

    // descendants(root): [mid(arch), leaf, kidA, kidB(merged), gone(dissolved)]
    // -> only leaf + kidA survive.
    $descRoot = $r->descendants('root');
    sort($descRoot);
    $chk('descendants(root) keeps only active nodes', $descRoot === ['kidA', 'leaf'], implode(',', $descRoot));

    // chain(leaf): self(leaf,true) -> ancestors(root) -> [null]. mid omitted.
    $chain = $r->chain('leaf');
    $chainIds = array_map(static fn ($c) => $c[0], $chain);
    $chk('chain starts at self leaf', $chain[0] === ['leaf', true]);
    $chk('chain omits archived mid', ! in_array('mid', $chainIds, true), json_encode($chainIds));
    $chk('chain includes active root then org-wide null', $chainIds === ['leaf', 'root', null], json_encode($chainIds));

    // grantCoversScoped: a self+descendants grant at root still covers the active
    // leaf even though the INTERMEDIATE mid is archived (archiving a middle node
    // must not orphan a live descendant from a live ancestor's authority).
    $chk('grant covers live leaf through archived intermediate',
        $r->grantCoversScoped('root', ScopeMode::SELF_AND_DESCENDANTS, 'leaf') === true);
    // …and still covers the active kidA.
    $chk('grant self+descendants covers active kidA',
        $r->grantCoversScoped('leaf', ScopeMode::SELF_AND_DESCENDANTS, 'kidA') === true);

    // resolveScopeGroups(root, self_and_descendants) excludes ALL dead nodes
    // (mid archived, gone dissolved, kidB merged) — a grant enumerated over its
    // scope acts only on live groups.
    $scopeGroups = $r->resolveScopeGroups('root', ScopeMode::SELF_AND_DESCENDANTS);
    sort($scopeGroups);
    $chk('resolveScopeGroups excludes dead nodes', $scopeGroups === ['kidA', 'leaf', 'root'], implode(',', $scopeGroups));

    // Combined GR2 + GR3: once GR2 has pruned the merged kidB from the closure,
    // a self+descendants grant at leaf no longer covers it (only org-wide would),
    // because kidB has no surviving ancestor edge.
    $db->rows['group_closure'] = array_values(array_filter(
        $db->rows['group_closure'],
        static fn ($e) => $e['ancestor_id'] !== 'kidB' && $e['descendant_id'] !== 'kidB',
    ));
    $rPruned = new GroupScopeResolver($db);
    $chk('GR2+GR3: pruned merged kidB is uncovered by a descendant grant',
        $rPruned->grantCoversScoped('leaf', ScopeMode::SELF_AND_DESCENDANTS, 'kidB') === false);

    // ---- FAIL-OPEN regression guard: no `groups` table seeded ---------------
    $db2 = new BaseConnection();
    $db2->rows['group_closure'] = [
        ['ancestor_id' => 'p', 'descendant_id' => 'c', 'distance' => 1],
        ['ancestor_id' => 'c', 'descendant_id' => 'c', 'distance' => 0],
    ];
    // NOTE: intentionally no $db2->rows['groups'].
    $r2 = new GroupScopeResolver($db2);
    $chk('fail-open: ancestors pass through when no groups table', $r2->ancestors('c') === ['p']);
    $chk('fail-open: descendants pass through when no groups table', $r2->descendants('p') === ['c']);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
