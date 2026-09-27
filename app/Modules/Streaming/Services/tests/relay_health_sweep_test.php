<?php

declare(strict_types=1);

/**
 * StreamRelayService::sweepStaleStreams() — ST4 relay/stream health sweep.
 *
 * Relay heartbeats are ingested but nothing acts on their ABSENCE: a stream
 * stuck `live` after its relay stops beating never transitions. This proves the
 * sweep:
 *   - auto-ends a `live` stream whose LAST heartbeat is older than the window;
 *   - auto-ends a `live` stream that NEVER beat but went live before the window;
 *   - leaves a `live` stream with a FRESH heartbeat alone;
 *   - leaves a stream that never beat AND has no started_at alone (can't judge);
 *   - opens exactly one monitor incident per ended stream (alert once) and sets
 *     relay_state=down;
 *   - is FEATURE-GATED, default OFF: with the flag off (or no gate) it ends 0
 *     and reports skipped_gate; a per-group override can enable one stream;
 *   - is IDEMPOTENT: a second pass ends 0 (stream is no longer live);
 *   - only touches non-live streams never; respects the org filter + limit.
 *
 * Tiny in-memory fake of the CI4 query builder (no DB/framework); notifications
 * are null (fireAlerts degrades safely) and the feature gate is a controllable
 * fake.
 *
 *   php app/Modules/Streaming/Services/tests/relay_health_sweep_test.php
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

    class QB
    {
        /** @var list<array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        /** @var array{k:string,vals:list<string>}|null */
        private ?array $in = null;
        private ?int $limit = null;
        private ?string $orderCol = null;
        private string $orderDir = 'ASC';

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s, $escape = true)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            [$key, $op] = $this->splitOp((string) $k);
            $this->conds[] = ['k' => $key, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in = ['k' => trim((string) $k), 'vals' => array_map('strval', $vals)];

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderCol = (string) $col;
            $this->orderDir = strtoupper((string) $dir) === 'DESC' ? 'DESC' : 'ASC';

            return $this;
        }

        public function limit($n)
        {
            $this->limit = (int) $n;

            return $this;
        }

        public function get(): RS
        {
            $rows = $this->matchingRows();
            if ($this->orderCol !== null) {
                usort($rows, function ($a, $b) {
                    $x = (string) ($a[$this->orderCol] ?? '');
                    $y = (string) ($b[$this->orderCol] ?? '');

                    return $this->orderDir === 'DESC' ? strcmp($y, $x) : strcmp($x, $y);
                });
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return new RS($rows);
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

        /** @return array{0:string,1:string} */
        private function splitOp(string $k): array
        {
            $k = trim($k);
            foreach (['>=', '<=', '!=', '>', '<'] as $op) {
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

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $left  = (string) ($r[$c['k']] ?? '');
                $right = (string) $c['v'];
                $ok = match ($c['op']) {
                    '='     => $left === $right,
                    '!='    => $left !== $right,
                    '>='    => $left >= $right,
                    '<='    => $left <= $right,
                    '>'     => $left > $right,
                    '<'     => $left < $right,
                    default => false,
                };
                if (! $ok) {
                    return false;
                }
            }
            if ($this->in !== null && ! in_array((string) ($r[$this->in['k']] ?? ''), $this->in['vals'], true)) {
                return false;
            }

            return true;
        }
    }
}

namespace Fake {
    require_once dirname(__DIR__, 5) . '/app/Modules/Streaming/Services/StreamFeatureGatePort.php';

    // Controllable feature gate: a map of "org|flag|group" => bool, with an
    // org-wide fallback "org|flag|".
    class Gate implements \WBS\Streaming\Services\StreamFeatureGatePort
    {
        /** @var array<string,bool> */
        public array $flags = [];

