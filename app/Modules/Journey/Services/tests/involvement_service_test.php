<?php

declare(strict_types=1);

/**
 * InvolvementService unit test — the involvement-based journey triage engine
 * (see docs/TODO_INVOLVEMENT_BASED_TRIAGE.md). Pins:
 *
 *   - the ORDERED RULE BANDS (classify()): each branch — no-activity cold,
 *     low-participation cold, fruitful-discipler kept off cold by quantum, hot by
 *     high participation, hot by high quantum, warm otherwise, and "recent
 *     activity" gating hot;
 *   - COMPOSITION: participation_bps = activities/target capped at 100%; own
 *     quantum = configurable weighted sum of points + sponsorships + giving
 *     (major units); downline quantum = configurable share of disciples' own
 *     quantum; effective quantum = own + downline;
 *   - CONFIG is hierarchical + configurable (window clamped to >= 30 days;
 *     thresholds and weights merged over defaults) via the ConfigResolverPort;
 *   - the enabled gate defaults OFF and honours truthy config;
 *   - refreshForMember() UPSERTS one snapshot row per (org,user,group) with the
 *     figures + band, and the read side (bandCountsByStage / snapshotsAtStage)
 *     returns them;
 *   - onTransition() refreshes the moved member (listener contract, never throws);
 *   - point computation is arithmetic (no eval).
 *
 *   php app/Modules/Journey/Services/tests/involvement_service_test.php
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
    class QB
    {
        private array $wheres = [];
        private array $order  = [];
        private ?string $group = null;
        private ?string $sumCol = null;
        private ?string $sumAs  = null;
        private ?int $limit = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function base(): string
        {
            return explode(' ', trim($this->t))[0];
        }

        private function key(string $k): string
        {
            $k = trim($k);
            $k = preg_replace('/\s*(>=|<=|<>|!=|>|<)\s*$/', '', $k) ?? $k;
            $p = explode('.', trim($k));

            return end($p);
        }

        public function select($s)
        {
            // Support selectSum-style "MAX(col) AS m" is handled separately.
            return $this;
        }

        public function selectSum($col, $as)
        {
            $this->sumCol = (string) $col;
            $this->sumAs  = (string) $as;

            return $this;
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

        public function limit($n, $o = null)
        {
            $this->limit = (int) $n;

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
                        if ($w['val'] === null) {
                            if ($actual !== null) { return false; }
                        } elseif ((string) $actual !== (string) $w['val']) {
                            return false;
                        }
                }
            }

            return true;
        }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get(): RS
        {
            $rows = $this->filtered();
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC' ? (($b[$c] ?? '') <=> ($a[$c] ?? '')) : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }
            if ($this->sumCol !== null) {
                $sum = 0;
                foreach ($rows as $r) { $sum += (int) ($r[$this->sumCol] ?? 0); }

                return new RS([[$this->sumAs => $sum]]);
            }
            if ($this->group !== null) {
                $cols    = array_map('trim', explode(',', $this->group));
                $buckets = [];
                foreach ($rows as $r) {
                    $gk = implode('|', array_map(static fn ($c) => (string) ($r[$c] ?? ''), $cols));
                    if (! isset($buckets[$gk])) {
                        $seed = ['total' => 0];
                        foreach ($cols as $c) { $seed[$c] = $r[$c] ?? null; }
                        $buckets[$gk] = $seed;
                    }
                    $buckets[$gk]['total']++;
                }

                return new RS(array_values($buckets));
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        public function insert(array $row): void
        {
            $this->db->rows[$this->base()][] = $row;
        }

        public function update(array $set): void
        {
            $base = $this->base();
            foreach (($this->db->rows[$base] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$base][$i] = array_merge($r, $set);
                }
            }
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
    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\ConfigResolverPort;
    use WBS\Journey\Services\InvolvementService;
    use WBS\Journey\Services\InvolvementSourcePort;

    // Explicit requires (no composer autoload in the standalone harness).
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementTriagePort.php';
    require_once $root . '/app/Modules/Journey/Services/ConfigResolverPort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementSourcePort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementService.php';

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    // A frozen clock (deterministic day-band boundaries).
    \WBS\Shared\Support\Clock::freeze(new \DateTimeImmutable('2026-09-14 12:00:00', new \DateTimeZone('UTC')));
    $clock = new \WBS\Shared\Support\Clock();

    // In-memory config port.
    $config = new class implements ConfigResolverPort {
        /** @var array<string,mixed> */
        public array $store = [];
        public function value(string $groupId, string $capability): mixed
        {
            return $this->store[$groupId . '|' . $capability] ?? null;
        }
    };

    // In-memory source port (per-user metrics).
    $sources = new class implements InvolvementSourcePort {
        /** @var array<string,array<string,mixed>> */
        public array $byUser = [];
        public function metricsFor(string $organizationId, string $userId, ?string $groupId, string $sinceUtc): array
        {
            return $this->byUser[$userId] ?? [
                'last_activity_at' => null, 'activity_count' => 0, 'sponsorship_count' => 0,
                'giving_minor' => 0, 'points' => 0, 'downline_own_quantum' => 0,
            ];
        }
    };

    $newSvc = static function (BaseConnection $db) use ($clock, $config, $sources): InvolvementService {
        return new InvolvementService($db, $clock, null, $config, $sources);
    };

    // ── 1. Pure band rules (classify) ────────────────────────────────────────
    echo "band rules (classify)\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $T   = [
        'cold_floor_bps' => 2500, 'cold_quantum' => 100,
        'hot_participation_bps' => 7500, 'hot_quantum' => 500, 'recent_activity_days' => 30,
    ];
    $now = '2026-09-14 12:00:00';
    $recent = '2026-09-10 00:00:00';   // 4 days ago -> recent
    $stale  = '2026-06-01 00:00:00';   // ~105 days ago -> not recent

    chk('no activity + low quantum => cold', $svc->classify(['activity_count' => 0, 'activity_target' => 4, 'participation_bps' => 0, 'last_activity_at' => null, 'quantum' => 0], $T, $now) === 'cold');
    chk('low participation + low quantum => cold', $svc->classify(['activity_count' => 1, 'activity_target' => 10, 'participation_bps' => 1000, 'last_activity_at' => $recent, 'quantum' => 50], $T, $now) === 'cold');
    chk('fruitful discipler (high quantum) kept OFF cold despite no activity', $svc->classify(['activity_count' => 0, 'activity_target' => 4, 'participation_bps' => 0, 'last_activity_at' => $stale, 'quantum' => 900], $T, $now) !== 'cold');
    chk('recent + high participation => hot', $svc->classify(['activity_count' => 4, 'activity_target' => 4, 'participation_bps' => 10000, 'last_activity_at' => $recent, 'quantum' => 10], $T, $now) === 'hot');
    chk('recent + high quantum => hot', $svc->classify(['activity_count' => 1, 'activity_target' => 4, 'participation_bps' => 2600, 'last_activity_at' => $recent, 'quantum' => 800], $T, $now) === 'hot');
    chk('high participation but STALE activity => not hot (warm)', $svc->classify(['activity_count' => 4, 'activity_target' => 4, 'participation_bps' => 10000, 'last_activity_at' => $stale, 'quantum' => 10], $T, $now) === 'warm');
    chk('middle => warm', $svc->classify(['activity_count' => 2, 'activity_target' => 4, 'participation_bps' => 5000, 'last_activity_at' => $recent, 'quantum' => 120], $T, $now) === 'warm');

    // ── 2. Composition + config-driven weights/target ────────────────────────
    echo "composition + configurable weights\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $config->store = [];
    $sources->byUser = [
        'u1' => ['last_activity_at' => $recent, 'activity_count' => 3, 'sponsorship_count' => 2, 'giving_minor' => 50000, 'points' => 40, 'downline_own_quantum' => 400],
    ];
    // No config => defaults: target 4, weights points1/spon50/give1, downline 25%.
    $r = $svc->refreshForMember('org', 'u1', null);
    $d = $r->data;
    // participation = 3/4 = 7500 bps
    chk('participation_bps = 7500 (3/4)', (int) $d['participation_bps'] === 7500, (string) $d['participation_bps']);
    // own quantum = 40*1 + 2*50 + (50000/100)*1 = 40 + 100 + 500 = 640
    chk('own_quantum = 640', (int) $d['own_quantum'] === 640, (string) $d['own_quantum']);
    // downline = 25% of 400 = 100
    chk('downline_quantum = 100 (25% of 400)', (int) $d['downline_quantum'] === 100, (string) $d['downline_quantum']);
    chk('quantum = own + downline = 740', (int) $d['quantum'] === 740, (string) $d['quantum']);
    // recent + quantum>=500 => hot
    chk('band = hot (recent + quantum>=hot_quantum)', $d['band'] === 'hot', (string) $d['band']);

    // Now override weights + target via config on the org-root group.
    $db->rows['groups'] = [['id' => 'root', 'organization_id' => 'org2', 'status' => 'active', 'depth' => 1, 'created_at' => '2026-01-01 00:00:00']];
    $config->store = [
        'root|journey.involvement.activity_target' => 6,
        'root|journey.involvement.quantum_weights' => ['points' => 2, 'per_sponsorship' => 10, 'per_giving_major' => 0, 'downline_share_pct' => 50],
    ];
    $sources->byUser['u2'] = ['last_activity_at' => $recent, 'activity_count' => 3, 'sponsorship_count' => 2, 'giving_minor' => 50000, 'points' => 40, 'downline_own_quantum' => 400];
    $r2 = $svc->refreshForMember('org2', 'u2', null);
    $d2 = $r2->data;
    chk('config target 6 => participation 5000 (3/6)', (int) $d2['participation_bps'] === 5000, (string) $d2['participation_bps']);
    // own = 40*2 + 2*10 + 0 = 100 ; downline = 50% of 400 = 200 ; quantum 300
    chk('config weights => own_quantum 100', (int) $d2['own_quantum'] === 100, (string) $d2['own_quantum']);
    chk('config weights => downline 200 (50%)', (int) $d2['downline_quantum'] === 200, (string) $d2['downline_quantum']);
    chk('config weights => quantum 300', (int) $d2['quantum'] === 300, (string) $d2['quantum']);

    // ── 3. Window clamp + hierarchical resolution ────────────────────────────
    echo "config window clamp\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $db->rows['groups'] = [['id' => 'root', 'organization_id' => 'orgw', 'status' => 'active', 'depth' => 1, 'created_at' => '2026-01-01 00:00:00']];
    $config->store = ['root|journey.involvement.window_days' => 5]; // below min
    $cfg = $svc->effectiveConfig('orgw', null);
    chk('window clamped up to min 30', $cfg['window_days'] === 30, (string) $cfg['window_days']);
    $config->store = ['root|journey.involvement.window_days' => 9999]; // above max
    chk('window clamped down to max 365', $svc->effectiveConfig('orgw', null)['window_days'] === 365);
    $config->store = ['root|journey.involvement.window_days' => 60];
    chk('window honoured when in range', $svc->effectiveConfig('orgw', null)['window_days'] === 60);

    // ── 4. enabled gate defaults OFF, honours truthy ─────────────────────────
    echo "enabled gate\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $db->rows['groups'] = [['id' => 'g1', 'organization_id' => 'orge', 'status' => 'active', 'depth' => 1, 'created_at' => '2026-01-01 00:00:00']];
    $config->store = [];
    chk('disabled by default', $svc->isEnabled('orge', 'g1') === false);
    $config->store = ['g1|journey.involvement.enabled' => true];
    chk('enabled when config true', $svc->isEnabled('orge', 'g1') === true);
    $config->store = ['g1|journey.involvement.enabled' => 'on'];
    chk('enabled when config "on"', $svc->isEnabled('orge', 'g1') === true);
    $config->store = ['g1|journey.involvement.enabled' => 0];
    chk('disabled when config 0', $svc->isEnabled('orge', 'g1') === false);

    // ── 5. Upsert + read side ────────────────────────────────────────────────
    echo "upsert + read side\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $config->store = [];
    $sources->byUser = [
        'a' => ['last_activity_at' => $recent, 'activity_count' => 4, 'sponsorship_count' => 3, 'giving_minor' => 100000, 'points' => 60, 'downline_own_quantum' => 0], // hot
        'b' => ['last_activity_at' => $stale, 'activity_count' => 0, 'sponsorship_count' => 0, 'giving_minor' => 0, 'points' => 0, 'downline_own_quantum' => 0],       // cold
    ];
    $svc->refreshForMember('org', 'a', null, ['stage_code' => 'new_believer', 'stage_phase' => 'win']);
    $svc->refreshForMember('org', 'b', null, ['stage_code' => 'new_believer', 'stage_phase' => 'win']);
    chk('one snapshot row per member', count($db->rows['member_involvement_snapshots']) === 2);

    // Re-refresh a => UPDATE not duplicate INSERT.
    $svc->refreshForMember('org', 'a', null, ['stage_code' => 'new_believer', 'stage_phase' => 'win']);
    chk('re-refresh upserts (no duplicate)', count($db->rows['member_involvement_snapshots']) === 2);

    $counts = $svc->bandCountsByStage('org', null);
    chk('bandCountsByStage groups by stage+band', isset($counts['new_believer']));
    chk('  hot=1 cold=1 at new_believer', ($counts['new_believer']['hot'] ?? 0) === 1 && ($counts['new_believer']['cold'] ?? 0) === 1, json_encode($counts['new_believer'] ?? []));

    $snaps = $svc->snapshotsAtStage('org', 'new_believer', null);
    chk('snapshotsAtStage keyed by user_id', isset($snaps['a'], $snaps['b']));
    chk('  snapshot carries quantum figures', array_key_exists('quantum', $snaps['a']) && array_key_exists('sponsorship_count', $snaps['a']));

    // ── 6. onTransition refreshes + never throws ─────────────────────────────
    echo "listener contract\n";
    $db  = new BaseConnection();
    $svc = $newSvc($db);
    $sources->byUser = ['mover' => ['last_activity_at' => $recent, 'activity_count' => 2, 'sponsorship_count' => 0, 'giving_minor' => 0, 'points' => 10, 'downline_own_quantum' => 0]];
    $svc->onTransition(['organization_id' => 'org', 'user_id' => 'mover', 'group_id' => null, 'to_stage' => 'growing', 'to_phase' => 'build']);
    chk('onTransition wrote a snapshot for the mover', ($db->rows['member_involvement_snapshots'][0]['user_id'] ?? null) === 'mover');
    chk('  snapshot stage from event', ($db->rows['member_involvement_snapshots'][0]['stage_code'] ?? null) === 'growing');
    // must not throw on a garbage event
    $threw = false;
    try { $svc->onTransition(['nonsense' => 1]); } catch (\Throwable) { $threw = true; }
    chk('onTransition never throws on bad input', $threw === false);

    // ── 7. no eval in the codebase ───────────────────────────────────────────
    echo "safety\n";
    $srcTxt = (string) file_get_contents($root . '/app/Modules/Journey/Services/InvolvementService.php');
    // Strip comments so the doc-comment phrase "no eval()" doesn't false-positive.
    $srcCode = (string) preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $srcTxt);
    chk('no eval() call in InvolvementService', ! preg_match('/\beval\s*\(/', $srcCode));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
