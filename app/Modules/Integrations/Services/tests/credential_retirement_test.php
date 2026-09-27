<?php

declare(strict_types=1);

/**
 * CredentialVault IN3 test — rotation retires, useSecret pins active, prune scrubs.
 *
 * `CredentialVault::put()` versioned each slot but rotation kept ALL historical
 * versions active + decryptable forever, with no active-version pointer — after a
 * compromise-driven rotation the leaked secret stayed live. Over an in-memory DB
 * fake + a stand-in SecretBox (final in prod, so declared here BEFORE the vault
 * is required), proves:
 *   - first put() stores v1 active;
 *   - rotation put() stores v2 active AND retires v1 (stamps retired_at);
 *   - exactly one active version per slot after rotation;
 *   - useSecret() decrypts the ACTIVE (latest) version, never a retired one;
 *   - pruneRetired() deletes retired versions past the grace window, NEVER an
 *     active one, and never a retired one still inside grace;
 *   - prune is org/connection-scopable and idempotent (second pass deletes 0);
 *   - counts {scanned,pruned} are accurate.
 *
 *   php app/Modules/Integrations/Services/tests/credential_retirement_test.php
 */

namespace WBS\Shared\Security {
    // Stand-in for the (final) production SecretBox. Reversible "encryption" so
    // the test can assert useSecret() returns the right plaintext.
    class SecretBox
    {
        public function encrypt(string $plaintext, string $aad = ''): string
        {
            return 'enc(' . $plaintext . '|' . $aad . ')';
        }

        public function decrypt(string $stored, string $aad = ''): string
        {
            // enc(PLAIN|AAD) -> PLAIN
            $inner = substr($stored, 4, -1);          // strip enc( ... )
            return explode('|', $inner, 2)[0];
        }

        public function fingerprint(string $plaintext): string
        {
            return substr(md5($plaintext), 0, 16);
        }

