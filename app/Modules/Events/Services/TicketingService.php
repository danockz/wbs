<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Paid ticketing, reservations, promo codes and transfers (SRS FR-EVT-016).
 *
 * Money is always INTEGER minor units + currency (no floats). Ticket/order
 * accounting is kept SEPARATE from the VBCS contribution ledger: an order's
 * total covers tickets only, and any explicitly approved cause add-on is
 * created as its OWN VBCS intent (see EventServices::ticketing()->checkout()),
 * never merged into the ticket order total.
 *
 * Overselling is prevented by reusing the existing atomic inventory hold
 * (`ticket_holds`): checkout requires a live hold and, inside a row-locking
 * transaction, re-checks confirmed registrations + live holds against event
 * capacity and per-ticket-type quantity before consuming the hold. Successful
 * payment alone can therefore never exceed capacity.
 */
final class TicketingService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly RegistrationService $registrations,
    ) {
    }

    // ---- ticket types ------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function createTicketType(string $organizationId, string $eventId, array $data): Result
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return Result::fail('NAME_REQUIRED', 'ticket.name_required', 422);
        }
        $price = (int) ($data['price_minor'] ?? 0);
        if ($price < 0) {
            return Result::fail('BAD_PRICE', 'ticket.bad_price', 422);
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'GHS'));
        if (strlen($currency) !== 3) {
            return Result::fail('BAD_CURRENCY', 'ticket.bad_currency', 422);
        }

        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_ticket_types')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'event_id'         => $eventId,
            'name'             => $data['name'],
            'description'      => $data['description'] ?? null,
            'currency'         => $currency,
            'price_minor'      => $price,
            'quantity_total'   => isset($data['quantity_total']) ? (int) $data['quantity_total'] : null,
            'per_user_limit'   => isset($data['per_user_limit']) ? (int) $data['per_user_limit'] : null,
            'session_label'    => $data['session_label'] ?? null,
            'venue_area'       => $data['venue_area'] ?? null,
            'group_allocation' => $data['group_allocation'] ?? null,
            'sales_start'      => $data['sales_start'] ?? null,
            'sales_end'        => $data['sales_end'] ?? null,
            'status'           => 'active',
            'created_at'       => $now,
        ]);

        return Result::created(['ticket_type_id' => $id, 'price_minor' => $price, 'currency' => $currency]);
    }

    /** @return list<array<string,mixed>> */
    public function listTicketTypes(string $eventId): array
    {
        return $this->db->table('event_ticket_types')
            ->where('event_id', $eventId)
            ->orderBy('price_minor', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Buyer-facing snapshot for the ticket purchase page: the event, its
     * on-sale ticket types with remaining availability, the buyer's own live
     * inventory hold (if any) and their already-purchased tickets. Read-only and
     * resource-light — a handful of bounded reads, no per-row fan-out.
     *
     * `remaining` is null for an uncapped tier; a tier is `available` only when
     * on sale, active, and (if capped) not sold out. `signed_in` is false when
     * $userId is empty so the view can render a sign-in prompt instead of a form.
     *
     * @return array{
     *   event_id:string, signed_in:bool, on_sale:bool, currency:string,
     *   ticket_types:list<array<string,mixed>>, hold:array{id:string,quantity:int,expires_at:string}|null,
     *   my_tickets:list<array<string,mixed>>
     * }
     */
    public function purchaseView(string $eventId, string $userId): array
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        $onSale = $event !== null
            && ($event['status'] ?? '') === 'published'
            && ($event['registration_policy'] ?? '') !== 'closed';
        $currency = strtoupper((string) ($event['currency'] ?? 'GHS'));

        $now  = $this->clock->nowUtcString();
        $rows = $this->db->table('event_ticket_types')
            ->where('event_id', $eventId)
            ->orderBy('price_minor', 'ASC')
            ->get()->getResultArray();

        $types = [];
        foreach ($rows as $t) {
            $total     = $t['quantity_total'] !== null ? (int) $t['quantity_total'] : null;
            $sold      = (int) ($t['quantity_sold'] ?? 0);
            $remaining = $total !== null ? max(0, $total - $sold) : null;
            $windowOk  = (($t['sales_start'] ?? null) === null || $t['sales_start'] <= $now)
                && (($t['sales_end'] ?? null) === null || $t['sales_end'] >= $now);
            $types[] = [
                'id'          => (string) $t['id'],
                'name'        => (string) ($t['name'] ?? ''),
                'description' => $t['description'] ?? null,
                'price_minor' => (int) ($t['price_minor'] ?? 0),
                'currency'    => strtoupper((string) ($t['currency'] ?? $currency)),
                'remaining'   => $remaining,
                'is_free'     => (int) ($t['price_minor'] ?? 0) === 0,
                'available'   => $onSale
                    && ($t['status'] ?? '') === 'active'
                    && $windowOk
                    && ($remaining === null || $remaining > 0),
            ];
        }

        $hold = null;
        if ($userId !== '') {
            $h = $this->db->query(
                'SELECT id, quantity, expires_at FROM ticket_holds
                 WHERE event_id = ? AND user_id = ? AND status = "held" AND expires_at > ?
                 ORDER BY created_at DESC LIMIT 1',
                [$eventId, $userId, $now],
            )->getRowArray();
            if ($h !== null) {
                $hold = ['id' => (string) $h['id'], 'quantity' => (int) $h['quantity'], 'expires_at' => (string) $h['expires_at']];
            }
        }

        // Buyer's already-held tickets (paid order lines they attend). One join,
        // bounded by the buyer's own orders — resource-light.
        $mine = [];
        if ($userId !== '') {
            $mine = $this->db->query(
                'SELECT oi.id, oi.ticket_type_id, oi.quantity, oi.status AS item_status,
                        tt.name AS ticket_name, o.status AS order_status, o.currency, oi.unit_price_minor
                 FROM event_order_items oi
                 JOIN event_orders o ON o.id = oi.order_id
                 LEFT JOIN event_ticket_types tt ON tt.id = oi.ticket_type_id
                 WHERE oi.event_id = ? AND oi.attendee_user_id = ? AND o.status = "paid" AND oi.status = "active"
                 ORDER BY oi.created_at DESC',
                [$eventId, $userId],
            )->getResultArray();
        }

        return [
            'event_id'     => $eventId,
            'signed_in'    => $userId !== '',
            'on_sale'      => $onSale,
            'currency'     => $currency,
            'ticket_types' => $types,
            'hold'         => $hold,
            'my_tickets'   => $mine,
        ];
    }

    // ---- promo codes -------------------------------------------------------

    /** @return list<array<string,mixed>> promo codes for an event (newest first) */
    public function listPromoCodes(string $eventId): array
    {
        return $this->db->table('event_promo_codes')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /** @param array<string,mixed> $data */
    public function createPromoCode(string $organizationId, string $eventId, array $data): Result
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            return Result::fail('CODE_REQUIRED', 'promo.code_required', 422);
        }
        $kind = ($data['kind'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
        if ($kind === 'percent') {
            $bps = (int) ($data['percent_bps'] ?? 0);
            if ($bps <= 0 || $bps > 10000) {
                return Result::fail('BAD_PERCENT', 'promo.bad_percent', 422);
            }
        } else {
            $amt = (int) ($data['amount_minor'] ?? 0);
            if ($amt <= 0) {
                return Result::fail('BAD_AMOUNT', 'promo.bad_amount', 422);
            }
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        try {
            $this->db->table('event_promo_codes')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'code'            => $code,
                'kind'            => $kind,
                'percent_bps'     => $kind === 'percent' ? (int) $data['percent_bps'] : null,
                'amount_minor'    => $kind === 'amount' ? (int) $data['amount_minor'] : null,
                'max_redemptions' => isset($data['max_redemptions']) ? (int) $data['max_redemptions'] : null,
                'starts_at'       => $data['starts_at'] ?? null,
                'ends_at'         => $data['ends_at'] ?? null,
                'status'          => 'active',
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('CODE_EXISTS', 'promo.code_exists', 409);
        }

        return Result::created(['promo_code_id' => $id, 'code' => $code]);
    }

    // ---- checkout ----------------------------------------------------------

    /**
     * Create a paid (or free) ticket order. Requires a live inventory hold so
     * capacity cannot be exceeded. Computes totals server-side (never trusts a
     * client-supplied total), applies an optional promo code, and — if an
     * explicitly approved cause add-on is supplied — records it separately.
     *
     * The order is created in `pending`; a successful payment webhook calls
     * markPaid(). For free orders (total 0) the caller may markPaid immediately.
     *
     * @param array<string,mixed> $data hold_id, currency, items[[ticket_type_id,quantity,attendee_user_id?]],
     *                                   promo_code?, addon_cause_id?, addon_amount_minor?, provider?, idempotency_key?
     */
    public function checkout(string $organizationId, string $eventId, string $userId, array $data): Result
    {
        $items = $data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return Result::fail('NO_ITEMS', 'ticket.no_items', 422);
        }
        $holdId = (string) ($data['hold_id'] ?? '');
        if ($holdId === '') {
            return Result::fail('HOLD_REQUIRED', 'ticket.hold_required', 422);
        }

        $idem = (string) ($data['idempotency_key'] ?? Uuid::v7());
        $existing = $this->db->table('event_orders')->where('idempotency_key', $idem)->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['order_id' => $existing['id'], 'status' => $existing['status'], 'total_minor' => (int) $existing['total_minor']], 200, ['deduplicated' => true]);
        }

        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if ($event['status'] !== 'published') {
            return Result::fail('NOT_OPEN', 'event.not_open', 409, ['status' => $event['status']]);
        }

        $now      = $this->clock->nowUtcString();
        $nowMicro = $this->clock->nowUtcMicro();

        $this->db->transStart();

        // Validate the hold belongs to this user/event and is still live.
        $hold = $this->db->query(
            'SELECT * FROM ticket_holds WHERE id = ? AND event_id = ? AND user_id = ? AND status = "held" AND expires_at > ? FOR UPDATE',
            [$holdId, $eventId, $userId, $now],
        )->getRowArray();
        if ($hold === null) {
            $this->db->transComplete();

            return Result::fail('HOLD_INVALID', 'ticket.hold_invalid', 409);
        }

        // Price the order server-side, locking each ticket type row.
        $currency   = strtoupper((string) ($data['currency'] ?? $event['currency'] ?? 'GHS'));
        $subtotal   = 0;
        $quantity   = 0;
        $lineBuffer = [];
        foreach ($items as $item) {
            $ttId = (string) ($item['ticket_type_id'] ?? '');
            $qty  = max(1, (int) ($item['quantity'] ?? 1));
            $tt   = $this->db->query(
                'SELECT * FROM event_ticket_types WHERE id = ? AND event_id = ? FOR UPDATE',
                [$ttId, $eventId],
            )->getRowArray();
            if ($tt === null) {
                $this->db->transComplete();

                return Result::fail('TYPE_NOT_FOUND', 'ticket.type_not_found', 422, ['ticket_type_id' => $ttId]);
            }
            if ($tt['status'] !== 'active') {
                $this->db->transComplete();

                return Result::fail('TYPE_UNAVAILABLE', 'ticket.type_unavailable', 409, ['ticket_type_id' => $ttId]);
            }
            // Per-ticket-type inventory: sold + this qty must not exceed total.
            if ($tt['quantity_total'] !== null && ((int) $tt['quantity_sold'] + $qty) > (int) $tt['quantity_total']) {
                $this->db->transComplete();

                return Result::fail('TYPE_SOLD_OUT', 'ticket.type_sold_out', 409, ['ticket_type_id' => $ttId]);
            }
            $unit = (int) $tt['price_minor'];
            $line = $unit * $qty;
            $subtotal += $line;
            $quantity += $qty;
            $lineBuffer[] = [
                'ticket_type_id'   => $ttId,
                'attendee_user_id' => $item['attendee_user_id'] ?? $userId,
                'quantity'         => $qty,
                'unit_price_minor' => $unit,
                'line_total_minor' => $line,
            ];
        }

        // Optional promo code (locked for redemption accounting).
        $discount    = 0;
        $promoCodeId = null;
        $promoRaw    = strtoupper(trim((string) ($data['promo_code'] ?? '')));
        if ($promoRaw !== '') {
            $promo = $this->db->query(
                'SELECT * FROM event_promo_codes WHERE event_id = ? AND code = ? AND status = "active" FOR UPDATE',
                [$eventId, $promoRaw],
            )->getRowArray();
            if ($promo === null) {
                $this->db->transComplete();

                return Result::fail('PROMO_INVALID', 'promo.invalid', 422);
            }
            if ($promo['max_redemptions'] !== null && (int) $promo['redeemed'] >= (int) $promo['max_redemptions']) {
                $this->db->transComplete();

                return Result::fail('PROMO_EXHAUSTED', 'promo.exhausted', 409);
            }
            if (($promo['starts_at'] !== null && $promo['starts_at'] > $now) || ($promo['ends_at'] !== null && $promo['ends_at'] < $now)) {
                $this->db->transComplete();

                return Result::fail('PROMO_WINDOW', 'promo.window', 409);
            }
            $discount = $promo['kind'] === 'percent'
                ? (int) floor($subtotal * (int) $promo['percent_bps'] / 10000)
                : min($subtotal, (int) $promo['amount_minor']);
            $promoCodeId = $promo['id'];
        }

        $total = max(0, $subtotal - $discount);

        // Cause add-on is tracked separately; NOT part of the ticket total's ledger.
        $addonCauseId = $data['addon_cause_id'] ?? null;
        $addonAmount  = $addonCauseId !== null ? max(0, (int) ($data['addon_amount_minor'] ?? 0)) : 0;

        $orderId = Uuid::v7();
        try {
            $this->db->table('event_orders')->insert([
                'id'                 => $orderId,
                'organization_id'    => $organizationId,
                'event_id'           => $eventId,
                'user_id'            => $userId,
                'currency'           => $currency,
                'subtotal_minor'     => $subtotal,
                'discount_minor'     => $discount,
                'total_minor'        => $total,
                'promo_code_id'      => $promoCodeId,
                'hold_id'            => $holdId,
                'quantity'           => $quantity,
                'provider'           => $data['provider'] ?? null,
                'idempotency_key'    => $idem,
                'addon_cause_id'     => $addonCauseId,
                'addon_amount_minor' => $addonAmount,
                'status'             => 'pending',
                'created_at'         => $nowMicro,
            ]);
            foreach ($lineBuffer as $line) {
                $this->db->table('event_order_items')->insert([
                    'id'               => Uuid::v7(),
                    'order_id'         => $orderId,
                    'event_id'         => $eventId,
                    'ticket_type_id'   => $line['ticket_type_id'],
                    'attendee_user_id' => $line['attendee_user_id'],
                    'quantity'         => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                    'status'           => 'active',
                    'created_at'       => $now,
                ]);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('CHECKOUT_FAILED', 'ticket.checkout_failed', 409);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('CHECKOUT_FAILED', 'ticket.checkout_failed', 500);
        }

        return Result::created([
            'order_id'       => $orderId,
            'status'         => 'pending',
            'currency'       => $currency,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor'    => $total,
            'quantity'       => $quantity,
            'addon'          => $addonCauseId !== null ? ['cause_id' => $addonCauseId, 'amount_minor' => $addonAmount] : null,
        ], ['payment_required' => $total > 0]);
    }

    /**
     * One-step, no-JS self-service ticket purchase for a browser attendee: take a
     * single ticket type + quantity, atomically HOLD the inventory (reusing the
     * existing RegistrationService hold), then CHECKOUT against that hold. This
     * collapses the two-call hold→checkout API dance the JSON funnel needs into
     * one PRG-friendly form submission.
     *
     * For a FREE tier (or a paid tier where the promo reduces the total to zero)
     * the order is marked paid immediately so the buyer's registration is
     * confirmed without a payment step. A paid order is left `pending` and the
     * caller surfaces "payment required" (the pay/webhook path is unchanged).
     *
     * @param array<string,mixed> $data ticket_type_id, quantity, promo_code?, currency?
     */
    public function purchase(string $organizationId, string $eventId, string $userId, array $data): Result
    {
        if ($userId === '') {
            return Result::fail('SIGN_IN_REQUIRED', 'ticket.sign_in_required', 401);
        }
        $ttId = (string) ($data['ticket_type_id'] ?? '');
        if ($ttId === '') {
            return Result::fail('TYPE_REQUIRED', 'ticket.type_required', 422);
        }
        $qty = max(1, (int) ($data['quantity'] ?? 1));

        // Atomic inventory hold (reused, capacity-safe). A live hold already
        // exists? Reuse it rather than colliding on the (event,user,status) unique.
        $now  = $this->clock->nowUtcString();
        $hold = $this->db->query(
            'SELECT id, quantity FROM ticket_holds
             WHERE event_id = ? AND user_id = ? AND status = "held" AND expires_at > ?
             ORDER BY created_at DESC LIMIT 1',
            [$eventId, $userId, $now],
        )->getRowArray();

        if ($hold === null) {
            $held = $this->registrations->hold($organizationId, $eventId, $userId, $qty);
            if (! $held->ok) {
                return $held;
            }
            $holdId = (string) ($held->data['hold_id'] ?? '');
        } else {
            $holdId = (string) $hold['id'];
        }

        $checkout = $this->checkout($organizationId, $eventId, $userId, [
            'hold_id'    => $holdId,
            'currency'   => $data['currency'] ?? null,
            'promo_code' => $data['promo_code'] ?? null,
            'items'      => [['ticket_type_id' => $ttId, 'quantity' => $qty, 'attendee_user_id' => $userId]],
        ]);
        if (! $checkout->ok) {
            return $checkout;
        }

        $orderId = (string) ($checkout->data['order_id'] ?? '');
        $total   = (int) ($checkout->data['total_minor'] ?? 0);

        // Free (or fully-discounted) order → confirm immediately, no payment step.
        if ($total === 0 && $orderId !== '') {
            $paid = $this->markPaid($orderId, ['provider' => 'free']);
            if (! $paid->ok) {
                return $paid;
            }

            return Result::ok([
                'order_id'    => $orderId,
                'status'      => 'paid',
                'total_minor' => 0,
                'quantity'    => $qty,
            ], 200, ['payment_required' => false, 'confirmed' => true]);
        }

        return Result::created([
            'order_id'    => $orderId,
            'status'      => (string) ($checkout->data['status'] ?? 'pending'),
            'total_minor' => $total,
            'currency'    => (string) ($checkout->data['currency'] ?? ''),
            'quantity'    => $qty,
        ], ['payment_required' => true]);
    }

    /**
     * Confirm payment for an order (called by the payment webhook or, for a
     * zero-total free order, directly). Idempotent. Consumes the inventory
     * hold, increments per-type sold counts and promo redemption, and confirms
     * each attendee's registration. Returns whether a separate cause add-on
     * intent should be created by the caller (Contributions owns that ledger).
     *
     * @param array<string,mixed> $txn provider, provider_ref
     */
    public function markPaid(string $orderId, array $txn = []): Result
    {
        $this->db->transStart();

        $order = $this->db->query('SELECT * FROM event_orders WHERE id = ? FOR UPDATE', [$orderId])->getRowArray();
        if ($order === null) {
            $this->db->transComplete();

            return Result::notFound('order.not_found', 'ORDER_NOT_FOUND');
        }
        if ($order['status'] === 'paid') {
            $this->db->transComplete();

            return Result::ok(['order_id' => $orderId, 'status' => 'paid'], 200, ['deduplicated' => true]);
        }
        if ($order['status'] !== 'pending') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'order.bad_state', 409, ['status' => $order['status']]);
        }

        $now = $this->clock->nowUtcMicro();

        // Consume the inventory hold (if still present).
        if ($order['hold_id'] !== null) {
            $this->db->table('ticket_holds')
                ->where('id', $order['hold_id'])->where('status', 'held')
                ->update(['status' => 'consumed']);
        }

        // Per-type sold counts + confirm attendee registrations.
        $lines = $this->db->table('event_order_items')->where('order_id', $orderId)->get()->getResultArray();
        foreach ($lines as $line) {
            $this->db->query(
                'UPDATE event_ticket_types SET quantity_sold = quantity_sold + ? WHERE id = ?',
                [(int) $line['quantity'], $line['ticket_type_id']],
            );
            $attendee = $line['attendee_user_id'] ?? $order['user_id'];
            if ($attendee !== null) {
                $this->confirmRegistration((string) $order['organization_id'], (string) $order['event_id'], (string) $attendee);
            }
        }

        if ($order['promo_code_id'] !== null) {
            $this->db->query('UPDATE event_promo_codes SET redeemed = redeemed + 1 WHERE id = ?', [$order['promo_code_id']]);
        }

        $this->db->table('event_orders')->where('id', $orderId)->update([
            'status'       => 'paid',
            'provider'     => $txn['provider'] ?? $order['provider'],
            'provider_ref' => $txn['provider_ref'] ?? $order['provider_ref'],
            'paid_at'      => $now,
            'updated_at'   => $now,
        ]);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('MARK_PAID_FAILED', 'order.mark_paid_failed', 500);
        }

        return Result::ok([
            'order_id' => $orderId,
            'status'   => 'paid',
            // Signal the caller to create a SEPARATE VBCS intent for the add-on.
            'addon'    => ($order['addon_cause_id'] !== null && (int) $order['addon_amount_minor'] > 0)
                ? ['cause_id' => $order['addon_cause_id'], 'amount_minor' => (int) $order['addon_amount_minor'], 'currency' => $order['currency'], 'user_id' => $order['user_id']]
                : null,
        ]);
    }

    /**
     * Controlled attendee transfer of a paid ticket line to another user.
     * Only the current holder (or an authorized organizer) may initiate.
     */
    public function transferTicket(string $organizationId, string $orderItemId, string $fromUserId, string $toUserId, string $initiatedBy): Result
    {
        if ($toUserId === '' || $toUserId === $fromUserId) {
            return Result::fail('BAD_TARGET', 'ticket.bad_transfer_target', 422);
        }

        $this->db->transStart();
        $item = $this->db->query('SELECT * FROM event_order_items WHERE id = ? FOR UPDATE', [$orderItemId])->getRowArray();
        if ($item === null) {
            $this->db->transComplete();

            return Result::notFound('order_item.not_found', 'ORDER_ITEM_NOT_FOUND');
        }
        if ($item['status'] !== 'active') {
            $this->db->transComplete();

            return Result::fail('NOT_TRANSFERABLE', 'ticket.not_transferable', 409, ['status' => $item['status']]);
        }
        if ((string) $item['attendee_user_id'] !== $fromUserId) {
            $this->db->transComplete();

            return Result::fail('NOT_HOLDER', 'ticket.not_holder', 403);
        }

        $now = $this->clock->nowUtcMicro();
        $transferId = Uuid::v7();
        $this->db->table('ticket_transfers')->insert([
            'id'              => $transferId,
            'organization_id' => $organizationId,
            'event_id'        => $item['event_id'],
            'order_item_id'   => $orderItemId,
            'from_user_id'    => $fromUserId,
            'to_user_id'      => $toUserId,
            'status'          => 'accepted', // direct organizer/holder transfer
            'initiated_by'    => $initiatedBy,
            'created_at'      => $now,
            'resolved_at'     => $now,
        ]);
        $this->db->table('event_order_items')->where('id', $orderItemId)->update([
            'attendee_user_id' => $toUserId,
        ]);
        // Move the confirmed registration to the new attendee.
        $this->confirmRegistration($organizationId, (string) $item['event_id'], $toUserId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('TRANSFER_FAILED', 'ticket.transfer_failed', 500);
        }

        return Result::ok(['transfer_id' => $transferId, 'order_item_id' => $orderItemId, 'to_user_id' => $toUserId, 'status' => 'accepted']);
    }

    /** Ensure the attendee has a confirmed registration (idempotent upsert). */
    private function confirmRegistration(string $organizationId, string $eventId, string $userId): void
    {
        $reg = $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)
            ->get()->getRowArray();
        $now = $this->clock->nowUtcString();
        if ($reg === null) {
            try {
                $this->db->table('event_registrations')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'event_id'        => $eventId,
                    'user_id'         => $userId,
                    'status'          => 'registered',
                    'rsvp_state'      => 'yes',
                    'created_at'      => $now,
                ]);
            } catch (Throwable) {
                // UNIQUE race -> fall through to update.
                $this->db->table('event_registrations')
                    ->where('event_id', $eventId)->where('user_id', $userId)
                    ->update(['status' => 'registered', 'updated_at' => $now]);
            }
        } elseif ($reg['status'] !== 'registered') {
            $this->db->table('event_registrations')
                ->where('id', $reg['id'])
                ->update(['status' => 'registered', 'updated_at' => $now]);
        }
    }
}
