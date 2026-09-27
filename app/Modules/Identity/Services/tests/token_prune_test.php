<?php

declare(strict_types=1);

/**
 * ID4 — auth-token pruning: TokenService::prune() + CredentialSetupService::prune().
 *
 * Access/refresh tokens and credential-setup (invite/reset) tokens lazy-expire —
 * a stale token simply fails validation but the row was never removed, so the
 * tables grew unbounded. This proves each prune() does the two-pass housekeeping,
 * mirroring SessionService::prune():
 *   - EXPIRE: still-live rows past their absolute expiry are flipped to a terminal
 *     state (access -> revoked_at; refresh -> status='expired'; invite -> consumed_at);
 *   - DELETE: rows terminal for longer than the retention window are hard-deleted,
 *     keeping a short audit grace;
 *   - fresh (unexpired) tokens are untouched;
 *   - recently-terminal rows (inside the window) are kept;
 *   - idempotent: a second immediate pass changes nothing.
 *
 *   php app/Modules/Identity/Services/tests/token_prune_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        private int $affected = 0;

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
            return true;
        }

        public function setAffected(int $n): void
        {
            $this->affected = $n;
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
        /** @var list<array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        private ?array $in = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function orderBy($k, $d = 'ASC')
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            [$key, $op] = $this->splitOp((string) $k);
            $this->conds[] = ['k' => $key, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in = ['k' => trim((string) $k), 'vals' => array_map('strval', $vals)];

            return $this;
        }

        private function splitOp(string $k): array
        {
            $k = trim($k);
            foreach (['<=', '>=', '!=', '<', '>'] as $op) {
                if (str_ends_with($k, ' ' . $op)) {
                    return [trim(substr($k, 0, -strlen($op))), $op];
                }
            }

            return [$k, '='];
        }

        private function valueOf(array $r, string $key): mixed
        {
            // Support COALESCE(a, b)
            if (preg_match('/^COALESCE\(([^,]+),\s*([^)]+)\)$/i', $key, $m)) {
                $a = trim($m[1]);
                $b = trim($m[2]);

                return $r[$a] ?? $r[$b] ?? null;
            }

            return $r[$key] ?? null;
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $actual = $this->valueOf($r, $c['k']);
                if ($c['op'] === '=' && $c['v'] === null) {
                    if ($actual !== null) {
                        return false;
                    }
                    continue;
                }
                if ($c['op'] === '!=' && $c['v'] === null) {
                    if ($actual === null) {
                        return false;
                    }
                    continue;
                }
                if ($actual === null) {
                    return false; // NULL never satisfies a value comparison
                }
                $l = (string) $actual;
                $rv = (string) $c['v'];
                $ok = match ($c['op']) {
                    '='  => $l === $rv,
                    '!=' => $l !== $rv,
                    '<=' => $l <= $rv,
                    '>=' => $l >= $rv,
                    '<'  => $l < $rv,
                    '>'  => $l > $rv,
                    default => false,
                };
                if (! $ok) {
                    return false;
                }
            }
            if ($this->in !== null && ! in_array((string) ($r[$this->in['k']] ?? ''), $this->in['vals'], true)) {
                return false;
            }

            return true;
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
            $this->db->setAffected($n);

            return true;
        }

        public function delete(): bool
        {
            $keep = [];
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $r) {
                if ($this->matches($r)) {
                    $n++;
                } else {
                    $keep[] = $r;
                }
            }
            $this->db->rows[$this->t] = $keep;
            $this->db->setAffected($n);

            return true;
        }
    }
}

namespace WBS\Identity\Services {
    // Minimal stand-ins so CredentialSetupService's ctor type-hints resolve; its
    // prune() touches neither collaborator, so empty classes are sufficient. These
    // are declared BEFORE the real service file is required, so the autoloader-free
    // test never loads the heavyweight real AccountService/SessionService.
    if (! class_exists(AccountService::class, false)) {
        class AccountService
        {
        }
    }
    if (! class_exists(SessionService::class, false)) {
        class SessionService
        {
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Identity\Services\AccountService;
    use WBS\Identity\Services\CredentialSetupService;
    use WBS\Identity\Services\SessionService;
    use WBS\Identity\Services\TokenService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Identity/Services/TokenService.php';
    require_once $root . '/app/Modules/Identity/Services/CredentialSetupService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $NOW = '2026-09-17 12:00:00';
    Clock::freeze(new \DateTimeImmutable($NOW . '.000000', new \DateTimeZone('UTC')));
    $ago = static function (int $days): string {
        return (new \DateTimeImmutable('2026-09-17 12:00:00', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")->format('Y-m-d H:i:s');
    };
    $ahead = static function (int $days): string {
        return (new \DateTimeImmutable('2026-09-17 12:00:00', new \DateTimeZone('UTC')))
            ->modify("+{$days} days")->format('Y-m-d H:i:s');
    };
    $byId = static function (array $rows, string $id): ?array {
        foreach ($rows as $r) {
            if (($r['id'] ?? null) === $id) {
                return $r;
            }
        }
        return null;
    };

    // ===================== TokenService::prune ==========================
    echo "TokenService::prune (access + refresh)\n";
    $db = new BaseConnection();
    $db->rows['access_tokens'] = [
        // live + not expired -> untouched
        ['id' => 'at-live', 'user_id' => 'u1', 'revoked_at' => null, 'expires_at' => $ahead(1), 'created_at' => $ago(1)],
        // live but past expiry -> expired (revoked_at stamped)
        ['id' => 'at-stale', 'user_id' => 'u1', 'revoked_at' => null, 'expires_at' => $ago(1), 'created_at' => $ago(10)],
        // revoked recently (5d < 30d) -> kept
        ['id' => 'at-recent-rev', 'user_id' => 'u1', 'revoked_at' => $ago(5), 'expires_at' => $ago(6), 'created_at' => $ago(20)],
        // revoked long ago (40d > 30d) -> deleted
        ['id' => 'at-old-rev', 'user_id' => 'u1', 'revoked_at' => $ago(40), 'expires_at' => $ago(41), 'created_at' => $ago(60)],
    ];
    $db->rows['refresh_tokens'] = [
        // active + not expired -> untouched
        ['id' => 'rt-live', 'user_id' => 'u1', 'status' => 'active', 'expires_at' => $ahead(3), 'created_at' => $ago(1), 'used_at' => null],
        // active but past expiry -> status=expired
        ['id' => 'rt-stale', 'user_id' => 'u1', 'status' => 'active', 'expires_at' => $ago(1), 'created_at' => $ago(20), 'used_at' => null],
        // rotated recently (used_at 5d) -> kept
        ['id' => 'rt-recent-rot', 'user_id' => 'u1', 'status' => 'rotated', 'expires_at' => $ago(2), 'created_at' => $ago(20), 'used_at' => $ago(5)],
        // revoked long ago (used_at 40d) -> deleted
        ['id' => 'rt-old-rev', 'user_id' => 'u1', 'status' => 'revoked', 'expires_at' => $ago(30), 'created_at' => $ago(60), 'used_at' => $ago(40)],
        // rotated, never used_at, created 45d ago -> COALESCE falls back to created_at -> deleted
        ['id' => 'rt-old-nulltouch', 'user_id' => 'u1', 'status' => 'rotated', 'expires_at' => $ago(30), 'created_at' => $ago(45), 'used_at' => null],
    ];

    $svc = new TokenService($db, new Clock());
    $r = $svc->prune(30);

    $chk('access_expired = 1', $r['access_expired'] === 1, json_encode($r));
    $chk('refresh_expired = 1', $r['refresh_expired'] === 1, json_encode($r));
    $chk('access_deleted = 1', $r['access_deleted'] === 1, json_encode($r));
    $chk('refresh_deleted = 2 (revoked + null-touch rotated)', $r['refresh_deleted'] === 2, json_encode($r));

    $chk('at-live untouched', ($byId($db->rows['access_tokens'], 'at-live')['revoked_at'] ?? null) === null);
    $chk('at-stale now revoked', ($byId($db->rows['access_tokens'], 'at-stale')['revoked_at'] ?? null) === $NOW . '.000000' || ! empty($byId($db->rows['access_tokens'], 'at-stale')['revoked_at']));
    $chk('at-recent-rev kept', $byId($db->rows['access_tokens'], 'at-recent-rev') !== null);
    $chk('at-old-rev deleted', $byId($db->rows['access_tokens'], 'at-old-rev') === null);

    $chk('rt-live still active', ($byId($db->rows['refresh_tokens'], 'rt-live')['status'] ?? null) === 'active');
    $chk('rt-stale now expired', ($byId($db->rows['refresh_tokens'], 'rt-stale')['status'] ?? null) === 'expired');
    $chk('rt-recent-rot kept', $byId($db->rows['refresh_tokens'], 'rt-recent-rot') !== null);
    $chk('rt-old-rev deleted', $byId($db->rows['refresh_tokens'], 'rt-old-rev') === null);
    $chk('rt-old-nulltouch deleted (COALESCE created_at)', $byId($db->rows['refresh_tokens'], 'rt-old-nulltouch') === null);

    // idempotent second pass
    $r2 = $svc->prune(30);
    $chk('second pass: nothing new expired/deleted', array_sum($r2) === 0, json_encode($r2));

    // ===================== CredentialSetupService::prune ================
    echo "CredentialSetupService::prune (invite/reset)\n";
    $db2 = new BaseConnection();
    $db2->rows['credential_setup_tokens'] = [
        // unconsumed + not expired -> untouched
        ['id' => 'cs-live', 'user_id' => 'u1', 'consumed_at' => null, 'expires_at' => $ahead(2)],
        // unconsumed but past expiry -> consumed_at stamped
        ['id' => 'cs-stale', 'user_id' => 'u1', 'consumed_at' => null, 'expires_at' => $ago(1)],
        // consumed recently (5d) -> kept
        ['id' => 'cs-recent', 'user_id' => 'u1', 'consumed_at' => $ago(5), 'expires_at' => $ago(6)],
        // consumed long ago (40d) -> deleted
        ['id' => 'cs-old', 'user_id' => 'u1', 'consumed_at' => $ago(40), 'expires_at' => $ago(41)],
    ];
    // CredentialSetupService needs AccountService + SessionService, but prune()
    // touches neither — the namespaced stand-ins above satisfy the type hints.
    $cs = new CredentialSetupService($db2, new Clock(), new AccountService(), new SessionService());
    $cr = $cs->prune(30);

    $chk('invite expired = 1', $cr['expired'] === 1, json_encode($cr));
    $chk('invite deleted = 1', $cr['deleted'] === 1, json_encode($cr));
    $chk('cs-live untouched', ($byId($db2->rows['credential_setup_tokens'], 'cs-live')['consumed_at'] ?? null) === null);
    $chk('cs-stale now consumed', ! empty($byId($db2->rows['credential_setup_tokens'], 'cs-stale')['consumed_at']));
    $chk('cs-recent kept', $byId($db2->rows['credential_setup_tokens'], 'cs-recent') !== null);
    $chk('cs-old deleted', $byId($db2->rows['credential_setup_tokens'], 'cs-old') === null);

    $cr2 = $cs->prune(30);
    $chk('second pass: nothing new', $cr2['expired'] === 0 && $cr2['deleted'] === 0, json_encode($cr2));

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}
