<?php

declare(strict_types=1);

/**
 * Account teardown-event emission test (Phase 0: gap ID1, the Theme-B emitter).
 *
 * Proves AccountLifecycleService.transition stages EXACTLY ONE canonical teardown
 * event on the outbox for each teardown transition, and NONE for non-teardown
 * transitions:
 *   - deactivated -> account.deactivated
 *   - suspended   -> account.suspended
 *   - anonymized  -> account.anonymized
 *   - merged (+ merged_into_id) -> account.merged { loser, survivor }
 *   - active / locked -> no event
 * Also checks the event is staged with the user/org/actor payload and a
 * deterministic source_ref, and that an illegal transition emits nothing.
 *
 * Uses in-memory fakes for the DB, sessions, tokens, audit and PDP so the test
 * is framework-free.
 *
 *   php app/Modules/Identity/Services/tests/account_teardown_events_test.php
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

        public function get(): RS
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
        /** @var list<array{topic:string,aggregate_id:string,payload:array}> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'aggregate_id' => $aggregateId, 'payload' => $payload];

            return 'ob-' . count($this->staged);
        }
    }
}

namespace WBS\Identity\Services {
    // Lightweight stand-ins for the collaborators transition() calls.
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
        public function isAllowed($r): bool
        {
            return true;
        }

        public function decide($r)
        {
            return new \Fake\Decision(true);
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

    $passed = 0;
    $failed = 0;
    function chk(string $label, bool $cond): void
    {
        global $passed, $failed;
        if ($cond) {
            $passed++;
        } else {
            $failed++;
            echo "  FAIL {$label}\n";
        }
    }

    $ORG = 'org-1';

    $mk = static function (string $status) use ($ORG): array {
        $db = new BaseConnection();
        $db->rows['users'] = [[
            'id' => 'u-1', 'organization_id' => $ORG, 'status' => $status,
        ]];
        $db->rows['account_state_transitions'] = [];
        $outbox = new OutboxService();
        $svc = new AccountLifecycleService(
            $db,
            new Clock(),
            new SessionService(),
            new TokenService(),
            new AuditLogger(),
            new AuthorizationService(),
            $outbox,
        );

        return [$svc, $outbox, $db];
    };

    // ---- deactivate emits exactly one account.deactivated -------------------
    [$svc, $outbox] = $mk('active');
    $r = $svc->deactivate($ORG, 'u-1', 'left the org', 'admin-1');
    chk('deactivate ok', $r->ok);
    chk('deactivate emits exactly one event', count($outbox->staged) === 1);
    chk('deactivate topic', ($outbox->staged[0]['topic'] ?? '') === 'account.deactivated');
    chk('deactivate payload user', ($outbox->staged[0]['payload']['user_id'] ?? '') === 'u-1');
    chk('deactivate payload org', ($outbox->staged[0]['payload']['organization_id'] ?? '') === $ORG);
    chk('deactivate payload actor', ($outbox->staged[0]['payload']['actor_id'] ?? '') === 'admin-1');
    chk('deactivate source_ref', ($outbox->staged[0]['payload']['source_ref'] ?? '') === 'account_transition:u-1:deactivated');

    // ---- suspend -----------------------------------------------------------
    [$svc, $outbox] = $mk('active');
    $svc->suspend($ORG, 'u-1', 'policy violation', 'admin-1');
    chk('suspend emits one', count($outbox->staged) === 1);
    chk('suspend topic', ($outbox->staged[0]['topic'] ?? '') === 'account.suspended');

    // ---- anonymize ---------------------------------------------------------
    [$svc, $outbox] = $mk('deactivated');
    $svc->anonymize($ORG, 'u-1', 'gdpr erasure', 'dpo-1');
    chk('anonymize emits one', count($outbox->staged) === 1);
    chk('anonymize topic', ($outbox->staged[0]['topic'] ?? '') === 'account.anonymized');

    // ---- merge emits account.merged with survivor --------------------------
    [$svc, $outbox] = $mk('active');
    $r = $svc->transition($ORG, 'u-1', 'merged', 'dup of u-2', [
        'actor_id' => 'admin-1', 'merged_into_id' => 'u-2', 'approval_ref' => 'mr-1',
    ]);
    chk('merge ok', $r->ok);
    chk('merge emits one', count($outbox->staged) === 1);
    chk('merge topic', ($outbox->staged[0]['topic'] ?? '') === 'account.merged');
    chk('merge loser', ($outbox->staged[0]['payload']['loser_user_id'] ?? '') === 'u-1');
    chk('merge survivor', ($outbox->staged[0]['payload']['survivor_user_id'] ?? '') === 'u-2');

    // ---- M10: reactivation from a torn-down state emits account.reactivated -
    [$svc, $outbox] = $mk('suspended');
    $r = $svc->reactivate($ORG, 'u-1', 'appeal upheld', 'admin-1'); // suspended -> active
    chk('reactivate ok', $r->ok);
    chk('reactivate (suspended->active) emits exactly one', count($outbox->staged) === 1);
    chk('reactivate topic', ($outbox->staged[0]['topic'] ?? '') === 'account.reactivated');
    chk('reactivate payload user', ($outbox->staged[0]['payload']['user_id'] ?? '') === 'u-1');
    chk('reactivate payload from_status', ($outbox->staged[0]['payload']['from_status'] ?? '') === 'suspended');
    chk('reactivate source_ref', ($outbox->staged[0]['payload']['source_ref'] ?? '') === 'account_transition:u-1:reactivated');

    [$svc, $outbox] = $mk('deactivated');
    $svc->reactivate($ORG, 'u-1', 'returned', 'admin-1'); // deactivated -> active
    chk('reactivate (deactivated->active) emits account.reactivated', count($outbox->staged) === 1
        && ($outbox->staged[0]['topic'] ?? '') === 'account.reactivated');

    // First activation (NOT a reactivation) emits nothing.
    [$svc, $outbox] = $mk('pending_verification');
    $svc->transition($ORG, 'u-1', 'active', 'verified', ['actor_id' => 'system']); // pending -> active
    chk('first activation (pending->active) emits nothing', count($outbox->staged) === 0);

    [$svc, $outbox] = $mk('active');
    $svc->lock($ORG, 'u-1', 'too many failed logins', 'system'); // -> locked
    chk('lock (->locked) emits nothing', count($outbox->staged) === 0);

    // ---- illegal transition emits nothing ----------------------------------
    [$svc, $outbox] = $mk('anonymized'); // terminal
    $r = $svc->deactivate($ORG, 'u-1', 'noop', 'admin-1');
    chk('illegal transition rejected', ! $r->ok);
    chk('illegal transition emits nothing', count($outbox->staged) === 0);

    // ---- no-change emits nothing -------------------------------------------
    [$svc, $outbox] = $mk('deactivated');
    $r = $svc->deactivate($ORG, 'u-1', 'again', 'admin-1');
    chk('no-change rejected', ! $r->ok);
    chk('no-change emits nothing', count($outbox->staged) === 0);

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
