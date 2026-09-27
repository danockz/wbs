<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Event ticket-order refunds — maker-checker money reversal (gap L2).
 *
 * Paid ticketing (D9-B) could take money (`event_orders.status = paid`) but had
 * no way to give it back: `event_orders` declared a `refunded` status that
 * nothing ever set, and cancelling a PUBLISHED paid event notified the roster
 * (G3) WITHOUT reversing a cent. This table is the refund workflow, deliberately
 * mirroring the VBCS `refund_requests` maker-checker (SRS FR-VBCS-007) so money
 * reversal is consistent platform-wide:
 *
 *   request → approve → execute, with strict segregation of duties (the approver
 *   MUST differ from the requester). Execution is idempotent: it flips the order
 *   + its items to `refunded`, restores ticket-type inventory, releases the
 *   attendee's registration (promoting the waitlist), and stages an
 *   `order.refund` outbox message for the payment-provider worker (no gateway
 *   call inline). The provider's refund id is recorded on execution/callback.
 *
 * `source` distinguishes a discretionary manual refund from the automatic
 * fan-out created when an event is cancelled. Additive/nullable — existing orders
 * and events are untouched.
 */
final class CreateEventOrderRefunds extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_order_refunds (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                event_id           CHAR(36)     NOT NULL,
                order_id           CHAR(36)     NOT NULL,
                amount_minor       BIGINT UNSIGNED NOT NULL,     -- reversed amount, minor units
                currency           CHAR(3)      NOT NULL,
                reason             VARCHAR(255) NULL,
                source             VARCHAR(16)  NOT NULL DEFAULT "manual", -- manual|event_cancel
                requested_by       CHAR(36)     NOT NULL,
                approved_by        CHAR(36)     NULL,             -- must differ from requested_by
                status             VARCHAR(20)  NOT NULL DEFAULT "requested", -- requested|approved|executed|rejected|failed
                provider           VARCHAR(40)  NULL,
                provider_refund_id VARCHAR(191) NULL,
                executed_at        DATETIME(6)  NULL,
                created_at         DATETIME(6)  NOT NULL,
                updated_at         DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY eor_order_idx (order_id, status),
                KEY eor_event_idx (event_id, status),
                KEY eor_org_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS event_order_refunds');
    }
}
