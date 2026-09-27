<?php

declare(strict_types=1);

/**
 * JourneyRecommendationService assessment — the stage-linked-activities read that
 * answers "given where this member is on the discipleship ladder, what should
 * they (or their discipler) do next?" (Option D). Pure read: computes nothing,
 * awards nothing.
 *
 * This test pins the behaviour the assessment cares about:
 *   - resolves the member's CURRENT stage via JourneyService and, by default,
 *     recommends for the current AND next ladder stage (scope: both);
 *   - a member with NO journey yet is recommended the ENTRY stage;
 *   - scope variants: 'current', 'next', 'from_current' (+ahead), and the
 *     terminal stage (no "next");
 *   - stage_code linkage across all THREE catalogs (gamification_rules,
 *     activity_categories, follow_up_types);
 *   - GROUP-SCOPE most-specific-wins: the group's own row hides the inherited
 *     ancestor row of the same code; an ancestor row is only inherited when it
 *     opts in with include_descendants = 1; org-wide (NULL group) is the
 *     fallback;
 *   - only ACTIVE rows; empty stages come back empty (stage_code is advisory);
 *   - the guard rails: blank user, and a context with no ladder.
 *
 * REGRESSION GUARD: exercises a member in a group WITH ancestors, which makes
 * the service filter every catalog — including `gamification_rules` — with
 * `include_descendants = 1`. That column was added to gamification_rules by
 * migration 000067 (it was missing before); this fixture includes the column so
 * the query resolves like the sibling catalogs.
 *
 * Uses a tiny in-memory fake of the CI4 query builder with FAITHFUL nested
 * predicate groups (groupStart / orGroupStart / groupEnd), no DB, no framework.
 *
 *   php app/Modules/Journey/Services/tests/journey_recommendation_test.php
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
    }
}

namespace Fake {
    /**
     * Predicate tree: each node is ['bool'=>'AND'|'OR', 'preds'=>[...]] where a
     * pred is ['type'=>'eq'|'in','col'=>..,'val'=>..] or a nested group node.
     */
    class QB
    {
        private array $root;
        /** @var list<array> stack of open group nodes */
        private array $stack;
        private array $order = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
            $this->root  = ['bool' => 'AND', 'preds' => []];
            $this->stack = [&$this->root];
        }

        private function &top(): array
        {
            return $this->stack[count($this->stack) - 1];
        }

        private function add(array $pred): void
        {
            $top             = &$this->stack[count($this->stack) - 1];
            $top['preds'][]  = $pred;
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k   = (string) $k;
            $op  = 'eq';
            if (preg_match('/\s*(>=|<=|>|<|!=)\s*$/', $k, $m)) {
                $op = ['>' => 'gt', '<' => 'lt', '>=' => 'ge', '<=' => 'le', '!=' => 'ne'][$m[1]];
            }
            $this->add(['type' => $op, 'col' => $this->col($k), 'val' => $v, 'bool' => 'AND']);

            return $this;
        }

        public function orWhere($k, $v = null)
        {
            $this->add(['type' => 'eq', 'col' => $this->col((string) $k), 'val' => $v, 'bool' => 'OR']);

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->add(['type' => 'in', 'col' => $this->col((string) $k), 'val' => $v, 'bool' => 'AND']);

            return $this;
        }

        public function groupStart()
        {
            return $this->openGroup('AND');
        }

        public function orGroupStart()
        {
            return $this->openGroup('OR');
        }

        private function openGroup(string $bool)
        {
            $node = ['bool' => 'AND', 'preds' => [], 'group' => true, 'joinBool' => $bool];
            $top  = &$this->stack[count($this->stack) - 1];
            $idx  = count($top['preds']);
            $top['preds'][$idx] = $node;
            $this->stack[]      = &$top['preds'][$idx];

            return $this;
        }

        public function groupEnd()
        {
            array_pop($this->stack);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [$this->col((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        public function get(): RS
        {
            $rows = array_values(array_filter(
                $this->db->rows[$this->baseTable()] ?? [],
                fn ($r) => $this->evalNode($this->root, $r),
            ));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }

            return new RS($rows);
        }

        private function evalNode(array $node, array $r): bool
        {
            // Combine child predicates honoring each child's own bool (AND/OR),
            // left-to-right, like a query builder's WHERE chain. A nested group
            // contributes as a single term joined by its joinBool.
            $acc  = null;
            foreach ($node['preds'] as $p) {
                if (isset($p['group'])) {
                    $val  = $this->evalNode($p, $r);
                    $bool = $p['joinBool'] ?? 'AND';
                } else {
                    $val  = $this->evalPred($p, $r);
                    $bool = $p['bool'] ?? 'AND';
                }
                if ($acc === null) {
                    $acc = $val;
                } elseif ($bool === 'OR') {
                    $acc = $acc || $val;
                } else {
                    $acc = $acc && $val;
                }
            }

            return $acc ?? true;
        }

        private function evalPred(array $p, array $r): bool
        {
            $actual = $r[$p['col']] ?? null;
            switch ($p['type']) {
                case 'in':
                    return in_array($actual, $p['val'], true);
                case 'gt':
                    return $actual !== null && $actual > $p['val'];
                case 'lt':
                    return $actual !== null && $actual < $p['val'];
                case 'ge':
                    return $actual !== null && $actual >= $p['val'];
                case 'le':
                    return $actual !== null && $actual <= $p['val'];
                case 'ne':
                    return $actual !== $p['val'];
            }
            // eq — treat NULL match explicitly; loose scalar compare otherwise.
            if ($p['val'] === null) {
                return $actual === null;
            }

            return (string) $actual === (string) $p['val'];
        }

        private function col(string $k): string
        {
            $k = trim($k);
            // strip a trailing operator like 'distance >'
            $k = preg_replace('/\s*[<>!=]+$/', '', $k) ?? $k;
            $p = explode('.', $k);

            return end($p);
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
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyRecommendationService.php';

    use WBS\Journey\Services\JourneyRecommendationService;
    use WBS\Journey\Services\JourneyService;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\GroupScopeResolver;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    // ---- Fixtures ----------------------------------------------------------
    $ORG = 'org1';
    $db  = new \CodeIgniter\Database\BaseConnection();

    // A 4-stage ladder: seeker(entry) -> new_believer -> growing -> sender(terminal)
    $stage = static fn (string $code, string $name, string $phase, int $order, int $entry = 0): array => [
        'id' => "st_$code", 'organization_id' => 'org1', 'group_id' => null, 'code' => $code,
        'name' => $name, 'phase' => $phase, 'sort_order' => $order, 'is_entry' => $entry,
        'is_terminal' => $code === 'sender' ? 1 : 0, 'status' => 'active',
    ];
    $db->rows['journey_stages'] = [
        $stage('seeker', 'Seeker', 'win', 10, 1),
        $stage('new_believer', 'New Believer', 'build', 20),
        $stage('growing', 'Growing', 'build', 30),
        $stage('sender', 'Sender', 'send', 40),
    ];

    // group_closure: g_child's ancestor is g_parent (distance 1)
    $db->rows['group_closure'] = [
        ['ancestor_id' => 'g_parent', 'descendant_id' => 'g_child', 'distance' => 1],
        ['ancestor_id' => 'g_child', 'descendant_id' => 'g_child', 'distance' => 0],
    ];

    // member journeys: alice at new_believer (org-wide); bob has none.
    $db->rows['member_journeys'] = [
        ['organization_id' => 'org1', 'user_id' => 'alice', 'group_id' => null, 'stage_code' => 'new_believer'],
    ];

    // gamification_rules (earning activities) — carries include_descendants now.
    $rule = static fn (array $o): array => array_merge([
        'id' => 'r_' . ($o['code'] ?? 'x'), 'organization_id' => 'org1', 'group_id' => null,
        'include_descendants' => 1, 'category_id' => null, 'phase' => 'build', 'activity_name' => null,
        'icon' => null, 'color' => null, 'sort_order' => 0, 'version' => 1, 'points' => 10,
        'point_mode' => 'fixed', 'status' => 'active', 'stage_code' => null,
    ], $o);
    $db->rows['gamification_rules'] = [
        $rule(['code' => 'read_bible', 'activity_name' => 'Read the Bible', 'stage_code' => 'new_believer', 'points' => 5]),
        $rule(['code' => 'attend_class', 'activity_name' => 'Attend foundations class', 'stage_code' => 'new_believer', 'points' => 10, 'sort_order' => 1]),
        $rule(['code' => 'lead_group', 'activity_name' => 'Lead a group', 'stage_code' => 'growing', 'points' => 30]),
        $rule(['code' => 'inactive_one', 'activity_name' => 'Old', 'stage_code' => 'new_believer', 'status' => 'archived']),
        $rule(['code' => 'no_stage', 'activity_name' => 'Untagged', 'stage_code' => null]),
    ];

    $cat = static fn (array $o): array => array_merge([
        'id' => 'c_' . ($o['code'] ?? 'x'), 'organization_id' => 'org1', 'group_id' => null,
        'include_descendants' => 1, 'code' => 'x', 'name' => 'X', 'phase' => 'build',
        'icon' => null, 'color' => null, 'sort_order' => 0, 'status' => 'active', 'stage_code' => null,
    ], $o);
    $db->rows['activity_categories'] = [
        $cat(['code' => 'devotion', 'name' => 'Devotion', 'stage_code' => 'new_believer']),
    ];

    $fut = static fn (array $o): array => array_merge([
        'id' => 'f_' . ($o['code'] ?? 'x'), 'organization_id' => 'org1', 'group_id' => null,
        'include_descendants' => 1, 'code' => 'x', 'name' => 'X', 'phase' => 'build',
        'award_rule_code' => null, 'sort_order' => 0, 'status' => 'active', 'stage_code' => null,
    ], $o);
    $db->rows['follow_up_types'] = [
        $fut(['code' => 'welcome_call', 'name' => 'Welcome call', 'stage_code' => 'new_believer']),
    ];

    $clock  = new Clock();
    $scope  = new GroupScopeResolver($db);
    $journey = new JourneyService($db, $clock, $scope);
    $svc    = new JourneyRecommendationService($db, $journey, $scope);

    // ---- current stage + default (both) -----------------------------------
    echo "current + next (default scope)\n";
    $res = $svc->forMember($ORG, 'alice');
    chk('ok', $res->ok);
    $d = $res->data;
    chk('resolved current stage', ($d['current_stage'] ?? null) === 'new_believer');
    chk('has_journey true', ($d['has_journey'] ?? null) === true);
    $byCode = [];
    foreach ($d['stages'] as $s) {
        $byCode[$s['code']] = $s;
    }
    chk('recommends current stage', isset($byCode['new_believer']) && $byCode['new_believer']['position'] === 'current');
    chk('recommends next stage', isset($byCode['growing']) && $byCode['growing']['position'] === 'next');
    chk('current stage lists its active activities', count($byCode['new_believer']['activities']) === 2);
    chk('activities ordered by sort_order', ($byCode['new_believer']['activities'][0]['code'] ?? '') === 'read_bible'
        && ($byCode['new_believer']['activities'][1]['code'] ?? '') === 'attend_class');
    chk('archived activity excluded', ! in_array('inactive_one', array_column($byCode['new_believer']['activities'], 'code'), true));
    chk('untagged activity excluded', ! in_array('no_stage', array_column($byCode['new_believer']['activities'], 'code'), true));
    chk('categories linked', ($byCode['new_believer']['categories'][0]['code'] ?? '') === 'devotion');
    chk('follow-up types linked', ($byCode['new_believer']['follow_up_types'][0]['code'] ?? '') === 'welcome_call');
    chk('next stage has its activity', ($byCode['growing']['activities'][0]['code'] ?? '') === 'lead_group');
    chk('totals summed', $d['totals']['activities'] === 3 && $d['totals']['categories'] === 1 && $d['totals']['follow_up_types'] === 1,
        json_encode($d['totals']));

    // ---- scope variants ----------------------------------------------------
    echo "scope variants\n";
    $r2 = $svc->forMember($ORG, 'alice', null, ['scope' => 'current'])->data;
    chk("scope=current -> only current", count($r2['stages']) === 1 && $r2['stages'][0]['code'] === 'new_believer');

    $r3 = $svc->forMember($ORG, 'alice', null, ['scope' => 'next'])->data;
    chk("scope=next -> only next", count($r3['stages']) === 1 && $r3['stages'][0]['code'] === 'growing');

    $r4 = $svc->forMember($ORG, 'alice', null, ['scope' => 'from_current', 'ahead' => 2])->data;
    $codes4 = array_column($r4['stages'], 'code');
    chk('scope=from_current ahead=2 -> current + next 2', $codes4 === ['new_believer', 'growing', 'sender'], json_encode($codes4));
    chk('from_current positions', $r4['stages'][0]['position'] === 'current'
        && $r4['stages'][1]['position'] === 'next' && $r4['stages'][2]['position'] === 'ahead');

    // ---- terminal stage: no "next" ----------------------------------------
    echo "terminal stage\n";
    $db->rows['member_journeys'][] = ['organization_id' => 'org1', 'user_id' => 'carol', 'group_id' => null, 'stage_code' => 'sender'];
    $rt = $svc->forMember($ORG, 'carol')->data;
    chk('terminal current resolved', $rt['current_stage'] === 'sender');
    chk('terminal has no next stage', count(array_filter($rt['stages'], static fn ($s) => $s['position'] === 'next')) === 0);

    // ---- no journey -> entry stage ----------------------------------------
    echo "no journey -> entry stage\n";
    $rb = $svc->forMember($ORG, 'bob')->data;
    chk('bob has_journey false', $rb['has_journey'] === false);
    chk('bob current_stage null', $rb['current_stage'] === null);
    chk('bob recommended entry stage', count($rb['stages']) === 1 && $rb['stages'][0]['code'] === 'seeker');
    chk('entry stage position=next', $rb['stages'][0]['position'] === 'next');

    // ---- group-scope most-specific-wins -----------------------------------
    echo "group scope: most-specific-wins + inheritance opt-in\n";
    // dave is at new_believer in group g_child (which has ancestor g_parent).
    $db->rows['member_journeys'][] = ['organization_id' => 'org1', 'user_id' => 'dave', 'group_id' => 'g_child', 'stage_code' => 'new_believer'];
    // Same code 'read_bible' defined at: org-wide(5pts), ancestor g_parent(inherit on, 7pts),
    // and the child's own(9pts). Child's own must win.
    $db->rows['gamification_rules'][] = $rule(['id' => 'r_rb_parent', 'code' => 'read_bible', 'activity_name' => 'RB parent', 'stage_code' => 'new_believer', 'points' => 7, 'group_id' => 'g_parent', 'include_descendants' => 1]);
    $db->rows['gamification_rules'][] = $rule(['id' => 'r_rb_child', 'code' => 'read_bible', 'activity_name' => 'RB child', 'stage_code' => 'new_believer', 'points' => 9, 'group_id' => 'g_child']);
    // An ancestor-only activity with inheritance OFF must NOT appear for the child.
    $db->rows['gamification_rules'][] = $rule(['id' => 'r_secret', 'code' => 'parent_secret', 'activity_name' => 'Secret', 'stage_code' => 'new_believer', 'points' => 4, 'group_id' => 'g_parent', 'include_descendants' => 0]);
    // An ancestor activity with inheritance ON must appear for the child.
    $db->rows['gamification_rules'][] = $rule(['id' => 'r_inh', 'code' => 'parent_shared', 'activity_name' => 'Shared', 'stage_code' => 'new_believer', 'points' => 6, 'group_id' => 'g_parent', 'include_descendants' => 1]);

    $rd = $svc->forMember($ORG, 'dave', 'g_child')->data;
    $nb = null;
    foreach ($rd['stages'] as $s) {
        if ($s['code'] === 'new_believer') {
            $nb = $s;
        }
    }
    chk('group ctx resolves current', $rd['current_stage'] === 'new_believer' && $nb !== null);
    $acts = [];
    foreach ($nb['activities'] as $a) {
        $acts[$a['code']] = $a;
    }
    chk('child override wins for read_bible (9pts)', ($acts['read_bible']['points'] ?? null) === 9, json_encode($acts['read_bible'] ?? null));
    chk('inheritance-on ancestor activity included', isset($acts['parent_shared']));
    chk('inheritance-off ancestor activity excluded', ! isset($acts['parent_secret']));

    // ---- guard rails -------------------------------------------------------
    echo "guard rails\n";
    $bad = $svc->forMember($ORG, '  ');
    chk('blank user fails 422', ! $bad->ok && $bad->status === 422 && $bad->code === 'BAD_USER');

    $emptyDb = new \CodeIgniter\Database\BaseConnection();
    $emptyDb->rows['journey_stages'] = [];
    $svc2 = new JourneyRecommendationService($emptyDb, new JourneyService($emptyDb, $clock, new GroupScopeResolver($emptyDb)), new GroupScopeResolver($emptyDb));
    $noLadder = $svc2->forMember($ORG, 'alice');
    chk('no ladder fails 422 NO_LADDER', ! $noLadder->ok && $noLadder->status === 422 && $noLadder->code === 'NO_LADDER');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
