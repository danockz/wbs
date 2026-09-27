<?php

declare(strict_types=1);

/**
 * JourneyService::pipeline TRIAGE unit test — pins the birds-eye downline board
 * that splits each stage's active members into HOT / WARM / COLD by how long
 * they have sat in their CURRENT stage (member_journeys.stage_entered_at):
 *
 *   - recent arrivals (≤ TRIAGE_HOT_DAYS) are hot (momentum);
 *   - the middle band (≤ TRIAGE_WARM_DAYS) is warm;
 *   - anyone stalled beyond that is cold and most needs a follow-up;
 *   - per-stage hot+warm+cold always sum to that stage's count, and the board
 *     totals (triage.hot/warm/cold) sum to the grand total;
 *   - paused/archived journeys and other-context journeys are excluded, exactly
 *     as the plain headcount already was;
 *   - the ladder ordering and per-stage counts are unchanged (backward compat).
 *
 * The clock is FROZEN so the day-band boundaries are deterministic. Uses a tiny
 * in-memory fake of the CI4 query builder that understands the date-comparison
 * predicates the triage split needs (>= and <), plus groupBy COUNT.
 *
 *   php app/Modules/Journey/Services/tests/journey_pipeline_triage_test.php
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

        // Journey pipeline() issues no writes; these satisfy any incidental use.
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
            return $this;
        }

        public function groupBy($g)
        {
            $this->group = (string) $g;

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
                    default: // eq
                        if ($w['val'] === null) {
                            if ($actual !== null) { return false; }
                        } elseif ((string) $actual !== (string) $w['val']) {
                            return false;
                        }
                }
            }

            return true;
        }

        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
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

    // Freeze "now" so day-band boundaries are deterministic.
    $NOW = new DateTimeImmutable('2026-09-13 12:00:00', new DateTimeZone('UTC'));
    Clock::freeze($NOW);
    $daysAgo = static fn (int $d): string => $NOW->modify("-{$d} days")->format('Y-m-d H:i:s');

    $ORG = 'org1';
    $db  = new \CodeIgniter\Database\BaseConnection();

    // Ladder: seeker(win) → growing(build) → sender(send).
    $db->rows['journey_stages'] = [
        ['id' => 's1', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'seeker',  'name' => 'Seeker',  'phase' => 'win',   'sort_order' => 1, 'is_entry' => 1, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 's2', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'sort_order' => 2, 'is_entry' => 0, 'is_terminal' => 0, 'status' => 'active'],
        ['id' => 's3', 'organization_id' => $ORG, 'group_id' => null, 'code' => 'sender',  'name' => 'Sender',  'phase' => 'send',  'sort_order' => 3, 'is_entry' => 0, 'is_terminal' => 1, 'status' => 'active'],
    ];

    // Active org-wide journeys with varied stage-entry ages.
    // HOT threshold = 30 days, WARM threshold = 90 days.
    $mj = static fn (string $u, string $stage, string $phase, string $entered, string $status = 'active', ?string $grp = null): array => [
        'id' => 'j_' . $u, 'organization_id' => $ORG, 'user_id' => $u, 'group_id' => $grp,
        'stage_code' => $stage, 'stage_phase' => $phase, 'stage_entered_at' => $entered, 'status' => $status,
    ];
    $db->rows['member_journeys'] = [
        // seeker: 2 hot, 1 warm, 1 cold
        $mj('u1', 'seeker', 'win', $daysAgo(2)),    // hot
        $mj('u2', 'seeker', 'win', $daysAgo(29)),   // hot (boundary inside 30)
        $mj('u3', 'seeker', 'win', $daysAgo(45)),   // warm
        $mj('u4', 'seeker', 'win', $daysAgo(200)),  // cold
        // growing: 1 warm (exactly 90d boundary → warm), 2 cold
        $mj('u5', 'growing', 'build', $daysAgo(90)), // warm (>= warm cutoff, < hot cutoff)
        $mj('u6', 'growing', 'build', $daysAgo(120)),// cold
        $mj('u7', 'growing', 'build', $daysAgo(365)),// cold
        // sender: 1 hot
        $mj('u8', 'sender', 'send', $daysAgo(1)),    // hot
        // excluded — paused
        $mj('u9', 'seeker', 'win', $daysAgo(1), 'paused'),
        // excluded — a group-context journey (not org-wide primary)
        $mj('u10', 'growing', 'build', $daysAgo(1), 'active', 'g1'),
    ];

    $svc = new JourneyService($db, new Clock());
    $res = $svc->pipeline($ORG);
    $d   = $res->data;

    $byCode = [];
    foreach ($d['stages'] as $s) {
        $byCode[$s['code']] = $s;
    }

    // ---- backward-compatible totals & ordering ----
    chk('total counts only active org-wide (8)', $d['total'] === 8, (string) $d['total']);
    chk('ordered by ladder', array_keys($byCode) === ['seeker', 'growing', 'sender']);
    chk('seeker count = 4', ($byCode['seeker']['count'] ?? null) === 4);
    chk('growing count = 3', ($byCode['growing']['count'] ?? null) === 3);
    chk('sender count = 1', ($byCode['sender']['count'] ?? null) === 1);

    // ---- per-stage triage split ----
    chk('seeker hot = 2', ($byCode['seeker']['hot'] ?? null) === 2, (string) ($byCode['seeker']['hot'] ?? -1));
    chk('seeker warm = 1', ($byCode['seeker']['warm'] ?? null) === 1, (string) ($byCode['seeker']['warm'] ?? -1));
    chk('seeker cold = 1', ($byCode['seeker']['cold'] ?? null) === 1, (string) ($byCode['seeker']['cold'] ?? -1));
    chk('growing hot = 0', ($byCode['growing']['hot'] ?? null) === 0);
    chk('growing warm = 1 (90d boundary)', ($byCode['growing']['warm'] ?? null) === 1, (string) ($byCode['growing']['warm'] ?? -1));
    chk('growing cold = 2', ($byCode['growing']['cold'] ?? null) === 2);
    chk('sender hot = 1', ($byCode['sender']['hot'] ?? null) === 1);
    chk('sender warm = 0', ($byCode['sender']['warm'] ?? null) === 0);
    chk('sender cold = 0', ($byCode['sender']['cold'] ?? null) === 0);

    // ---- invariants: per-stage split sums to its count ----
    $sumOk = true;
    foreach ($d['stages'] as $s) {
        if (($s['hot'] + $s['warm'] + $s['cold']) !== $s['count']) {
            $sumOk = false;
        }
    }
    chk('every stage hot+warm+cold == count', $sumOk);

    // ---- board totals ----
    chk('triage.hot total = 3', $d['triage']['hot'] === 3, (string) $d['triage']['hot']);
    chk('triage.warm total = 2', $d['triage']['warm'] === 2, (string) $d['triage']['warm']);
    chk('triage.cold total = 3', $d['triage']['cold'] === 3, (string) $d['triage']['cold']);
    chk('board totals sum to grand total', ($d['triage']['hot'] + $d['triage']['warm'] + $d['triage']['cold']) === $d['total']);

    // ---- empty org (no members) still returns zeroed triage, no crash ----
    $db2 = new \CodeIgniter\Database\BaseConnection();
    $db2->rows['journey_stages']   = $db->rows['journey_stages'];
    $db2->rows['member_journeys']  = [];
    $svc2 = new JourneyService($db2, new Clock());
    $d2   = $svc2->pipeline($ORG)->data;
    chk('empty org total 0', $d2['total'] === 0);
    chk('empty org triage all zero', $d2['triage'] === ['hot' => 0, 'warm' => 0, 'cold' => 0]);
    chk('empty org stages still enumerate ladder', count($d2['stages']) === 3);

    Clock::freeze(null);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
