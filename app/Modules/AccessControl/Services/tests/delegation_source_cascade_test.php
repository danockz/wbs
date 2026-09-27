<?php

declare(strict_types=1);

/**
 * Delegation source-grant cascade test (Phase 0: gap AC2).
 *
 * A delegation may be carved from a role_assignment or an access_request. When
 * that SOURCE grant is revoked or lapses, the derived delegation (and the whole
 * sub-delegation chain rooted on it) must not outlive it. Previously delegations
 * rooted on a role/request had parent_id = NULL, so revoke()'s parent_id-walking
 * cascade never reached them -> a bounded privilege-retention window.
 *
 * The fix stamps every delegation with the ROOT source_grant_type/source_grant_id
 * (children inherit their parent's root), so:
 *   - revokeBySourceGrant() flips the whole subtree in one flat WHERE, and
 *   - expireOrphanedBySource() tears down delegations whose source grant is no
 *     longer live during the periodic sweep.
 *
 * This test exercises those two methods directly with the real DelegationService
 * (GroupScopeResolver / GrantScopeWriter are unused by them) over an in-memory
 * DB fake.
 *
 *   php app/Modules/AccessControl/Services/tests/delegation_source_cascade_test.php
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
        /** @var list<callable> */
        private array $raw = [];
        private ?int $limit = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            // Support the two raw predicates AuditLogger/expireLapsed style code uses.
            if ($v === null && str_ends_with($k, 'IS NOT NULL')) {
                $col = trim(substr($k, 0, -strlen('IS NOT NULL')));
                $this->raw[] = static fn (array $r): bool => ($r[$col] ?? null) !== null;

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        // No-op chainables used by AuditLogger.
        public function select($x)
        {
            return $this;
        }

        public function orderBy($x, $y = null)
        {
            return $this;
        }

        public function limit(int $n)
        {
            $this->limit = $n;

            return $this;
        }

        public function get(): RS
        {
            $m = $this->matching();
            if ($this->limit !== null) {
                $m = array_slice($m, 0, $this->limit);
            }

            return new RS($m);
        }

        public function countAllResults(): int
        {
            return count($this->matching());
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

        private function matching(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }
            foreach ($this->raw as $pred) {
                if (! $pred($r)) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Services\DelegationService;
    use WBS\AccessControl\Services\GrantScopeWriter;
    use WBS\Shared\Support\Clock;
    use WBS\Shared\Support\GroupScopeResolver;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/ScopeMode.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/Audit/Services/AuditLogger.php';
    require_once $root . '/app/Modules/AccessControl/Services/GrantScopeWriter.php';
    require_once $root . '/app/Modules/AccessControl/Services/DelegationService.php';

    $passed = 0;
    $failed = 0;
    $check  = static function (string $label, bool $cond) use (&$passed, &$failed): void {
        if ($cond) {
            $passed++;

            return;
        }
        $failed++;
        fwrite(STDERR, "  FAIL: {$label}\n");
    };

    $clock = new Clock();
    $ORG   = 'org-1';

    $makeService = static function (BaseConnection $db) use ($clock): DelegationService {
        $audit = new WBS\Audit\Services\AuditLogger($db, $clock);

        return new DelegationService(
            $db,
            $clock,
            new GroupScopeResolver($db),
            $audit,
            new GrantScopeWriter($db, $clock),
        );
    };

    // Helper: seed a delegation row.
    $seedDelegation = static function (BaseConnection $db, string $id, string $status, ?string $srcType, ?string $srcId, ?string $parentId = null, int $depth = 0) use ($ORG): void {
        $db->rows['delegations'][] = [
            'id'                => $id,
            'organization_id'   => $ORG,
            'parent_id'         => $parentId,
            'source_grant_type' => $srcType,
            'source_grant_id'   => $srcId,
            'depth'             => $depth,
            'status'            => $status,
            'revoked_at'        => null,
            'revoked_by'        => null,
        ];
    };

    // ---------------------------------------------------------------------
    // Scenario 1: revokeBySourceGrant cascades the WHOLE chain rooted on a
    // role_assignment, and leaves unrelated delegations untouched.
    // ---------------------------------------------------------------------
    {
        $db = new BaseConnection();
        // Chain rooted on role_assignment RA1: d1 (from RA1) -> d2 (sub) -> d3 (sub).
        // Every row in the chain shares the ROOT source_grant_id (that's the fix).
        $seedDelegation($db, 'd1', 'active', 'role_assignment', 'RA1', null, 0);
        $seedDelegation($db, 'd2', 'active', 'role_assignment', 'RA1', 'd1', 1);
        $seedDelegation($db, 'd3', 'active', 'role_assignment', 'RA1', 'd2', 2);
        // Unrelated delegation rooted on a different assignment.
        $seedDelegation($db, 'x1', 'active', 'role_assignment', 'RA2', null, 0);

        $svc = $makeService($db);
        $n   = $svc->revokeBySourceGrant($ORG, 'role_assignment', 'RA1', 'actor-1', 'source revoked');

        $check('S1 returns count of the whole chain (3)', $n === 3);

        $byId = [];
        foreach ($db->rows['delegations'] as $r) {
            $byId[$r['id']] = $r;
        }
        $check('S1 d1 revoked', $byId['d1']['status'] === 'revoked');
        $check('S1 d2 (sub) revoked', $byId['d2']['status'] === 'revoked');
        $check('S1 d3 (sub-sub) revoked', $byId['d3']['status'] === 'revoked');
        $check('S1 d1 stamped revoked_by', $byId['d1']['revoked_by'] === 'actor-1');
        $check('S1 d1 stamped revoked_at', $byId['d1']['revoked_at'] !== null);
        $check('S1 unrelated RA2 delegation untouched', $byId['x1']['status'] === 'active');

        // Idempotent: a second call flips nothing.
        $n2 = $svc->revokeBySourceGrant($ORG, 'role_assignment', 'RA1', 'actor-1', 'again');
        $check('S1 idempotent second call returns 0', $n2 === 0);
    }

    // ---------------------------------------------------------------------
    // Scenario 2: cascade is org-scoped and type-scoped.
    // ---------------------------------------------------------------------
    {
        $db = new BaseConnection();
        $seedDelegation($db, 'd1', 'active', 'access_request', 'AR1', null, 0);
        // Same id value but a role_assignment: must NOT be touched by an access_request cascade.
        $seedDelegation($db, 'd2', 'active', 'role_assignment', 'AR1', null, 0);

        $svc = $makeService($db);
        $n   = $svc->revokeBySourceGrant($ORG, 'access_request', 'AR1', 'actor-1', 'req revoked');

        $check('S2 access_request cascade returns 1', $n === 1);
        $byId = [];
        foreach ($db->rows['delegations'] as $r) {
            $byId[$r['id']] = $r;
        }
        $check('S2 access_request-rooted delegation revoked', $byId['d1']['status'] === 'revoked');
        $check('S2 same-id role_assignment delegation untouched', $byId['d2']['status'] === 'active');
    }

    // ---------------------------------------------------------------------
    // Scenario 3: expireOrphanedBySource — sweep path. A delegation whose source
    // grant is no longer live (role_assignment not 'active' / access_request not
    // 'approved') is expired; one whose source is still live is left alone.
    // ---------------------------------------------------------------------
    {
        $db = new BaseConnection();
        // Source grants.
        $db->rows['role_assignments'][] = ['id' => 'RA_live', 'status' => 'active'];
        $db->rows['role_assignments'][] = ['id' => 'RA_dead', 'status' => 'expired'];
        $db->rows['access_requests'][]  = ['id' => 'AR_live', 'status' => 'approved'];
        $db->rows['access_requests'][]  = ['id' => 'AR_dead', 'status' => 'expired'];

        $seedDelegation($db, 'live_ra', 'active', 'role_assignment', 'RA_live', null, 0);
        $seedDelegation($db, 'dead_ra', 'active', 'role_assignment', 'RA_dead', null, 0);
        $seedDelegation($db, 'live_ar', 'active', 'access_request', 'AR_live', null, 0);
        $seedDelegation($db, 'dead_ar', 'active', 'access_request', 'AR_dead', null, 0);
        // A delegation whose source grant row is missing entirely -> treated as not live.
        $seedDelegation($db, 'gone', 'active', 'role_assignment', 'RA_missing', null, 0);
        // A legacy self-rooted delegation (source_grant_type='delegation') -> left alone.
        $seedDelegation($db, 'legacy', 'active', 'delegation', 'legacy', null, 0);

        $svc = $makeService($db);
        $n   = $svc->expireOrphanedBySource($ORG, '2026-09-15 12:00:00');

        $byId = [];
        foreach ($db->rows['delegations'] as $r) {
            $byId[$r['id']] = $r;
        }
        $check('S3 live role_assignment delegation kept active', $byId['live_ra']['status'] === 'active');
        $check('S3 dead role_assignment delegation expired', $byId['dead_ra']['status'] === 'revoked');
        $check('S3 live access_request delegation kept active', $byId['live_ar']['status'] === 'active');
        $check('S3 dead access_request delegation expired', $byId['dead_ar']['status'] === 'revoked');
        $check('S3 missing-source delegation expired', $byId['gone']['status'] === 'revoked');
        $check('S3 legacy self-rooted delegation left alone', $byId['legacy']['status'] === 'active');
        $check('S3 returns count of orphans (3)', $n === 3);
    }

    // ---------------------------------------------------------------------
    // Scenario 4: nothing to do -> returns 0, no error.
    // ---------------------------------------------------------------------
    {
        $db  = new BaseConnection();
        $svc = $makeService($db);
        $check('S4 revokeBySourceGrant on empty returns 0', $svc->revokeBySourceGrant($ORG, 'role_assignment', 'NOPE', null, 'x') === 0);
        $check('S4 expireOrphanedBySource on empty returns 0', $svc->expireOrphanedBySource($ORG, '2026-09-15 12:00:00') === 0);
    }

    echo "\n";
    if ($failed === 0) {
        echo "OK  {$passed} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$passed} passed, {$failed} failed\n";
    exit(1);
}
