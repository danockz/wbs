<?php

declare(strict_types=1);

/**
 * JourneyService::funnel + wiring test — the discipleship FUNNEL & progression
 * report (the reporting gap named in the activities/membership-journey
 * assessment §3). Pins:
 *
 *   - the CURRENT-STATE funnel is monotonic: at_or_beyond[i] = Σ current[j≥i],
 *     so a Leader counts toward every earlier stage's reach;
 *   - reach_pct is the share of total_active at-or-beyond each stage;
 *   - conversion_pct = at_or_beyond[next] ÷ at_or_beyond[i] (share who advanced
 *     past this stage), and is 0 for the terminal stage;
 *   - stall_pct = current[i] ÷ at_or_beyond[i] (share stuck at exactly here);
 *   - RECENT MOMENTUM: moves_in / movers_in count arrivals INTO each stage
 *     within the window from the transition trail (advance/open/set), honouring
 *     the window cutoff and distinct-user counting;
 *   - group context vs org-wide isolation, and windowDays clamping;
 *   - controller renders the bespoke view + JSON, route is a plain read, the
 *     view is self-contained / CSP-clean, i18n parity (funnel block + funnelLink)
 *     across all 6 locales, and the menu links it.
 *
 * A frozen clock makes the window cutoff deterministic. Tiny in-memory fake of
 * the CI4 query builder understands eq/null/in/date predicates + groupBy with
 * COUNT / COUNT(DISTINCT).
 *
 *   php app/Modules/Journey/Services/tests/journey_funnel_test.php
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

        public function transStart(): void {}

        public function transComplete(): void {}

        public function transStatus(): bool
        {
            return true;
        }
    }
}

namespace Fake {
    class QB
    {
        /** @var list<array{col:string,val:mixed,op:string}> */
        private array $wheres = [];
        private array $order  = [];
        private ?string $group = null;
        /** @var list<string> */
        private array $selCount    = [];      // aliases needing COUNT(*)
        /** @var array<string,string> */
        private array $selDistinct = [];       // alias => distinct column

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function key(string $k): string
        {
            $k = trim($k);
            $k = preg_replace('/\s*(>=|<=|<>|!=|>|<)\s*$/', '', $k) ?? $k;
            $p = explode('.', trim($k));

            return end($p);
        }

        public function where($k, $v = null, $escape = true)
        {
            $k  = (string) $k;
            $op = 'eq';
            if (preg_match('/\s*(>=|<=|<>|!=|>|<)\s*$/', $k, $m)) {
                $op = ['>=' => 'ge', '<=' => 'le', '>' => 'gt', '<' => 'lt', '<>' => 'ne', '!=' => 'ne'][$m[1]];
            }
            $this->wheres[] = ['col' => $this->key($k), 'val' => $v, 'op' => $op];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->wheres[] = ['col' => $this->key((string) $k), 'val' => $v, 'op' => 'in'];

            return $this;
        }

        public function select($s)
        {
            // Parse "COUNT(*) AS alias" and "COUNT(DISTINCT col) AS alias".
            foreach (preg_split('/,(?![^(]*\))/', (string) $s) as $expr) {
                $expr = trim($expr);
                if (preg_match('/COUNT\(DISTINCT\s+([a-z_]+)\)\s+AS\s+([a-z_]+)/i', $expr, $m)) {
                    $this->selDistinct[$m[2]] = $m[1];
                } elseif (preg_match('/COUNT\(\*\)\s+AS\s+([a-z_]+)/i', $expr, $m)) {
                    $this->selCount[] = $m[1];
                }
            }

            return $this;
        }

        public function groupBy($g)
        {
            $this->group = $this->key((string) $g);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [$this->key((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        private function matches(array $r): bool
        {
            foreach ($this->wheres as $w) {
                $actual = $r[$w['col']] ?? null;
                switch ($w['op']) {
                    case 'in':
                        if (! in_array($actual, $w['val'], true)) { return false; }
                        break;
                    case 'ge':
                        if (! ($actual !== null && (string) $actual >= (string) $w['val'])) { return false; }
                        break;
                    case 'gt':
                        if (! ($actual !== null && (string) $actual > (string) $w['val'])) { return false; }
                        break;
                    case 'le':
                        if (! ($actual !== null && (string) $actual <= (string) $w['val'])) { return false; }
                        break;
                    case 'lt':
                        if (! ($actual !== null && (string) $actual < (string) $w['val'])) { return false; }
                        break;
                    case 'ne':
                        if ($actual === $w['val']) { return false; }
                        break;
                    default: // eq (with NULL semantics)
                        if ($w['val'] === null) {
                            if ($actual !== null) { return false; }
                        } elseif ((string) $actual !== (string) $w['val']) {
                            return false;
                        }
                }
            }

            return true;
        }

        private function base(): string
        {
            return explode(' ', trim($this->t))[0];
        }

        public function get($limit = null): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));

            if ($this->group !== null) {
                $buckets = [];
                foreach ($rows as $r) {
                    $gk = (string) ($r[$this->group] ?? '');
                    if (! isset($buckets[$gk])) {
                        $buckets[$gk]                 = [$this->group => $r[$this->group] ?? null];
                        foreach ($this->selCount as $a) { $buckets[$gk][$a] = 0; }
                        foreach ($this->selDistinct as $a => $col) { $buckets[$gk]['__d_' . $a] = []; }
                    }
                    foreach ($this->selCount as $a) { $buckets[$gk][$a]++; }
                    foreach ($this->selDistinct as $a => $col) { $buckets[$gk]['__d_' . $a][(string) ($r[$col] ?? '')] = true; }
                }
                $out = [];
                foreach ($buckets as $b) {
                    foreach ($this->selDistinct as $a => $col) {
                        $b[$a] = count($b['__d_' . $a]);
                        unset($b['__d_' . $a]);
                    }
                    $out[] = $b;
                }

                return new RS($out);
            }

            return new RS($rows);
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

    use WBS\Journey\Services\JourneyService;
    use WBS\Shared\Support\Clock;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }
    function near(float $a, float $b): bool
    {
        return abs($a - $b) < 0.05;
    }

    $NOW = new DateTimeImmutable('2026-09-14 12:00:00', new DateTimeZone('UTC'));
    Clock::freeze($NOW);
    $daysAgo = static fn (int $d): string => $NOW->modify("-{$d} days")->format('Y-m-d H:i:s');

    $ORG = 'org1';
    $db  = new \CodeIgniter\Database\BaseConnection();

    // Ladder: seeker(win,1) → growing(build,2) → worker(build,3) → sender(send,4).
    $db->rows['journey_stages'] = [
        ['id' => 's1', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'seeker',  'name' => 'Seeker',  'phase' => 'win',   'sort_order' => 1, 'is_entry' => 1, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 's2', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'sort_order' => 2, 'is_entry' => 0, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 's3', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'worker',  'name' => 'Worker',  'phase' => 'build', 'sort_order' => 3, 'is_entry' => 0, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 's4', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'sender',  'name' => 'Sender',  'phase' => 'send',  'sort_order' => 4, 'is_entry' => 0, 'is_terminal' => 1, 'status' => 'active'],
        // A group-scoped ladder for grpA (same codes) to prove isolation of reads.
        ['id' => 'g1', 'organization_id' => $ORG, 'group_id' => 'grpA', 'code' => 'seeker',  'name' => 'Seeker',  'phase' => 'win',   'sort_order' => 1, 'is_entry' => 1, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 'g2', 'organization_id' => $ORG, 'group_id' => 'grpA', 'code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'sort_order' => 2, 'is_entry' => 0, 'is_terminal' => 1, 'status' => 'active'],
    ];

    // Current org-wide distribution: seeker 10, growing 6, worker 3, sender 1.
    // (A paused seeker + a grpA journey must be EXCLUDED from the org-wide read.)
    $mj = static fn (string $id, string $stage, string $status = 'active', ?string $grp = null): array => [
        'id' => $id, 'organization_id' => $ORG, 'user_id' => 'u_' . $id, 'group_id' => $grp,
        'stage_code' => $stage, 'status' => $status,
    ];
    $journeys = [];
    for ($i = 0; $i < 10; $i++) { $journeys[] = $mj('sk' . $i, 'seeker'); }
    for ($i = 0; $i < 6; $i++)  { $journeys[] = $mj('gr' . $i, 'growing'); }
    for ($i = 0; $i < 3; $i++)  { $journeys[] = $mj('wk' . $i, 'worker'); }
    $journeys[] = $mj('sn0', 'sender');
    $journeys[] = $mj('pz0', 'seeker', 'paused');        // excluded (not active)
    $journeys[] = $mj('ga0', 'growing', 'active', 'grpA'); // excluded from org-wide
    $db->rows['member_journeys'] = $journeys;

    // Transition trail for momentum: arrivals into stages.
    // In-window (<=90d): 2 into growing (distinct users), 1 into worker, 1 regress (ignored).
    // Out-of-window (120d): 1 into growing (excluded by cutoff).
    $tr = static fn (string $u, string $to, string $dir, int $ageDays, ?string $grp = null): array => [
        'id' => 't_' . $u . '_' . $to, 'organization_id' => $ORG, 'user_id' => $u, 'group_id' => $grp,
        'to_stage' => $to, 'direction' => $dir, 'created_at' => $daysAgo($ageDays),
    ];
    $db->rows['member_journey_transitions'] = [
        $tr('gr0', 'growing', 'advance', 10),
        $tr('gr1', 'growing', 'advance', 40),
        $tr('gr0', 'growing', 'advance', 5),    // same user gr0 again -> movers distinct = 2, moves = 3
        $tr('wk0', 'worker', 'advance', 20),
        $tr('sk9', 'seeker', 'regress', 15),    // regress -> NOT counted as arrival
        $tr('grX', 'growing', 'advance', 120),  // out of 90d window -> excluded
        $tr('ga0', 'growing', 'advance', 3, 'grpA'), // group-scoped -> excluded org-wide
    ];

    $svc = new JourneyService($db, new Clock());

    // ── 1. Current-state funnel (monotonic reach) ────────────────────────────
    echo "current-state funnel\n";
    $res = $svc->funnel($ORG, null, 90);
    chk('ok result', $res->ok);
    $d = $res->data;
    chk('total_active excludes paused + other-group (=20)', $d['total_active'] === 20, (string) $d['total_active']);
    $byCode = [];
    foreach ($d['stages'] as $s) { $byCode[$s['code']] = $s; }
    chk('4 ladder stages', count($d['stages']) === 4);
    // at_or_beyond: seeker 20, growing 10, worker 4, sender 1
    chk('seeker at_or_beyond = 20', $byCode['seeker']['at_or_beyond'] === 20, (string) $byCode['seeker']['at_or_beyond']);
    chk('growing at_or_beyond = 10', $byCode['growing']['at_or_beyond'] === 10, (string) $byCode['growing']['at_or_beyond']);
    chk('worker at_or_beyond = 4', $byCode['worker']['at_or_beyond'] === 4, (string) $byCode['worker']['at_or_beyond']);
    chk('sender at_or_beyond = 1', $byCode['sender']['at_or_beyond'] === 1, (string) $byCode['sender']['at_or_beyond']);
    // current (stall) counts
    chk('seeker current = 10', $byCode['seeker']['current'] === 10);
    chk('growing current = 6', $byCode['growing']['current'] === 6);
    chk('worker current = 3', $byCode['worker']['current'] === 3);
    chk('sender current = 1', $byCode['sender']['current'] === 1);
    // monotonic non-increasing reach
    $prev = PHP_INT_MAX; $mono = true;
    foreach ($d['stages'] as $s) { if ($s['at_or_beyond'] > $prev) { $mono = false; } $prev = $s['at_or_beyond']; }
    chk('reach is monotonic non-increasing', $mono);

    // ── 2. Percentages ───────────────────────────────────────────────────────
    echo "percentages\n";
    chk('seeker reach_pct = 100', near((float) $byCode['seeker']['reach_pct'], 100.0), (string) $byCode['seeker']['reach_pct']);
    chk('growing reach_pct = 50', near((float) $byCode['growing']['reach_pct'], 50.0), (string) $byCode['growing']['reach_pct']);
    // conversion: seeker -> growing = 10/20 = 50%
    chk('seeker conversion_pct = 50', near((float) $byCode['seeker']['conversion_pct'], 50.0), (string) $byCode['seeker']['conversion_pct']);
    // growing -> worker = 4/10 = 40%
    chk('growing conversion_pct = 40', near((float) $byCode['growing']['conversion_pct'], 40.0), (string) $byCode['growing']['conversion_pct']);
    // worker -> sender = 1/4 = 25%
    chk('worker conversion_pct = 25', near((float) $byCode['worker']['conversion_pct'], 25.0), (string) $byCode['worker']['conversion_pct']);
    chk('sender is_last true', $byCode['sender']['is_last'] === true);
    chk('sender conversion_pct = 0 (terminal)', near((float) $byCode['sender']['conversion_pct'], 0.0));
    // stall: seeker current/reach = 10/20 = 50%
    chk('seeker stall_pct = 50', near((float) $byCode['seeker']['stall_pct'], 50.0), (string) $byCode['seeker']['stall_pct']);
    // growing stall = 6/10 = 60%
    chk('growing stall_pct = 60', near((float) $byCode['growing']['stall_pct'], 60.0), (string) $byCode['growing']['stall_pct']);

    // ── 3. Recent momentum (from the trail) ──────────────────────────────────
    echo "recent momentum\n";
    chk('growing moves_in = 3 (in-window arrivals)', $byCode['growing']['moves_in'] === 3, (string) $byCode['growing']['moves_in']);
    chk('growing movers_in = 2 (distinct users)', $byCode['growing']['movers_in'] === 2, (string) $byCode['growing']['movers_in']);
    chk('worker moves_in = 1', $byCode['worker']['moves_in'] === 1);
    chk('seeker moves_in = 0 (only a regress, excluded)', $byCode['seeker']['moves_in'] === 0, (string) $byCode['seeker']['moves_in']);
    chk('moves_in_window total = 4', $d['moves_in_window'] === 4, (string) $d['moves_in_window']);

    // ── 4. Group-context isolation + window clamp ────────────────────────────
    echo "group isolation + clamp\n";
    $g = $svc->funnel($ORG, 'grpA', 90)->data;
    // grpA ladder: seeker, growing(terminal). Only the ga0 growing journey is in grpA.
    chk('grpA total_active = 1', $g['total_active'] === 1, (string) $g['total_active']);
    $gByCode = [];
    foreach ($g['stages'] as $s) { $gByCode[$s['code']] = $s; }
    chk('grpA growing current = 1', ($gByCode['growing']['current'] ?? -1) === 1);
    chk('grpA growing moves_in = 1 (group-scoped arrival)', ($gByCode['growing']['moves_in'] ?? -1) === 1);
    chk('grpA seeker moves_in = 0 (org-wide arrivals excluded)', ($gByCode['seeker']['moves_in'] ?? -1) === 0);
    $clamped = $svc->funnel($ORG, null, 99999)->data;
    chk('window clamped to <= 3650', $clamped['window_days'] === 3650, (string) $clamped['window_days']);
    $clampLo = $svc->funnel($ORG, null, 0)->data;
    chk('window clamped to >= 1', $clampLo['window_days'] === 1, (string) $clampLo['window_days']);

    // ── 5. Empty-context safety ──────────────────────────────────────────────
    echo "empty safety\n";
    $emptyDb = new \CodeIgniter\Database\BaseConnection();
    $emptyDb->rows['journey_stages']              = [];
    $emptyDb->rows['member_journeys']             = [];
    $emptyDb->rows['member_journey_transitions']  = [];
    $es = (new JourneyService($emptyDb, new Clock()))->funnel($ORG, null, 90)->data;
    chk('no ladder => empty stages + zero totals', $es['stages'] === [] && $es['total_active'] === 0);

    // ── 6. Controller + route + view + i18n + menu wiring ────────────────────
    echo "controller / route / view / i18n / menu wiring\n";
    $ctrl = (string) file_get_contents($root . '/app/Modules/Journey/Controllers/JourneyController.php');
    chk('controller has funnel() action', str_contains($ctrl, 'function funnel') && str_contains($ctrl, '->funnel('));
    chk('controller renders funnel view', str_contains($ctrl, 'WBS\\Journey\\Views\\funnel'));
    chk('controller passes window through', str_contains($ctrl, "field('window'"));

    $routes = (string) file_get_contents($root . '/app/Config/Routes.php');
    chk('GET journey funnel route present', (bool) preg_match('#\$routes->get\(\'funnel\',[^\n]*JourneyController::funnel\'\)#', $routes));
    // it must be a plain read (no webcsrf/authorize filter on this line)
    if (preg_match('#\$routes->get\(\'funnel\',[^\n]*JourneyController::funnel\'[^\n]*#', $routes, $m)) {
        chk('funnel read has no write filter', ! str_contains($m[0], 'webcsrf') && ! str_contains($m[0], 'authorize:'));
    } else {
        chk('funnel route matched', false);
    }

    $view = (string) file_get_contents($root . '/app/Modules/Journey/Views/funnel.php');
    chk('view self-contained (own <html> + _locale)', str_contains($view, '_locale.php') && str_contains($view, '<html'));
    $noC = (string) preg_replace('#/\*.*?\*/#s', '', $view);
    chk('view CSP-clean: no <script>', ! str_contains($noC, '<script'));
    chk('view CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));
    chk('view has no <form> (pure read)', ! str_contains($noC, '<form'));

    $en = require $root . '/app/Modules/Journey/Language/en/Journey.php';
    $enF = $en['funnel'] ?? [];
    chk('en funnel block present (>= 20 keys)', count($enF) >= 20, (string) count($enF));
    chk('en has funnelLink top-level key', isset($en['funnelLink']) && $en['funnelLink'] !== '');
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $l  = require $root . "/app/Modules/Journey/Language/$loc/Journey.php";
        $lf = $l['funnel'] ?? [];
        chk("$loc mirrors funnel keys", array_diff(array_keys($enF), array_keys($lf)) === [],
            'missing: ' . implode(',', array_diff(array_keys($enF), array_keys($lf))));
        chk("$loc has funnelLink", isset($l['funnelLink']) && $l['funnelLink'] !== '');
    }

    $menu = (string) file_get_contents($root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php');
    chk('menu links journey/funnel', str_contains($menu, "'journey/funnel'"));
    $pipe = (string) file_get_contents($root . '/app/Modules/Journey/Views/pipeline.php');
    chk('pipeline view links to the funnel report', str_contains($pipe, '/journey/funnel'));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
