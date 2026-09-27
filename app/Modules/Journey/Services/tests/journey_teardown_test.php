<?php

declare(strict_types=1);

/**
 * JourneyService lifecycle-signal consumers test (Theme B — J4).
 *
 * Over an in-memory DB fake, proves:
 *   pauseAllForSubject (deactivate/suspend):
 *     - only the subject's ACTIVE journeys flip to paused (across contexts);
 *     - completed/archived and OTHER users' journeys are untouched;
 *     - idempotent re-run pauses 0; empty org/subject -> 0.
 *   reassignForMerge (merge):
 *     - a loser journey in a context the survivor does NOT have -> re-pointed
 *       (user_id becomes survivor);
 *     - a loser journey in a context the survivor ALREADY has -> archived
 *       (survivor's own journey wins; no unique-key collision / silent overwrite);
 *     - loser == survivor / empty ids -> no-op.
 *
 *   php app/Modules/Journey/Services/tests/journey_teardown_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
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
        /** @var array<string,mixed> */
        private array $eq = [];
        /** @var array<string,list<string>> */
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            // Support ->where('group_id', null) as an explicit NULL match.
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        /** @var array<string,string> col => required prefix */
        private array $likePrefix = [];

        public function like($k, $v, $side = 'both')
        {
            $this->likePrefix[trim((string) $k)] = (string) $v; // 'after' (prefix) form only

            return $this;
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function update(array $set): bool
        {
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                    $n++;
                }
            }
            $this->db->affected = $n;

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                $rv = $r[$k] ?? null;
                if ($v === null) {
                    if ($rv !== null) {
                        return false;
                    }
                    continue;
                }
                if ((string) $rv !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }
            foreach ($this->likePrefix as $k => $prefix) {
                if (! str_starts_with((string) ($r[$k] ?? ''), $prefix)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\JourneyService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementTriagePort.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $rowOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['member_journeys'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    // ---- pauseAllForSubject -------------------------------------------------
    $db = new BaseConnection();
    $db->rows['member_journeys'] = [
        ['id' => 'j1', 'organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => null, 'status' => 'active'],
        ['id' => 'j2', 'organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => 'g1', 'status' => 'active'],
        ['id' => 'j3', 'organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => 'g2', 'status' => 'completed'],
        ['id' => 'j4', 'organization_id' => $ORG, 'user_id' => 'u2', 'group_id' => null, 'status' => 'active'],
    ];
    $svc = new JourneyService($db, new Clock());
    $n = $svc->pauseAllForSubject($ORG, 'u1', 'account.deactivated');
    $chk('pauses 2 active journeys', $n === 2, (string) $n);
    $chk('org-wide journey paused', $rowOf($db, 'j1')['status'] === 'paused');
    $chk('group journey paused', $rowOf($db, 'j2')['status'] === 'paused');
    $chk('paused journey notes the reason', str_contains((string) $rowOf($db, 'j1')['note'], 'account.deactivated'));
    $chk('completed journey untouched', $rowOf($db, 'j3')['status'] === 'completed');
    $chk('other user untouched', $rowOf($db, 'j4')['status'] === 'active');
    $chk('re-run pauses 0 (idempotent)', $svc->pauseAllForSubject($ORG, 'u1', 'x') === 0);
    $chk('empty org -> 0', $svc->pauseAllForSubject('', 'u1', 'x') === 0);
    $chk('empty subject -> 0', $svc->pauseAllForSubject($ORG, '', 'x') === 0);

    // ---- resumeAllForSubject (M10 inverse) ----------------------------------
    // Continues from the pause above: j1/j2 are teardown-paused, j3 completed,
    // j4 (other user) active. Add a MEMBER-paused journey that must NOT resume.
    $db->rows['member_journeys'][] = ['id' => 'j5', 'organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => 'g9', 'status' => 'paused', 'note' => 'on sabbatical'];
    $r = $svc->resumeAllForSubject($ORG, 'u1');
    $chk('resumes exactly the 2 teardown-paused journeys', $r === 2, (string) $r);
    $chk('org-wide journey resumed to active', $rowOf($db, 'j1')['status'] === 'active');
    $chk('group journey resumed to active', $rowOf($db, 'j2')['status'] === 'active');
    $chk('resumed journey note cleared', $rowOf($db, 'j1')['note'] === null);
    $chk('member-paused journey NOT resumed', $rowOf($db, 'j5')['status'] === 'paused');
    $chk('completed journey still untouched', $rowOf($db, 'j3')['status'] === 'completed');
    $chk('other user still untouched', $rowOf($db, 'j4')['status'] === 'active');
    $chk('resume re-run resumes 0 (idempotent)', $svc->resumeAllForSubject($ORG, 'u1') === 0);
    $chk('resume empty org -> 0', $svc->resumeAllForSubject('', 'u1') === 0);
    $chk('resume empty subject -> 0', $svc->resumeAllForSubject($ORG, '') === 0);

    // ---- reassignForMerge ---------------------------------------------------
    $db2 = new BaseConnection();
    $db2->rows['member_journeys'] = [
        // loser 'L' has org-wide + g1 journeys.
        ['id' => 'L-org', 'organization_id' => $ORG, 'user_id' => 'L', 'group_id' => null, 'status' => 'active'],
        ['id' => 'L-g1', 'organization_id' => $ORG, 'user_id' => 'L', 'group_id' => 'g1', 'status' => 'active'],
        // survivor 'S' already has a g1 journey (collision) but NOT an org-wide one.
        ['id' => 'S-g1', 'organization_id' => $ORG, 'user_id' => 'S', 'group_id' => 'g1', 'status' => 'active'],
    ];
    $svc2 = new JourneyService($db2, new Clock());
    $res = $svc2->reassignForMerge($ORG, 'L', 'S');
    $chk('reassign repoints 1 (org-wide, survivor had none)', $res['repointed'] === 1, (string) $res['repointed']);
    $chk('reassign archives 1 dupe (g1 collision)', $res['archived_dupes'] === 1, (string) $res['archived_dupes']);
    $chk('org-wide journey now owned by survivor', $rowOf($db2, 'L-org')['user_id'] === 'S');
    $chk('org-wide journey still active', $rowOf($db2, 'L-org')['status'] === 'active');
    $chk('colliding loser g1 journey archived', $rowOf($db2, 'L-g1')['status'] === 'archived');
    $chk('colliding loser g1 still owned by loser (archived, not moved)', $rowOf($db2, 'L-g1')['user_id'] === 'L');
    $chk('survivor own g1 journey untouched', $rowOf($db2, 'S-g1')['status'] === 'active' && $rowOf($db2, 'S-g1')['user_id'] === 'S');

    // ---- archiveGroupContextJourneys (J4-group) -----------------------------
    // A dead group's OWN journey context is archived; org-wide + other groups +
    // already-terminal journeys are left alone.
    $db3 = new \CodeIgniter\Database\BaseConnection();
    $db3->rows['member_journeys'] = [
        ['id' => 'd-active', 'organization_id' => $ORG, 'user_id' => 'a', 'group_id' => 'dead', 'status' => 'active'],
        ['id' => 'd-paused', 'organization_id' => $ORG, 'user_id' => 'b', 'group_id' => 'dead', 'status' => 'paused'],
        ['id' => 'd-completed', 'organization_id' => $ORG, 'user_id' => 'c', 'group_id' => 'dead', 'status' => 'completed'],
        ['id' => 'other-grp', 'organization_id' => $ORG, 'user_id' => 'a', 'group_id' => 'live', 'status' => 'active'],
        ['id' => 'org-wide', 'organization_id' => $ORG, 'user_id' => 'a', 'group_id' => null, 'status' => 'active'],
    ];
    $svc3 = new JourneyService($db3, new Clock());
    $n3 = $svc3->archiveGroupContextJourneys($ORG, 'dead', 'group.dissolved');
    $chk('archives 2 group-context journeys (active+paused)', $n3 === 2, (string) $n3);
    $chk('dead-group active journey archived', $rowOf($db3, 'd-active')['status'] === 'archived');
    $chk('dead-group paused journey archived', $rowOf($db3, 'd-paused')['status'] === 'archived');
    $chk('archived journey notes the reason', str_contains((string) $rowOf($db3, 'd-active')['note'], 'group.dissolved'));
    $chk('dead-group completed journey untouched', $rowOf($db3, 'd-completed')['status'] === 'completed');
    $chk('other live group journey untouched', $rowOf($db3, 'other-grp')['status'] === 'active');
    $chk('org-wide journey untouched', $rowOf($db3, 'org-wide')['status'] === 'active');
    $chk('archive group idempotent (re-run 0)', $svc3->archiveGroupContextJourneys($ORG, 'dead', 'x') === 0);
    $chk('archive group empty org -> 0', $svc3->archiveGroupContextJourneys('', 'dead', 'x') === 0);
    $chk('archive group empty group -> 0', $svc3->archiveGroupContextJourneys($ORG, '', 'x') === 0);

    // ---- guards -------------------------------------------------------------
    $chk('reassign loser==survivor no-op', $svc2->reassignForMerge($ORG, 'X', 'X') === ['repointed' => 0, 'archived_dupes' => 0]);
    $chk('reassign empty survivor no-op', $svc2->reassignForMerge($ORG, 'L', '') === ['repointed' => 0, 'archived_dupes' => 0]);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
