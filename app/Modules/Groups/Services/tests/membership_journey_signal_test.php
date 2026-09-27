<?php

declare(strict_types=1);

/**
 * GroupMembershipService — belonging → journey signals (finding M7).
 *
 * Standing definition: "integration" = group membership + foundations/membership
 * courses. Courses already emit `journey.signal.course.completed`; this pins the
 * MEMBERSHIP half so joining/leaving a group drives the SAME journey rule engine
 * (through the JourneySignalPort seam — Groups stays decoupled from Journey,
 * which already depends on Groups):
 *
 *   - add() that lands ACTIVE (no approval) emits `journey.signal.group.joined`
 *     with the org-wide journey context (group_id=null) + originating group as
 *     scope_group_id, evidence membership:<id>, and membership_type/source attrs;
 *   - add() that lands PENDING (requires_approval) emits NOTHING — a not-yet-
 *     approved belonging must not advance a stage;
 *   - approve() of that pending row emits `journey.signal.group.joined` (the
 *     belonging is now real), marked approved=true;
 *   - leave() emits the regress counterpart `journey.signal.group.left` with the
 *     reason;
 *   - a signal-port THROW never breaks the belonging write (fault-isolated);
 *   - a service with NO port wired still writes belongings (signal is optional).
 *
 *   php app/Modules/Groups/Services/tests/membership_journey_signal_test.php
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

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f, $esc = null)
        {
            return $this;
        }

        public function join($t, $c, $type = '')
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            return $this;
        }

        // detectConflict() uses these; with an empty conflicts table they no-op.
        public function groupStart()
        {
            return $this;
        }

        public function orWhere($k, $v = null)
        {
            return $this;
        }

        public function groupEnd()
        {
            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            return $this;
        }

        /** @return list<array<string,mixed>> */
        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get(): RS
        {
            return new RS($this->filtered());
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
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

            return true;
        }
    }
}

// Stub AuditLogger under its exact FQCN (real file never loaded).
namespace WBS\Audit\Services {
    final class AuditLogger
    {
        public function record(string $organizationId, array $data)
        {
            return null;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Groups\Services\GroupMembershipService;
    use WBS\Groups\Services\JourneySignalPort;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\Result;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/JourneySignalPort.php';
    require_once $root . '/app/Modules/Groups/Services/GroupMembershipService.php';

    // In-memory JourneySignalPort spy.
    $spy = new class implements JourneySignalPort {
        /** @var list<array<string,mixed>> */
        public array $calls = [];
        public bool $throw = false;

        public function ingest(string $organizationId, array $data): Result
        {
            $this->calls[] = ['org' => $organizationId] + $data;
            if ($this->throw) {
                throw new \RuntimeException('journey boom');
            }

            return Result::ok(['matched' => 1]);
        }
    };

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $newDb = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        // add() requires an active group in the org.
        $db->rows['groups'] = [];
        foreach (['g1', 'g2', 'g3', 'g4', 'g5'] as $g) {
            $db->rows['groups'][] = ['id' => $g, 'organization_id' => $ORG, 'status' => 'active'];
        }
        $db->rows['group_members'] = [];
        $db->rows['group_membership_events'] = [];
        $db->rows['group_membership_conflicts'] = []; // no conflicts configured
        return $db;
    };
    $last = static fn () => $GLOBALS['__spy']->calls[count($GLOBALS['__spy']->calls) - 1] ?? [];
    $GLOBALS['__spy'] = $spy;

    // ---- 1) direct active join emits group.joined --------------------------
    $db  = $newDb();
    $svc = new GroupMembershipService($db, new Clock(), new AuditLogger(), $spy);
    $spy->calls = [];
    $res = $svc->add($ORG, 'g1', ['user_id' => 'u1', 'membership_type' => 'member', 'actor_id' => 'admin']);
    $chk('active join: membership created', $res->ok);
    $chk('active join: one signal emitted', count($spy->calls) === 1, (string) count($spy->calls));
    $c = $last();
    $chk('active join: action = group.joined', ($c['action'] ?? '') === 'journey.signal.group.joined');
    $chk('active join: user threaded', ($c['user_id'] ?? '') === 'u1');
    $chk('active join: journey context is org-wide (group_id null)', array_key_exists('group_id', $c) && $c['group_id'] === null);
    $chk('active join: scope_group_id = originating group', ($c['scope_group_id'] ?? '') === 'g1');
    $chk('active join: evidence type', ($c['evidence_type'] ?? '') === 'group_membership');
    $chk('active join: evidence ref points at membership', str_starts_with((string) ($c['evidence_ref'] ?? ''), 'membership:'));
    $chk('active join: attributes carry group + type', ($c['attributes']['group_id'] ?? '') === 'g1'
        && ($c['attributes']['membership_type'] ?? '') === 'member');
    $chk('active join: actor threaded', ($c['actor_id'] ?? '') === 'admin');

