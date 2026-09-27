<?php

declare(strict_types=1);

/**
 * ReconciliationService test (Theme C — C4/C5).
 *
 * `reconciliation_cases` was defined but never written/read; quarantined/failed
 * webhooks had no processor. Over an in-memory DB fake (with hand-modelled
 * results for the three raw analytic queries the service runs), proves:
 *
 *   - missing_ledger: a `succeeded` contribution with no journal entry opens a
 *     case (idempotently — a second pass opens no duplicate);
 *   - a healthy contribution (balanced ledger) opens NO case;
 *   - amount_mismatch: ledger debits ≠ contribution amount opens a case;
 *   - auto-resolve: once the missing ledger entry is posted, the open case flips
 *     to resolved and the open-case count drops;
 *   - C5 dead-letters: a quarantined+verified+known-type webhook is re-queued to
 *     `received`; an unverified or unknown-type one is left stuck and counted;
 *   - the headline Result carries opened/resolved/open_total/dead-letter counts.
 *
 *   php app/Modules/Contributions/Services/tests/reconciliation_test.php
 */

namespace CodeIgniter\Database {
    class DatabaseException extends \RuntimeException
    {
    }

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

        // Raw analytic queries the ReconciliationService runs. We recognise each
        // by a substring of its SQL and compute the answer from the fake rows.
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            // missing_ledger detector: succeeded contributions with no entry.
            if (str_contains($sql, 'je.id IS NULL')) {
                $org = (string) $binds[1];
                $out = [];
                foreach ($this->rows['contributions'] ?? [] as $c) {
                    if ((string) $c['organization_id'] !== $org || (string) $c['state'] !== 'succeeded') {
                        continue;
                    }
                    if ($this->entryFor($org, (string) $c['id']) === null) {
                        $out[] = ['id' => $c['id'], 'amount_minor' => $c['amount_minor'], 'currency' => $c['currency']];
                    }
                }

                return new \Fake\RS($out);
            }

            // amount_mismatch detector (has HAVING) OR single-row recheck.
            if (str_contains($sql, 'ledger_debits')) {
                $org = (string) $binds[1];
                $one = str_contains($sql, 'AND c.id = ?') ? (string) $binds[2] : null;
                $out = [];
                foreach ($this->rows['contributions'] ?? [] as $c) {
                    if ((string) $c['organization_id'] !== $org || (string) $c['state'] !== 'succeeded') {
                        continue;
                    }
                    if ($one !== null && (string) $c['id'] !== $one) {
                        continue;
                    }
                    $entry = $this->entryFor($org, (string) $c['id']);
                    if ($entry === null) {
                        continue; // JOIN excludes rows with no entry
                    }
                    $debits = $this->debitsFor((string) $entry['id']);
                    $row    = ['id' => $c['id'], 'amount_minor' => $c['amount_minor'], 'ledger_debits' => $debits];
                    if ($one !== null) {
                        $out[] = $row; // recheck path: return regardless
                    } elseif ($debits !== (int) $c['amount_minor']) {
                        $out[] = $row; // HAVING mismatch
                    }
                }

                return new \Fake\RS($out);
            }

