<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event ticket-order refunds — maker-checker money reversal (gap L2).
 *
 * Paid ticketing could take money but never give it back. This service is the
 * reversal workflow, mirroring the VBCS RefundService (SRS FR-VBCS-007) so money
 * reversal behaves the same across the platform:
 *
 *   request → approve → execute
 *
 *  - Segregation of duties: the approver MUST differ from the requester
 *    (SOD_SELF_APPROVAL otherwise).
 *  - Amount is the order's captured total (full refund); partial line refunds are
 *    a later extension.
 *  - Execution is idempotent and, inside one transaction: flips the order + its
 *    items to `refunded`, restores per-ticket-type inventory, releases the
 *    attendees' registrations (promoting the waitlist via RegistrationService),
 *    and stages an `order.refund` outbox message for the payment-provider worker.
 *    No payment gateway is called inline; the provider's refund id is recorded on
 *    execution or a later callback.
 *
 * Only a PAID order can be refunded; a pending/expired/cancelled order has no
 * captured money to reverse.
 */
final class OrderRefundService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly OutboxService $outbox,
        // Seat release + waitlist promotion on execution. Optional so the pure
        // maker-checker state machine can be unit-tested without the registrar.
        private readonly ?RegistrationService $registrations = null,
    ) {
    }

    /**
     * Request a refund for a PAID order (maker step).
     *
     * @param array<string,mixed> $opts reason, source (manual|event_cancel)
     */
    public function request(string $organizationId, string $orderId, string $requestedBy, array $opts = []): Result
    {
        $order = $this->db->table('event_orders')->where('id', $orderId)->get()->getRowArray();
        if ($order === null) {
            return Result::notFound('Events.refund.errOrderNotFound', 'ORDER_NOT_FOUND');
        }
        if ((string) $order['status'] !== 'paid') {
            return Result::fail('NOT_REFUNDABLE', 'Events.refund.errNotRefundable', 409, ['status' => $order['status']]);
        }

        // Idempotent per order: an open (requested/approved) refund is reused, and
        // an already-executed refund short-circuits so a cancel fan-out re-run or
        // a double click never double-reverses.
        $open = $this->db->table('event_order_refunds')
            ->where('order_id', $orderId)
            ->whereIn('status', ['requested', 'approved', 'executed'])
            ->get()->getRowArray();
        if ($open !== null) {
            return Result::ok([
                'refund_id' => $open['id'],
                'status'    => $open['status'],
            ], 200, ['deduplicated' => true]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_order_refunds')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => (string) $order['event_id'],
            'order_id'        => $orderId,
            'amount_minor'    => (int) $order['total_minor'],
            'currency'        => (string) $order['currency'],
            'reason'          => ($opts['reason'] ?? '') !== '' ? (string) $opts['reason'] : null,
            'source'          => in_array(($opts['source'] ?? 'manual'), ['manual', 'event_cancel'], true) ? (string) ($opts['source'] ?? 'manual') : 'manual',
            'requested_by'    => $requestedBy,
            'status'          => 'requested',
            'created_at'      => $now,
        ]);

        return Result::created(['refund_id' => $id, 'status' => 'requested', 'amount_minor' => (int) $order['total_minor']]);
    }

    /** Approve a requested refund (checker step). Enforces approver != requester. */
    public function approve(string $refundId, string $approverId): Result
    {
        $r = $this->find($refundId);
        if ($r === null) {
            return Result::notFound('Events.refund.errNotFound', 'REFUND_NOT_FOUND');
        }
        if ((string) $r['status'] !== 'requested') {
            return Result::fail('BAD_STATE', 'Events.refund.errBadState', 409, ['status' => $r['status']]);
        }
        if ((string) $r['requested_by'] === $approverId) {
            return Result::fail('SOD_SELF_APPROVAL', 'Events.refund.errSelfApproval', 403);
        }
        $this->db->table('event_order_refunds')->where('id', $refundId)->update([
            'status'      => 'approved',
            'approved_by' => $approverId,
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['refund_id' => $refundId, 'status' => 'approved']);
    }

    /** Reject a requested refund. */
    public function reject(string $refundId, string $rejectedBy): Result
    {
        $r = $this->find($refundId);
        if ($r === null) {
            return Result::notFound('Events.refund.errNotFound', 'REFUND_NOT_FOUND');
        }
        if ((string) $r['status'] !== 'requested') {
            return Result::fail('BAD_STATE', 'Events.refund.errBadState', 409, ['status' => $r['status']]);
        }
        $this->db->table('event_order_refunds')->where('id', $refundId)->update([
            'status'      => 'rejected',
            'approved_by' => $rejectedBy,
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['refund_id' => $refundId, 'status' => 'rejected']);
    }

    /**
     * Execute an approved refund. Idempotent: re-executing an executed refund is
     * a no-op. In one transaction: order + items → refunded, ticket inventory
     * restored, registrations released (waitlist promoted), and an `order.refund`
     * message staged for the provider worker.
     *
     * @param array<string,mixed> $txn provider, provider_refund_id
     */
    public function execute(string $refundId, array $txn = []): Result
    {
        $r = $this->find($refundId);
        if ($r === null) {
            return Result::notFound('Events.refund.errNotFound', 'REFUND_NOT_FOUND');
        }
        if ((string) $r['status'] === 'executed') {
            return Result::ok(['refund_id' => $refundId, 'status' => 'executed'], 200, ['deduplicated' => true]);
        }
        // 'failed' is retryable (a prior dispatch could not complete); 'approved'
        // is the normal path. Anything else must be approved first.
        if (! in_array((string) $r['status'], ['approved', 'failed'], true)) {
            return Result::fail('NOT_APPROVED', 'Events.refund.errNotApproved', 409, ['status' => $r['status']]);
        }

        $orgId   = (string) $r['organization_id'];
        $orderId = (string) $r['order_id'];
        $eventId = (string) $r['event_id'];
        $now     = $this->clock->nowUtcMicro();

        $this->db->transStart();

        // Guard against a concurrent state change on the order itself.
        $order = $this->db->query('SELECT * FROM event_orders WHERE id = ? FOR UPDATE', [$orderId])->getRowArray();
        if ($order === null || (string) $order['status'] !== 'paid') {
            $this->db->transComplete();

            return Result::fail('ORDER_NOT_PAID', 'Events.refund.errOrderNotPaid', 409, ['status' => $order['status'] ?? null]);
        }

        // Restore per-ticket-type inventory and collect the attendees whose seats
        // are being released.
        $lines     = $this->db->table('event_order_items')->where('order_id', $orderId)->get()->getResultArray();
        $attendees = [];
        foreach ($lines as $line) {
            if ((string) $line['status'] === 'active') {
                $this->db->query(
                    'UPDATE event_ticket_types SET quantity_sold = GREATEST(quantity_sold - ?, 0) WHERE id = ?',
                    [(int) $line['quantity'], $line['ticket_type_id']],
                );
            }
            $attendee = (string) ($line['attendee_user_id'] ?? '') !== '' ? (string) $line['attendee_user_id'] : (string) $order['user_id'];
            if ($attendee !== '') {
                $attendees[$attendee] = true;
            }
        }

        $this->db->table('event_order_items')->where('order_id', $orderId)->update([
            'status' => 'refunded',
        ]);
        $this->db->table('event_orders')->where('id', $orderId)->update([
            'status'     => 'refunded',
            'updated_at' => $now,
        ]);
        $this->db->table('event_order_refunds')->where('id', $refundId)->update([
            'status'             => 'executed',
            'provider'           => $txn['provider'] ?? null,
            'provider_refund_id' => $txn['provider_refund_id'] ?? null,
            'executed_at'        => $now,
            'updated_at'         => $now,
        ]);

        // Release each attendee's registration; RegistrationService::cancel also
        // promotes the waitlist. Done inside the same transaction.
        $released = [];
        if ($this->registrations !== null) {
            foreach (array_keys($attendees) as $attendee) {
                $this->registrations->cancel($eventId, $attendee);
                $released[] = $attendee;
            }
        }

        // Stage the provider refund dispatch (worker performs the actual gateway
        // call and posts back provider_refund_id). Mirrors the VBCS outbox path.
        $this->outbox->stage('event_order', $orderId, 'order.refund', [
            'refund_id'    => $refundId,
            'order_id'     => $orderId,
            'event_id'     => $eventId,
            'amount_minor' => (int) $r['amount_minor'],
            'currency'     => (string) $r['currency'],
            'provider'     => $order['provider'] ?? null,
            'provider_ref' => $order['provider_ref'] ?? null,
        ], $orgId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('EXECUTE_FAILED', 'Events.refund.errExecuteFailed', 500);
        }

        return Result::ok([
            'refund_id' => $refundId,
            'status'    => 'executed',
            'order_id'  => $orderId,
            'released'  => $released,
        ]);
    }

    /**
     * Auto-create refund REQUESTS for every paid order of a cancelled event
     * (fan-out). Approval + execution stay manual (auditable). Idempotent per
     * order via request(). Returns how many requests were created/reused.
     *
     * @return array{requested:int, orders:int}
     */
    public function requestForCancelledEvent(string $organizationId, string $eventId, string $requestedBy): array
    {
        $orders = $this->db->table('event_orders')
            ->select('id')
            ->where('event_id', $eventId)
            ->where('status', 'paid')
            ->get()->getResultArray();

        $requested = 0;
        foreach ($orders as $o) {
            $res = $this->request($organizationId, (string) $o['id'], $requestedBy, [
                'source' => 'event_cancel',
                'reason' => 'Event cancelled',
            ]);
            if ($res->ok) {
                $requested++;
            }
        }

        return ['requested' => $requested, 'orders' => count($orders)];
    }

    /**
     * Actionable refunds for the maker-checker console: everything not yet in a
     * terminal state (executed/rejected). Bounded read.
     *
     * @return list<array<string,mixed>>
     */
    public function pending(string $organizationId, int $limit = 200): array
    {
        return $this->db->table('event_order_refunds')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['requested', 'approved', 'failed'])
            ->orderBy('created_at', 'ASC')
            ->limit(max(1, min($limit, 500)))
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function find(string $refundId): ?array
    {
        return $this->db->table('event_order_refunds')->where('id', $refundId)->get()->getRowArray();
    }
}