    // ---- 2) pending request emits NOTHING, approve() does ------------------
    $db  = $newDb();
    $svc = new GroupMembershipService($db, new Clock(), new AuditLogger(), $spy);
    $spy->calls = [];
    $res = $svc->add($ORG, 'g2', ['user_id' => 'u2', 'membership_type' => 'member', 'requires_approval' => true, 'actor_id' => 'admin']);
    $chk('pending request: created', $res->ok);
    $chk('pending request: NO signal at request time', count($spy->calls) === 0, (string) count($spy->calls));
    $mid = (string) ($res->data['membership_id'] ?? '');

    $ap = $svc->approve($ORG, $mid, 'reviewer');
    $chk('approve: ok', $ap->ok);
    $chk('approve: one signal emitted', count($spy->calls) === 1, (string) count($spy->calls));
    $c = $last();
    $chk('approve: action = group.joined', ($c['action'] ?? '') === 'journey.signal.group.joined');
    $chk('approve: user threaded from row', ($c['user_id'] ?? '') === 'u2');
    $chk('approve: scope_group_id from row', ($c['scope_group_id'] ?? '') === 'g2');
    $chk('approve: marked approved', ($c['attributes']['approved'] ?? false) === true);
    $chk('approve: actor = reviewer', ($c['actor_id'] ?? '') === 'reviewer');

    // ---- 3) leave emits the regress counterpart ---------------------------
    $db  = $newDb();
    $svc = new GroupMembershipService($db, new Clock(), new AuditLogger(), $spy);
    $add = $svc->add($ORG, 'g3', ['user_id' => 'u3', 'membership_type' => 'leader']);
    $mid = (string) ($add->data['membership_id'] ?? '');
    $spy->calls = [];
    $lv = $svc->leave($ORG, $mid, 'admin', 'moved away');
    $chk('leave: ok', $lv->ok);
    $chk('leave: one signal emitted', count($spy->calls) === 1, (string) count($spy->calls));
    $c = $last();
    $chk('leave: action = group.left', ($c['action'] ?? '') === 'journey.signal.group.left');
    $chk('leave: user threaded', ($c['user_id'] ?? '') === 'u3');
    $chk('leave: reason carried', ($c['attributes']['reason'] ?? '') === 'moved away');
    $chk('leave: membership_type carried', ($c['attributes']['membership_type'] ?? '') === 'leader');

    // ---- 4) fault isolation: a throwing port never breaks the write -------
    $db  = $newDb();
    $svc = new GroupMembershipService($db, new Clock(), new AuditLogger(), $spy);
    $spy->throw = true;
    $spy->calls = [];
    $res = $svc->add($ORG, 'g4', ['user_id' => 'u4', 'membership_type' => 'member']);
    $chk('throwing port: membership still created', $res->ok);
    $chk('throwing port: the row exists + is active', count(array_filter($db->rows['group_members'], fn ($r) => $r['user_id'] === 'u4' && $r['status'] === 'active')) === 1);
    $chk('throwing port: emit was attempted', count($spy->calls) === 1);
    $spy->throw = false;

    // ---- 5) no port wired: belongings still work (signal optional) --------
    $db  = $newDb();
    $svc = new GroupMembershipService($db, new Clock(), new AuditLogger()); // no port
    $res = $svc->add($ORG, 'g5', ['user_id' => 'u5', 'membership_type' => 'member']);
    $chk('no port: membership created without a journey seam', $res->ok
        && count(array_filter($db->rows['group_members'], fn ($r) => $r['user_id'] === 'u5')) === 1);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
