<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Events D9-B: paid ticketing, offline check-in kiosks, logistics/operations,
 * and expenses (SRS FR-EVT-016/017/018/019).
 *
 * Design invariants preserved from the core Events flow:
 *
 *  - ALL money is stored as INTEGER minor units + an explicit currency
 *    (BIGINT UNSIGNED for amounts, signed only where a delta may be negative).
 *    No floats, ever. Ticket/order accounting is kept SEPARATE from the VBCS
 *    contribution ledger (FR-EVT-016); an explicitly approved cause add-on is
 *    recorded as its own reference, not blended into the order total's ledger.
 *  - Inventory holds already exist (`ticket_holds`); orders consume a hold so
 *    successful payment alone can never oversell (FR-EVT-016).
 *  - Offline kiosk manifests are encrypted, short-lived and event-scoped; no
 *    device ever receives an unrestricted organization roster (FR-EVT-017).
 *    Offline scans are queued tamper-evidently and reconciled server-side,
 *    where the existing one-active-attendance UNIQUE resolves duplicates.
 *  - Logistics keeps PLANNED (projection) separate from ORDERED (approved) so
 *    expected-attendance refreshes never overwrite approved orders (FR-EVT-018).
 *    Individual dietary/accessibility data is specially classified in its own
 *    table (FR-EVT-018).
 *  - Expenses enforce segregation of duties: an approval row's actor must
 *    differ from the expense submitter; expense approval is separate from any
 *    contribution receipt/ledger behavior (FR-EVT-019).
 */
