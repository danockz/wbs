<?php

declare(strict_types=1);

/**
 * AuditLogger persistence-shape regression (prod log 2026-09-18).
 *
 * Against real MySQL, `record()` blew up with:
 *   mysqli_sql_exception: Operand should contain 1 column(s)
 *   INSERT INTO audit_log (... metadata ...) VALUES (..., ('a','b','member'), ...)
 *
 * Root cause: the insert built its row with the `+` array-union operator
 * (`$core + [ 'metadata' => json_encode(...) , ... ]`). Because `$core` ALREADY
 * held `metadata` as a raw PHP array (needed for hashing), the left operand won
 * and the `json_encode()` on the right was silently dropped — so the query
 * builder received an array for `metadata` and rendered it as a SQL row
 * constructor `('a','b','c')`. The fix switches to array_merge (right wins).
 *
 * This test captures the exact array handed to the query builder's insert() and
 * asserts `metadata` is a JSON STRING (never an array), that it round-trips to
 * the original data, that redaction still applies, and that the hash chain links
 * across sequential writes.
 *
 *   php app/Modules/Audit/Services/tests/audit_logger_metadata_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        /** @var list<array<string,mixed>> every row passed to insert(), verbatim */
        public array $inserted = [];

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }

    class QB
    {
        private array $eq = [];
        private ?int $limit = null;
        private array $order = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { return $this; }
        public function where($k, $v = null, $escape = true) { $this->eq[trim((string) $k)] = $v; return $this; }
        public function orderBy($c, $d = 'ASC') { $this->order[] = [$c, strtoupper((string) $d)]; return $this; }
        public function limit($n) { $this->limit = (int) $n; return $this; }

        public function get($l = null): RS
        {
            $rows = $this->rowsFor();
            foreach (array_reverse($this->order) as [$c, $d]) {
                usort($rows, static fn ($a, $b) => $d === 'DESC'
                    ? ($b[$c] <=> $a[$c]) : ($a[$c] <=> $b[$c]));
            }
            if ($this->limit !== null) { $rows = array_slice($rows, 0, $this->limit); }

            return new RS($rows);
        }

        public function insert(array $row): bool
        {
            // Capture verbatim for assertions, and store for the tail/chain reads.
            $this->db->inserted[] = $row;
            $this->db->rows[$this->t][] = $row;

            return true;
        }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->eq as $k => $v) {
                    if (($r[$k] ?? null) !== $v) { return false; }
                }

                return true;
            }));
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Audit/Services/AuditLogger.php';

use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-18 12:10:42', new DateTimeZone('UTC')));
$org = '00000000-0000-7000-8000-000000000001';

echo "1. metadata is persisted as a JSON STRING, not a raw array\n";
$db  = new \CodeIgniter\Database\BaseConnection();
$log = new AuditLogger($db, new Clock());

// The exact shape from the crashing call: a list-valued metadata that MySQL
// rendered as a row constructor ('a','b','member').
$res = $log->record($org, [
    'actor_id'    => '01a0b46b-e815-7a3b-b7fa-0359ce8a9def',
    'actor_type'  => 'user',
    'action'      => 'group.membership.requested',
    'object_type' => 'group_membership',
    'object_id'   => '01a0b46d-1539-710d-ac5c-65625c901826',
    'outcome'     => 'success',
    'metadata'    => ['01a0b463-bde2-7f47-83a9-2311aca7e603', '01a0b46b-e815-7a3b-b7fa-0359ce8a9def', 'member'],
]);
chk('record() returns ok', $res->ok, json_encode($res->error ?? null));

$row = $db->inserted[0] ?? [];
chk('a row was inserted', $row !== []);
chk('metadata column is a STRING (not array)', is_string($row['metadata'] ?? null), 'got ' . gettype($row['metadata'] ?? null));
chk('metadata is NOT a PHP array (the bug)', ! is_array($row['metadata'] ?? null));
chk('metadata is valid JSON that round-trips', (json_decode((string) ($row['metadata'] ?? ''), true))
    === ['01a0b463-bde2-7f47-83a9-2311aca7e603', '01a0b46b-e815-7a3b-b7fa-0359ce8a9def', 'member']);

echo "2. associative metadata + redaction still stored as JSON string\n";
$db2  = new \CodeIgniter\Database\BaseConnection();
$log2 = new AuditLogger($db2, new Clock());
$log2->record($org, [
    'action'   => 'user.login',
    'metadata' => ['ip' => '10.0.0.1', 'password' => 'hunter2', 'nested' => ['token' => 'abc']],
]);
$m = json_decode((string) ($db2->inserted[0]['metadata'] ?? ''), true);
chk('assoc metadata is a JSON string', is_string($db2->inserted[0]['metadata'] ?? null));
chk('top-level secret redacted', ($m['password'] ?? null) === '[redacted]');
chk('nested secret redacted', ($m['nested']['token'] ?? null) === '[redacted]');
chk('non-secret preserved', ($m['ip'] ?? null) === '10.0.0.1');

echo "3. empty metadata → '[]' JSON string, never NULL-as-array\n";
$db3  = new \CodeIgniter\Database\BaseConnection();
$log3 = new AuditLogger($db3, new Clock());
$log3->record($org, ['action' => 'noop']);
chk('empty metadata encodes to "[]"', ($db3->inserted[0]['metadata'] ?? null) === '[]');

echo "4. every persisted column is a scalar/NULL (safe for a real INSERT)\n";
$allScalar = true;
foreach (($db->inserted[0] ?? []) as $k => $v) {
    if (is_array($v)) { $allScalar = false; echo "      offending column: $k\n"; }
}
chk('no column holds a raw array', $allScalar);

echo "5. hash chain links across sequential writes\n";
$db4  = new \CodeIgniter\Database\BaseConnection();
$log4 = new AuditLogger($db4, new Clock());
$r1 = $log4->record($org, ['action' => 'a.one', 'metadata' => ['x' => 1]]);
$r2 = $log4->record($org, ['action' => 'a.two', 'metadata' => ['y' => 2]]);
chk('seq increments 1→2', ($db4->inserted[0]['seq'] ?? null) === 1 && ($db4->inserted[1]['seq'] ?? null) === 2);
chk('second entry prev_hash = first entry_hash',
    ($db4->inserted[1]['prev_hash'] ?? null) === ($db4->inserted[0]['entry_hash'] ?? '::none::'));
$verify = $log4->verifyChain($org);
chk('verifyChain() intact', $verify->ok, json_encode($verify->data ?? $verify->error ?? null));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
