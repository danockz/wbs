<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Events core online flow (SRS FR-EVT-001/007/008/011/016).
 *
 *  - events: lifecycle, mode (virtual|physical|hybrid), tz-aware schedule, capacity.
 *  - event_registrations: one registration per person/event (UNIQUE).
 *  - ticket_holds: short-lived atomic inventory holds so payment success alone
 *    cannot oversell.
 *  - event_attendance: ONE active attendance per event/person (UNIQUE), with
 *    group attribution kept separately so shared/cross-group events never
 *    double-count people.
 *  - checkin_nonces: single-use signed QR nonces (replay protection).
 *
 * Paid ticketing/offline-kiosk/logistics/expenses (D9-B) follow in later
 * releases; the schema leaves room without blocking the core flow.
 */
final class CreateEvents extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS events (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,       -- organizing group
                title            VARCHAR(200) NOT NULL,
                slug             VARCHAR(200) NULL,
                description      MEDIUMTEXT   NULL,
                type             VARCHAR(40)  NULL,
                mode             VARCHAR(16)  NOT NULL DEFAULT "physical", -- virtual|physical|hybrid
                venue_id         CHAR(36)     NULL,
                access_url       VARCHAR(500) NULL,       -- virtual join/embed (never public secret)
                timezone         VARCHAR(64)  NOT NULL DEFAULT "UTC",
                starts_at        DATETIME     NOT NULL,   -- stored UTC
                ends_at          DATETIME     NULL,
                capacity         INT UNSIGNED NULL,       -- null = unlimited
                registration_policy VARCHAR(20) NOT NULL DEFAULT "open", -- open|invite|closed
                attendance_policy VARCHAR(30) NOT NULL DEFAULT "checkin", -- checkin|streaming|manual
                audience         JSON         NULL,
                consent_wording  TEXT         NULL,
                status           VARCHAR(30)  NOT NULL DEFAULT "draft", -- draft|published|cancelled|completed|completed_no_attendance
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY ev_org_idx (organization_id, status, starts_at),
                KEY ev_group_idx (group_id, starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_registrations (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                group_attribution CHAR(36)    NULL,       -- crediting group if cross-group
                source_ref       VARCHAR(191) NULL,       -- referral/invite attribution
                status           VARCHAR(20)  NOT NULL DEFAULT "registered", -- registered|waitlisted|cancelled
                rsvp_state       VARCHAR(16)  NOT NULL DEFAULT "yes",        -- yes|no|maybe
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY er_person_uq (event_id, user_id),
                KEY er_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS ticket_holds (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                quantity         INT UNSIGNED NOT NULL DEFAULT 1,
                status           VARCHAR(16)  NOT NULL DEFAULT "held", -- held|consumed|expired|released
                expires_at       DATETIME     NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY th_active_uq (event_id, user_id, status),
                KEY th_expiry_idx (status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS waitlist_entries (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                position         INT UNSIGNED NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "waiting", -- waiting|promoted|expired
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY wl_person_uq (event_id, user_id),
                KEY wl_order_idx (event_id, status, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_attendance (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                method           VARCHAR(16)  NOT NULL DEFAULT "qr", -- qr|manual|streaming
                checked_in_at    DATETIME(6)  NOT NULL,
                checked_in_by    CHAR(36)     NULL,       -- staff for manual entry
                manual_reason    VARCHAR(255) NULL,
                active_key       VARCHAR(80)  NULL,       -- = event_id:user_id while active (one active)
                status           VARCHAR(16)  NOT NULL DEFAULT "present", -- present|voided
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ea_active_uq (active_key),
                KEY ea_event_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Group attribution kept SEPARATE from person attendance so a person can
        // be credited to authorized group(s) without double-counting total people.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_attendance_group_attribution (
                id               CHAR(36)     NOT NULL,
                attendance_id    CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY eaga_uq (attendance_id, group_id),
                KEY eaga_group_idx (event_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Single-use signed QR nonces (replay protection, FR-EVT-007).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS checkin_nonces (
                id               CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                nonce            CHAR(64)     NOT NULL,
                issued_at        DATETIME(6)  NOT NULL,
                expires_at       DATETIME(6)  NOT NULL,
                consumed_at      DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cn_nonce_uq (nonce)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'checkin_nonces', 'event_attendance_group_attribution', 'event_attendance',
            'waitlist_entries', 'ticket_holds', 'event_registrations', 'events',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