        public function keyId(): string
        {
            return 'test-key';
        }
    }
}

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
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        /** @var array<string,list<string>> */
        private array $in = [];
        private ?string $orderKey = null;
        private ?string $maxCol = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($f)
        {
            return $this;
        }

        public function selectMax($c)
        {
            $this->maxCol = trim((string) $c);

            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if ($escape === false && str_contains($k, 'IS NOT NULL')) {
                $col           = trim(str_replace('IS NOT NULL', '', $k));
                $this->conds[] = ['k' => $col, 'op' => 'notnull', 'v' => null];

                return $this;
            }
            $op = '=';
            if (str_ends_with($k, '<=')) {
                $op = '<=';
                $k  = trim(substr($k, 0, -2));
            } elseif (str_ends_with($k, '<')) {
                $op = '<';
                $k  = trim(substr($k, 0, -1));
            }
            $this->conds[] = ['k' => $k, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            $this->orderKey = trim((string) $k);

            return $this;
        }

        private function filtered(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get($limit = null): RS
        {
            $rows = $this->filtered();
            if ($this->maxCol !== null) {
                $mx = null;
                foreach ($rows as $r) {
                    $v = (int) ($r[$this->maxCol] ?? 0);
                    if ($mx === null || $v > $mx) {
                        $mx = $v;
                    }
                }

                return new RS([[$this->maxCol => $mx]]);
            }
            if ($this->orderKey !== null) {
                usort($rows, fn ($a, $b) => (string) ($a[$this->orderKey] ?? '') <=> (string) ($b[$this->orderKey] ?? ''));
            }
            if ($limit !== null) {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            return new RS($rows);
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

        public function delete(): bool
        {
            $keep = [];
            $n    = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $r) {
                if ($this->matches($r)) {
                    $n++;
                } else {
                    $keep[] = $r;
                }
            }
            $this->db->rows[$this->t] = $keep;
            $this->db->affected       = $n;

            return true;
        }

        public function countAllResults(): int
        {
            return count($this->filtered());
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    'notnull' => $rv !== null,
                    '<='      => $rv !== null && (string) $rv <= (string) $c['v'],
                    '<'       => $rv !== null && (string) $rv < (string) $c['v'],
                    default   => (string) ($rv ?? '') === (string) $c['v'],
                };
                if (! $ok) {
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

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Integrations\Services\CredentialVault;
    use WBS\Shared\Security\SecretBox;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    // NOTE: the real SecretBox is deliberately NOT required — the stand-in above
    // occupies its namespace so the vault binds to the fake.
    require_once $root . '/app/Modules/Integrations/Services/CredentialVault.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $CONN = 'conn-1';
    $SLOT = 'api_key';

    $verRows = static function (BaseConnection $db) use ($CONN, $SLOT): array {
        return array_values(array_filter(
            $db->rows['connection_credentials'] ?? [],
            fn ($r) => $r['connection_id'] === $CONN && $r['slot'] === $SLOT,
        ));
    };
    $activeRows = static function (array $rows): array {
        return array_values(array_filter($rows, fn ($r) => ($r['status'] ?? '') === 'active'));
    };

    // ---- store v1 -----------------------------------------------------------
    $db    = new BaseConnection();
    $vault = new CredentialVault($db, new SecretBox(), new Clock());

    $r1 = $vault->put($CONN, $SLOT, 'secret-one');
    $chk('put v1 ok', $r1->ok === true, (string) ($r1->code ?? ''));
    $chk('put v1 version=1', ($r1->data['version'] ?? 0) === 1);
    $rows = $verRows($db);
    $chk('one row after v1', count($rows) === 1);
    $chk('v1 is active', ($rows[0]['status'] ?? '') === 'active');

    // ---- rotate to v2 (compromise-driven) ----------------------------------
    Clock::freeze(new DateTimeImmutable('2026-06-01 13:00:00', new DateTimeZone('UTC')));
    $r2 = $vault->put($CONN, $SLOT, 'secret-two');
    $chk('put v2 version=2', ($r2->data['version'] ?? 0) === 2);
    $rows   = $verRows($db);
    $active = $activeRows($rows);
    $chk('two rows after rotation', count($rows) === 2);
    $chk('exactly one active version', count($active) === 1, (string) count($active));
    $chk('active version is v2', (int) ($active[0]['version'] ?? 0) === 2);
    $v1 = array_values(array_filter($rows, fn ($r) => (int) $r['version'] === 1))[0];
    $chk('v1 now retired', ($v1['status'] ?? '') === 'retired');
    $chk('v1 retired_at stamped', ! empty($v1['retired_at']));

    // ---- useSecret pins the active (latest) version ------------------------
    $seen = $vault->useSecret($CONN, $SLOT, static fn (string $s) => $s);
    $chk('useSecret returns active plaintext (v2)', $seen === 'secret-two', (string) $seen);

    // ---- prune: v1 retired at 13:00; still inside a 30-day grace -----------
    Clock::freeze(new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')));
    $p0 = $vault->pruneRetired($CONN, 30, 500);
    $chk('within grace: prunes 0', $p0['pruned'] === 0, json_encode($p0));
    $chk('v1 still present within grace', count($verRows($db)) === 2);

    // ---- prune: well past grace -> v1 gone, v2 (active) kept ---------------
    Clock::freeze(new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')));
    $p1 = $vault->pruneRetired($CONN, 30, 500);
    $chk('past grace: scanned 1', $p1['scanned'] === 1, json_encode($p1));
    $chk('past grace: pruned 1', $p1['pruned'] === 1, json_encode($p1));
    $rows = $verRows($db);
    $chk('only active v2 remains', count($rows) === 1 && (int) $rows[0]['version'] === 2);
    $chk('active never pruned', ($rows[0]['status'] ?? '') === 'active');
    $chk('active still usable after prune', $vault->useSecret($CONN, $SLOT, static fn (string $s) => $s) === 'secret-two');

    // ---- idempotent second pass --------------------------------------------
    $p2 = $vault->pruneRetired($CONN, 30, 500);
    $chk('second pass scans 0', $p2['scanned'] === 0, json_encode($p2));
    $chk('second pass prunes 0', $p2['pruned'] === 0, json_encode($p2));

    // ---- connection scoping -------------------------------------------------
    $db    = new BaseConnection();
    $vault = new CredentialVault($db, new SecretBox(), new Clock());
    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $vault->put('conn-A', 'slot', 'a1');
    $vault->put('conn-B', 'slot', 'b1');
    Clock::freeze(new DateTimeImmutable('2026-06-01 13:00:00', new DateTimeZone('UTC')));
    $vault->put('conn-A', 'slot', 'a2'); // retires conn-A v1
    $vault->put('conn-B', 'slot', 'b2'); // retires conn-B v1
    Clock::freeze(new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')));
    $ps = $vault->pruneRetired('conn-A', 30, 500);
    $chk('scoped prune only touches conn-A', $ps['pruned'] === 1, json_encode($ps));
    $bRows = array_values(array_filter($db->rows['connection_credentials'], fn ($r) => $r['connection_id'] === 'conn-B'));
    $chk('conn-B untouched under scope', count($bRows) === 2, (string) count($bRows));
    $pall = $vault->pruneRetired(null, 30, 500);
    $chk('null-scope prune reaches conn-B retired', $pall['pruned'] === 1, json_encode($pall));

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