            return new \Fake\RS([]);
        }

        private function entryFor(string $org, string $contributionId): ?array
        {
            foreach ($this->rows['journal_entries'] ?? [] as $e) {
                if ((string) $e['organization_id'] === $org
                    && (string) $e['source_ref'] === 'contribution:' . $contributionId) {
                    return $e;
                }
            }

            return null;
        }

        private function debitsFor(string $entryId): int
        {
            $sum = 0;
            foreach ($this->rows['journal_lines'] ?? [] as $l) {
                if ((string) $l['entry_id'] === $entryId && (string) $l['direction'] === 'debit') {
                    $sum += (int) $l['amount_minor'];
                }
            }

            return $sum;
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
        private ?array $select = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($cols)
        {
            $this->select = array_map('trim', explode(',', (string) $cols));

            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }
            if ($this->select !== null) {
                $out = array_map(fn ($r) => array_intersect_key($r, array_flip($this->select)), $out);
            }

            return new RS($out);
        }

        public function countAllResults(): int
        {
            return count(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function insert(array $row): bool
        {
            // Enforce reconciliation_cases UNIQUE(organization_id, dedupe_ref).
            if ($this->t === 'reconciliation_cases' && isset($row['dedupe_ref'])) {
                foreach ($this->db->rows[$this->t] ?? [] as $r) {
                    if ((string) ($r['organization_id'] ?? '') === (string) $row['organization_id']
                        && (string) ($r['dedupe_ref'] ?? '') === (string) $row['dedupe_ref']) {
                        throw new \CodeIgniter\Database\DatabaseException('dup');
                    }
                }
            }
            $this->db->rows[$this->t][] = $row;
            $this->db->affected = 1;

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

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\ReconciliationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Contributions/Services/WebhookInboxService.php';
    require_once $root . '/app/Modules/Contributions/Services/ReconciliationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $db = new BaseConnection();
    $db->rows['organizations'] = [['id' => $ORG]];
    $db->rows['contributions'] = [
        // c1: succeeded, NO ledger entry -> missing_ledger
        ['id' => 'c1', 'organization_id' => $ORG, 'state' => 'succeeded', 'amount_minor' => 5000, 'currency' => 'USD'],
        // c2: succeeded, balanced ledger -> healthy
        ['id' => 'c2', 'organization_id' => $ORG, 'state' => 'succeeded', 'amount_minor' => 3000, 'currency' => 'USD'],
        // c3: succeeded, ledger debits mismatch -> amount_mismatch
        ['id' => 'c3', 'organization_id' => $ORG, 'state' => 'succeeded', 'amount_minor' => 2000, 'currency' => 'USD'],
        // c4: pending -> ignored
        ['id' => 'c4', 'organization_id' => $ORG, 'state' => 'pending', 'amount_minor' => 9000, 'currency' => 'USD'],
    ];
    $db->rows['journal_entries'] = [
        ['id' => 'e2', 'organization_id' => $ORG, 'source_ref' => 'contribution:c2'],
        ['id' => 'e3', 'organization_id' => $ORG, 'source_ref' => 'contribution:c3'],
    ];
    $db->rows['journal_lines'] = [
        ['entry_id' => 'e2', 'direction' => 'debit', 'amount_minor' => 3000],  // matches c2
        ['entry_id' => 'e3', 'direction' => 'debit', 'amount_minor' => 1500],  // != c3.amount 2000
    ];
    $db->rows['reconciliation_cases'] = [];
    $db->rows['webhook_inbox'] = [
        // w1: quarantined, verified, known type -> requeue
        ['id' => 'w1', 'status' => 'quarantined', 'verified' => 1, 'event_type' => 'payment.succeeded', 'received_at' => '2026-01-01 00:00:00'],
        // w2: quarantined, unverified -> stays stuck
        ['id' => 'w2', 'status' => 'quarantined', 'verified' => 0, 'event_type' => 'payment.succeeded', 'received_at' => '2026-01-01 00:00:01'],
        // w3: quarantined, verified, UNKNOWN type -> stays stuck
        ['id' => 'w3', 'status' => 'quarantined', 'verified' => 1, 'event_type' => 'mystery.event', 'received_at' => '2026-01-01 00:00:02'],
        // w4: failed -> stays stuck (counted)
        ['id' => 'w4', 'status' => 'failed', 'verified' => 1, 'event_type' => 'payment.failed', 'received_at' => '2026-01-01 00:00:03'],
    ];

    $svc = new ReconciliationService($db, new Clock());

    $statusOfCase = static function (BaseConnection $db, string $dedupe): ?string {
        foreach ($db->rows['reconciliation_cases'] as $c) {
            if ((string) $c['dedupe_ref'] === $dedupe) {
                return (string) $c['status'];
            }
        }

        return null;
    };
    $statusOfWebhook = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['webhook_inbox'] as $w) {
            if ((string) $w['id'] === $id) {
                return (string) $w['status'];
            }
        }

        return '';
    };

    // ---- first pass ---------------------------------------------------------
    $r = $svc->reconcile($ORG, 500);
    $chk('pass ok', $r->ok);
    $chk('opened 2 cases (missing + mismatch)', ($r->data['opened'] ?? 0) === 2, (string) ($r->data['opened'] ?? -1));
    $chk('missing_ledger case opened for c1', $statusOfCase($db, 'missing_ledger:contribution:c1') === 'open');
    $chk('amount_mismatch case opened for c3', $statusOfCase($db, 'amount_mismatch:contribution:c3') === 'open');
    $chk('healthy c2 opens no case', $statusOfCase($db, 'missing_ledger:contribution:c2') === null);
    $chk('open_total = 2', ($r->data['open_total'] ?? 0) === 2, (string) ($r->data['open_total'] ?? -1));

    // C5 dead-letters
    $chk('w1 requeued to received', $statusOfWebhook($db, 'w1') === 'received');
    $chk('w2 (unverified) stays quarantined', $statusOfWebhook($db, 'w2') === 'quarantined');
    $chk('w3 (unknown type) stays quarantined', $statusOfWebhook($db, 'w3') === 'quarantined');
    $chk('w4 (failed) stays failed', $statusOfWebhook($db, 'w4') === 'failed');
    $chk('dead_letters_requeued = 1', ($r->data['dead_letters_requeued'] ?? 0) === 1);
    $chk('dead_letters_open = 3', ($r->data['dead_letters_open'] ?? 0) === 3, (string) ($r->data['dead_letters_open'] ?? -1));

    // ---- idempotent second pass (no new cases) -----------------------------
    $r2 = $svc->reconcile($ORG, 500);
    $chk('second pass opens 0 new cases', ($r2->data['opened'] ?? 99) === 0, (string) ($r2->data['opened'] ?? -1));
    $chk('still exactly 2 cases stored', count($db->rows['reconciliation_cases']) === 2, (string) count($db->rows['reconciliation_cases']));

    // ---- fix c1 (post its ledger) then reconcile: case auto-resolves -------
    $db->rows['journal_entries'][] = ['id' => 'e1', 'organization_id' => $ORG, 'source_ref' => 'contribution:c1'];
    $db->rows['journal_lines'][]   = ['entry_id' => 'e1', 'direction' => 'debit', 'amount_minor' => 5000];
    $r3 = $svc->reconcile($ORG, 500);
    $chk('c1 case auto-resolved after ledger posted', $statusOfCase($db, 'missing_ledger:contribution:c1') === 'resolved');
    $chk('resolved count >= 1', ($r3->data['resolved'] ?? 0) >= 1, (string) ($r3->data['resolved'] ?? -1));
    $chk('open_total dropped to 1', ($r3->data['open_total'] ?? 0) === 1, (string) ($r3->data['open_total'] ?? -1));

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
