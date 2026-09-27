<?php

declare(strict_types=1);

/**
 * GrantCascadeService test (Theme B consumer — AC3).
 *
 * Proves the ACL teardown cascade over an in-memory DB fake:
 *   - active role assignments for the subject -> revoked;
 *   - delegations the subject RECEIVED or GRANTED -> revoked, INCLUDING their
 *     sub-delegation subtrees (a child cannot outlive its parent);
 *   - active break-glass sessions for the subject -> expired;
 *   - OTHER subjects' grants are untouched;
 *   - already-revoked rows are left alone (idempotent re-run is a no-op);
 *   - a system-authority audit row is written;
 *   - merge cascade revokes the LOSER's grants (never copies to survivor);
 *   - empty org/subject is rejected.
 *
 *   php app/Modules/AccessControl/Services/tests/grant_cascade_test.php
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
        private array $eq = [];
        private array $in = [];
        /** @var list<array{0:string,1:string}> OR-group of equals */
        private array $orEq = [];
        private bool $inOrGroup = false;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            if ($this->inOrGroup) {
                $this->orEq[] = [$k, (string) $v];
            } else {
                $this->eq[$k] = $v;
            }

            return $this;
        }

        public function orWhere($k, $v = null)
        {
            $this->orEq[] = [trim((string) $k), (string) $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function groupStart()
        {
            $this->inOrGroup = true;

            return $this;
        }

        public function groupEnd()
        {
            $this->inOrGroup = false;

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

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;
            $this->db->affected = 1;

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }
            if ($this->orEq !== []) {
                $any = false;
                foreach ($this->orEq as [$k, $v]) {
                    if ((string) ($r[$k] ?? '') === $v) {
                        $any = true;
                        break;
                    }
                }
                if (! $any) {
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

    $ORG = 'org-1';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        // subject u1 (torn down), u2 (bystander), u3 (survivor / other).
        $db->rows['role_assignments'] = [
            ['id' => 'ra-1', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'active'],
            ['id' => 'ra-2', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'revoked'], // already gone
            ['id' => 'ra-3', 'organization_id' => $ORG, 'subject_id' => 'u2', 'status' => 'active'],   // bystander
        ];
        // Delegations: d1 received by u1 (root) -> d1a child; d2 granted by u1 (root) -> d2a child;
        // d3 unrelated (u2->u3).
        $db->rows['delegations'] = [
            ['id' => 'd1', 'organization_id' => $ORG, 'delegator_id' => 'u9', 'delegate_id' => 'u1', 'parent_id' => null, 'status' => 'active'],
            ['id' => 'd1a', 'organization_id' => $ORG, 'delegator_id' => 'u1', 'delegate_id' => 'u5', 'parent_id' => 'd1', 'status' => 'active'],
            ['id' => 'd2', 'organization_id' => $ORG, 'delegator_id' => 'u1', 'delegate_id' => 'u6', 'parent_id' => null, 'status' => 'active'],
            ['id' => 'd2a', 'organization_id' => $ORG, 'delegator_id' => 'u6', 'delegate_id' => 'u7', 'parent_id' => 'd2', 'status' => 'active'],
            ['id' => 'd3', 'organization_id' => $ORG, 'delegator_id' => 'u2', 'delegate_id' => 'u3', 'parent_id' => null, 'status' => 'active'],
        ];
        $db->rows['break_glass_sessions'] = [
            ['id' => 'bg-1', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'active'],
            ['id' => 'bg-2', 'organization_id' => $ORG, 'subject_id' => 'u2', 'status' => 'active'],
        ];

        return $db;
    };

    $statusOf = static function (BaseConnection $db, string $table, string $id): string {
        foreach ($db->rows[$table] as $r) {
            if ($r['id'] === $id) {
                return (string) $r['status'];
            }
        }

        return '';
    };

    // ---- deactivate teardown ------------------------------------------------
    $db = $seed();
    $audit = new AuditLogger();
    $svc = new GrantCascadeService($db, new Clock(), $audit);
    $res = $svc->onAccountTornDown($ORG, 'u1', 'account.deactivated');

    $chk('cascade ok', $res->ok);
    $chk('subject active assignment revoked', $statusOf($db, 'role_assignments', 'ra-1') === 'revoked');
    $chk('bystander assignment untouched', $statusOf($db, 'role_assignments', 'ra-3') === 'active');
    $chk('received delegation revoked', $statusOf($db, 'delegations', 'd1') === 'revoked');
    $chk('received delegation SUBTREE revoked', $statusOf($db, 'delegations', 'd1a') === 'revoked');
    $chk('granted delegation revoked', $statusOf($db, 'delegations', 'd2') === 'revoked');
    $chk('granted delegation SUBTREE revoked', $statusOf($db, 'delegations', 'd2a') === 'revoked');
    $chk('unrelated delegation untouched', $statusOf($db, 'delegations', 'd3') === 'active');
    $chk('subject break-glass expired', $statusOf($db, 'break_glass_sessions', 'bg-1') === 'expired');
    $chk('bystander break-glass untouched', $statusOf($db, 'break_glass_sessions', 'bg-2') === 'active');

    $chk('reports 1 revoked assignment', ($res->data['revoked_assignments'] ?? null) === 1);
    $chk('reports 4 revoked delegations', ($res->data['revoked_delegations'] ?? null) === 4, (string) ($res->data['revoked_delegations'] ?? -1));
    $chk('reports 1 closed break-glass', ($res->data['closed_break_glass'] ?? null) === 1);

    $teardownAudit = array_values(array_filter($audit->records, fn ($r) => ($r['entry']['action'] ?? '') === 'acl.grant.cascade_teardown'));
    $chk('audit row written', count($teardownAudit) === 1);
    $chk('audit is system authority', ($teardownAudit[0]['entry']['actor_type'] ?? '') === 'system'
        && array_key_exists('actor_id', $teardownAudit[0]['entry'])
        && $teardownAudit[0]['entry']['actor_id'] === null);
    $chk('audit reason = topic', ($teardownAudit[0]['entry']['metadata']['reason'] ?? '') === 'account.deactivated');

    // ---- idempotent re-run --------------------------------------------------
    $res2 = $svc->onAccountTornDown($ORG, 'u1', 'account.deactivated');
    $chk('re-run is a no-op (0 assignments)', ($res2->data['revoked_assignments'] ?? null) === 0);
    $chk('re-run is a no-op (0 delegations)', ($res2->data['revoked_delegations'] ?? null) === 0);
    $chk('re-run is a no-op (0 break-glass)', ($res2->data['closed_break_glass'] ?? null) === 0);

    // ---- merge revokes the LOSER (not the survivor) -------------------------
    $db3 = $seed();
    $svc3 = new GrantCascadeService($db3, new Clock(), new AuditLogger());
    $svc3->onAccountTornDown($ORG, 'u1', 'account.merged');
    $chk('merge revokes loser assignment', $statusOf($db3, 'role_assignments', 'ra-1') === 'revoked');
    $chk('merge leaves survivor-side (u3) delegation intact', $statusOf($db3, 'delegations', 'd3') === 'active');

    // ---- bad input ----------------------------------------------------------
    $bad = $svc->onAccountTornDown('', 'u1', 'account.deactivated');
    $chk('empty org rejected', ! $bad->ok && $bad->code === 'CASCADE_BAD_INPUT');
    $bad2 = $svc->onAccountTornDown($ORG, '', 'account.deactivated');
    $chk('empty subject rejected', ! $bad2->ok && $bad2->code === 'CASCADE_BAD_INPUT');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
