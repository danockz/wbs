<?php

declare(strict_types=1);

/**
 * ConnectionService IN1/IN2 test — guarded, audited, cascading disable()/revoke().
 *
 * Before: `disable()` did a bare `UPDATE status='disabled'` from ANY state, with
 * no reason/actor/audit, staged NO signal, and left `capability_grants` to
 * descendant groups live; there was no `revoke()` at all. Over an in-memory DB
 * fake + audit/outbox spies, proves:
 *   - disable() from a legal state transitions, records ONE audit entry, stages
 *     `connection.disabled`, and auto-revokes the connection's ACTIVE grants;
 *   - a grant on ANOTHER connection is left alone;
 *   - reason and actor are required (422);
 *   - disable() from draft is an illegal transition (409);
 *   - disable() of an already-disabled connection is an idempotent no-op (no 2nd
 *     audit/outbox);
 *   - revoke() is terminal, emits `connection.revoked`, and can run from
 *     disabled; revoke() from draft is illegal;
 *   - a missing connection -> not found;
 *   - with null audit/outbox deps the transition + cascade still happen (no throw).
 *
 *   php app/Modules/Integrations/Services/tests/connection_teardown_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;
        public bool $failTx = false;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
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
        /** @var array<string,mixed> */
        private array $eq = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f)
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
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Shared\Security {
    // Stand-in for the (final) production SecretBox — unused by disable/revoke,
    // but the vault ctor type-hints it. Declared before the vault is required.
    class SecretBox
    {
        public function encrypt(string $p, string $a = ''): string
        {
            return 'x';
        }

        public function decrypt(string $s, string $a = ''): string
        {
            return 'x';
        }

        public function fingerprint(string $p): string
        {
            return 'fp';
        }

        public function keyId(): string
        {
            return 'k';
        }
    }
}

namespace WBS\Audit\Services {
    class AuditLogger
    {
        /** @var list<array<string,mixed>> */
        public array $entries = [];

        public function record(string $organizationId, array $data): \WBS\Shared\Support\Result
        {
            $this->entries[] = ['org' => $organizationId] + $data;

            return \WBS\Shared\Support\Result::ok(['recorded' => true]);
        }
    }
}

namespace WBS\Shared\Messaging {
    class OutboxService
    {
        /** @var list<array{topic:string,payload:array}> */
        public array $staged = [];

