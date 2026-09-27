<?php

declare(strict_types=1);

/**
 * END-TO-END ASSESSMENT — a fresh org's seeded `membership`-facet rules actually
 * exist AND actually match the real journey signals.
 *
 * This proves the whole Option-C chain works out of the box, not in isolation:
 *
 *   MembershipRuleSeeder  (REAL seeder — writes rules for a fresh org)
 *        │
 *        ▼   real `rules` rows
 *   JourneySignalService::ingest(signal)          (REAL)
 *        │  builds context {action, current_stage, …}
 *        ▼
 *   RuleEngine::evaluate('membership', …)         (REAL)
 *        │  action_pattern + scope + AbacConditionEvaluator on current_stage
 *        ▼
 *   JourneyService::transition / proposal         (REAL)
 *        ▼  member_journeys advances / journey_stage_proposals queued
 *
 * The ONLY fake is an in-memory CI4 query-builder + connection; every service in
 * the chain is the production class. Stage codes come from JourneyStageSeeder's
 * default ladder, which this test seeds the same way.
 *
 * Assessments pinned:
 *   0. BASELINE — before seeding, a fresh org matches NOTHING (proves the gap the
 *      seeder closes: without it the whole pipeline is a silent no-op).
 *   1. The seeder writes exactly the expected membership rules, org-wide, enabled,
 *      with valid conditions the write-time evaluator accepts.
 *   2. event.attended on a prospect AUTO-ADVANCES to first_timer (effect adjust).
 *   3. course.completed on a new_believer AUTO-ADVANCES to in_foundation.
 *   4. event.attended on a first_timer PROPOSES new_believer (require_review) —
 *      no auto-move; a pending proposal is queued.
 *   5. follow_up.recorded on a prospect PROPOSES first_timer.
 *   6. contribution.verified on an established member PROPOSES worker.
 *   7. current_stage gating: the SAME signal for a member at the WRONG stage
 *      matches nothing (the ABAC condition really is evaluated).
 *   8. idempotency: running the seeder twice creates no duplicate rules.
 *   9. a group-scoped member (group WITH an ancestor) is still covered by the
 *      org-wide (scope_mode=self) default rules.
 *
 *   php app/Modules/Journey/Database/Seeds/tests/membership_rule_seeder_e2e_test.php
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

        /** Columns to report as ABSENT from fieldExists(), keyed "table.column". */
        public array $missingColumns = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function fieldExists(string $column, string $table): bool
        {
            return ! in_array($table . '.' . $column, $this->missingColumns, true);
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
    class QB
    {
        /** @var list<array{0:string,1:mixed,2:string}> */
        private array $wheres = [];
        private array $order  = [];
        private ?string $group = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function key(string $k): array
        {
            $k  = trim($k);
            $op = 'eq';
            if (preg_match('/\s*(>=|<=|>|<|!=)\s*$/', $k, $m)) {
                $op = ['>' => 'gt', '<' => 'lt', '>=' => 'ge', '<=' => 'le', '!=' => 'ne'][$m[1]];
                $k  = (string) preg_replace('/\s*[<>!=]+$/', '', $k);
            }
            $p = explode('.', trim($k));

            return [end($p), $op];
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            [$col, $op] = $this->key((string) $k);
            $this->wheres[] = [$col, $v, $op];

            return $this;
        }

        public function orWhere($k, $v = null)
        {
            [$col, $op] = $this->key((string) $k);
            $this->wheres[] = [$col, $v, 'or_' . $op];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            [$col] = $this->key((string) $k);
            $this->wheres[] = [$col, $v, 'in'];

            return $this;
        }

        public function groupBy($g)
        {
            $this->group = (string) $g;

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            [$col] = $this->key((string) $k);
            $this->order[] = [$col, strtoupper((string) $dir)];

            return $this;
        }

        private function matches(array $r): bool
        {
            $acc = null;
            foreach ($this->wheres as [$c, $v, $op]) {
                $isOr = str_starts_with($op, 'or_');
                $bare = $isOr ? substr($op, 3) : $op;
                $val  = $this->cmp($r[$c] ?? null, $bare, $v);
                if ($acc === null) {
                    $acc = $val;
                } elseif ($isOr) {
                    $acc = $acc || $val;
                } else {
                    $acc = $acc && $val;
                }
            }

            return $acc ?? true;
        }

        private function cmp(mixed $actual, string $op, mixed $v): bool
        {
            switch ($op) {
                case 'in':  return in_array($actual, $v, true);
                case 'gt':  return $actual !== null && $actual > $v;
                case 'lt':  return $actual !== null && $actual < $v;
                case 'ge':  return $actual !== null && $actual >= $v;
                case 'le':  return $actual !== null && $actual <= $v;
                case 'ne':  return (string) $actual !== (string) $v;
            }
            if ($v === null) {
                return $actual === null;
            }

            return (string) $actual === (string) $v;
        }

        private function filtered(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }

            return $rows;
        }

        public function get($limit = null, $offset = 0): RS
        {
            $rows = $this->filtered();
            if ($this->group !== null) {
                $buckets = [];
                foreach ($rows as $r) {
                    $gk = $r['stage_code'] ?? '';
                    $buckets[$gk] ??= ['stage_code' => $r['stage_code'] ?? null, 'stage_phase' => $r['stage_phase'] ?? null, 'total' => 0];
                    $buckets[$gk]['total']++;
                }
                $rows = array_values($buckets);
            }
            if ($limit !== null) {
                $rows = array_slice($rows, (int) $offset, (int) $limit);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->base()][] = $row;

            return true;
        }

        public function update(array $set): bool
        {
            foreach ($this->db->rows[$this->base()] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->base()][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function base(): string
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
    $root = dirname(__DIR__, 6);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/AccessControl/Policy/RuleOutcome.php';
    require_once $root . '/app/Modules/AccessControl/Policy/AbacConditionEvaluator.php';
    require_once $root . '/app/Modules/AccessControl/Services/RuleEngine.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';
    require_once $root . '/app/Modules/Journey/Services/JourneySignalService.php';
    require_once $root . '/app/Modules/Journey/Database/Seeds/MembershipRuleSeeder.php';

    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Policy\AbacConditionEvaluator;
    use WBS\AccessControl\Services\RuleEngine;
    use WBS\Journey\Database\Seeds\MembershipRuleSeeder;
    use WBS\Journey\Services\JourneyService;
    use WBS\Journey\Services\JourneySignalService;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\GroupScopeResolver;
    use WBS\Shared\Support\Uuid;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    // Default ladder codes, mirroring JourneyStageSeeder.
    $LADDER = [
        ['prospect', 'win', 10, 1, 0],
        ['first_timer', 'win', 20, 0, 0],
        ['new_believer', 'win', 30, 0, 0],
        ['in_foundation', 'build', 40, 0, 0],
        ['established', 'build', 50, 0, 0],
        ['worker', 'build', 60, 0, 0],
        ['leader', 'send', 70, 0, 0],
        ['sender', 'send', 80, 0, 1],
    ];

    $ORG = 'org1';

    /** Build a fresh world: ladder + org row; optionally seed a member journey. */
    $newWorld = static function () use ($LADDER, $ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['organizations'] = [['id' => $ORG, 'slug' => 'wbs']];
        $db->rows['journey_stages'] = [];
        foreach ($LADDER as [$code, $phase, $sort, $entry, $term]) {
            $db->rows['journey_stages'][] = [
                'id' => "st_$code", 'organization_id' => $ORG, 'group_id' => null, 'code' => $code,
                'name' => ucfirst($code), 'phase' => $phase, 'sort_order' => $sort,
                'is_entry' => $entry, 'is_terminal' => $term, 'status' => 'active',
            ];
        }
        $db->rows['member_journeys']            = [];
        $db->rows['member_journey_transitions'] = [];
        $db->rows['journey_stage_proposals']    = [];
        $db->rows['rules']                      = [];
        $db->rows['grant_scope_groups']         = [];
        $db->rows['group_closure']              = [];
        $db->rows['group_crosscut_links']       = [];

        return $db;
    };

    /** Wire the REAL Option-C chain over a db. */
    $wire = static function (BaseConnection $db): JourneySignalService {
        $clock   = new Clock();
        $scope   = new GroupScopeResolver($db);
        $journey = new JourneyService($db, $clock, $scope, []);
        $engine  = new RuleEngine($db, new AbacConditionEvaluator(), $scope);

        return new JourneySignalService($db, $clock, $journey, $engine);
    };

    /** Put a member at a stage (org-wide journey unless group given). */
    $seedMember = static function (BaseConnection $db, string $user, string $stage, ?string $group = null) use ($ORG): void {
        $db->rows['member_journeys'][] = [
            'id' => 'j_' . $user, 'organization_id' => $ORG, 'user_id' => $user, 'group_id' => $group,
            'stage_code' => $stage, 'stage_phase' => 'win', 'previous_stage' => null,
            'status' => 'active', 'source' => 'manual', 'source_ref' => null,
        ];
    };

    $runSeeder = static function (BaseConnection $db): void {
        $seeder = new MembershipRuleSeeder();
        (function () use ($db) { $this->db = $db; })->call($seeder);
        $seeder->run();
    };

    $stageOf = static function (BaseConnection $db, string $user): ?string {
        foreach ($db->rows['member_journeys'] as $j) {
            if ($j['user_id'] === $user) {
                return $j['stage_code'];
            }
        }

        return null;
    };

    // ── 0. BASELINE: fresh org, NO seeder run → nothing matches ──────────────
    echo "0. baseline — a fresh org (rules NOT seeded) matches nothing\n";
    $db = $newWorld();
    $seedMember($db, 'ann', 'prospect');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.event.attended']);
    chk('no rules => matched 0', $r->ok && $r->data['matched'] === 0);
    chk('member unchanged (no progression without rules)', $stageOf($db, 'ann') === 'prospect');
    chk('rules table empty before seeding', count($db->rows['rules']) === 0);

    // ── 1. Seeder writes the expected membership rules ───────────────────────
    echo "1. MembershipRuleSeeder populates the membership facet\n";
    $db = $newWorld();
    $runSeeder($db);
    $rules = $db->rows['rules'];
    chk('seeded 10 rules', count($rules) === 10, (string) count($rules));
    chk('all facet=membership', array_reduce($rules, fn ($c, $r) => $c && $r['facet'] === 'membership', true));
    chk('all enabled + org-wide (null scope, self)', array_reduce($rules, fn ($c, $r) => $c
        && (int) $r['enabled'] === 1 && $r['scope_group_id'] === null && $r['scope_mode'] === 'self', true));
    $codes = array_column($rules, 'code');
    chk('has attend->first_timer adjust rule', in_array('mbr.attend.prospect_to_first_timer', $codes, true));
    chk('has course->foundation adjust rule', in_array('mbr.course.new_believer_to_foundation', $codes, true));
    chk('has group.joined->first_timer adjust rule (M7)', in_array('mbr.group.prospect_to_first_timer', $codes, true));
    chk('has group.joined->foundation review rule (M7)', in_array('mbr.group.new_believer_to_foundation', $codes, true));
    // Conditions are valid against the SAME evaluator the write path uses.
    $ev = new AbacConditionEvaluator();
    $allValid = true;
    foreach ($rules as $r) {
        $cond = json_decode((string) $r['condition'], true);
        $ep   = json_decode((string) $r['effect_params'], true);
        if (! is_array($cond) || ! $ev->isValid($cond) || ! isset($ep['to_stage'])) {
            $allValid = false;
        }
    }
    chk('every rule has a valid condition + to_stage param', $allValid);
    $distinctActions = array_values(array_unique(array_column($rules, 'action_pattern')));
    sort($distinctActions);
    chk('actions cover all 7 emitters (incl. group.joined + entry signals)', $distinctActions === [
        'journey.signal.contribution.verified',
        'journey.signal.course.completed',
        'journey.signal.event.attended',
        'journey.signal.follow_up.recorded',
        'journey.signal.group.joined',
        'journey.signal.member.converted',
        'journey.signal.member.registered',
    ], implode(',', $distinctActions));
    chk('has member.registered entry rule (M4)', in_array('mbr.register.open_prospect', $codes, true));
    chk('has member.converted entry rule (M4)', in_array('mbr.convert.open_first_timer', $codes, true));

    // ── 2. event.attended on prospect → AUTO-advance to first_timer ──────────
    echo "2. event.attended auto-advances a prospect (effect adjust)\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'ann', 'prospect');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'ann', 'action' => 'journey.signal.event.attended', 'evidence_ref' => 'att1']);
    chk('matched', ($r->data['matched'] ?? 0) >= 1);
    chk('applied to first_timer', count($r->data['applied']) === 1 && $r->data['applied'][0]['to_stage'] === 'first_timer', json_encode($r->data));
    chk('member advanced', $stageOf($db, 'ann') === 'first_timer');
    chk('transition recorded source=rule', (function () use ($db) {
        $t = end($db->rows['member_journey_transitions']);
        return $t && $t['to_stage'] === 'first_timer' && $t['source'] === 'rule';
    })());

    // ── 3. course.completed on new_believer → auto-advance to in_foundation ──
    echo "3. course.completed auto-advances a new believer\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'ben', 'new_believer');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'ben', 'action' => 'journey.signal.course.completed', 'attributes' => ['course_code' => 'FND101']]);
    chk('applied to in_foundation', count($r->data['applied']) === 1 && $r->data['applied'][0]['to_stage'] === 'in_foundation');
    chk('member advanced', $stageOf($db, 'ben') === 'in_foundation');

    // ── 3b. group.joined on prospect → AUTO-advance to first_timer (M7) ──────
    echo "3b. group.joined auto-advances a prospect (membership half of integration)\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'gus', 'prospect');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'gus', 'action' => 'journey.signal.group.joined', 'scope_group_id' => 'grpX', 'evidence_ref' => 'membership:m1']);
    chk('group.joined matched', ($r->data['matched'] ?? 0) >= 1);
    chk('group.joined applied to first_timer', count($r->data['applied']) === 1 && $r->data['applied'][0]['to_stage'] === 'first_timer', json_encode($r->data));
    chk('member advanced by belonging', $stageOf($db, 'gus') === 'first_timer');

    // ── 3c. group.joined on new_believer → PROPOSE in_foundation (review) ────
    echo "3c. group.joined proposes foundation for a new believer\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'ivy', 'new_believer');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'ivy', 'action' => 'journey.signal.group.joined']);
    chk('nothing auto-applied (review)', $r->data['applied'] === []);
    chk('one proposal to in_foundation', count($r->data['proposed']) === 1 && $r->data['proposed'][0]['to_stage'] === 'in_foundation');
    chk('new believer NOT moved', $stageOf($db, 'ivy') === 'new_believer');

    // ── 3d. member.registered OPENS a journey at prospect (M4 entry seam) ────
    echo "3d. member.registered opens a journey for a brand-new account (M4)\n";
    $db = $newWorld();
    $runSeeder($db);
    // NO journey seeded — this is a brand-new member who has done nothing.
    $svc = $wire($db);
    chk('no journey row before the signal', ($stageOf($db, 'newbie')) === null);
    $r = $svc->ingest($ORG, ['user_id' => 'newbie', 'action' => 'journey.signal.member.registered']);
    chk('registered matched the entry rule', ($r->data['matched'] ?? 0) >= 1, json_encode($r->data));
    chk('journey opened at prospect', $stageOf($db, 'newbie') === 'prospect');
    // Replay is a harmless no-op — the journey now exists, has_journey=true.
    $r2 = $svc->ingest($ORG, ['user_id' => 'newbie', 'action' => 'journey.signal.member.registered']);
    chk('replay does not re-fire the entry rule (has_journey now true)', $r2->data['applied'] === []);
    chk('member still at prospect after replay', $stageOf($db, 'newbie') === 'prospect');
    chk('exactly one journey row for the member', count(array_filter($db->rows['member_journeys'], fn ($j) => $j['user_id'] === 'newbie')) === 1);

    // ── 3e. member.converted OPENS a journey at first_timer (M4 entry seam) ──
    echo "3e. member.converted opens a journey at first_timer for a converted referral (M4)\n";
    $db = $newWorld();
    $runSeeder($db);
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'convert', 'action' => 'journey.signal.member.converted']);
    chk('converted matched the entry rule', ($r->data['matched'] ?? 0) >= 1);
    chk('journey opened at first_timer', $stageOf($db, 'convert') === 'first_timer');

    // ── 3f. entry rule does NOT clobber an existing journey ─────────────────
    echo "3f. member.registered no-ops for a member who already has a journey\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'existing', 'established');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'existing', 'action' => 'journey.signal.member.registered']);
    chk('entry rule did not fire for an established member', $r->data['applied'] === []);
    chk('established member unchanged by registered signal', $stageOf($db, 'existing') === 'established');

    // ── 4. event.attended on first_timer → PROPOSE new_believer (review) ─────
    echo "4. repeat attendance PROPOSES new believer (require_review)\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'cai', 'first_timer');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'cai', 'action' => 'journey.signal.event.attended']);
    chk('nothing auto-applied', $r->data['applied'] === []);
    chk('one proposal queued', count($r->data['proposed']) === 1 && $r->data['proposed'][0]['to_stage'] === 'new_believer');
    chk('member NOT moved', $stageOf($db, 'cai') === 'first_timer');
    chk('pending proposal row exists', count(array_filter($db->rows['journey_stage_proposals'],
        fn ($p) => $p['status'] === 'pending' && $p['to_stage'] === 'new_believer')) === 1);

    // ── 5. follow_up.recorded on prospect → PROPOSE first_timer ──────────────
    echo "5. follow-up proposes advancing a prospect\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'dee', 'prospect');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'dee', 'action' => 'journey.signal.follow_up.recorded']);
    chk('one proposal to first_timer', count($r->data['proposed']) === 1 && $r->data['proposed'][0]['to_stage'] === 'first_timer');
    chk('member still prospect', $stageOf($db, 'dee') === 'prospect');

    // ── 6. contribution.verified on established → PROPOSE worker ──────────────
    echo "6. verified contribution proposes recognising a worker\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'eve', 'established');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'eve', 'action' => 'journey.signal.contribution.verified', 'project_code' => 'cause1']);
    chk('one proposal to worker', count($r->data['proposed']) === 1 && $r->data['proposed'][0]['to_stage'] === 'worker');
    chk('proposal carries project_code', (function () use ($db) {
        $p = end($db->rows['journey_stage_proposals']);
        return $p && ($p['project_code'] ?? null) === 'cause1';
    })());

    // ── 7. current_stage gating: wrong stage → no match ──────────────────────
    echo "7. current_stage gating — a signal for the wrong stage matches nothing\n";
    $db = $newWorld();
    $runSeeder($db);
    $seedMember($db, 'flo', 'established'); // course rule targets new_believer/in_foundation
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'flo', 'action' => 'journey.signal.course.completed']);
    chk('no course rule matches an established member', $r->data['applied'] === [] && $r->data['proposed'] === []);
    chk('established member unchanged', $stageOf($db, 'flo') === 'established');

    // ── 8. idempotency: second seeder run adds no duplicates ─────────────────
    echo "8. seeder is idempotent\n";
    $db = $newWorld();
    $runSeeder($db);
    $runSeeder($db);
    chk('still exactly 10 rules after re-run', count($db->rows['rules']) === 10, (string) count($db->rows['rules']));

    // ── 8b. REGRESSION: seeder is column-defensive (no include_crosscut) ─────
    // Reproduces the production crash "Unknown column 'include_crosscut'": a DB
    // whose rules table predates migration 000056 must still seed cleanly.
    echo "8b. seeder tolerates a rules table missing include_crosscut / scope_mode\n";
    $db = $newWorld();
    $db->missingColumns = ['rules.include_crosscut', 'rules.scope_mode'];
    $threw = false;
    try {
        $runSeeder($db);
    } catch (\Throwable $e) {
        $threw = true;
        echo '     ' . $e->getMessage() . "\n";
    }
    chk('seeding does not throw when optional columns are absent', ! $threw);
    chk('still wrote 10 rules', count($db->rows['rules']) === 10, (string) count($db->rows['rules']));
    chk('omitted include_crosscut from the insert', ! array_key_exists('include_crosscut', $db->rows['rules'][0] ?? []));
    chk('omitted scope_mode from the insert', ! array_key_exists('scope_mode', $db->rows['rules'][0] ?? []));
    // And the chain still works on that schema (engine defaults scope_mode=self).
    $seedMember($db, 'hal', 'prospect');
    $svc = $wire($db);
    $r = $svc->ingest($ORG, ['user_id' => 'hal', 'action' => 'journey.signal.event.attended']);
    chk('progression still works without the optional columns', $stageOf($db, 'hal') === 'first_timer');

    // ── 9. group-scoped member covered by org-wide default rules ─────────────
    echo "9. org-wide default rule covers a group-scoped member (group with ancestor)\n";
    $db = $newWorld();
    $runSeeder($db);
    // g_child's ancestor is g_parent.
    $db->rows['group_closure'] = [
        ['ancestor_id' => 'g_parent', 'descendant_id' => 'g_child', 'distance' => 1],
        ['ancestor_id' => 'g_child',  'descendant_id' => 'g_child', 'distance' => 0],
    ];
    $seedMember($db, 'gus', 'prospect', 'g_child');
    $svc = $wire($db);
    // Journey context = the group journey; scope_group_id = the group so a
    // group-scoped leader rule COULD fire — here the org-wide default covers it.
    $r = $svc->ingest($ORG, ['user_id' => 'gus', 'action' => 'journey.signal.event.attended', 'group_id' => 'g_child', 'scope_group_id' => 'g_child']);
    chk('org-wide rule matched a grouped member', ($r->data['matched'] ?? 0) >= 1);
    chk('grouped member advanced to first_timer', $stageOf($db, 'gus') === 'first_timer');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
