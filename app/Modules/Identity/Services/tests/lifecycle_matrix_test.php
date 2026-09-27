<?php

declare(strict_types=1);

/**
 * AccountLifecycleService ID5 test — transition matrix + merge maker-checker.
 *
 * The ID1 emitter test covers the outbox staging; this closes the remaining ID5
 * gap: the STATE MATRIX (legal / illegal / terminal / no-change) with its
 * revocation cascade, and the MERGE SoD path (self-approval denied, PDP denial,
 * terminal refused, bad pair, bad state). Framework-free in-memory fakes.
 *
 *   php app/Modules/Identity/Services/tests/lifecycle_matrix_test.php
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

        public function orderBy($k, $d = 'ASC')
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
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

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Shared\Messaging {
    class OutboxService
    {
        public array $staged = [];

        public function stage(string $a, string $b, string $topic, array $payload, ?string $org = null, array $h = []): string
        {
            $this->staged[] = ['topic' => $topic, 'payload' => $payload];

            return 'ob';
        }
    }
}

namespace WBS\Identity\Services {
    class SessionService
    {
        public int $revoked = 0;

        public function revokeAllForUser(string $userId): void
        {
            $this->revoked++;
        }
    }
    class TokenService
    {
        public int $revoked = 0;

        public function revokeAllForUser(string $userId): void
        {
            $this->revoked++;
        }
    }
}

namespace WBS\Audit\Services {
    class AuditLogger
    {
        public function record(string $organizationId, array $entry): void
        {
        }
    }
}

namespace WBS\AccessControl\Services {
    class AuthorizationService
    {
        // Toggle to simulate PDP allow/deny for merge approval.
        public bool $permit = true;

        public function isAllowed($r): bool
        {
            return $this->permit;
        }

        public function decide($r)
        {
            return new \Fake\Decision($this->permit);
        }
    }
}

namespace WBS\AccessControl\Policy {
    class AccessRequest
    {
        public function __construct(...$args)
        {
        }
    }
}

namespace Fake {
    class Decision
    {
        public function __construct(private bool $ok)
        {
        }

        public function isPermitted(): bool
        {
            return $this->ok;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\AccessControl\Services\AuthorizationService;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Identity\Services\AccountLifecycleService;
    use WBS\Identity\Services\SessionService;
    use WBS\Identity\Services\TokenService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Identity/Services/AccountLifecycleService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';

    $mk = static function (string $status, ?AuthorizationService $pdp = null) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['users'] = [[
            'id' => 'u-1', 'organization_id' => $ORG, 'status' => $status,
            'email' => 'a@x.io', 'phone' => '+100', 'password_hash' => 'h',
        ]];
        $db->rows['account_state_transitions'] = [];
        $sess = new SessionService();
        $tok  = new TokenService();
        $svc  = new AccountLifecycleService(
            $db, new Clock(), $sess, $tok, new AuditLogger(),
            $pdp ?? new AuthorizationService(), new OutboxService(),
        );

        return [$svc, $db, $sess, $tok];
    };
    $statusOf = static fn (BaseConnection $db, string $id) => (string) (array_values(array_filter($db->rows['users'], fn ($u) => $u['id'] === $id))[0]['status'] ?? '');

    // ===================== TRANSITION MATRIX ================================

    // Legal forward moves.
    [$svc, $db] = $mk('active');
    $chk('active -> suspended legal', $svc->suspend($ORG, 'u-1', 'r')->ok && $statusOf($db, 'u-1') === 'suspended');

    [$svc, $db] = $mk('suspended');
    $chk('suspended -> active legal', $svc->reactivate($ORG, 'u-1', 'r')->ok && $statusOf($db, 'u-1') === 'active');

    [$svc, $db] = $mk('locked');
    $chk('locked -> active legal', $svc->reactivate($ORG, 'u-1', 'r')->ok);

    // Illegal jumps.
    [$svc, $db] = $mk('locked');
    $r = $svc->transition($ORG, 'u-1', 'anonymized', 'r'); // locked cannot anonymize directly
    $chk('locked -> anonymized illegal', $r->code === 'ILLEGAL_TRANSITION', (string) $r->code);
    $chk('locked unchanged after illegal', $statusOf($db, 'u-1') === 'locked');

    [$svc, $db] = $mk('prospect');
    $chk('prospect -> suspended illegal', $svc->transition($ORG, 'u-1', 'suspended', 'r')->code === 'ILLEGAL_TRANSITION');

    // Terminal states admit nothing.
    [$svc, $db] = $mk('anonymized');
    $chk('anonymized -> active illegal (terminal)', $svc->transition($ORG, 'u-1', 'active', 'r')->code === 'ILLEGAL_TRANSITION');
    [$svc, $db] = $mk('merged');
    $chk('merged -> active illegal (terminal)', $svc->transition($ORG, 'u-1', 'active', 'r')->code === 'ILLEGAL_TRANSITION');

    // No-change + bad input guards.
    [$svc, $db] = $mk('active');
    $chk('active -> active is NO_CHANGE', $svc->transition($ORG, 'u-1', 'active', 'r')->code === 'NO_CHANGE');
    $chk('empty reason -> 422', $svc->transition($ORG, 'u-1', 'suspended', '  ')->code === 'REASON_REQUIRED');
    $chk('unknown status -> 422', $svc->transition($ORG, 'u-1', 'zombie', 'r')->code === 'BAD_STATUS');
    $chk('missing user -> not found', $svc->transition($ORG, 'ghost', 'suspended', 'r')->code === 'USER_NOT_FOUND');

    // Revocation cascade on security-relevant states, and NOT on a benign move.
    [$svc, $db, $sess, $tok] = $mk('active');
    $svc->deactivate($ORG, 'u-1', 'r');
    $chk('deactivate revokes sessions', $sess->revoked === 1);
    $chk('deactivate revokes tokens', $tok->revoked === 1);

    [$svc, $db, $sess, $tok] = $mk('pending_verification');
    $svc->transition($ORG, 'u-1', 'active', 'r'); // activation is not a revoke state
    $chk('activation does NOT revoke', $sess->revoked === 0 && $tok->revoked === 0);

    // Anonymize scrubs PII.
    [$svc, $db] = $mk('active');
    $svc->anonymize($ORG, 'u-1', 'gdpr');
    $u = array_values(array_filter($db->rows['users'], fn ($x) => $x['id'] === 'u-1'))[0];
    $chk('anonymize clears email', array_key_exists('email', $u) && $u['email'] === null);
    $chk('anonymize tags display_name', str_starts_with((string) ($u['display_name'] ?? ''), 'anon-'));

    // ===================== MERGE MAKER-CHECKER =============================

    $mkMerge = static function (array $overrides = []) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['users'] = [
            ['id' => 'primary', 'organization_id' => $ORG, 'status' => $overrides['primary_status'] ?? 'active', 'email' => 'p@x.io', 'phone' => '+1', 'password_hash' => 'h'],
            ['id' => 'dup', 'organization_id' => $ORG, 'status' => $overrides['dup_status'] ?? 'active', 'email' => 'd@x.io', 'phone' => '+2', 'password_hash' => 'h'],
        ];
        $db->rows['account_state_transitions'] = [];
        $db->rows['identity_merge_requests']   = [];
        $db->rows['identity_merge_reviews']    = [];
        $pdp = new AuthorizationService();
        $svc = new AccountLifecycleService(
            $db, new Clock(), new SessionService(), new TokenService(),
            new AuditLogger(), $pdp, new OutboxService(),
        );

        return [$svc, $db, $pdp];
    };

    // Submit guards.
    [$svc] = $mkMerge();
    $chk('merge empty reason -> 422', $svc->submitMerge($ORG, 'primary', 'dup', 'req', ' ')->code === 'REASON_REQUIRED');
    $chk('merge same id -> bad pair', $svc->submitMerge($ORG, 'primary', 'primary', 'req', 'r')->code === 'BAD_PAIR');
    [$svc] = $mkMerge(['dup_status' => 'merged']);
    $chk('merge with terminal dup -> 409', $svc->submitMerge($ORG, 'primary', 'dup', 'req', 'r')->code === 'TERMINAL_STATE');

    // Happy submit + SoD self-approval denial.
    [$svc, $db, $pdp] = $mkMerge();
    $sub = $svc->submitMerge($ORG, 'primary', 'dup', 'req-1', 'dupe account');
    $chk('submit ok pending', $sub->ok && ($sub->data['status'] ?? '') === 'pending');
    $mrid = (string) $sub->data['merge_request_id'];

    $self = $svc->approveMerge($ORG, $mrid, 'req-1'); // same as requester
    $chk('self-approval denied (SoD)', $self->code === 'SOD_SELF_APPROVAL', (string) $self->code);
    $chk('dup still active after self-approval', $statusOf($db, 'dup') === 'active');

    // PDP denial.
    [$svc, $db, $pdp] = $mkMerge();
    $mrid = (string) $svc->submitMerge($ORG, 'primary', 'dup', 'req-1', 'r')->data['merge_request_id'];
    $pdp->permit = false;
    $chk('pdp denies approval', $svc->approveMerge($ORG, $mrid, 'checker')->code === 'MERGE_APPROVE_DENIED');
    $chk('dup untouched on pdp deny', $statusOf($db, 'dup') === 'active');

    // Valid approval by a different actor -> dup merged into primary.
    [$svc, $db, $pdp] = $mkMerge();
    $mrid = (string) $svc->submitMerge($ORG, 'primary', 'dup', 'req-1', 'r')->data['merge_request_id'];
    $ap = $svc->approveMerge($ORG, $mrid, 'checker-2');
    $chk('approval ok by different actor', $ap->ok === true, (string) ($ap->code ?? ''));
    $chk('dup transitioned to merged', $statusOf($db, 'dup') === 'merged');
    $chk('dup merged_into primary', (string) (array_values(array_filter($db->rows['users'], fn ($u) => $u['id'] === 'dup'))[0]['merged_into_id'] ?? '') === 'primary');
    $chk('approve is honest: belongings not repointed', ($ap->data['belongings_repointed'] ?? null) === false);

    // Approve a non-pending request -> bad state.
    $chk('re-approve -> bad state', $svc->approveMerge($ORG, $mrid, 'checker-2')->code === 'BAD_STATE');
    $chk('approve missing -> not found', $svc->approveMerge($ORG, 'nope', 'checker-2')->code === 'MERGE_NOT_FOUND');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
