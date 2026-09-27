<?php

declare(strict_types=1);

/**
 * AccountLifecycleService::processVerifyExpiry test (Theme C — M6).
 *
 * `pending_verification` was a real starting state but nothing nudged or expired
 * it. Over in-memory fakes (DB, sessions, tokens, audit, PDP, outbox) + a config
 * stub and reminder spy, proves:
 *   - DEFAULT OFF: unset gate -> nothing reminded/expired (all skipped_gated);
 *   - enabled: an account past remind_after_days gets one nudge (watermark set);
 *   - an account not yet old enough is left alone;
 *   - an account within remind cadence is not nudged again;
 *   - an account past expire_after_days is deactivated (audited transition),
 *     not merely reminded;
 *   - a non-pending account is ignored;
 *   - idempotent: a fresh pass right after doesn't re-nudge (cadence) and the
 *     expired one is gone from the pending set.
 *
 *   php app/Modules/Identity/Services/tests/verify_expiry_test.php
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

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($c, $d = 'ASC')
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
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
                if ((string) ($r[$k] ?? '') !== (string) $v) {
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
            $this->staged[] = $topic;

            return 'ob';
        }
    }
}

namespace WBS\Identity\Services {
    class SessionService
    {
        public function revokeAllForUser(string $userId): void
        {
        }
    }
    class TokenService
    {
        public function revokeAllForUser(string $userId): void
        {
        }
    }

    // Config stub + reminder spy (declared here; interfaces required first).
    require_once dirname(__DIR__, 5) . '/app/Modules/Identity/Services/LifecycleConfigPort.php';
    require_once dirname(__DIR__, 5) . '/app/Modules/Identity/Services/VerifyReminderPort.php';

    class MapLifecycleConfig implements LifecycleConfigPort
    {
        public array $map = [];
        public ?string $root = 'g-root';

        public function value(string $groupId, string $capability): mixed
        {
            return $this->map[$groupId . '|' . $capability] ?? null;
        }

        public function orgRootGroup(string $organizationId): ?string
        {
            return $this->root;
        }
    }

    class SpyVerifyReminder implements VerifyReminderPort
    {
        /** @var list<array{user:string,dedupe:string}> */
        public array $sent = [];

        public function remindVerify(string $organizationId, string $userId, string $dedupeKey, array $context = []): void
        {
            $this->sent[] = ['user' => $userId, 'dedupe' => $dedupeKey];
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
    use WBS\Identity\Services\MapLifecycleConfig;
    use WBS\Identity\Services\SessionService;
    use WBS\Identity\Services\SpyVerifyReminder;
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

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $ORG  = 'org-1';
    $ROOT = 'g-root';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['users'] = [
            // 5 days old, never reminded -> nudge
            ['id' => 'u1', 'organization_id' => $ORG, 'status' => 'pending_verification', 'created_at' => '2026-05-27 12:00:00', 'verify_reminded_at' => null, 'verify_reminder_count' => 0],
            // 1 day old -> too new, skip
            ['id' => 'u2', 'organization_id' => $ORG, 'status' => 'pending_verification', 'created_at' => '2026-05-31 12:00:00', 'verify_reminded_at' => null, 'verify_reminder_count' => 0],
            // 5 days old, reminded yesterday -> within cadence, skip
            ['id' => 'u3', 'organization_id' => $ORG, 'status' => 'pending_verification', 'created_at' => '2026-05-27 12:00:00', 'verify_reminded_at' => '2026-05-31 12:00:00', 'verify_reminder_count' => 1],
            // 40 days old -> expire
            ['id' => 'u4', 'organization_id' => $ORG, 'status' => 'pending_verification', 'created_at' => '2026-04-22 12:00:00', 'verify_reminded_at' => null, 'verify_reminder_count' => 0],
            // active -> ignored
            ['id' => 'u5', 'organization_id' => $ORG, 'status' => 'active', 'created_at' => '2026-01-01 12:00:00', 'verify_reminded_at' => null, 'verify_reminder_count' => 0],
        ];
        $db->rows['account_state_transitions'] = [];

        return $db;
    };

    $statusOf = static function (BaseConnection $db, string $uid): string {
        foreach ($db->rows['users'] as $u) {
            if ($u['id'] === $uid) {
                return (string) $u['status'];
            }
        }

        return '';
    };

    $mk = static function (BaseConnection $db, MapLifecycleConfig $cfg, SpyVerifyReminder $spy): AccountLifecycleService {
        return new AccountLifecycleService(
            $db, new Clock(), new SessionService(), new TokenService(),
            new AuditLogger(), new AuthorizationService(), new OutboxService(),
            $cfg, $spy,
        );
    };

    // ---- DEFAULT OFF --------------------------------------------------------
    $db  = $seed();
    $cfg = new MapLifecycleConfig(); // enabled unset
    $spy = new SpyVerifyReminder();
    $svc = $mk($db, $cfg, $spy);
    $r   = $svc->processVerifyExpiry($ORG, 500);
    $chk('default off reminds nothing', $r['reminded'] === 0 && count($spy->sent) === 0);
    $chk('default off expires nothing', $r['expired'] === 0);
    $chk('default off skips pending as gated', $r['skipped_gated'] === 4, (string) $r['skipped_gated']);

    // ---- ENABLED (defaults: remind_after 3, every 3, expire 30) ------------
    $db  = $seed();
    $cfg = new MapLifecycleConfig();
    $cfg->map[$ROOT . '|' . AccountLifecycleService::CAP_VERIFY_ENABLED] = true;
    $spy = new SpyVerifyReminder();
    $svc = $mk($db, $cfg, $spy);
    $r   = $svc->processVerifyExpiry($ORG, 500);

    $chk('u1 nudged', count(array_filter($spy->sent, fn ($s) => $s['user'] === 'u1')) === 1);
    $chk('u1 dedupe keyed per round', str_contains($spy->sent[0]['dedupe'] ?? '', 'u1:1'));
    $chk('u2 too new not nudged', count(array_filter($spy->sent, fn ($s) => $s['user'] === 'u2')) === 0);
    $chk('u3 within cadence not nudged', count(array_filter($spy->sent, fn ($s) => $s['user'] === 'u3')) === 0);
    $chk('u4 expired -> deactivated', $statusOf($db, 'u4') === 'deactivated');
    $chk('u4 not counted as reminder', count(array_filter($spy->sent, fn ($s) => $s['user'] === 'u4')) === 0);
    $chk('u5 active ignored', $statusOf($db, 'u5') === 'active');
    $chk('reminded = 1', $r['reminded'] === 1, (string) $r['reminded']);
    $chk('expired = 1', $r['expired'] === 1, (string) $r['expired']);

    // ---- idempotent second pass --------------------------------------------
    $spy->sent = [];
    $r2 = $svc->processVerifyExpiry($ORG, 500);
    $chk('second pass does not re-nudge u1 (cadence)', count(array_filter($spy->sent, fn ($s) => $s['user'] === 'u1')) === 0, (string) count($spy->sent));
    $chk('second pass expires 0 (u4 already deactivated)', $r2['expired'] === 0, (string) $r2['expired']);

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
