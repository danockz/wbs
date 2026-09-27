<?php

declare(strict_types=1);

/**
 * GrantCascadeService::onGroupTornDown test (Theme B group-half — AC9).
 *
 * When a group is dissolved / merged away, authority SCOPED TO THAT GROUP must
 * stop resolving. Over an in-memory DB fake, proves:
 *
 *   - active role_assignments scoped to the dead group -> revoked;
 *   - active delegations scoped to the dead group -> revoked, INCLUDING their
 *     sub-delegation subtrees;
 *   - active break_glass_sessions scoped to the dead group -> expired;
 *   - live (pending/approved) access_requests scoped to the dead group -> revoked;
 *   - grants scoped to OTHER groups (and org-wide) are untouched;
 *   - the dead group is pruned from every grant_scope_groups set; a groups-mode
 *     grant left with an EMPTY set is revoked, one still naming a live group is kept;
 *   - a system-authority audit row is written;
 *   - idempotent re-run is a no-op; empty org/group rejected.
 *
 *   php app/Modules/AccessControl/Services/tests/grant_group_cascade_test.php
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

        public function tableExists(string $t): bool
        {
            return isset($this->rows[$t]);
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
        private array $eq = [];
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
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

        public function get(): RS
        {
            return new RS($this->matchingRows());
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
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

        public function delete(): bool
        {
            $before = count($this->db->rows[$this->t] ?? []);
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));
            $this->db->affected = $before - count($this->db->rows[$this->t]);

            return true;
        }

        private function matchingRows(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ($v === null) {
                    if (($r[$k] ?? null) !== null) {
                        return false;
                    }

                    continue;
                }
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Audit\Services {
    class AuditLogger
    {
        /** @var list<array{org:string,entry:array}> */
        public array $records = [];

        public function record(string $organizationId, array $entry)
        {
            $this->records[] = ['org' => $organizationId, 'entry' => $entry];

            return \WBS\Shared\Support\Result::ok();
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Services\GrantCascadeService;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/AccessControl/Services/GrantCascadeService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG  = 'org-1';
    $DEAD = 'g-dead';
    $LIVE = 'g-live';

    $seed = static function () use ($ORG, $DEAD, $LIVE): BaseConnection {
        $db = new BaseConnection();
        $db->rows['role_assignments'] = [
            ['id' => 'ra-dead', 'organization_id' => $ORG, 'subject_id' => 'u1', 'scope_group_id' => $DEAD, 'scope_mode' => 'self', 'status' => 'active'],
            ['id' => 'ra-live', 'organization_id' => $ORG, 'subject_id' => 'u2', 'scope_group_id' => $LIVE, 'scope_mode' => 'self', 'status' => 'active'],
            ['id' => 'ra-org',  'organization_id' => $ORG, 'subject_id' => 'u3', 'scope_group_id' => null, 'scope_mode' => 'self', 'status' => 'active'],
            // groups-mode grants whose sets we prune below.
            ['id' => 'ra-set-empty', 'organization_id' => $ORG, 'subject_id' => 'u4', 'scope_group_id' => null, 'scope_mode' => 'groups', 'status' => 'active'],
            ['id' => 'ra-set-keep',  'organization_id' => $ORG, 'subject_id' => 'u5', 'scope_group_id' => null, 'scope_mode' => 'groups', 'status' => 'active'],
        ];
        $db->rows['delegations'] = [
            // scoped to the dead group (root) -> child d-dead-a (subtree).
            ['id' => 'd-dead', 'organization_id' => $ORG, 'scope_group_id' => $DEAD, 'scope_mode' => 'self', 'parent_id' => null, 'status' => 'active'],
            ['id' => 'd-dead-a', 'organization_id' => $ORG, 'scope_group_id' => null, 'scope_mode' => 'self', 'parent_id' => 'd-dead', 'status' => 'active'],
            // scoped to a live group -> untouched.
            ['id' => 'd-live', 'organization_id' => $ORG, 'scope_group_id' => $LIVE, 'scope_mode' => 'self', 'parent_id' => null, 'status' => 'active'],
        ];
        $db->rows['break_glass_sessions'] = [
            ['id' => 'bg-dead', 'organization_id' => $ORG, 'subject_id' => 'u1', 'scope_group_id' => $DEAD, 'status' => 'active'],
            ['id' => 'bg-live', 'organization_id' => $ORG, 'subject_id' => 'u2', 'scope_group_id' => $LIVE, 'status' => 'active'],
        ];
        $db->rows['access_requests'] = [
            ['id' => 'ar-pending', 'organization_id' => $ORG, 'scope_group_id' => $DEAD, 'status' => 'pending'],
            ['id' => 'ar-approved', 'organization_id' => $ORG, 'scope_group_id' => $DEAD, 'status' => 'approved'],
            ['id' => 'ar-rejected', 'organization_id' => $ORG, 'scope_group_id' => $DEAD, 'status' => 'rejected'], // terminal, leave
            ['id' => 'ar-live', 'organization_id' => $ORG, 'scope_group_id' => $LIVE, 'status' => 'pending'],
        ];
        $db->rows['grant_scope_groups'] = [
            // ra-set-empty ONLY names the dead group -> becomes empty -> revoked.
            ['id' => 'gsg-1', 'organization_id' => $ORG, 'grant_type' => 'role_assignment', 'grant_id' => 'ra-set-empty', 'group_id' => $DEAD],
            // ra-set-keep names dead + a live group -> kept after prune.
            ['id' => 'gsg-2', 'organization_id' => $ORG, 'grant_type' => 'role_assignment', 'grant_id' => 'ra-set-keep', 'group_id' => $DEAD],
            ['id' => 'gsg-3', 'organization_id' => $ORG, 'grant_type' => 'role_assignment', 'grant_id' => 'ra-set-keep', 'group_id' => $LIVE],
        ];

        return $db;
    };

    $statusOf = static function (BaseConnection $db, string $table, string $id): string {
        foreach ($db->rows[$table] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['status'];
            }
        }

        return '(missing)';
    };

    // ---- dissolve ----------------------------------------------------------
    $db = $seed();
    $audit = new AuditLogger();
    $svc = new GrantCascadeService($db, new Clock(), $audit);
    $res = $svc->onGroupTornDown($ORG, $DEAD, 'group.dissolved');

    $chk('cascade ok', $res->ok === true);
    $chk('revoked_assignments = 1 (exact scope)', $res->data['revoked_assignments'] === 1, json_encode($res->data));
    $chk('ra-dead -> revoked', $statusOf($db, 'role_assignments', 'ra-dead') === 'revoked');
    $chk('ra-live (other group) untouched', $statusOf($db, 'role_assignments', 'ra-live') === 'active');
    $chk('ra-org (org-wide) untouched', $statusOf($db, 'role_assignments', 'ra-org') === 'active');

    $chk('revoked_delegations = 2 (root + subtree)', $res->data['revoked_delegations'] === 2, json_encode($res->data));
    $chk('d-dead -> revoked', $statusOf($db, 'delegations', 'd-dead') === 'revoked');
    $chk('d-dead-a (child) -> revoked', $statusOf($db, 'delegations', 'd-dead-a') === 'revoked');
    $chk('d-live untouched', $statusOf($db, 'delegations', 'd-live') === 'active');

    $chk('closed_break_glass = 1', $res->data['closed_break_glass'] === 1);
    $chk('bg-dead -> expired', $statusOf($db, 'break_glass_sessions', 'bg-dead') === 'expired');
    $chk('bg-live untouched', $statusOf($db, 'break_glass_sessions', 'bg-live') === 'active');

    $chk('revoked_requests = 2 (pending+approved)', $res->data['revoked_requests'] === 2, json_encode($res->data));
    $chk('ar-pending -> revoked', $statusOf($db, 'access_requests', 'ar-pending') === 'revoked');
    $chk('ar-approved -> revoked', $statusOf($db, 'access_requests', 'ar-approved') === 'revoked');
    $chk('ar-rejected (terminal) untouched', $statusOf($db, 'access_requests', 'ar-rejected') === 'rejected');
    $chk('ar-live untouched', $statusOf($db, 'access_requests', 'ar-live') === 'pending');

    // multi-group set prune.
    // Two gsg rows named the dead group (gsg-1 on ra-set-empty, gsg-2 on ra-set-keep).
    $chk('pruned_set_rows = 2', $res->data['pruned_set_rows'] === 2, json_encode($res->data));
    $remainingSets = array_column($db->rows['grant_scope_groups'], 'group_id');
    $chk('dead group removed from all sets', ! in_array($DEAD, $remainingSets, true), implode(',', $remainingSets));
    $chk('live group set row kept', in_array($LIVE, $remainingSets, true));
    $chk('revoked_empty_set = 1', $res->data['revoked_empty_set'] === 1, json_encode($res->data));
    $chk('ra-set-empty (now empty) -> revoked', $statusOf($db, 'role_assignments', 'ra-set-empty') === 'revoked');
    $chk('ra-set-keep (still has live) untouched', $statusOf($db, 'role_assignments', 'ra-set-keep') === 'active');

    // audit.
    $chk('one system audit row', count($audit->records) === 1);
    $chk('audit action = group_cascade_teardown', ($audit->records[0]['entry']['action'] ?? '') === 'acl.grant.group_cascade_teardown');
    $chk('audit actor_type = system', ($audit->records[0]['entry']['actor_type'] ?? '') === 'system');
    $chk('audit object = the group', ($audit->records[0]['entry']['object_id'] ?? '') === $DEAD);

    // ---- idempotent re-run -------------------------------------------------
    $res2 = $svc->onGroupTornDown($ORG, $DEAD, 'group.dissolved');
    $chk('re-run revokes 0 assignments', $res2->data['revoked_assignments'] === 0);
    $chk('re-run revokes 0 delegations', $res2->data['revoked_delegations'] === 0);
    $chk('re-run revokes 0 requests', $res2->data['revoked_requests'] === 0);
    $chk('re-run prunes 0 set rows', $res2->data['pruned_set_rows'] === 0);

    // ---- merge behaves like dissolve for the loser group -------------------
    $db = $seed();
    $svc = new GrantCascadeService($db, new Clock(), new AuditLogger());
    $resM = $svc->onGroupTornDown($ORG, $DEAD, 'group.merged');
    $chk('merge revokes the loser group scoped assignment', $statusOf($db, 'role_assignments', 'ra-dead') === 'revoked');
    $chk('merge does NOT touch the live/survivor group', $statusOf($db, 'role_assignments', 'ra-live') === 'active');

    // ---- guards ------------------------------------------------------------
    $svc = new GrantCascadeService($seed(), new Clock(), new AuditLogger());
    $chk('empty org rejected', $svc->onGroupTornDown('', $DEAD, 'x')->ok === false);
    $chk('empty group rejected', $svc->onGroupTornDown($ORG, '', 'x')->ok === false);

    // ---- no grant_scope_groups table -> graceful ---------------------------
    $dbNoSet = $seed();
    unset($dbNoSet->rows['grant_scope_groups']);
    $svc = new GrantCascadeService($dbNoSet, new Clock(), new AuditLogger());
    $resNo = $svc->onGroupTornDown($ORG, $DEAD, 'group.dissolved');
    $chk('no set table: pruned_set_rows = 0', $resNo->data['pruned_set_rows'] === 0);
    $chk('no set table: still revokes exact-scope assignment', $statusOf($dbNoSet, 'role_assignments', 'ra-dead') === 'revoked');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