final class CreateEventsD9B extends Migration
{
    public function up(): void
    {
        // ---- FR-EVT-016: capacity, ticketing, reservations -----------------

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_ticket_types (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                name             VARCHAR(160) NOT NULL,
                description      VARCHAR(500) NULL,
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                price_minor      BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = free RSVP tier
                quantity_total   INT UNSIGNED NULL,       -- null = bounded only by event capacity
                quantity_sold    INT UNSIGNED NOT NULL DEFAULT 0,
                per_user_limit   INT UNSIGNED NULL,
                session_label    VARCHAR(120) NULL,       -- session / time-slot
                venue_area       VARCHAR(120) NULL,       -- venue area allocation
                group_allocation CHAR(36)     NULL,       -- optional per-group allocation
                sales_start      DATETIME     NULL,
                sales_end        DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|paused|sold_out
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY ett_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_promo_codes (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                code             VARCHAR(64)  NOT NULL,
                kind             VARCHAR(12)  NOT NULL DEFAULT "percent", -- percent|amount
                percent_bps      INT UNSIGNED NULL,       -- basis points (e.g. 1000 = 10%)
                amount_minor     BIGINT UNSIGNED NULL,    -- flat discount, minor units
                max_redemptions  INT UNSIGNED NULL,
                redeemed         INT UNSIGNED NOT NULL DEFAULT 0,
                starts_at        DATETIME     NULL,
                ends_at          DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|disabled
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY epc_code_uq (event_id, code),
                KEY epc_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Orders keep ticket accounting SEPARATE from VBCS causes. A cause
        // add-on is stored as an explicit reference; when the order is paid the
        // add-on is handed to Contributions as its own intent (never merged into
        // the ticket ledger).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_orders (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                subtotal_minor   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                discount_minor   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                total_minor      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                promo_code_id    CHAR(36)     NULL,
                hold_id          CHAR(36)     NULL,       -- ticket_holds inventory hold
                quantity         INT UNSIGNED NOT NULL DEFAULT 0,
                provider         VARCHAR(40)  NULL,
                provider_ref     VARCHAR(191) NULL,       -- provider checkout/session id
                idempotency_key  VARCHAR(191) NULL,
                addon_cause_id   CHAR(36)     NULL,       -- explicitly approved cause add-on
                addon_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                addon_intent_id  CHAR(36)     NULL,       -- separate VBCS intent, if created
                status           VARCHAR(20)  NOT NULL DEFAULT "pending", -- pending|paid|cancelled|refunded|expired
                paid_at          DATETIME(6)  NULL,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY eo_idem_uq (idempotency_key),
                KEY eo_event_idx (event_id, status),
                KEY eo_user_idx (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_order_items (
                id               CHAR(36)     NOT NULL,
                order_id         CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                ticket_type_id   CHAR(36)     NOT NULL,
                attendee_user_id CHAR(36)     NULL,       -- who holds this ticket (transferable)
                quantity         INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                line_total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|transferred|refunded|cancelled
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY eoi_order_idx (order_id),
                KEY eoi_type_idx (ticket_type_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Controlled attendee transfer (FR-EVT-016).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS ticket_transfers (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                order_item_id    CHAR(36)     NOT NULL,
                from_user_id     CHAR(36)     NOT NULL,
                to_user_id       CHAR(36)     NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|accepted|revoked
                initiated_by     CHAR(36)     NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                resolved_at      DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY tt_item_idx (order_item_id, status),
                KEY tt_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-017: check-in kiosk and offline mode -------------------

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_checkin_kiosks (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                device_label     VARCHAR(120) NOT NULL,
                device_fp_hash   CHAR(64)     NULL,       -- hashed device fingerprint (no raw device data)
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|revoked
                manifest_version INT UNSIGNED NOT NULL DEFAULT 0,
                last_seen_at     DATETIME(6)  NULL,
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                revoked_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY eck_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Encrypted, short-lived, event-scoped attendee manifest. The ciphertext
        // is a SecretBox blob (aad kiosk:{id}); it contains ONLY this event''s
        // registrants — never an org-wide roster.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS kiosk_manifests (
                id               CHAR(36)     NOT NULL,
                kiosk_id         CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                version          INT UNSIGNED NOT NULL,
                entry_count      INT UNSIGNED NOT NULL DEFAULT 0,
                ciphertext       LONGTEXT     NOT NULL,   -- SecretBox envelope
                issued_at        DATETIME(6)  NOT NULL,
                expires_at       DATETIME(6)  NOT NULL,   -- short-lived
                PRIMARY KEY (id),
                UNIQUE KEY km_ver_uq (kiosk_id, version),
                KEY km_expiry_idx (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Offline scans queued for reconciliation. UNIQUE(kiosk_id, local_ref)
        // makes replaying a device batch idempotent; server-side reconcile then
        // applies each via the one-active-attendance path.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS offline_checkin_queue (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                kiosk_id         CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                local_ref        VARCHAR(120) NOT NULL,   -- device-generated id
                user_id          CHAR(36)     NULL,
                method           VARCHAR(16)  NOT NULL DEFAULT "qr", -- qr|manual
                manual_reason    VARCHAR(255) NULL,
                scanned_at       DATETIME(6)  NOT NULL,   -- device-local scan time
                reconcile_status VARCHAR(16)  NOT NULL DEFAULT "queued", -- queued|applied|duplicate|rejected
                reconcile_note   VARCHAR(255) NULL,
                created_at       DATETIME(6)  NOT NULL,
                reconciled_at    DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ocq_local_uq (kiosk_id, local_ref),
                KEY ocq_status_idx (event_id, reconcile_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-018: logistics and operations --------------------------

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_logistics_plans (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                projection_expected INT UNSIGNED NOT NULL DEFAULT 0, -- last expected-attendance projection
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|approved
                approved_by      CHAR(36)     NULL,
                approved_at      DATETIME     NULL,
                notes            TEXT         NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY elp_event_uq (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // quantity_planned tracks the live projection; quantity_ordered is the
        // approved order and is NEVER overwritten by a projection refresh.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_resources (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                plan_id          CHAR(36)     NULL,
                kind             VARCHAR(20)  NOT NULL DEFAULT "material", -- material|equipment|security|catering
                name             VARCHAR(160) NOT NULL,
                unit             VARCHAR(40)  NULL,
                quantity_planned INT UNSIGNED NOT NULL DEFAULT 0,  -- projection
                quantity_ordered INT UNSIGNED NOT NULL DEFAULT 0,  -- approved order (protected)
                per_attendee     DECIMAL(10,4) NULL,  -- planning ratio for projection refresh
                supplier_id      CHAR(36)     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "planned", -- planned|ordered|received
                notes            VARCHAR(500) NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY er_event_idx (event_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_seating_areas (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                plan_id          CHAR(36)     NULL,
                name             VARCHAR(120) NOT NULL,
                capacity         INT UNSIGNED NOT NULL DEFAULT 0,
                allocated_group_id CHAR(36)   NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY esa_event_idx (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_staff_roster (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                plan_id          CHAR(36)     NULL,
                user_id          CHAR(36)     NOT NULL,
                role             VARCHAR(80)  NOT NULL,   -- volunteer|steward|security|...
                assignment       VARCHAR(160) NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "assigned", -- assigned|confirmed|declined
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY esr_person_uq (event_id, user_id, role),
                KEY esr_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_suppliers (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                name             VARCHAR(160) NOT NULL,
                category         VARCHAR(80)  NULL,
                contact          VARCHAR(191) NULL,
                notes            VARCHAR(500) NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY esup_event_idx (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Aggregate catering headcount by dietary label (non-sensitive rollup).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_catering_aggregates (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                diet_label       VARCHAR(80)  NOT NULL,   -- standard|vegetarian|halal|...
                headcount        INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY eca_label_uq (event_id, diet_label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Individual dietary/accessibility needs are SPECIALLY CLASSIFIED — this
        // table is only ever read by authorized logistics personnel (ABAC), and
        // details are never surfaced in aggregate reports.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_accessibility_needs (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                need_type        VARCHAR(40)  NOT NULL,   -- dietary|mobility|medical|other
                detail           VARCHAR(500) NULL,       -- classified free text
                classification   VARCHAR(20)  NOT NULL DEFAULT "restricted",
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ean_person_uq (event_id, user_id, need_type),
                KEY ean_event_idx (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-019: expenses ------------------------------------------

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_budgets (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                amount_minor     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|approved
                approved_by      CHAR(36)     NULL,
                approved_at      DATETIME     NULL,
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY eb_event_uq (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_expenses (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                submitted_by     CHAR(36)     NOT NULL,
                category         VARCHAR(80)  NULL,
                description      VARCHAR(500) NOT NULL,
                currency         CHAR(3)      NOT NULL DEFAULT "GHS",
                amount_minor     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                allocation       VARCHAR(120) NULL,       -- budget line / cost centre
                receipt_ref      VARCHAR(191) NULL,       -- opaque stored-object ref (access-controlled)
                approved_amount_minor BIGINT UNSIGNED NULL,
                variance_minor   BIGINT       NULL,       -- signed: approved - requested
                payment_reference VARCHAR(191) NULL,      -- reimbursement/payment ref
                status           VARCHAR(20)  NOT NULL DEFAULT "submitted", -- submitted|approved|rejected|reimbursed
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY ee_event_idx (event_id, status),
                KEY ee_submitter_idx (submitted_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Maker-checker trail. Segregation of duties is enforced in the service
        // (actor_id must differ from the expense submitter); this table is the
        // append-only audit of who acted.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_expense_approvals (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                expense_id       CHAR(36)     NOT NULL,
                action           VARCHAR(16)  NOT NULL,   -- approve|reject|reimburse
                actor_id         CHAR(36)     NOT NULL,
                amount_minor     BIGINT UNSIGNED NULL,    -- approved amount at this step
                note             VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY eea_expense_idx (expense_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'event_expense_approvals', 'event_expenses', 'event_budgets',
            'event_accessibility_needs', 'event_catering_aggregates', 'event_suppliers',
            'event_staff_roster', 'event_seating_areas', 'event_resources', 'event_logistics_plans',
            'offline_checkin_queue', 'kiosk_manifests', 'event_checkin_kiosks',
            'ticket_transfers', 'event_order_items', 'event_orders',
            'event_promo_codes', 'event_ticket_types',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
