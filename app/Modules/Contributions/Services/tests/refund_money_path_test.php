<?php

declare(strict_types=1);

/**
 * Refund money-path test (Phase 0: gaps C1, C2, C3; the C10 negative coverage).
 *
 * C1 — refund of a MANUAL contribution posts a compensating ledger entry, so the
 *      ledger nets to zero (the reversal lookup is provider-agnostic:
 *      contribution:{id} OR manual:{id}).
 * C3 — cumulative-refund guard: successive partial refunds can never sum past the
 *      original; a partial refund leaves the contribution `succeeded`, the final
 *      one flips it to `refunded`.
 * C2 — provider-originated refund (markProviderRefund) reverses the ledger + moves
 *      the contribution to `reversed` (idempotent); a dispute (markDisputed) moves
 *      it to `disputed`.
 *
 * Uses the REAL LedgerService + RefundService + ContributionService against an
 * in-memory DB fake, so the ledger balance assertion is genuine.
 *
 *   php app/Modules/Contributions/Services/tests/refund_money_path_test.php
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

        public function transStart(): void
        {
        }

        public function transComplete(): void
        {
        }

        public function transRollback(): void
        {
        }

        public function transStatus(): bool
        {
            return true;
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
        private array $ne = [];
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($s)
        {
            return $this;
        }

        public function join($a, $b, $c = null)
        {
            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        public function limit($a, $b = null)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '!=')) {
                $this->ne[trim(substr($k, 0, -2))] = $v;
            } else {
                $this->eq[$k] = $v;
            }

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = $vals;

            return $this;
        }

        public function get(): RS
        {
            return new RS($this->matching());
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
            foreach ($this->ne as $k => $v) {
                if (($r[$k] ?? null) === $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array($r[$k] ?? null, $vals, true)) {
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
        /** @var list<array{topic:string,payload:array}> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'payload' => $payload];

            return 'ob';
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\ContributionService;
    use WBS\Contributions\Services\LedgerService;
    use WBS\Contributions\Services\RefundService;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Contributions/Services/LedgerService.php';
    require_once $root . '/app/Modules/Contributions/Services/RefundService.php';
    require_once $root . '/app/Modules/Contributions/Services/ContributionService.php';

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
    $clock = new Clock();

    // Net balance of an account across all journal lines (credits - debits).
    $balance = static function (BaseConnection $db, string $account): int {
        $entryIds = array_column($db->rows['journal_entries'] ?? [], 'id');
        $net = 0;
        foreach ($db->rows['journal_lines'] ?? [] as $l) {
            if (($l['account'] ?? null) !== $account) {
                continue;
            }
            if (! in_array($l['entry_id'] ?? null, $entryIds, true)) {
                continue;
            }
            $net += ($l['direction'] === 'credit' ? 1 : -1) * (int) $l['amount_minor'];
        }

        return $net;
    };

    // ---- C1: refund of a MANUAL contribution nets the ledger to zero -------
    $db = new BaseConnection();
    $db->rows['journal_entries'] = [];
    $db->rows['journal_lines'] = [];
    $db->rows['refund_requests'] = [];
    $db->rows['payment_transactions'] = [];
    $db->rows['contributions'] = [[
        'id' => 'con-m', 'organization_id' => $ORG, 'cause_id' => 'cause-1', 'user_id' => 'u-1',
        'amount_minor' => 10000, 'currency' => 'USD', 'state' => 'succeeded',
    ]];
    $ledger = new LedgerService($db, $clock);
    // Manual gift posts with the `manual:` source_ref.
    $ledger->post($ORG, 'succeeded', 'USD', [
        ['account' => 'cash_clearing', 'direction' => 'debit', 'amount_minor' => 10000],
        ['account' => 'cause_funds', 'direction' => 'credit', 'amount_minor' => 10000],
    ], 'manual:con-m', ['cause_id' => 'cause-1', 'contribution_id' => 'con-m']);
    chk('C1 cause_funds credited before refund', $balance($db, 'cause_funds') === 10000);

    $refunds = new RefundService($db, $clock, $ledger, new OutboxService());
    $rq = $refunds->request($ORG, 'con-m', 10000, 'req-1', 'donor asked');
    chk('C1 full refund requested', $rq->ok);
    $refunds->approve($rq->data['refund_id'], 'approver-1');
    $ex = $refunds->execute($rq->data['refund_id'], ['provider' => 'manual']);
    chk('C1 refund executed', $ex->ok);
    chk('C1 manual refund reverses ledger to ZERO', $balance($db, 'cause_funds') === 0);
    $conM = $db->table('contributions')->where('id', 'con-m')->get()->getRowArray();
    chk('C1 contribution now refunded', $conM['state'] === 'refunded');

    // ---- C3: cumulative partial refunds cannot exceed the original ---------
    $db = new BaseConnection();
    $db->rows['journal_entries'] = [];
    $db->rows['journal_lines'] = [];
    $db->rows['refund_requests'] = [];
    $db->rows['payment_transactions'] = [];
    $db->rows['contributions'] = [[
        'id' => 'con-p', 'organization_id' => $ORG, 'cause_id' => 'cause-1', 'user_id' => 'u-1',
        'amount_minor' => 10000, 'currency' => 'USD', 'state' => 'succeeded',
    ]];
    $ledger = new LedgerService($db, $clock);
    $ledger->post($ORG, 'succeeded', 'USD', [
        ['account' => 'provider_clearing', 'direction' => 'debit', 'amount_minor' => 10000],
        ['account' => 'cause_funds', 'direction' => 'credit', 'amount_minor' => 10000],
    ], 'contribution:con-p', ['cause_id' => 'cause-1', 'contribution_id' => 'con-p']);
    $refunds = new RefundService($db, $clock, $ledger, new OutboxService());

    $r1 = $refunds->request($ORG, 'con-p', 6000, 'req-1');
    chk('C3 first partial (6000) allowed', $r1->ok);
    $refunds->approve($r1->data['refund_id'], 'ap-1');
    $refunds->execute($r1->data['refund_id']);
    $conP = $db->table('contributions')->where('id', 'con-p')->get()->getRowArray();
    chk('C3 partial refund leaves state succeeded', $conP['state'] === 'succeeded');

    // second partial that would exceed remaining (10000-6000=4000) -> rejected
    $r2 = $refunds->request($ORG, 'con-p', 5000, 'req-1');
    chk('C3 second partial over remaining rejected', ! $r2->ok && $r2->code === 'REFUND_EXCEEDS_REMAINING');

    // second partial within remaining -> allowed, and completes the refund
    $r3 = $refunds->request($ORG, 'con-p', 4000, 'req-1');
    chk('C3 second partial within remaining allowed', $r3->ok);
    $refunds->approve($r3->data['refund_id'], 'ap-1');
    $refunds->execute($r3->data['refund_id']);
    $conP = $db->table('contributions')->where('id', 'con-p')->get()->getRowArray();
    chk('C3 full amount refunded flips to refunded', $conP['state'] === 'refunded');
    chk('C3 ledger nets to zero after full partial refunds', $balance($db, 'cause_funds') === 0);

    // ---- C3 race: two requests before approval, approval re-checks ----------
    $db = new BaseConnection();
    $db->rows['journal_entries'] = [];
    $db->rows['journal_lines'] = [];
    $db->rows['refund_requests'] = [];
    $db->rows['payment_transactions'] = [];
    $db->rows['contributions'] = [[
        'id' => 'con-r', 'organization_id' => $ORG, 'cause_id' => 'c', 'user_id' => 'u',
        'amount_minor' => 10000, 'currency' => 'USD', 'state' => 'succeeded',
    ]];
    $ledger = new LedgerService($db, $clock);
    $refunds = new RefundService($db, $clock, $ledger, new OutboxService());
    $a = $refunds->request($ORG, 'con-r', 7000, 'req-1');
    $b = $refunds->request($ORG, 'con-r', 7000, 'req-1'); // both pass request() (nothing committed yet)
    chk('C3 both requests created (uncommitted)', $a->ok && $b->ok);
    chk('C3 approve first ok', $refunds->approve($a->data['refund_id'], 'ap-1')->ok);
    $bad = $refunds->approve($b->data['refund_id'], 'ap-1'); // now 7000 already committed, 3000 remains
    chk('C3 approve second over remaining rejected at commit', ! $bad->ok && $bad->code === 'REFUND_EXCEEDS_REMAINING');

    // ---- C2: provider-originated refund reverses + idempotent --------------
    $db = new BaseConnection();
    $db->rows['journal_entries'] = [];
    $db->rows['journal_lines'] = [];
    $db->rows['payment_transactions'] = [[
        'id' => 'pt-1', 'organization_id' => $ORG, 'contribution_id' => 'con-w', 'provider' => 'stripe',
        'provider_txn_id' => 'ch_123', 'type' => 'charge', 'amount_minor' => 10000, 'currency' => 'USD', 'status' => 'succeeded',
    ]];
    $db->rows['contributions'] = [[
        'id' => 'con-w', 'organization_id' => $ORG, 'cause_id' => 'cause-1', 'user_id' => 'u-9',
        'amount_minor' => 10000, 'currency' => 'USD', 'state' => 'succeeded',
    ]];
    $ledger = new LedgerService($db, $clock);
    $ledger->post($ORG, 'succeeded', 'USD', [
        ['account' => 'provider_clearing', 'direction' => 'debit', 'amount_minor' => 10000],
        ['account' => 'cause_funds', 'direction' => 'credit', 'amount_minor' => 10000],
    ], 'contribution:con-w', ['cause_id' => 'cause-1', 'contribution_id' => 'con-w']);
    $outbox = new OutboxService();
    $contrib = new ContributionService($db, $clock, $ledger, $outbox);

    $pr = $contrib->markProviderRefund($ORG, ['provider' => 'stripe', 'provider_refund_id' => 're_1', 'provider_txn_id' => 'ch_123']);
    chk('C2 provider refund applied', $pr->ok && $pr->data['state'] === 'reversed');
    chk('C2 provider refund reverses ledger to zero', $balance($db, 'cause_funds') === 0);
    chk('C2 provider refund stages reversal signal', $outbox->staged !== [] && $outbox->staged[count($outbox->staged) - 1]['topic'] === 'contribution.refunded');
    // idempotent replay
    $pr2 = $contrib->markProviderRefund($ORG, ['provider' => 'stripe', 'provider_refund_id' => 're_1', 'provider_txn_id' => 'ch_123']);
    chk('C2 provider refund idempotent', $pr2->ok && ($pr2->meta['deduplicated'] ?? false) === true);

    // ---- C2: dispute freezes the gift --------------------------------------
    $db = new BaseConnection();
    $db->rows['journal_entries'] = [];
    $db->rows['journal_lines'] = [];
    $db->rows['payment_transactions'] = [[
        'id' => 'pt-2', 'organization_id' => $ORG, 'contribution_id' => 'con-d', 'type' => 'charge',
        'provider_txn_id' => 'ch_d', 'amount_minor' => 5000, 'currency' => 'USD', 'status' => 'succeeded',
    ]];
    $db->rows['contributions'] = [[
        'id' => 'con-d', 'organization_id' => $ORG, 'cause_id' => 'c', 'user_id' => 'u',
        'amount_minor' => 5000, 'currency' => 'USD', 'state' => 'succeeded',
    ]];
    $outbox = new OutboxService();
    $contrib = new ContributionService($db, $clock, new LedgerService($db, $clock), $outbox);
    $d = $contrib->markDisputed($ORG, ['provider_txn_id' => 'ch_d', 'dispute_id' => 'dp_1', 'reason' => 'fraud']);
    chk('C2 dispute applied', $d->ok && $d->data['state'] === 'disputed');
    $conD = $db->table('contributions')->where('id', 'con-d')->get()->getRowArray();
    chk('C2 disputed contribution is frozen (state disputed)', $conD['state'] === 'disputed');

    // a disputed contribution is no longer refundable via the internal path
    $refunds = new RefundService($db, $clock, new LedgerService($db, $clock), new OutboxService());
    $rf = $refunds->request($ORG, 'con-d', 5000, 'req-1');
    chk('C2 disputed gift not refundable internally', ! $rf->ok && $rf->code === 'NOT_REFUNDABLE');

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