        public function stage(string $a, string $b, string $topic, array $payload, ?string $org = null, array $h = []): string
        {
            $this->staged[] = ['topic' => $topic, 'payload' => $payload];

            return 'ob';
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Audit\Services\AuditLogger;
    use WBS\Integrations\Services\ConnectionService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Integrations/Services/CredentialVault.php';
    require_once $root . '/app/Modules/Integrations/Services/ConnectionService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $ORG = 'org-1';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['integration_connections'] = [
            ['id' => 'conn-1', 'organization_id' => $ORG, 'group_id' => 'g-1', 'category' => 'notification', 'status' => 'active', 'requested_by' => 'u-req'],
            ['id' => 'conn-draft', 'organization_id' => $ORG, 'group_id' => 'g-1', 'category' => 'streaming', 'status' => 'draft', 'requested_by' => 'u-req'],
        ];
        $db->rows['capability_grants'] = [
            ['id' => 'gr-1', 'connection_id' => 'conn-1', 'status' => 'active'],
            ['id' => 'gr-2', 'connection_id' => 'conn-1', 'status' => 'active'],
            ['id' => 'gr-rev', 'connection_id' => 'conn-1', 'status' => 'revoked'],
            ['id' => 'gr-other', 'connection_id' => 'conn-2', 'status' => 'active'],
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
    $mk = static function (BaseConnection $db, ?AuditLogger $a, ?OutboxService $o): ConnectionService {
        // CredentialVault + reliability are unused by disable/revoke; pass a bare
        // vault built on the same db and null reliability.
        $vault = new WBS\Integrations\Services\CredentialVault($db, new WBS\Shared\Security\SecretBox(), new Clock());

        return new ConnectionService($db, new Clock(), $vault, null, $a, $o);
    };

    // ---- disable() happy path ----------------------------------------------
    $db    = $seed();
    $audit = new AuditLogger();
    $ob    = new OutboxService();
    $svc   = $mk($db, $audit, $ob);
    $r     = $svc->disable('conn-1', 'provider compromised', 'admin-1');

    $chk('disable ok', $r->ok === true, (string) ($r->code ?? ''));
    $chk('conn-1 now disabled', $statusOf($db, 'integration_connections', 'conn-1') === 'disabled');
    $chk('disable reports changed', ($r->data['changed'] ?? null) === true);
    $chk('grant gr-1 auto-revoked', $statusOf($db, 'capability_grants', 'gr-1') === 'revoked');
    $chk('grant gr-2 auto-revoked', $statusOf($db, 'capability_grants', 'gr-2') === 'revoked');
    $chk('revoked_grants count = 2', ($r->data['revoked_grants'] ?? -1) === 2, (string) ($r->data['revoked_grants'] ?? -1));
    $chk('other-connection grant untouched', $statusOf($db, 'capability_grants', 'gr-other') === 'active');
    $chk('exactly one audit entry', count($audit->entries) === 1, (string) count($audit->entries));
    $chk('audit action = disabled', ($audit->entries[0]['action'] ?? '') === 'integration.connection.disabled');
    $chk('audit carries reason', ($audit->entries[0]['metadata']['reason'] ?? '') === 'provider compromised');
    $chk('audit carries actor', ($audit->entries[0]['actor_id'] ?? '') === 'admin-1');
    $chk('one outbox signal', count($ob->staged) === 1, (string) count($ob->staged));
    $chk('outbox topic = connection.disabled', ($ob->staged[0]['topic'] ?? '') === 'connection.disabled');
    $chk('outbox payload from/to', ($ob->staged[0]['payload']['from_status'] ?? '') === 'active' && ($ob->staged[0]['payload']['to_status'] ?? '') === 'disabled');

    // ---- idempotent re-disable ---------------------------------------------
    $r2 = $svc->disable('conn-1', 'again', 'admin-1');
    $chk('re-disable ok (no-op)', $r2->ok === true);
    $chk('re-disable reports not changed', ($r2->data['changed'] ?? null) === false);
    $chk('no second audit entry', count($audit->entries) === 1);
    $chk('no second outbox signal', count($ob->staged) === 1);

    // ---- validation ---------------------------------------------------------
    $db  = $seed();
    $svc = $mk($db, new AuditLogger(), new OutboxService());
    $chk('empty reason -> 422', $svc->disable('conn-1', '  ', 'admin-1')->code === 'REASON_REQUIRED');
    $chk('empty actor -> 422', $svc->disable('conn-1', 'x', '')->code === 'ACTOR_REQUIRED');
    $chk('missing connection -> not found', $svc->disable('nope', 'x', 'admin-1')->code === 'CONNECTION_NOT_FOUND');

    // ---- illegal transition from draft -------------------------------------
    $chk('disable from draft -> illegal', $svc->disable('conn-draft', 'x', 'admin-1')->code === 'ILLEGAL_TRANSITION');
    $chk('draft still draft', $statusOf($db, 'integration_connections', 'conn-draft') === 'draft');
    $chk('revoke from draft -> illegal', $svc->revoke('conn-draft', 'x', 'admin-1')->code === 'ILLEGAL_TRANSITION');

    // ---- revoke() from active, and from disabled ---------------------------
    $db  = $seed();
    $ob  = new OutboxService();
    $svc = $mk($db, new AuditLogger(), $ob);
    $rv  = $svc->revoke('conn-1', 'kill switch', 'admin-9');
    $chk('revoke ok', $rv->ok === true, (string) ($rv->code ?? ''));
    $chk('conn-1 revoked', $statusOf($db, 'integration_connections', 'conn-1') === 'revoked');
    $chk('revoke emits connection.revoked', ($ob->staged[0]['topic'] ?? '') === 'connection.revoked');
    $chk('revoke also auto-revoked grants', $statusOf($db, 'capability_grants', 'gr-1') === 'revoked');

    // revoke can run from a disabled connection
    $db2 = $seed();
    $db2->rows['integration_connections'][0]['status'] = 'disabled';
    $svc2 = $mk($db2, new AuditLogger(), new OutboxService());
    $chk('revoke from disabled ok', $svc2->revoke('conn-1', 'escalate', 'admin-9')->ok === true);
    $chk('conn-1 now revoked from disabled', $statusOf($db2, 'integration_connections', 'conn-1') === 'revoked');

    // ---- null audit/outbox deps: still transitions + cascades --------------
    $db3 = $seed();
    $svc3 = $mk($db3, null, null);
    $r3   = $svc3->disable('conn-1', 'no observers wired', 'admin-1');
    $chk('null deps: disable still ok', $r3->ok === true);
    $chk('null deps: still transitions', $statusOf($db3, 'integration_connections', 'conn-1') === 'disabled');
    $chk('null deps: still cascades grants', $statusOf($db3, 'capability_grants', 'gr-1') === 'revoked');

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
