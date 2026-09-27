<?php

declare(strict_types=1);

/**
 * JourneyService × involvement integration test — proves the pipeline board and
 * per-stage roster switch their HOT/WARM/COLD basis from time-in-stage to
 * INVOLVEMENT when (and only when) an InvolvementService is wired AND switched on
 * for the context, reading the pre-classified bands + quantum figures from the
 * materialized snapshot (no per-member fan-out on the read path). When the
 * feature is off (or no service wired), the legacy time-in-stage triage is used
 * unchanged (covered by journey_pipeline_triage_test / journey_members_at_stage_test).
 *
 * Uses a tiny in-memory query-builder fake + a stub InvolvementService so the
 * JourneyService read branches are exercised deterministically.
 *
 *   php app/Modules/Journey/Services/tests/journey_involvement_triage_test.php
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
        public function transStatus(): bool { return true; }
    }
}

namespace Fake {
    class QB
    {
        private array $wheres = [];
        private array $order  = [];
        private ?string $group = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function base(): string { return explode(' ', trim($this->t))[0]; }

        private function key(string $k): string
        {
            $k = trim($k);
            $k = preg_replace('/\s*(>=|<=|<>|!=|>|<)\s*$/', '', $k) ?? $k;
            $p = explode('.', trim($k));

            return end($p);
        }

        public function select($s) { return $this; }

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

        public function groupBy($g) { $this->group = (string) $g; return $this; }

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
                    case 'in': if (! in_array($actual, $w['val'], true)) { return false; } break;
                    case 'ge': if (! ($actual !== null && (string) $actual >= (string) $w['val'])) { return false; } break;
                    case 'gt': if (! ($actual !== null && (string) $actual > (string) $w['val'])) { return false; } break;
                    case 'le': if (! ($actual !== null && (string) $actual <= (string) $w['val'])) { return false; } break;
                    case 'lt': if (! ($actual !== null && (string) $actual < (string) $w['val'])) { return false; } break;
                    case 'ne': if ($actual === $w['val']) { return false; } break;
                    default:
                        if ($w['val'] === null) { if ($actual !== null) { return false; } }
                        elseif ((string) $actual !== (string) $w['val']) { return false; }
                }
            }

            return true;
        }

        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC' ? (($b[$c] ?? '') <=> ($a[$c] ?? '')) : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }
            if ($this->group !== null) {
                $buckets = [];
                foreach ($rows as $r) {
                    $gk = $r['stage_code'] ?? '';
                    $buckets[$gk] ??= ['stage_code' => $r['stage_code'] ?? null, 'stage_phase' => $r['stage_phase'] ?? null, 'total' => 0];
                    $buckets[$gk]['total']++;
                }

                return new RS(array_values($buckets));
            }

            return new RS($rows);
        }
    }

    class RS
    {
        public function __construct(private array $r) {}
        public function getResultArray(): array { return $this->r; }
        public function getRowArray(): ?array { return $this->r[0] ?? null; }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementTriagePort.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';

    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\InvolvementTriagePort;
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

    /**
     * A stub InvolvementTriagePort — the narrow read contract JourneyService
     * depends on — lets us pin the branch behaviour without config/sources.
     */
    $makeInv = static function (bool $enabled, array $bandCounts, array $snaps): InvolvementTriagePort {
        return new class($enabled, $bandCounts, $snaps) implements InvolvementTriagePort {
            public function __construct(private bool $en, private array $bc, private array $sn) {}
            public function isEnabled(string $organizationId, ?string $groupId): bool { return $this->en; }
            public function bandCountsByStage(string $organizationId, ?string $groupId): array { return $this->bc; }
            public function snapshotsAtStage(string $organizationId, string $stageCode, ?string $groupId): array { return $this->sn; }
        };
    };

    // Shared fixtures: a 2-stage ladder, 5 active journeys at 'seeker'.
    $seed = static function (BaseConnection $db): void {
        $db->rows['journey_stages'] = [
            ['id' => 's1', 'organization_id' => 'org', 'group_id' => null, 'code' => 'seeker', 'name' => 'Seeker', 'phase' => 'win', 'sort_order' => 1, 'status' => 'active'],
            ['id' => 's2', 'organization_id' => 'org', 'group_id' => null, 'code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'sort_order' => 2, 'status' => 'active'],
        ];
        // 5 members at seeker, all entered long ago (=> legacy would call them cold).
        $db->rows['member_journeys'] = [];
        foreach (['u1', 'u2', 'u3', 'u4', 'u5'] as $u) {
            $db->rows['member_journeys'][] = [
                'id' => 'j_' . $u, 'organization_id' => 'org', 'user_id' => $u, 'group_id' => null,
                'stage_code' => 'seeker', 'stage_phase' => 'win', 'stage_entered_at' => '2026-01-01 00:00:00', 'status' => 'active',
            ];
        }
        $db->rows['users'] = array_map(static fn ($u) => ['id' => $u, 'organization_id' => 'org', 'display_name' => strtoupper($u)], ['u1', 'u2', 'u3', 'u4', 'u5']);
    };

    // ── ladder() reads journey_stages; JourneyService needs it. Confirm shape. ──
    // ── 1. Involvement ENABLED: pipeline uses snapshot bands, not time-in-stage ─
    echo "pipeline: involvement mode\n";
    $db = new BaseConnection();
    $seed($db);
    // Snapshot says 3 hot, 1 warm, 1 cold at seeker (contradicting time-in-stage).
    $inv = $makeInv(true, ['seeker' => ['hot' => 3, 'warm' => 1, 'cold' => 1]], []);
    $svc = new JourneyService($db, new Clock(), null, [], $inv);
    $res = $svc->pipeline('org', null);
    $d   = $res->data;
    chk('pipeline ok', $res->ok);
    chk('triage_mode = involvement', ($d['triage_mode'] ?? '') === 'involvement', (string) ($d['triage_mode'] ?? ''));
    $seeker = null;
    foreach ($d['stages'] as $s) { if ($s['code'] === 'seeker') { $seeker = $s; } }
    chk('seeker count still 5', $seeker !== null && (int) $seeker['count'] === 5);
    chk('seeker hot=3 (from snapshot, NOT time-in-stage)', (int) $seeker['hot'] === 3, (string) $seeker['hot']);
    chk('seeker warm=1', (int) $seeker['warm'] === 1, (string) $seeker['warm']);
    chk('seeker cold=1 (by subtraction)', (int) $seeker['cold'] === 1, (string) $seeker['cold']);
    chk('board totals hot=3', (int) $d['triage']['hot'] === 3);

    // ── 2. Involvement DISABLED: falls back to time-in-stage (all cold here) ────
    echo "pipeline: disabled => legacy time-in-stage\n";
    $db = new BaseConnection();
    $seed($db);
    $invOff = $makeInv(false, ['seeker' => ['hot' => 3, 'warm' => 1, 'cold' => 1]], []);
    $svc2 = new JourneyService($db, new Clock(), null, [], $invOff);
    $d2   = $svc2->pipeline('org', null)->data;
    chk('triage_mode = time_in_stage when disabled', ($d2['triage_mode'] ?? '') === 'time_in_stage');
    $seeker2 = null;
    foreach ($d2['stages'] as $s) { if ($s['code'] === 'seeker') { $seeker2 = $s; } }
    // All entered 2026-01-01 which is > warm window from "now" => all cold.
    chk('legacy: seeker cold=5 (old entries), snapshot IGNORED', (int) $seeker2['cold'] === 5, (string) $seeker2['cold']);
    chk('legacy: seeker hot=0', (int) $seeker2['hot'] === 0);

    // ── 3. No InvolvementService wired at all => legacy (backward compat) ───────
    echo "pipeline: no service => legacy\n";
    $db = new BaseConnection();
    $seed($db);
    $svc3 = new JourneyService($db, new Clock());
    $d3   = $svc3->pipeline('org', null)->data;
    chk('triage_mode = time_in_stage with no service', ($d3['triage_mode'] ?? '') === 'time_in_stage');

    // ── 4. Roster (membersAtStage) in involvement mode: band + quantum + order ─
    echo "roster: involvement mode\n";
    $db = new BaseConnection();
    $seed($db);
    $snaps = [
        'u1' => ['user_id' => 'u1', 'band' => 'hot',  'participation_bps' => 10000, 'activity_count' => 4, 'activity_target' => 4, 'last_activity_at' => '2026-09-10 00:00:00', 'sponsorship_count' => 3, 'giving_minor' => 100000, 'points' => 90, 'quantum' => 900, 'downline_quantum' => 0],
        'u2' => ['user_id' => 'u2', 'band' => 'cold', 'participation_bps' => 0,     'activity_count' => 0, 'activity_target' => 4, 'last_activity_at' => null,                  'sponsorship_count' => 0, 'giving_minor' => 0,      'points' => 0,  'quantum' => 0,   'downline_quantum' => 0],
        'u3' => ['user_id' => 'u3', 'band' => 'warm', 'participation_bps' => 5000,  'activity_count' => 2, 'activity_target' => 4, 'last_activity_at' => '2026-09-01 00:00:00', 'sponsorship_count' => 1, 'giving_minor' => 0,      'points' => 30, 'quantum' => 80,  'downline_quantum' => 0],
        // u4/u5 have NO snapshot -> treated as cold, quantum 0.
    ];
    $inv2 = $makeInv(true, [], $snaps);
    $svc4 = new JourneyService($db, new Clock(), null, [], $inv2);
    $rr   = $svc4->membersAtStage('org', 'seeker', null);
    $rd   = $rr->data;
    chk('roster triage_mode = involvement', ($rd['triage_mode'] ?? '') === 'involvement');
    chk('roster matched = 5', (int) $rd['matched'] === 5, (string) $rd['matched']);
    $members = $rd['members'];
    // least-involved first: u2/u4/u5 (quantum 0) ... then u3 (80) ... then u1 (900).
    chk('least-involved first (quantum asc): last is u1', ($members[count($members) - 1]['user_id'] ?? '') === 'u1');
    chk('most-needy (quantum 0) at top', (int) ($members[0]['involvement']['quantum'] ?? -1) === 0);
    // find u1 row, assert band + figures surfaced
    $u1 = null;
    foreach ($members as $m) { if ($m['user_id'] === 'u1') { $u1 = $m; } }
    chk('u1 band = hot from snapshot', ($u1['temperature'] ?? '') === 'hot');
    chk('u1 involvement figures surfaced', (int) ($u1['involvement']['sponsorship_count'] ?? 0) === 3 && (int) ($u1['involvement']['quantum'] ?? 0) === 900);
    // member without a snapshot => cold, involvement null
    $u4 = null;
    foreach ($members as $m) { if ($m['user_id'] === 'u4') { $u4 = $m; } }
    chk('u4 (no snapshot) => cold', ($u4['temperature'] ?? '') === 'cold');
    chk('u4 involvement null', array_key_exists('involvement', $u4) && $u4['involvement'] === null);

    // ── 5. Roster band FILTER in involvement mode ──────────────────────────────
    echo "roster: band filter\n";
    $db = new BaseConnection();
    $seed($db);
    $svc5 = new JourneyService($db, new Clock(), null, [], $makeInv(true, [], $snaps));
    $hotOnly = $svc5->membersAtStage('org', 'seeker', null, 200, 0, 'hot')->data;
    chk('filter hot => only u1', (int) $hotOnly['matched'] === 1 && ($hotOnly['members'][0]['user_id'] ?? '') === 'u1');
    $coldOnly = $svc5->membersAtStage('org', 'seeker', null, 200, 0, 'cold')->data;
    // u2 + u4 + u5 (no snapshot) = 3 cold
    chk('filter cold => u2,u4,u5 (3)', (int) $coldOnly['matched'] === 3, (string) $coldOnly['matched']);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
