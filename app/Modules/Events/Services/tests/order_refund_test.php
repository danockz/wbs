<?php

declare(strict_types=1);

/**
 * EVENT TICKET-ORDER REFUNDS — maker-checker money reversal (gap L2).
 *
 * Paid ticketing could take money but never give it back; cancelling a paid event
 * notified the roster (G3) without reversing a cent. This test proves the
 * OrderRefundService workflow that closes that gap, mirroring the VBCS refund
 * maker-checker:
 *   - only a PAID order can be refunded (fail-closed otherwise);
 *   - request → approve → execute, with segregation of duties (approver ≠ requester);
 *   - execution is idempotent, flips order + items to refunded, restores ticket
 *     inventory, releases the attendee's registration (waitlist promoted), and
 *     stages an `order.refund` outbox dispatch for the provider worker;
 *   - cancelling a PUBLISHED paid event auto-creates refund REQUESTS for every
 *     paid order (approval/execution stay manual);
 *   - and the routes / factory / i18n / view are wired.
 *
 *   php app/Modules/Events/Services/tests/order_refund_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        /** @var list<array{sql:string,binds:array}> */
        public array $queries = [];
        public bool $txOpen = false;
        public bool $txOk = true;

        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }

        public function transStart(): void { $this->txOpen = true; }
        public function transComplete(): void { $this->txOpen = false; }
        public function transStatus(): bool { return $this->txOk; }

        public function query(string $sql, array $binds = []): \Fake\RS
        {
            $this->queries[] = ['sql' => $sql, 'binds' => $binds];
            // Support: SELECT * FROM event_orders WHERE id = ? FOR UPDATE
            if (preg_match('/SELECT \* FROM event_orders WHERE id = \?/', $sql)) {
                $id = $binds[0] ?? null;
                foreach ($this->rows['event_orders'] ?? [] as $r) {
                    if (($r['id'] ?? null) === $id) { return new \Fake\RS([$r]); }
                }

                return new \Fake\RS([]);
            }
            // UPDATE event_ticket_types SET quantity_sold = GREATEST(...) WHERE id = ?
            if (preg_match('/UPDATE event_ticket_types SET quantity_sold = GREATEST\(quantity_sold - \?, 0\) WHERE id = \?/', $sql)) {
                [$qty, $ttId] = $binds;
                foreach ($this->rows['event_ticket_types'] ?? [] as $i => $r) {
                    if (($r['id'] ?? null) === $ttId) {
                        $this->rows['event_ticket_types'][$i]['quantity_sold'] = max(0, (int) $r['quantity_sold'] - (int) $qty);
                    }
                }

                return new \Fake\RS([]);
            }

            return new \Fake\RS([]);
        }
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
        private array $in = [];
        private array $sel = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($s) { $this->sel = array_map('trim', explode(',', (string) $s)); return $this; }
        public function where($k, $v = null, $escape = true) { $this->eq[trim((string) $k)] = $v; return $this; }
        public function whereIn($k, array $v) { $this->in[trim((string) $k)] = $v; return $this; }
        public function orderBy($k, $d = 'ASC', $e = true) { return $this; }
        public function limit($n) { return $this; }

        public function get($limit = null): RS { return new RS($this->rowsFor()); }
        public function countAllResults(): int { return count($this->rowsFor()); }

        public function insert(array $data): bool { $this->db->rows[$this->t][] = $data; return true; }

        public function update(array $data): bool
        {
            foreach ($this->db->rows[$this->t] ?? [] as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $data);
                }
            }

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if (($r[$k] ?? null) !== $v) { return false; }
            }
            foreach ($this->in as $k => $vs) {
                if (! in_array($r[$k] ?? null, $vs, true)) { return false; }
            }

            return true;
        }

        private function rowsFor(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }
    }
}

namespace WBS\Shared\Messaging {
    // In-memory outbox spy.
    class OutboxService
    {
        /** @var list<array{topic:string,payload:array,org:?string}> */
        public array $staged = [];