        public function enabled(string $organizationId, string $flagKey, ?string $groupId = null): bool
        {
            if ($groupId !== null && array_key_exists("$organizationId|$flagKey|$groupId", $this->flags)) {
                return $this->flags["$organizationId|$flagKey|$groupId"];
            }

            return $this->flags["$organizationId|$flagKey|"] ?? false;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use Fake\Gate;
    use WBS\Shared\Support\Clock;
    use WBS\Streaming\Services\StreamRelayService;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Streaming/Services/StreamFeatureGatePort.php';
    require_once $root . '/app/Modules/Streaming/Services/StreamRelayService.php';

    // Provide a log_message() shim if the framework helper isn't loaded.
    if (! function_exists('log_message')) {
        function log_message($level, $message)
        {
            return true;
        }
    }

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $FLAG = StreamRelayService::RELAY_SWEEP_FLAG;

    // Clock at a fixed "now". Heartbeats/started_at are strings compared lexically
    // (same Y-m-d H:i:s.u format the service uses), which is monotonic for ISO.
    $now = '2026-09-16 12:00:00.000000';
    $old = '2026-09-16 11:50:00.000000'; // 10 min ago (> 5 min window)
    $fresh = '2026-09-16 11:59:00.000000'; // 1 min ago

    $seed = static function () use ($ORG, $now, $old, $fresh): BaseConnection {
        $db = new BaseConnection();
        $db->rows['streams'] = [
            // stale: last beat 10 min ago -> should end
            ['id' => 's-stale', 'organization_id' => $ORG, 'group_id' => null, 'created_by' => 'u1', 'title' => 'Stale', 'status' => 'live', 'relay_state' => 'healthy', 'started_at' => $old],
            // never beat, went live 10 min ago -> should end (uses started_at)
            ['id' => 's-nobeat', 'organization_id' => $ORG, 'group_id' => null, 'created_by' => 'u1', 'title' => 'NoBeat', 'status' => 'live', 'relay_state' => 'healthy', 'started_at' => $old],
            // fresh beat -> keep
            ['id' => 's-fresh', 'organization_id' => $ORG, 'group_id' => null, 'created_by' => 'u1', 'title' => 'Fresh', 'status' => 'live', 'relay_state' => 'healthy', 'started_at' => $old],
            // never beat, no started_at -> can't judge, keep
            ['id' => 's-unknown', 'organization_id' => $ORG, 'group_id' => null, 'created_by' => 'u1', 'title' => 'Unknown', 'status' => 'live', 'relay_state' => 'healthy', 'started_at' => null],
            // not live -> never touched
            ['id' => 's-ended', 'organization_id' => $ORG, 'group_id' => null, 'created_by' => 'u1', 'title' => 'Ended', 'status' => 'ended', 'relay_state' => 'healthy', 'started_at' => $old],
            // other org, stale -> only touched when org filter is null
            ['id' => 's-other', 'organization_id' => 'org-2', 'group_id' => null, 'created_by' => 'u9', 'title' => 'Other', 'status' => 'live', 'relay_state' => 'healthy', 'started_at' => $old],
        ];
        $db->rows['stream_relay_health'] = [
            ['id' => 'h1', 'stream_id' => 's-stale', 'status' => 'down', 'created_at' => $old],
            ['id' => 'h2', 'stream_id' => 's-fresh', 'status' => 'healthy', 'created_at' => $fresh],
        ];
        $db->rows['stream_relay_incidents'] = [];
        $db->rows['stream_destinations']    = [];

        return $db;
    };

    // Freeze the shared Clock at $now so nowUtcMicro()/now() are deterministic.
    Clock::freeze(new \DateTimeImmutable($now, new \DateTimeZone('UTC')));
    $clockAt = static fn (string $iso): Clock => new Clock();

    $rowById = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['streams'] as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    };

    // ---- gate OFF: nothing ends, everything skipped ------------------------
    $db  = $seed();
    $gate = new Gate(); // all off
    $svc = new StreamRelayService($db, $clockAt($now), null, $gate);
    $r = $svc->sweepStaleStreams($ORG, 200, 5);
    $chk('gate off: ends 0', $r['ended'] === 0, json_encode($r));
    $chk('gate off: skipped_gate counts live streams in org', $r['skipped_gate'] === 4, (string) $r['skipped_gate']);
    $chk('gate off: s-stale still live', ($rowById($db, 's-stale')['status'] ?? '') === 'live');

