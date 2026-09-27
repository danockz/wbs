<?php

declare(strict_types=1);

/**
 * JourneyService::membersAtStage ROSTER unit test — pins the drill-down behind
 * a pipeline triage cell:
 *
 *   - returns active members currently at the given stage in the context;
 *   - COLDEST-FIRST (oldest stage_entered_at at the top — most in need);
 *   - each row is tagged with a per-member triage `temperature`
 *     (hot ≤30d, warm ≤90d, cold beyond) consistent with the board;
 *   - each row carries the member's display_name (ONE batched users read) and a
 *     presenter `entered_at` mirror of stage_entered_at;
 *   - an optional temperature filter narrows to one band and reports `matched`
 *     as the full filtered count (paging stays honest);
 *   - paused / other-context journeys are excluded.
 *
 * Clock is FROZEN for deterministic day-bands. Uses the date-aware fake QB.
 *
 *   php app/Modules/Journey/Services/tests/journey_members_at_stage_test.php
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
        /** @var list<array{col:string,val:mixed,op:string}> */
        private array $wheres = [];
        private array $order  = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function key(string $k): string
        {
            $k = preg_replace('/\s*(>=|<=|<>|!=|>|<)\s*$/', '', trim($k)) ?? $k;
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

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [$this->key((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        private function matches(array $r): bool
        {
            foreach ($this->wheres as $w) {
                $a = $r[$w['col']] ?? null;
                switch ($w['op']) {
                    case 'in':  if (! in_array($a, $w['val'], true)) { return false; } break;
                    case 'ge':  if (! ($a !== null && (string) $a >= (string) $w['val'])) { return false; } break;
                    case 'gt':  if (! ($a !== null && (string) $a > (string) $w['val'])) { return false; } break;
                    case 'le':  if (! ($a !== null && (string) $a <= (string) $w['val'])) { return false; } break;
                    case 'lt':  if (! ($a !== null && (string) $a < (string) $w['val'])) { return false; } break;
                    case 'ne':  if ($a === $w['val']) { return false; } break;
                    default:
                        if ($w['val'] === null) { if ($a !== null) { return false; } }
                        elseif ((string) $a !== (string) $w['val']) { return false; }
                }
            }

            return true;
        }

        public function get($limit = null, $offset = 0): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }
            if ($limit !== null) {
                $rows = array_slice($rows, (int) $offset, (int) $limit);
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

    $NOW = new DateTimeImmutable('2026-09-13 12:00:00', new DateTimeZone('UTC'));
    Clock::freeze($NOW);
    $daysAgo = static fn (int $d): string => $NOW->modify("-{$d} days")->format('Y-m-d H:i:s');

    $ORG = 'org1';
    $db  = new \CodeIgniter\Database\BaseConnection();

    $mj = static fn (string $u, string $entered, string $status = 'active', ?string $grp = null): array => [
        'id' => 'j_' . $u, 'organization_id' => $ORG, 'user_id' => $u, 'group_id' => $grp,
        'stage_code' => 'seeker', 'stage_phase' => 'win', 'stage_entered_at' => $entered, 'status' => $status,
    ];
    $db->rows['member_journeys'] = [
        $mj('u1', $daysAgo(3)),    // hot
        $mj('u2', $daysAgo(40)),   // warm
        $mj('u3', $daysAgo(200)),  // cold (oldest → top)
        $mj('u4', $daysAgo(10)),   // hot
        $mj('u5', $daysAgo(5), 'paused'),          // excluded (paused)
        $mj('u6', $daysAgo(5), 'active', 'g1'),     // excluded (group context)
    ];
    $db->rows['users'] = [
        ['id' => 'u1', 'organization_id' => $ORG, 'display_name' => 'Ama'],
        ['id' => 'u2', 'organization_id' => $ORG, 'display_name' => 'Kofi'],
        ['id' => 'u3', 'organization_id' => $ORG, 'display_name' => 'Esi'],
        ['id' => 'u4', 'organization_id' => $ORG, 'display_name' => 'Yaw'],
    ];

    $svc = new JourneyService($db, new Clock());

    // ---- full roster (org-wide) ----
    $d = $svc->membersAtStage($ORG, 'seeker')->data;
    $ids = array_column($d['members'], 'user_id');
    chk('excludes paused + group-context (4 active org-wide)', count($ids) === 4, implode(',', $ids));
    chk('coldest-first ordering (u3 oldest at top)', $ids[0] === 'u3', $ids[0]);
    chk('matched = 4', $d['matched'] === 4);

    $byId = [];
    foreach ($d['members'] as $m) { $byId[$m['user_id']] = $m; }
    chk('display_name enriched', ($byId['u3']['display_name'] ?? '') === 'Esi');
    chk('entered_at presenter mirror set', ($byId['u1']['entered_at'] ?? '') === ($byId['u1']['stage_entered_at'] ?? 'x'));
    chk('u1 temperature hot', ($byId['u1']['temperature'] ?? '') === 'hot');
    chk('u2 temperature warm', ($byId['u2']['temperature'] ?? '') === 'warm');
    chk('u3 temperature cold', ($byId['u3']['temperature'] ?? '') === 'cold');

    // ---- temperature filter ----
    $hot = $svc->membersAtStage($ORG, 'seeker', null, 200, 0, 'hot')->data;
    chk('hot filter keeps only hot', array_column($hot['members'], 'user_id') === ['u4', 'u1'] || array_column($hot['members'], 'user_id') === ['u1', 'u4'], implode(',', array_column($hot['members'], 'user_id')));
    chk('hot filter matched = 2', $hot['matched'] === 2);
    chk('hot filter echoes temperature', $hot['temperature'] === 'hot');
    $cold = $svc->membersAtStage($ORG, 'seeker', null, 200, 0, 'cold')->data;
    chk('cold filter = only u3', array_column($cold['members'], 'user_id') === ['u3']);
    $bad = $svc->membersAtStage($ORG, 'seeker', null, 200, 0, 'lukewarm')->data;
    chk('invalid temperature ignored (all 4)', count($bad['members']) === 4 && $bad['temperature'] === null);

    // ---- paging honesty (matched reflects full filtered set) ----
    $pg = $svc->membersAtStage($ORG, 'seeker', null, 2, 0)->data;
    chk('page limit applied (2 rows)', count($pg['members']) === 2);
    chk('matched still reports full 4 despite page limit', $pg['matched'] === 4);
    chk('first page still coldest-first (u3 top)', $pg['members'][0]['user_id'] === 'u3');

    // ---- empty stage ----
    $none = $svc->membersAtStage($ORG, 'ghost')->data;
    chk('unknown stage → empty roster, matched 0', $none['members'] === [] && $none['matched'] === 0);

    Clock::freeze(null);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