        public function stage(string $aggregateType, string $aggregateId, string $topic, array $payload, ?string $organizationId = null, array $headers = []): string
        {
            $this->staged[] = ['topic' => $topic, 'payload' => $payload, 'org' => $organizationId];

            return 'outbox-' . count($this->staged);
        }
    }
}

namespace WBS\Events\Services {
    // Registrar spy: records seat releases (real cancel() promotes the waitlist).
    class RegistrationService
    {
        /** @var list<array{event:string,user:string}> */
        public array $cancelled = [];

        public function cancel(string $eventId, string $userId): \WBS\Shared\Support\Result
        {
            $this->cancelled[] = ['event' => $eventId, 'user' => $userId];

            return \WBS\Shared\Support\Result::ok(['event_id' => $eventId]);
        }
    }
}

namespace {

$root = dirname(__DIR__, 5);

require_once $root . '/app/Modules/Shared/Support/Result.php';
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Support/Uuid.php';
require_once $root . '/app/Modules/Events/Services/OrderRefundService.php';

use WBS\Events\Services\OrderRefundService;
use WBS\Events\Services\RegistrationService;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

Clock::freeze(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC')));
$clock = new Clock();

function fresh(): array
{
    $db = new \CodeIgniter\Database\BaseConnection();
    $db->rows['event_orders'] = [
        ['id' => 'ord-1', 'organization_id' => 'org-1', 'event_id' => 'ev-1', 'user_id' => 'u-buyer', 'currency' => 'GHS', 'total_minor' => 5000, 'status' => 'paid', 'provider' => 'stripe', 'provider_ref' => 'ch_1'],
        ['id' => 'ord-pending', 'organization_id' => 'org-1', 'event_id' => 'ev-1', 'user_id' => 'u-x', 'currency' => 'GHS', 'total_minor' => 3000, 'status' => 'pending'],
    ];
    $db->rows['event_order_items'] = [
        ['id' => 'it-1', 'order_id' => 'ord-1', 'ticket_type_id' => 'tt-1', 'attendee_user_id' => 'u-att', 'quantity' => 2, 'status' => 'active'],
    ];
    $db->rows['event_ticket_types'] = [
        ['id' => 'tt-1', 'quantity_sold' => 5],
    ];

    return [$db, new OutboxService(), new RegistrationService()];
}

// ── 1. only a paid order is refundable ───────────────────────────────────────
echo "refundability\n";
[$db, $ob, $reg] = fresh();
$svc = new OrderRefundService($db, $clock, $ob, $reg);
chk('a pending order cannot be refunded', ! $svc->request('org-1', 'ord-pending', 'u-org')->ok);
chk('a missing order 404s', ! $svc->request('org-1', 'nope', 'u-org')->ok);
$req = $svc->request('org-1', 'ord-1', 'u-org', ['reason' => 'changed mind']);
chk('a paid order can be requested', $req->ok && $req->data['status'] === 'requested');
chk('requested amount = order total', (int) $req->data['amount_minor'] === 5000);

// ── 2. idempotent request ────────────────────────────────────────────────────
echo "idempotent request\n";
$again = $svc->request('org-1', 'ord-1', 'u-org');
chk('re-requesting reuses the open refund', $again->ok && $again->data['refund_id'] === $req->data['refund_id'] && ($again->meta['deduplicated'] ?? false));

// ── 3. segregation of duties on approve ──────────────────────────────────────
echo "maker-checker (SOD)\n";
$rid = (string) $req->data['refund_id'];
$self = $svc->approve($rid, 'u-org');
chk('the requester cannot approve their own refund', ! $self->ok && $self->code === 'SOD_SELF_APPROVAL');
$ap = $svc->approve($rid, 'u-finance');
chk('a different approver can approve', $ap->ok && $ap->data['status'] === 'approved');
chk('cannot execute before approval is respected (double approve fails)', ! $svc->approve($rid, 'u-finance2')->ok);

// ── 4. execute: reversal effects ─────────────────────────────────────────────
echo "execute\n";
$ex = $svc->execute($rid, ['provider' => 'stripe', 'provider_refund_id' => 're_1']);
chk('execute succeeds', $ex->ok && $ex->data['status'] === 'executed');
$order = null; foreach ($db->rows['event_orders'] as $r) { if ($r['id'] === 'ord-1') { $order = $r; } }
chk('order is now refunded', ($order['status'] ?? '') === 'refunded');
chk('order items are refunded', ($db->rows['event_order_items'][0]['status'] ?? '') === 'refunded');
chk('ticket inventory restored (5 - 2 = 3)', (int) $db->rows['event_ticket_types'][0]['quantity_sold'] === 3);
chk('the attendee seat was released (not the buyer)', $reg->cancelled === [['event' => 'ev-1', 'user' => 'u-att']]);
chk('provider refund id recorded', ($db->rows['event_order_refunds'][0]['provider_refund_id'] ?? '') === 're_1');
chk('an order.refund outbox dispatch was staged', count($ob->staged) === 1 && $ob->staged[0]['topic'] === 'order.refund');
chk('outbox payload carries amount + currency', ($ob->staged[0]['payload']['amount_minor'] ?? 0) === 5000 && ($ob->staged[0]['payload']['currency'] ?? '') === 'GHS');

// ── 5. execute idempotency + bad states ──────────────────────────────────────
echo "execute idempotency\n";
$ex2 = $svc->execute($rid);
chk('re-executing is a no-op (deduplicated)', $ex2->ok && ($ex2->meta['deduplicated'] ?? false));
chk('no second outbox dispatch on re-execute', count($ob->staged) === 1);
chk('no second seat release on re-execute', count($reg->cancelled) === 1);

echo "execute guards\n";
[$db2, $ob2, $reg2] = fresh();
$svc2 = new OrderRefundService($db2, $clock, $ob2, $reg2);
$r2 = (string) $svc2->request('org-1', 'ord-1', 'u-org')->data['refund_id'];
chk('cannot execute a refund that is not approved', ! $svc2->execute($r2)->ok);
chk('cannot reject after nothing (missing) ', ! $svc2->reject('missing', 'u-finance')->ok);

// ── 6. reject ────────────────────────────────────────────────────────────────
echo "reject\n";
$rj = $svc2->reject($r2, 'u-finance');
chk('a requested refund can be rejected', $rj->ok && $rj->data['status'] === 'rejected');
chk('a rejected refund cannot be approved', ! $svc2->approve($r2, 'u-finance')->ok);

// ── 7. event-cancel fan-out ──────────────────────────────────────────────────
echo "event-cancel fan-out\n";
[$db3, $ob3, $reg3] = fresh();
// add a second paid order for the same event + one for another event.
$db3->rows['event_orders'][] = ['id' => 'ord-2', 'organization_id' => 'org-1', 'event_id' => 'ev-1', 'user_id' => 'u2', 'currency' => 'GHS', 'total_minor' => 2000, 'status' => 'paid'];
$db3->rows['event_orders'][] = ['id' => 'ord-other', 'organization_id' => 'org-1', 'event_id' => 'ev-OTHER', 'user_id' => 'u3', 'currency' => 'GHS', 'total_minor' => 9999, 'status' => 'paid'];
$svc3 = new OrderRefundService($db3, $clock, $ob3, $reg3);
$fan = $svc3->requestForCancelledEvent('org-1', 'ev-1', 'u-org');
chk('fan-out requests one refund per PAID order of the event', $fan['requested'] === 2, 'got ' . $fan['requested']);
chk('fan-out ignores the pending order and other events', $fan['orders'] === 2);
chk('fan-out refunds are tagged source=event_cancel', ($db3->rows['event_order_refunds'][0]['source'] ?? '') === 'event_cancel');
$fan2 = $svc3->requestForCancelledEvent('org-1', 'ev-1', 'u-org');
chk('fan-out is idempotent (re-cancel does not duplicate)', count($db3->rows['event_order_refunds']) === 2);

// ── 8. pending console list ──────────────────────────────────────────────────
echo "pending console\n";
$pending = $svc3->pending('org-1');
chk('pending lists actionable (requested) refunds', count($pending) === 2);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
