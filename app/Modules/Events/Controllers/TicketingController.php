<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Paid ticketing, promo codes, reservations and transfers (SRS FR-EVT-016).
 *
 * Ticket accounting is kept separate from VBCS: when an order with an approved
 * cause add-on is paid, this controller creates a SEPARATE contribution intent
 * via the Contributions module rather than blending it into the ticket ledger.
 */
final class TicketingController extends BaseController
{
    /**
     * GET events/{id}/ticketing — the browser TICKETING CONSOLE: the event's
     * ticket types and promo codes, each section with a no-JS PRG form to create a
     * new one. API clients get the same data as JSON. `renderForm` mints the
     * `_csrf` token the webcsrf-guarded POSTs need.
     */
    public function console(string $eventId = '')
    {
        $svc   = EventServices::ticketing();
        $types = $svc->listTicketTypes($eventId);
        $promos = $svc->listPromoCodes($eventId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok([
                'event_id'     => $eventId,
                'ticket_types' => $types,
                'promo_codes'  => $promos,
            ]));
        }

        return $this->renderForm('WBS\Events\Views\ticketing_console', [
            'eventId'     => $eventId,
            'ticketTypes' => $types,
            'promoCodes'  => $promos,
        ]);
    }

    public function createTicketType(string $eventId = '')
    {
        return $this->respondTicketing(
            EventServices::ticketing()->createTicketType($this->orgId(), $eventId, $this->input()),
            $eventId,
            'ticketTypeCreatedFlash',
        );
    }

    public function listTicketTypes(string $eventId = '')
    {
        $types = EventServices::ticketing()->listTicketTypes($eventId);

        return $this->respondPage(
            Result::ok(['ticket_types' => $types, 'count' => count($types)]),
            'event_ticket_types',
            static fn (array $d): array => [
                'rows'  => $d['ticket_types'] ?? [],
                'count' => $d['count'] ?? null,
                'back'  => 'events/' . $eventId,
                // Create form → the webcsrf-guarded POST /events/{id}/ticket-types.
                // Field names mirror TicketingService::createTicketType() exactly.
                'forms' => [[
                    'action'     => 'events/' . $eventId . '/ticket-types',
                    'summaryKey' => 'Pages.forms.ticketType.summary',
                    'submitKey'  => 'Pages.forms.ticketType.submit',
                    'fields'     => [
                        ['name' => 'name', 'type' => 'text', 'labelKey' => 'Pages.common.colName', 'required' => true, 'maxlength' => 200],
                        ['name' => 'price_minor', 'type' => 'number', 'labelKey' => 'Pages.forms.ticketType.priceMinor', 'min' => 0, 'step' => 1, 'inputmode' => 'numeric'],
                        ['name' => 'currency', 'type' => 'text', 'labelKey' => 'Pages.common.colCurrency', 'maxlength' => 3, 'minlength' => 3, 'pattern' => '[A-Za-z]{3}', 'placeholder' => 'GHS'],
                        ['name' => 'quantity_total', 'type' => 'number', 'labelKey' => 'Pages.common.colQuantity', 'min' => 0, 'step' => 1, 'inputmode' => 'numeric'],
                        ['name' => 'per_user_limit', 'type' => 'number', 'labelKey' => 'Pages.forms.ticketType.perUserLimit', 'min' => 0, 'step' => 1, 'inputmode' => 'numeric'],
                        ['name' => 'description', 'type' => 'textarea', 'labelKey' => 'Pages.common.colDescription', 'full' => true, 'maxlength' => 500],
                    ],
                ]],
            ],
        );
    }

    public function createPromoCode(string $eventId = '')
    {
        return $this->respondTicketing(
            EventServices::ticketing()->createPromoCode($this->orgId(), $eventId, $this->input()),
            $eventId,
            'promoCreatedFlash',
        );
    }

    /**
     * PRG for a browser ticketing config write: API clients keep the raw Result
     * (JSON); browsers redirect back to the event's ticketing console with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function respondTicketing(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/ticketing' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.ticketing.' . $okKey));
    }

    /**
     * GET events/{id}/tickets — the ATTENDEE-FACING ticket purchase page: the
     * event's on-sale ticket types with remaining availability, a no-JS PRG
     * purchase form per available tier, the buyer's own already-purchased
     * tickets, and (when signed out) a sign-in prompt. API clients get the same
     * snapshot as JSON. `renderForm` mints the `_csrf` the purchase POST needs.
     */
    public function tickets(string $eventId = '')
    {
        $userId = $this->currentUserId();
        $view   = EventServices::ticketing()->purchaseView($eventId, $userId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($view));
        }

        return $this->renderForm('WBS\\Events\\Views\\tickets', [
            'eventId'     => $eventId,
            'view'        => $view,
            'ticketTypes' => $view['ticket_types'],
            'hold'        => $view['hold'],
            'myTickets'   => $view['my_tickets'],
            'signedIn'    => $view['signed_in'],
            'onSale'      => $view['on_sale'],
        ]);
    }

    /**
     * POST events/{id}/tickets — one-step self-service purchase for a browser
     * attendee (hold + checkout in one submit). API callers may still name any
     * user_id in the body; a browser buys as the SESSION user. PRGs back to the
     * ticket page with a confirmed / payment-required flash.
     */
    public function purchase(string $eventId = '')
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');
        $result = EventServices::ticketing()->purchase($this->orgId(), $eventId, $userId, [
            'ticket_type_id' => (string) ($in['ticket_type_id'] ?? ''),
            'quantity'       => (int) ($in['quantity'] ?? 1),
            'promo_code'     => $in['promo_code'] ?? null,
            'currency'       => $in['currency'] ?? null,
        ]);

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/tickets' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        // Free/zero-total orders confirm immediately; paid orders await payment.
        $confirmed = (bool) (($result->meta['confirmed'] ?? false) || ! ($result->meta['payment_required'] ?? true));
        $flashKey  = $confirmed ? 'purchasedFreeFlash' : 'purchasedPaidFlash';

        return redirect()->to($to)->with('success', lang('Events.tickets.' . $flashKey));
    }

    /** Create a short-lived inventory hold before checkout (reuses RegistrationService). */
    public function hold(string $eventId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::eventRegistrations()->hold(
            $this->orgId(),
            $eventId,
            $this->currentUserId('user_id'),
            (int) ($in['quantity'] ?? 1),
        ));
    }

    public function checkout(string $eventId = '')
    {
        return $this->respondWith(EventServices::ticketing()->checkout(
            $this->orgId(),
            $eventId,
            $this->currentUserId('user_id'),
            $this->input(),
        ));
    }

    /**
     * Confirm payment for an order. On success, if the order carried an
     * explicitly approved cause add-on, create a SEPARATE VBCS intent for it.
     */
    public function markPaid(string $orderId = '')
    {
        $in     = $this->input();
        $result = EventServices::ticketing()->markPaid($orderId, [
            'provider'     => $in['provider'] ?? null,
            'provider_ref' => $in['provider_ref'] ?? null,
        ]);

        if ($result->ok && is_array($result->data) && ($result->data['addon'] ?? null) !== null) {
            $addon = $result->data['addon'];
            // Separate contribution intent — ticket order total is untouched.
            ContributionServices::contributions()->createIntent(
                $this->orgId(),
                (string) $addon['cause_id'],
                [
                    'user_id'         => $addon['user_id'] ?? null,
                    'amount_minor'    => (int) $addon['amount_minor'],
                    'currency'        => $addon['currency'] ?? 'GHS',
                    'recognition'     => 'public',
                    'idempotency_key' => 'order-addon:' . $orderId,
                ],
            );
        }

        return $this->respondWith($result);
    }

    public function transfer(string $orderItemId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::ticketing()->transferTicket(
            $this->orgId(),
            $orderItemId,
            (string) ($in['from_user_id'] ?? $this->currentUserId()),
            (string) ($in['to_user_id'] ?? ''),
            $this->currentUserId('from_user_id'),
        ));
    }

    // ---- Order refunds — maker-checker money reversal (gap L2) --------------

    /**
     * POST orders/{id}/refunds — REQUEST a refund for a paid order (maker step).
     * Authorized by `event.tickets.manage` (organizer); approval/execution are a
     * separate finance step.
     */
    public function requestRefund(string $orderId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::orderRefunds()->request(
            $this->orgId(),
            $orderId,
            (string) ($this->actorId() ?? $this->currentUserId()),
            ['reason' => $in['reason'] ?? null],
        ));
    }

    /**
     * GET event-refunds/pending — the maker-checker console: refunds awaiting
     * approval/execution. Authorized by `contribution.refund.approve` (finance),
     * reusing the platform's money-reversal approver role.
     */
    public function pendingRefunds()
    {
        $list = EventServices::orderRefunds()->pending($this->orgId());

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['refunds' => $list]));
        }

        return $this->respondWith(
            Result::ok(['refunds' => $list]),
            htmlView: 'WBS\\Events\\Views\\refunds',
            viewData: [
                'refunds' => $list,
                'title'   => (string) lang('Events.refund.heading'),
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** POST event-refunds/{id}/approve — approve a requested refund (checker). */
    public function approveRefund(string $refundId = '')
    {
        $result = EventServices::orderRefunds()->approve($refundId, (string) ($this->actorId() ?? $this->currentUserId()));

        return $this->respondRefundAction($result);
    }

    /** POST event-refunds/{id}/reject — reject a requested refund. */
    public function rejectRefund(string $refundId = '')
    {
        $result = EventServices::orderRefunds()->reject($refundId, (string) ($this->actorId() ?? $this->currentUserId()));

        return $this->respondRefundAction($result);
    }

    /** POST event-refunds/{id}/execute — execute an approved refund. */
    public function executeRefund(string $refundId = '')
    {
        $in     = $this->input();
        $result = EventServices::orderRefunds()->execute($refundId, [
            'provider'           => $in['provider'] ?? null,
            'provider_refund_id' => $in['provider_refund_id'] ?? null,
        ]);

        return $this->respondRefundAction($result);
    }

    /** Shared PRG/JSON response for a refund maker-checker action. */
    private function respondRefundAction(Result $result)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        $to = '/event-refunds/pending';
        if (! $result->ok) {
            // Service messages are i18n keys (e.g. Events.refund.errBadState);
            // translate for display, falling back to the raw key.
            $msg = (string) $result->message;
            $t   = lang($msg);
            $msg = (is_string($t) && $t !== '' && $t !== $msg) ? $t : $msg;

            return redirect()->to($to)->with('error', $msg);
        }

        return redirect()->to($to)->with('success', (string) lang('Events.refund.actionDone'));
    }
}