    // ---- no gate wired at all: inert -------------------------------------
    $db2 = $seed();
    $svc2 = new StreamRelayService($db2, $clockAt($now), null, null);
    $r2 = $svc2->sweepStaleStreams($ORG, 200, 5);
    $chk('no gate: ends 0', $r2['ended'] === 0 && $r2['skipped_gate'] === 4);

    // ---- gate ON (org-wide): stale + nobeat end, fresh/unknown/ended keep --
    $db  = $seed();
    $gate = new Gate();
    $gate->flags["$ORG|$FLAG|"] = true;
    $svc = new StreamRelayService($db, $clockAt($now), null, $gate);
    $r = $svc->sweepStaleStreams($ORG, 200, 5);
    $chk('gate on: ends exactly 2', $r['ended'] === 2, json_encode($r));
    $chk('gate on: s-stale ended', ($rowById($db, 's-stale')['status'] ?? '') === 'ended');
    $chk('gate on: s-nobeat ended (used started_at)', ($rowById($db, 's-nobeat')['status'] ?? '') === 'ended');
    $chk('gate on: s-fresh kept live', ($rowById($db, 's-fresh')['status'] ?? '') === 'live');
    $chk('gate on: s-unknown kept live (no basis)', ($rowById($db, 's-unknown')['status'] ?? '') === 'live');
    $chk('gate on: s-ended untouched', ($rowById($db, 's-ended')['status'] ?? '') === 'ended');
    $chk('gate on: other org untouched (org filter)', ($rowById($db, 's-other')['status'] ?? '') === 'live');
    $chk('ended stream stamped relay_state=down', ($rowById($db, 's-stale')['relay_state'] ?? '') === 'down');
    $chk('ended stream got ended_at', ! empty($rowById($db, 's-stale')['ended_at']));

    // one incident opened per ended stream
    $incFor = static function (BaseConnection $db, string $sid): int {
        $n = 0;
        foreach ($db->rows['stream_relay_incidents'] as $i) {
            if ($i['stream_id'] === $sid) {
                $n++;
            }
        }

        return $n;
    };
    $chk('one incident opened for s-stale', $incFor($db, 's-stale') === 1, (string) $incFor($db, 's-stale'));
    $chk('one incident opened for s-nobeat', $incFor($db, 's-nobeat') === 1);
    $chk('incident detected_by = sweep', ($db->rows['stream_relay_incidents'][0]['detected_by'] ?? '') === 'sweep');

    // ---- idempotent: second pass ends 0 -----------------------------------
    $r = $svc->sweepStaleStreams($ORG, 200, 5);
    $chk('second pass ends 0 (idempotent)', $r['ended'] === 0, json_encode($r));

    // ---- org=null runs every org (other org now eligible) -----------------
    $db  = $seed();
    $gate = new Gate();
    $gate->flags["$ORG|$FLAG|"]   = true;
    $gate->flags["org-2|$FLAG|"]  = true;
    $svc = new StreamRelayService($db, $clockAt($now), null, $gate);
    $r = $svc->sweepStaleStreams(null, 200, 5);
    $chk('org=null: ends 3 (both orgs)', $r['ended'] === 3, json_encode($r));
    $chk('org=null: s-other ended', ($rowById($db, 's-other')['status'] ?? '') === 'ended');

    // ---- per-group override enables just one stream -----------------------
    $db  = $seed();
    $db->rows['streams'][0]['group_id'] = 'g-live';   // s-stale in group g-live
    $db->rows['streams'][1]['group_id'] = 'g-off';    // s-nobeat in group g-off
    $gate = new Gate();
    $gate->flags["$ORG|$FLAG|"]        = false; // org default off
    $gate->flags["$ORG|$FLAG|g-live"]  = true;  // only this group on
    $svc = new StreamRelayService($db, $clockAt($now), null, $gate);
    $r = $svc->sweepStaleStreams($ORG, 200, 5);
    $chk('group override: ends only the enabled group\'s stream', $r['ended'] === 1, json_encode($r));
    $chk('group override: s-stale (g-live) ended', ($rowById($db, 's-stale')['status'] ?? '') === 'ended');
    $chk('group override: s-nobeat (g-off) kept', ($rowById($db, 's-nobeat')['status'] ?? '') === 'live');

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
