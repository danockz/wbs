<?php

declare(strict_types=1);

namespace WBS\Audit\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tamper-evident audit log (SRS NFR-SEC-007).
 *
 * Append-only, hash-chained: each row stores prev_hash + entry_hash where
 * entry_hash = sha256(prev_hash || canonical(payload)). Any edit/removal of a
 * historical row breaks the chain and is detectable by re-walking it. Records
 * never store raw credentials or full sensitive payloads — only redacted,
 * structured metadata. A per-(organization) sequence gives a strict order.
 */
final class CreateAuditLog extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS audit_log (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                seq              BIGINT UNSIGNED NOT NULL,   -- per-org monotonic sequence
                actor_id         CHAR(36)     NULL,          -- null = system
                actor_type       VARCHAR(20)  NOT NULL DEFAULT "user", -- user|system|service
                action           VARCHAR(80)  NOT NULL,      -- e.g. auth.login, contribution.refund.execute
                object_type      VARCHAR(80)  NULL,
                object_id        CHAR(36)     NULL,
                outcome          VARCHAR(16)  NOT NULL DEFAULT "success", -- success|denied|failure
                metadata         JSON         NULL,          -- redacted structured detail (never secrets)
                ip_hash          CHAR(64)     NULL,
                prev_hash        CHAR(64)     NOT NULL,
                entry_hash       CHAR(64)     NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY audit_seq_uq (organization_id, seq),
                KEY audit_action_idx (organization_id, action, created_at),
                KEY audit_object_idx (object_type, object_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS audit_log');
    }
}
