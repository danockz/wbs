<?php

declare(strict_types=1);

/**
 * FollowUpService::dueFollowUps journey-aware ENRICHMENT unit test — pins the
 * "who needs following up" work-queue behaviour after it grew from a raw
 * follow_ups dump into a triage-ready, journey-aware queue:
 *
 *   - the due-window query still selects only OPEN follow-ups with a
 *     next_follow_up_at on/before the cutoff, follower-scoped when asked;
 *   - each row is ENRICHED in a RESOURCE-LIGHT way (batched whereIn reads, not
 *     per-row): subject_name + follower_name (users), type name + WBS phase
 *     (follow_up_types), and the subject's CURRENT journey stage_code +
 *     stage_phase (member_journeys);
 *   - the subject's ORG-WIDE primary journey (group_id NULL) wins over a
 *     group-scoped one for the displayed stage;
 *   - an optional stage_code filter narrows the queue to one journey stage, and
 *     a phase filter matches either the type phase or the member's stage phase;
 *   - presenter keys (subject_name, type, due_at, phase, stage_code) are present
 *     so the gam_followups_due page renders populated cells.
 *
 * Uses a tiny in-memory fake of the CI4 query builder (no DB, no framework),
 * mirroring the sibling journey_recommendation_test harness.
 *
 *   php app/Modules/Gamification/Services/tests/followup_due_enrichment_test.php
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
        /** @var list<array{type:string,col:string,val:mixed}> */
        private array $preds = [];
        private array $order = [];
        private ?int $limit  = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = (string) $k;
            // Raw predicate like 'next_follow_up_at IS NOT NULL'.
            if ($v === null && $escape === false && stripos($k, 'IS NOT NULL') !== false) {
                $col = trim(str_ireplace('IS NOT NULL', '', $k));
                $this->preds[] = ['type' => 'notnull', 'col' => $this->col($col), 'val' => null];

                return $this;
            }
            $op = 'eq';
            if (preg_match('/\s*(>=|<=|>|<|!=)\s*$/', $k, $m)) {
                $op = ['>' => 'gt', '<' => 'lt', '>=' => 'ge', '<=' => 'le', '!=' => 'ne'][$m[1]];
            }
            $this->preds[] = ['type' => $op, 'col' => $this->col($k), 'val' => $v];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->preds[] = ['type' => 'in', 'col' => $this->col((string) $k), 'val' => $v];

            return $this;
        }

        public function orderBy($k, $dir = 'ASC', $escape = true)
        {
            $this->order[] = [$this->col((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        public function limit($n)
        {
            $this->limit = (int) $n;

            return $this;
        }

        public function get(): RS
        {
            $rows = array_values(array_filter(
                $this->db->rows[$this->baseTable()] ?? [],
                fn ($r) => $this->matches($r),
            ));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }

            return new RS($rows);
        }

        private function matches(array $r): bool
        {
            foreach ($this->preds as $p) {
                $actual = $r[$p['col']] ?? null;
                switch ($p['type']) {
                    case 'notnull':
                        if ($actual === null) { return false; }
                        break;
                    case 'in':
                        if (! in_array($actual, $p['val'], true)) { return false; }
                        break;
                    case 'le':
                        if (! ($actual !== null && $actual <= $p['val'])) { return false; }
                        break;
                    case 'ge':
                        if (! ($actual !== null && $actual >= $p['val'])) { return false; }
                        break;
                    case 'gt':
                        if (! ($actual !== null && $actual > $p['val'])) { return false; }
                        break;
                    case 'lt':
                        if (! ($actual !== null && $actual < $p['val'])) { return false; }
                        break;
                    case 'ne':
                        if ($actual === $p['val']) { return false; }
                        break;
                    default: // eq
                        if ($p['val'] === null) {
                            if ($actual !== null) { return false; }
                        } elseif ((string) $actual !== (string) $p['val']) {
                            return false;
                        }
                }
            }

            return true;
        }

        private function col(string $k): string
        {
            $k = trim($k);
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
    require_once $root . '/app/Modules/Gamification/Services/FollowUpService.php';

    use WBS\Gamification\Services\FollowUpService;
    use WBS\Shared\Support\Clock;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $ORG = 'org1';
    $db  = new \CodeIgniter\Database\BaseConnection();

    // Follow-ups: two due + open, one open but not-yet-due, one cancelled.
    $db->rows['follow_ups'] = [
        ['id' => 'f1', 'organization_id' => $ORG, 'group_id' => null, 'subject_user_id' => 'u_sub1',
            'follower_user_id' => 'u_lead', 'type_code' => 'new_visitor', 'method_code' => 'call',
            'status' => 'pending', 'next_follow_up_at' => '2026-09-10 09:00:00'],
        ['id' => 'f2', 'organization_id' => $ORG, 'group_id' => 'g1', 'subject_user_id' => 'u_sub2',
            'follower_user_id' => 'u_lead', 'type_code' => 'absent_member', 'method_code' => 'visit',
            'status' => 'in_progress', 'next_follow_up_at' => '2026-09-11 09:00:00'],
        ['id' => 'f3', 'organization_id' => $ORG, 'group_id' => null, 'subject_user_id' => 'u_sub3',
            'follower_user_id' => 'u_lead', 'type_code' => 'new_visitor', 'method_code' => 'call',
            'status' => 'pending', 'next_follow_up_at' => '2026-12-01 09:00:00'], // future → not due
        ['id' => 'f4', 'organization_id' => $ORG, 'group_id' => null, 'subject_user_id' => 'u_sub1',
            'follower_user_id' => 'u_lead', 'type_code' => 'new_visitor', 'method_code' => 'call',
            'status' => 'cancelled', 'next_follow_up_at' => '2026-09-09 09:00:00'], // cancelled → excluded
    ];
    $db->rows['users'] = [
        ['id' => 'u_sub1', 'organization_id' => $ORG, 'display_name' => 'Ama Mensah'],
        ['id' => 'u_sub2', 'organization_id' => $ORG, 'display_name' => 'Kofi Boateng'],
        ['id' => 'u_lead', 'organization_id' => $ORG, 'display_name' => 'Pastor Yaw'],
    ];
    $db->rows['follow_up_types'] = [
        ['code' => 'new_visitor', 'organization_id' => $ORG, 'group_id' => null, 'name' => 'New visitor', 'phase' => 'win', 'status' => 'active'],
        ['code' => 'absent_member', 'organization_id' => $ORG, 'group_id' => null, 'name' => 'Absent member', 'phase' => 'build', 'status' => 'active'],
    ];
    $db->rows['member_journeys'] = [
        // u_sub1: org-wide primary (win) + a group-scoped one (build) → primary wins.
        ['user_id' => 'u_sub1', 'organization_id' => $ORG, 'group_id' => 'g1', 'stage_code' => 'growing', 'stage_phase' => 'build', 'status' => 'active', 'stage_entered_at' => '2026-08-01 00:00:00'],
        ['user_id' => 'u_sub1', 'organization_id' => $ORG, 'group_id' => null, 'stage_code' => 'new_believer', 'stage_phase' => 'win', 'status' => 'active', 'stage_entered_at' => '2026-07-01 00:00:00'],
        // u_sub2: only a group-scoped journey.
        ['user_id' => 'u_sub2', 'organization_id' => $ORG, 'group_id' => 'g1', 'stage_code' => 'worker', 'stage_phase' => 'send', 'status' => 'active', 'stage_entered_at' => '2026-06-01 00:00:00'],
    ];

    $svc = new FollowUpService($db, new Clock());

    // ---- 1. Base due list (cutoff after both due, before the future one) ----
    $due = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100);
    $ids = array_column($due, 'id');
    chk('returns the two open+due rows', $ids === ['f1', 'f2'], implode(',', $ids));
    chk('excludes the not-yet-due row (f3)', ! in_array('f3', $ids, true));
    chk('excludes the cancelled row (f4)', ! in_array('f4', $ids, true));
    chk('ordered by next_follow_up_at ASC', $ids === ['f1', 'f2']);

    // ---- 2. Enrichment fields present ----
    $byId = [];
    foreach ($due as $r) { $byId[$r['id']] = $r; }
    chk('subject_name resolved', ($byId['f1']['subject_name'] ?? '') === 'Ama Mensah');
    chk('follower_name resolved', ($byId['f1']['follower_name'] ?? '') === 'Pastor Yaw');
    chk('type name resolved', ($byId['f1']['type'] ?? '') === 'New visitor');
    chk('type phase resolved', ($byId['f1']['phase'] ?? '') === 'win');
    chk('due_at mirrors next_follow_up_at', ($byId['f1']['due_at'] ?? '') === '2026-09-10 09:00:00');

    // ---- 3. Journey stage — org-wide primary wins over group-scoped ----
    chk('subject stage = org-wide primary (new_believer)', ($byId['f1']['stage_code'] ?? '') === 'new_believer', $byId['f1']['stage_code'] ?? '(none)');
    chk('subject stage_phase = win', ($byId['f1']['stage_phase'] ?? '') === 'win');
    chk('group-only subject uses its group journey (worker)', ($byId['f2']['stage_code'] ?? '') === 'worker');

    // ---- 4. Follower scope filter ----
    $none = $svc->dueFollowUps($ORG, 'someone_else', '2026-09-30 00:00:00', 100);
    chk('follower filter excludes other leaders', $none === []);
    $mine = $svc->dueFollowUps($ORG, 'u_lead', '2026-09-30 00:00:00', 100);
    chk('follower filter keeps own queue', array_column($mine, 'id') === ['f1', 'f2']);

    // ---- 5. stage_code filter ----
    $onlyNb = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100, ['stage_code' => 'new_believer']);
    chk('stage_code filter narrows to that stage', array_column($onlyNb, 'id') === ['f1']);
    $onlyWorker = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100, ['stage_code' => 'worker']);
    chk('stage_code filter matches the group-journey stage', array_column($onlyWorker, 'id') === ['f2']);
    $noStage = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100, ['stage_code' => 'nonexistent']);
    chk('stage_code filter with no matches → empty', $noStage === []);

    // ---- 6. phase filter (matches type OR stage phase) ----
    $winPhase = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100, ['phase' => 'win']);
    chk('phase=win keeps f1 (type win / stage win)', array_column($winPhase, 'id') === ['f1']);
    $sendPhase = $svc->dueFollowUps($ORG, null, '2026-09-30 00:00:00', 100, ['phase' => 'send']);
    chk('phase=send keeps f2 (stage phase send)', array_column($sendPhase, 'id') === ['f2']);

    // ---- 7. empty input → empty output ----
    $empty = $svc->dueFollowUps($ORG, null, '2020-01-01 00:00:00', 100);
    chk('cutoff before all → empty (and no crash on enrichment)', $empty === []);

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}
