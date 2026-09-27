<?php

declare(strict_types=1);

/**
 * GroupMembershipService — account teardown + merge reactions (findings M1/M2).
 *
 * TEARDOWN (endActiveForSubject): when an account is deactivated / suspended /
 * anonymized, the person's ACTIVE group memberships must stop counting as
 * `active` so they leave rosters, scope resolution and group-size metrics:
 *   - every `active` row for the subject flips to `status='ended'`, with
 *     left_at, a `teardown: <reason>` leave_reason, effective_to backfilled and
 *     active_key freed (NULL) so the one-active slot re-opens;
 *   - historical (`ended`) rows are untouched — history is preserved;
 *   - other people's memberships are never touched;
 *   - each ended row emits a membership event + an audit record;
 *   - a re-run ends 0 (idempotent — the rows are no longer active);
 *   - empty org / user -> 0, no writes.
 *
 * MERGE (reassignForMerge): the loser's memberships re-point to the survivor,
 * deduped on the one-active (group, membership_type) slot:
 *   - an active loser membership for a slot the survivor does NOT hold re-points
 *     (user_id + recomputed active_key);
 *   - an active loser membership colliding with a survivor slot is ENDED as a
 *     superseded duplicate (survivor's membership wins; active_key freed);
 *   - two active loser rows for the SAME slot: the first re-points, the second
 *     is superseded (intra-loser dedup);
 *   - historical loser rows re-point owner for continuity, active_key stays NULL;
 *   - loser==survivor / empty -> all-zero no-op.
 *
 *   php app/Modules/Groups/Services/tests/membership_teardown_test.php
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
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            return $this;
        }

        /** @var array<string,string> col => required prefix */
        private array $likePrefix = [];

        public function like($k, $v, $side = 'both')
        {
            // Only the 'after' (prefix) form is used by the code under test.
            $this->likePrefix[trim((string) $k)] = (string) $v;

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

// Stub AuditLogger under its exact FQCN (real file never loaded).
namespace WBS\Audit\Services {
    final class AuditLogger
    {
        /** @var list<array<string,mixed>> */
        public array $records = [];

        public function record(string $organizationId, array $data)
        {
            $this->records[] = ['org' => $organizationId] + $data;

            return null;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Groups\Services\GroupMembershipService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Groups/Services/GroupMembershipService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $rowById = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['group_members'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };
    $activeKey = static fn (string $u, string $g, string $t): string => hash('sha256', $u . ':' . $g . ':' . $t);
    $svcOf = static fn (BaseConnection $db, AuditLogger $audit) => new GroupMembershipService($db, new Clock(), $audit);

    // ======================= TEARDOWN =======================================
    $db    = new BaseConnection();
    $audit = new AuditLogger();
    $db->rows['group_members'] = [
        ['id' => 'm-a', 'organization_id' => $ORG, 'group_id' => 'g1', 'user_id' => 'gone', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => 'k-a'],
        ['id' => 'm-b', 'organization_id' => $ORG, 'group_id' => 'g2', 'user_id' => 'gone', 'membership_type' => 'leader', 'status' => 'active', 'effective_to' => null, 'active_key' => 'k-b'],
        ['id' => 'm-hist', 'organization_id' => $ORG, 'group_id' => 'g3', 'user_id' => 'gone', 'membership_type' => 'member', 'status' => 'ended', 'effective_to' => '2020-01-01 00:00:00', 'active_key' => null],
        ['id' => 'm-other', 'organization_id' => $ORG, 'group_id' => 'g1', 'user_id' => 'stays', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => 'k-other'],
        ['id' => 'm-otherorg', 'organization_id' => 'org-2', 'group_id' => 'g1', 'user_id' => 'gone', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => 'k-oo'],
    ];
    $db->rows['group_membership_events'] = [];

    $svc   = $svcOf($db, $audit);
    $ended = $svc->endActiveForSubject($ORG, 'gone', 'account.suspended');

    $chk('teardown: ended count = 2', $ended === 2, (string) $ended);
    $ma = $rowById($db, 'm-a');
    $mb = $rowById($db, 'm-b');
    $chk('teardown: m-a now ended', ($ma['status'] ?? '') === 'ended');
    $chk('teardown: m-a active_key freed', array_key_exists('active_key', $ma) && $ma['active_key'] === null);
    $chk('teardown: m-a left_at set', ! empty($ma['left_at']));
    $chk('teardown: m-a leave_reason carries reason', ($ma['leave_reason'] ?? '') === 'teardown: account.suspended');
    $chk('teardown: m-a effective_to backfilled', ! empty($ma['effective_to']));
    $chk('teardown: m-b now ended', ($mb['status'] ?? '') === 'ended');
    $chk('teardown: history row untouched', $rowById($db, 'm-hist')['status'] === 'ended'
        && $rowById($db, 'm-hist')['effective_to'] === '2020-01-01 00:00:00');
    $chk('teardown: other person untouched', $rowById($db, 'm-other')['status'] === 'active'
        && $rowById($db, 'm-other')['active_key'] === 'k-other');
    $chk('teardown: other org untouched', $rowById($db, 'm-otherorg')['status'] === 'active');
    $chk('teardown: one membership event per ended row', count($db->rows['group_membership_events']) === 2);
    $chk('teardown: events are "left"', array_values(array_unique(array_map(fn ($e) => $e['action'], $db->rows['group_membership_events']))) === ['left']);
    $chk('teardown: one audit record per ended row', count($audit->records) === 2);
    $chk('teardown: audit action = group.membership.left', ($audit->records[0]['action'] ?? '') === 'group.membership.left');
    $chk('teardown: audit marks teardown', ($audit->records[0]['metadata']['teardown'] ?? false) === true);

    // idempotent re-run
    $again = $svc->endActiveForSubject($ORG, 'gone', 'account.suspended');
    $chk('teardown: re-run ends 0 (idempotent)', $again === 0, (string) $again);

    // guards
    $chk('teardown: empty user -> 0', $svc->endActiveForSubject($ORG, '', 'r') === 0);
    $chk('teardown: empty org -> 0', $svc->endActiveForSubject('', 'gone', 'r') === 0);

    // ======================= MERGE ==========================================
    $db    = new BaseConnection();
    $audit = new AuditLogger();
    // loser holds: g1/member (survivor free), g2/leader (survivor COLLIDES),
    //   two active rows for g4/member (intra-loser dup), one historical g5 row.
    $db->rows['group_members'] = [
        ['id' => 'L-free', 'organization_id' => $ORG, 'group_id' => 'g1', 'user_id' => 'loser', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => $activeKey('loser', 'g1', 'member')],
        ['id' => 'L-collide', 'organization_id' => $ORG, 'group_id' => 'g2', 'user_id' => 'loser', 'membership_type' => 'leader', 'status' => 'active', 'effective_to' => null, 'active_key' => $activeKey('loser', 'g2', 'leader')],
        ['id' => 'L-dup1', 'organization_id' => $ORG, 'group_id' => 'g4', 'user_id' => 'loser', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => $activeKey('loser', 'g4', 'member')],
        ['id' => 'L-dup2', 'organization_id' => $ORG, 'group_id' => 'g4', 'user_id' => 'loser', 'membership_type' => 'member', 'status' => 'active', 'effective_to' => null, 'active_key' => 'dup2-legacy'],
        ['id' => 'L-hist', 'organization_id' => $ORG, 'group_id' => 'g5', 'user_id' => 'loser', 'membership_type' => 'member', 'status' => 'ended', 'effective_to' => '2019-01-01 00:00:00', 'active_key' => null],
        // survivor already leads g2 (the collision).
        ['id' => 'S-g2', 'organization_id' => $ORG, 'group_id' => 'g2', 'user_id' => 'surv', 'membership_type' => 'leader', 'status' => 'active', 'effective_to' => null, 'active_key' => $activeKey('surv', 'g2', 'leader')],
    ];
    $db->rows['group_membership_events'] = [];

    $svc = $svcOf($db, $audit);
    $out = $svc->reassignForMerge($ORG, 'loser', 'surv');

    $chk('merge: repointed = 2 (g1 + first g4)', ($out['repointed'] ?? -1) === 2, json_encode($out));
    $chk('merge: superseded = 2 (g2 collision + 2nd g4)', ($out['superseded'] ?? -1) === 2, json_encode($out));
    $chk('merge: history_repointed = 1', ($out['history_repointed'] ?? -1) === 1, json_encode($out));

    $free = $rowById($db, 'L-free');
    $chk('merge: free row now owned by survivor', ($free['user_id'] ?? '') === 'surv');
    $chk('merge: free row active_key recomputed for survivor', ($free['active_key'] ?? '') === $activeKey('surv', 'g1', 'member'));
    $chk('merge: free row still active', ($free['status'] ?? '') === 'active');

    $coll = $rowById($db, 'L-collide');
    $chk('merge: colliding row ended (superseded)', ($coll['status'] ?? '') === 'ended');
    $chk('merge: colliding row active_key freed', array_key_exists('active_key', $coll) && $coll['active_key'] === null);
    $chk('merge: colliding row keeps loser as owner (not stolen)', ($coll['user_id'] ?? '') === 'loser');
    $chk('merge: survivor own g2 membership untouched', $rowById($db, 'S-g2')['status'] === 'active'
        && $rowById($db, 'S-g2')['user_id'] === 'surv');

    $dup1 = $rowById($db, 'L-dup1');
    $dup2 = $rowById($db, 'L-dup2');
    $repointedDup = ($dup1['status'] === 'active' && $dup1['user_id'] === 'surv') ? $dup1 : $dup2;
    $supersededDup = $repointedDup === $dup1 ? $dup2 : $dup1;
    $chk('merge: one g4 dup re-points to survivor', $repointedDup['user_id'] === 'surv' && $repointedDup['status'] === 'active');
    $chk('merge: the other g4 dup is superseded', $supersededDup['status'] === 'ended' && $supersededDup['active_key'] === null);

    $hist = $rowById($db, 'L-hist');
    $chk('merge: historical row re-points owner', ($hist['user_id'] ?? '') === 'surv');
    $chk('merge: historical row stays ended', ($hist['status'] ?? '') === 'ended');
    $chk('merge: historical row active_key stays NULL', array_key_exists('active_key', $hist) && $hist['active_key'] === null);

    // no-op guards
    $chk('merge: loser==survivor -> zero', $svc->reassignForMerge($ORG, 'x', 'x') === ['repointed' => 0, 'superseded' => 0, 'history_repointed' => 0]);
    $chk('merge: empty -> zero', $svc->reassignForMerge('', 'a', 'b') === ['repointed' => 0, 'superseded' => 0, 'history_repointed' => 0]);

    // ======================= RESTORE (M10 inverse) ==========================
    $db    = new BaseConnection();
    $audit = new AuditLogger();
    $activeKey2 = static fn (string $u, string $g, string $t): string => hash('sha256', $u . ':' . $g . ':' . $t);
    $db->rows['group_members'] = [
        // ended BY teardown — should be restored.
        ['id' => 'R-td1', 'organization_id' => $ORG, 'group_id' => 'g1', 'user_id' => 'back', 'membership_type' => 'member', 'status' => 'ended', 'left_at' => '2026-01-01 00:00:00', 'leave_reason' => 'teardown: account.suspended', 'effective_to' => '2026-01-01 00:00:00', 'active_key' => null],
        ['id' => 'R-td2', 'organization_id' => $ORG, 'group_id' => 'g2', 'user_id' => 'back', 'membership_type' => 'leader', 'status' => 'ended', 'left_at' => '2026-01-01 00:00:00', 'leave_reason' => 'teardown: account.suspended', 'effective_to' => '2026-01-01 00:00:00', 'active_key' => null],
        // ended by a MEMBER-initiated leave — must NOT be resurrected.
        ['id' => 'R-left', 'organization_id' => $ORG, 'group_id' => 'g3', 'user_id' => 'back', 'membership_type' => 'member', 'status' => 'ended', 'left_at' => '2025-06-01 00:00:00', 'leave_reason' => 'moved away', 'effective_to' => '2025-06-01 00:00:00', 'active_key' => null],
        // teardown-ended, but the person RE-JOINED this exact slot while away —
        // the newer active row wins; this teardown row stays ended (deduped).
        ['id' => 'R-tddup', 'organization_id' => $ORG, 'group_id' => 'g4', 'user_id' => 'back', 'membership_type' => 'member', 'status' => 'ended', 'left_at' => '2026-01-01 00:00:00', 'leave_reason' => 'teardown: account.suspended', 'effective_to' => '2026-01-01 00:00:00', 'active_key' => null],
        ['id' => 'R-rejoin', 'organization_id' => $ORG, 'group_id' => 'g4', 'user_id' => 'back', 'membership_type' => 'member', 'status' => 'active', 'left_at' => null, 'leave_reason' => null, 'effective_to' => null, 'active_key' => $activeKey2('back', 'g4', 'member')],
        // another person's teardown row — never touched.
        ['id' => 'R-other', 'organization_id' => $ORG, 'group_id' => 'g1', 'user_id' => 'someone', 'membership_type' => 'member', 'status' => 'ended', 'left_at' => '2026-01-01 00:00:00', 'leave_reason' => 'teardown: account.suspended', 'effective_to' => '2026-01-01 00:00:00', 'active_key' => null],
    ];
    $db->rows['group_membership_events'] = [];

    $svc      = $svcOf($db, $audit);
    $restored = $svc->restoreForSubject($ORG, 'back');

    $chk('restore: count = 2 (both teardown rows, not the dup)', $restored === 2, (string) $restored);
    $r1 = $rowById($db, 'R-td1');
    $r2 = $rowById($db, 'R-td2');
    $chk('restore: R-td1 active again', ($r1['status'] ?? '') === 'active');
    $chk('restore: R-td1 leave_reason cleared', array_key_exists('leave_reason', $r1) && $r1['leave_reason'] === null);
    $chk('restore: R-td1 left_at cleared', array_key_exists('left_at', $r1) && $r1['left_at'] === null);
    $chk('restore: R-td1 active_key recomputed', ($r1['active_key'] ?? '') === $activeKey2('back', 'g1', 'member'));
    $chk('restore: R-td2 active again', ($r2['status'] ?? '') === 'active');
    $chk('restore: member-initiated leave NOT resurrected', $rowById($db, 'R-left')['status'] === 'ended');
    $chk('restore: re-joined slot dup stays ended', $rowById($db, 'R-tddup')['status'] === 'ended');
    $chk('restore: the re-join row untouched + still active', $rowById($db, 'R-rejoin')['status'] === 'active');
    $chk('restore: other person untouched', $rowById($db, 'R-other')['status'] === 'ended');
    $chk('restore: one event per restored row', count($db->rows['group_membership_events']) === 2);
    $chk('restore: events are "joined"', array_values(array_unique(array_map(fn ($e) => $e['action'], $db->rows['group_membership_events']))) === ['joined']);

    // idempotent re-run restores 0 (rows are active now / dup still guarded).
    $again2 = $svc->restoreForSubject($ORG, 'back');
    $chk('restore: re-run restores 0 (idempotent)', $again2 === 0, (string) $again2);
    // guards
    $chk('restore: empty user -> 0', $svc->restoreForSubject($ORG, '') === 0);
    $chk('restore: empty org -> 0', $svc->restoreForSubject('', 'back') === 0);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
