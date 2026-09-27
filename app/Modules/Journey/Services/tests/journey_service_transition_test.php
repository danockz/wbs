<?php

declare(strict_types=1);

/**
 * JourneyService assessment — the transition spine (Option B).
 *
 * This is the core write path of the membership journey: open a journey, move a
 * member advance/regress/set across the effective (group-overridable) ladder,
 * record immutable history, and fan the committed change out to listeners (e.g.
 * disciple-making credit). The assessment pins:
 *
 *   - DIRECTION is derived from ladder sort_order: forward = 'advance',
 *     backward = 'regress', off-ladder code = 'set', same code = unchanged;
 *   - transition() OPENS the journey at the target when none exists yet;
 *   - reaching the terminal stage keeps the journey ACTIVE (a Sender is still a
 *     member) — only setStatus() closes it;
 *   - the effective ladder applies GROUP OVERRIDES (a group's own stage row
 *     shadows the org-wide row of the same code, incl. its sort_order), so the
 *     SAME move can be an advance org-wide but classified against the group's
 *     order;
 *   - every change writes exactly one member_journey_transitions row carrying
 *     actor_id + discipler_id + direction + source;
 *   - listeners are notified AFTER commit with a fully-shaped event, and a
 *     throwing listener never breaks the (already durable) transition;
 *   - guard rails: blank user/target, unknown target stage, and a re-set to the
 *     current stage returns unchanged (no history row, no notify).
 *
 * Tiny in-memory fake of the CI4 query builder (no DB, no framework). It
 * implements the exact subset JourneyService uses: where/whereIn/select/
 * groupBy/orderBy/insert/update/get + the trans* no-ops.
 *
 *   php app/Modules/Journey/Services/tests/journey_service_transition_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public bool $failTx = false;

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
            return ! $this->failTx;
        }
    }
}

namespace Fake {
    class QB
    {
        private array $wheres = [];   // list of [col, val, type]
        private array $order  = [];
        private ?string $group = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        private function key(string $k): string
        {
            $k = trim(explode(' ', trim($k))[0]);
            $p = explode('.', $k);

            return end($p);
        }

        public function where($k, $v = null)
        {
            $this->wheres[] = [$this->key((string) $k), $v, 'eq'];

            return $this;
        }

        public function whereIn($k, array $v)
        {
            $this->wheres[] = [$this->key((string) $k), $v, 'in'];

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

        public function orderBy($k, $dir = 'ASC')
        {
            $this->order[] = [$this->key((string) $k), strtoupper((string) $dir)];

            return $this;
        }

        private function matches(array $r): bool
        {
            foreach ($this->wheres as [$c, $v, $type]) {
                $actual = $r[$c] ?? null;
                if ($type === 'in') {
                    if (! in_array($actual, $v, true)) {
                        return false;
                    }
                    continue;
                }
                if ($v === null) {
                    if ($actual !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $actual !== (string) $v) {
                    return false;
                }
            }

            return true;
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

        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->base()] ?? [], fn ($r) => $this->matches($r)));
            foreach (array_reverse($this->order) as [$c, $dir]) {
                usort($rows, static fn ($a, $b) => $dir === 'DESC'
                    ? (($b[$c] ?? '') <=> ($a[$c] ?? ''))
                    : (($a[$c] ?? '') <=> ($b[$c] ?? '')));
            }
            // COUNT(*) AS total grouped by stage — the pipeline() read.
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

namespace WBS\Journey\Services {
    require_once dirname(__DIR__, 5) . '/app/Modules/Journey/Services/JourneyTransitionListener.php';

    /** Spy listener capturing every committed transition event. */
    final class SpyListener implements JourneyTransitionListener
    {
        /** @var list<array<string,mixed>> */
        public array $events = [];

        public bool $throw = false;

        public function onTransition(array $event): void
        {
            $this->events[] = $event;
            if ($this->throw) {
                throw new \RuntimeException('listener boom');
            }
        }
    }
}

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';

    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\JourneyService;
    use WBS\Journey\Services\SpyListener;
    use WBS\Shared\Support\Clock;

    $pass = 0;
    $fail = 0;
    function chk(string $label, bool $ok, string $detail = ''): void
    {
        global $pass, $fail;
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    }

    $ORG   = 'org1';
    $clock = new Clock();

    // Fresh world with a 4-stage org-wide ladder each time.
    $world = static function () use ($clock): array {
        $db  = new BaseConnection();
        $mk  = static fn (string $code, string $phase, int $order, int $entry = 0, int $term = 0): array => [
            'id' => "st_$code", 'organization_id' => 'org1', 'group_id' => null, 'code' => $code,
            'name' => ucfirst($code), 'phase' => $phase, 'sort_order' => $order, 'is_entry' => $entry,
            'is_terminal' => $term, 'status' => 'active',
        ];
        $db->rows['journey_stages'] = [
            $mk('seeker', 'win', 10, 1),
            $mk('new_believer', 'build', 20),
            $mk('growing', 'build', 30),
            $mk('sender', 'send', 40, 0, 1),
        ];
        $db->rows['member_journeys']            = [];
        $db->rows['member_journey_transitions'] = [];
        $spy = new SpyListener();
        $svc = new JourneyService($db, $clock, null, [$spy]);

        return [$db, $svc, $spy];
    };

    // ---- open via transition (no journey yet) -----------------------------
    echo "transition opens a journey when none exists\n";
    [$db, $svc, $spy] = $world();
    $r = $svc->transition($ORG, 'alice', 'new_believer', ['actor_id' => 'leader1', 'discipler_id' => 'leader1']);
    chk('created', $r->ok && ($r->data['stage_code'] ?? null) === 'new_believer', json_encode($r->data));
    chk('journey row written', count($db->rows['member_journeys']) === 1);
    chk('opened at target stage', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'new_believer');
    chk('one open transition recorded', count($db->rows['member_journey_transitions']) === 1
        && $db->rows['member_journey_transitions'][0]['direction'] === 'open');
    chk('listener notified once (open)', count($spy->events) === 1 && $spy->events[0]['direction'] === 'open');
    chk('open event carries discipler', ($spy->events[0]['discipler_id'] ?? null) === 'leader1');

    // ---- advance -----------------------------------------------------------
    echo "advance up the ladder\n";
    [$db, $svc, $spy] = $world();
    $svc->openJourney($ORG, 'bob', ['stage_code' => 'seeker']);
    $spy->events = [];
    $r = $svc->transition($ORG, 'bob', 'growing', ['discipler_id' => 'mentor1', 'actor_id' => 'mentor1', 'project_code' => 'harvest25']);
    chk('advance ok', $r->ok);
    chk('direction advance', ($r->data['direction'] ?? null) === 'advance', json_encode($r->data));
    chk('from_stage recorded', ($r->data['from_stage'] ?? null) === 'seeker');
    chk('phase updated to build', ($r->data['phase'] ?? null) === 'build');
    chk('member row moved', ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'growing'
        && ($db->rows['member_journeys'][0]['previous_stage'] ?? null) === 'seeker');
    $t = end($db->rows['member_journey_transitions']);
    chk('history direction advance', $t['direction'] === 'advance');
    chk('history carries project_code', ($t['project_code'] ?? null) === 'harvest25');
    chk('notify advance w/ full event', $spy->events[0]['direction'] === 'advance'
        && $spy->events[0]['from_stage'] === 'seeker' && $spy->events[0]['to_stage'] === 'growing'
        && $spy->events[0]['to_phase'] === 'build' && $spy->events[0]['project_code'] === 'harvest25');

    // ---- regress -----------------------------------------------------------
    echo "regress down the ladder\n";
    [$db, $svc, $spy] = $world();
    $svc->openJourney($ORG, 'carl', ['stage_code' => 'growing']);
    $spy->events = [];
    $r = $svc->transition($ORG, 'carl', 'seeker');
    chk('direction regress', ($r->data['direction'] ?? null) === 'regress', json_encode($r->data));
    chk('notify regress', ($spy->events[0]['direction'] ?? null) === 'regress');

    // ---- terminal stays active --------------------------------------------
    echo "terminal stage keeps journey active\n";
    [$db, $svc, $spy] = $world();
    $svc->openJourney($ORG, 'dina', ['stage_code' => 'growing']);
    $r = $svc->transition($ORG, 'dina', 'sender');
    chk('reached terminal', ($r->data['to_stage'] ?? null) === 'sender');
    chk('status still active', ($r->data['status'] ?? null) === 'active'
        && ($db->rows['member_journeys'][0]['status'] ?? null) === 'active');

    // ---- unchanged (same stage) -------------------------------------------
    echo "re-setting to current stage is a no-op\n";
    [$db, $svc, $spy] = $world();
    $svc->openJourney($ORG, 'ed', ['stage_code' => 'growing']);
    $before = count($db->rows['member_journey_transitions']);
    $spy->events = [];
    $r = $svc->transition($ORG, 'ed', 'growing');
    chk('unchanged flag', ($r->data['unchanged'] ?? null) === true);
    chk('no new history row', count($db->rows['member_journey_transitions']) === $before);
    chk('no notify on unchanged', $spy->events === []);

    // ---- group override changes classification ----------------------------
    echo "group override shadows org-wide ladder order\n";
    [$db, $svc, $spy] = $world();
    // In group g1, 'growing' is re-ordered BELOW 'seeker' (sort 5 vs 10), so a
    // seeker->growing move that is an ADVANCE org-wide is a REGRESS in g1.
    $db->rows['journey_stages'][] = [
        'id' => 'st_growing_g1', 'organization_id' => 'org1', 'group_id' => 'g1', 'code' => 'growing',
        'name' => 'Growing', 'phase' => 'build', 'sort_order' => 5, 'is_entry' => 0, 'is_terminal' => 0, 'status' => 'active',
    ];
    $svc->openJourney($ORG, 'fay', ['group_id' => 'g1', 'stage_code' => 'seeker']);
    $spy->events = [];
    $r = $svc->transition($ORG, 'fay', 'growing', ['group_id' => 'g1']);
    chk('group-scoped move classified by group order (regress)', ($r->data['direction'] ?? null) === 'regress', json_encode($r->data));
    // sanity: same move org-wide is an advance
    [$db2, $svc2] = $world();
    $svc2->openJourney($ORG, 'fay', ['stage_code' => 'seeker']);
    $r2 = $svc2->transition($ORG, 'fay', 'growing');
    chk('same move org-wide is advance', ($r2->data['direction'] ?? null) === 'advance');

    // ---- off-ladder target from resolveStage → NO_STAGE -------------------
    echo "guard rails\n";
    [$db, $svc] = $world();
    $bad = $svc->transition($ORG, 'gil', 'nonexistent');
    chk('unknown stage -> 422 NO_STAGE', ! $bad->ok && $bad->status === 422 && $bad->code === 'NO_STAGE');

    [$db, $svc] = $world();
    $bt = $svc->transition($ORG, '  ', 'seeker');
    chk('blank user -> 422 BAD_TRANSITION', ! $bt->ok && $bt->status === 422 && $bt->code === 'BAD_TRANSITION');

    [$db, $svc] = $world();
    $bt2 = $svc->transition($ORG, 'gil', '   ');
    chk('blank target -> 422 BAD_TRANSITION', ! $bt2->ok && $bt2->status === 422 && $bt2->code === 'BAD_TRANSITION');

    // ---- throwing listener never breaks the transition --------------------
    echo "listener failure is swallowed\n";
    [$db, $svc, $spy] = $world();
    $spy->throw = true;
    $svc->openJourney($ORG, 'hank', ['stage_code' => 'seeker']);
    $threw = false;
    try {
        $r = $svc->transition($ORG, 'hank', 'growing');
    } catch (\Throwable $e) {
        $threw = true;
    }
    chk('transition did not throw', ! $threw);
    chk('transition still committed', isset($r) && $r->ok
        && ($db->rows['member_journeys'][0]['stage_code'] ?? null) === 'growing');

    // ---- setStatus closes; direction() 'set' for off-ladder current -------
    echo "setStatus + set classification\n";
    [$db, $svc] = $world();
    $svc->openJourney($ORG, 'ivy', ['stage_code' => 'seeker']);
    $rs = $svc->setStatus($ORG, 'ivy', 'completed');
    chk('setStatus ok', $rs->ok && ($db->rows['member_journeys'][0]['status'] ?? null) === 'completed');
    $rsn = $svc->setStatus($ORG, 'nobody', 'paused');
    chk('setStatus on missing journey -> not found', ! $rsn->ok && $rsn->code === 'JOURNEY_NOT_FOUND');

    // ---- M9: setStatus is guarded + recorded as immutable history ---------
    echo "M9 setStatus guard + history\n";
    [$db, $svc] = $world();
    $svc->openJourney($ORG, 'jae', ['stage_code' => 'seeker']);
    $before = count($db->rows['member_journey_transitions'] ?? []);
    // legal edge active -> paused records ONE status transition row
    $r1 = $svc->setStatus($ORG, 'jae', 'paused', null, ['actor_id' => 'leader-1', 'reason' => 'on sabbatical']);
    chk('M9 legal edge ok', $r1->ok && ($r1->data['from'] ?? null) === 'active');
    $rows = $db->rows['member_journey_transitions'];
    $last = end($rows);
    chk('M9 one history row appended', count($rows) === $before + 1);
    chk('M9 history direction=status', ($last['direction'] ?? null) === 'status');
    chk('M9 history stage unchanged (from==to==seeker)', ($last['from_stage'] ?? null) === 'seeker' && ($last['to_stage'] ?? null) === 'seeker');
    chk('M9 history carries actor + reason', ($last['actor_id'] ?? null) === 'leader-1' && ($last['reason'] ?? null) === 'on sabbatical');
    chk('M9 status source=manual', ($last['source'] ?? null) === 'manual');
    // idempotent no-op: paused -> paused writes NO row
    $r2 = $svc->setStatus($ORG, 'jae', 'paused');
    chk('M9 no-op ok + flagged unchanged', $r2->ok && ($r2->meta['unchanged'] ?? false) === true);
    chk('M9 no-op wrote no history row', count($db->rows['member_journey_transitions']) === $before + 1);
    // illegal edge: paused -> ... first archive, then archived -> completed is illegal
    $svc->setStatus($ORG, 'jae', 'archived');
    $countBeforeBad = count($db->rows['member_journey_transitions']);
    $bad = $svc->setStatus($ORG, 'jae', 'completed'); // archived -> completed not allowed
    chk('M9 illegal edge rejected', ! $bad->ok && $bad->code === 'BAD_STATUS_TRANSITION');
    chk('M9 illegal edge lists allowed set', ($bad->errors['allowed'] ?? null) === ['active']);
    chk('M9 illegal edge did not mutate status', ($db->rows['member_journeys'][0]['status'] ?? null) === 'archived');
    chk('M9 illegal edge wrote no history row', count($db->rows['member_journey_transitions']) === $countBeforeBad);
    // restore path: archived -> active is allowed
    $restore = $svc->setStatus($ORG, 'jae', 'active');
    chk('M9 archived -> active restore allowed', $restore->ok && ($db->rows['member_journeys'][0]['status'] ?? null) === 'active');
    // unknown status still 422
    $badEnum = $svc->setStatus($ORG, 'jae', 'frozen');
    chk('M9 unknown status -> 422 BAD_STATUS', ! $badEnum->ok && $badEnum->code === 'BAD_STATUS');
    // compareStages: same and set
    chk('compareStages same', $svc->compareStages($ORG, null, 'seeker', 'seeker') === 'same');
    chk('compareStages off-ladder = set', $svc->compareStages($ORG, null, 'seeker', 'ghost') === 'set');
    chk('compareStages advance', $svc->compareStages($ORG, null, 'seeker', 'sender') === 'advance');

    // ---- pipeline counts by stage -----------------------------------------
    echo "pipeline counts\n";
    [$db, $svc] = $world();
    $svc->openJourney($ORG, 'p1', ['stage_code' => 'seeker']);
    $svc->openJourney($ORG, 'p2', ['stage_code' => 'seeker']);
    $svc->openJourney($ORG, 'p3', ['stage_code' => 'growing']);
    $pl = $svc->pipeline($ORG)->data;
    $byCode = [];
    foreach ($pl['stages'] as $s) {
        $byCode[$s['code']] = $s['count'];
    }
    chk('pipeline total', $pl['total'] === 3, (string) $pl['total']);
    chk('pipeline seeker=2', ($byCode['seeker'] ?? null) === 2);
    chk('pipeline growing=1', ($byCode['growing'] ?? null) === 1);
    chk('pipeline sender=0', ($byCode['sender'] ?? null) === 0);
    chk('pipeline ordered by ladder', array_keys($byCode) === ['seeker', 'new_believer', 'growing', 'sender']);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
