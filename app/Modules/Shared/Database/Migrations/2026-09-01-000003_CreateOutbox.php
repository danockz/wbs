<?php

declare(strict_types=1);

namespace WBS\Shared\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transactional outbox + queue jobs (SRS FR-ARC-005/006).
 *
 * The outbox row is written in the SAME DB transaction as the domain change.
 * A publisher later relays undispatched rows to the queue exactly once and
 * marks them dispatched; consumers are idempotent. This removes dual-write
 * races between the database and the message broker.
 */
final class CreateOutbox extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS outbox_messages (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NULL,
                aggregate_type   VARCHAR(60)  NOT NULL,   -- e.g. contribution, referral, notification
                aggregate_id     VARCHAR(64)  NOT NULL,
                topic            VARCHAR(80)  NOT NULL,   -- routing key / queue name
                payload          JSON         NOT NULL,
                headers          JSON         NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|dispatched|failed
                attempts         INT UNSIGNED NOT NULL DEFAULT 0,
                available_at     DATETIME(6)  NOT NULL,   -- earliest dispatch time
                dispatched_at    DATETIME(6)  NULL,
                last_error       VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ob_relay_idx (status, available_at),
                KEY ob_aggregate_idx (aggregate_type, aggregate_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS queue_jobs (
                id               CHAR(36)     NOT NULL,
                queue            VARCHAR(80)  NOT NULL DEFAULT "default",
                topic            VARCHAR(80)  NOT NULL,
                payload          JSON         NOT NULL,
                dedupe_key       VARCHAR(191) NULL,       -- idempotent enqueue
                status           VARCHAR(16)  NOT NULL DEFAULT "ready", -- ready|reserved|done|failed|dead
                attempts         INT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts     INT UNSIGNED NOT NULL DEFAULT 25,
                available_at     DATETIME(6)  NOT NULL,
                reserved_at      DATETIME(6)  NULL,
                reserved_by      VARCHAR(80)  NULL,
                completed_at     DATETIME(6)  NULL,
                last_error       VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY qj_dedupe_uq (dedupe_key),
                KEY qj_reserve_idx (queue, status, available_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Idempotency ledger for consumers: a handler records a processed key so
        // a redelivered message becomes a no-op.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS processed_messages (
                id               CHAR(36)     NOT NULL,
                consumer         VARCHAR(80)  NOT NULL,
                message_key      VARCHAR(191) NOT NULL,
                processed_at     DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pm_key_uq (consumer, message_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['processed_messages', 'queue_jobs', 'outbox_messages'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
