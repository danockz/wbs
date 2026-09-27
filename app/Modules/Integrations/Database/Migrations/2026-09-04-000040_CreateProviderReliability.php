<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Provider circuit-breaking + quota accounting (SRS FR-INT-012).
 *
 *  - provider_circuit_state: one row per (org, scope_key) circuit breaker.
 *    A run of consecutive failures OPENS the circuit; after a cooldown it goes
 *    HALF_OPEN to allow a single probe; a probe success CLOSES it. This stops a
 *    failing provider from being hammered and lets callers fall back cleanly.
 *  - provider_quota_usage: rolling per-window call counters so an adapter's
 *    approved API quota is respected (and the documented fallback engaged before
 *    the provider rejects us).
 *
 * The DOCUMENTED per-feature fallback matrix itself is code-owned
 * (FallbackMatrix), derived from each adapter's declared capabilities — it needs
 * no table. These tables hold only runtime reliability state.
 */
final class CreateProviderReliability extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS provider_circuit_state (
                id                   CHAR(36)     NOT NULL,
                organization_id      CHAR(36)     NOT NULL,
                scope_key            VARCHAR(120) NOT NULL,   -- provider/adapter code (e.g. youtube, zoom, stripe_v1)
                state                VARCHAR(12)  NOT NULL DEFAULT "closed", -- closed|open|half_open
                consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
                failure_count        INT UNSIGNED NOT NULL DEFAULT 0,   -- lifetime, for observability
                success_count        INT UNSIGNED NOT NULL DEFAULT 0,   -- lifetime, for observability
                last_outcome         VARCHAR(12)  NULL,        -- success|failure|short_circuit
                last_error           VARCHAR(255) NULL,        -- provider message (never a secret)
                opened_at            DATETIME(6)  NULL,
                next_probe_at        DATETIME(6)  NULL,        -- earliest half-open probe when open
                last_transition_at   DATETIME(6)  NULL,
                created_at           DATETIME(6)  NOT NULL,
                updated_at           DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pcs_scope_uq (organization_id, scope_key),
                KEY pcs_state_idx (organization_id, state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS provider_quota_usage (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                scope_key        VARCHAR(120) NOT NULL,
                window_unit      VARCHAR(8)   NOT NULL,   -- minute|hour|day
                window_start     DATETIME     NOT NULL,   -- truncated bucket start (UTC)
                used             INT UNSIGNED NOT NULL DEFAULT 0,
                limit_hint       INT UNSIGNED NULL,        -- last known configured limit
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY pqu_bucket_uq (organization_id, scope_key, window_unit, window_start),
                KEY pqu_scope_idx (organization_id, scope_key, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS provider_quota_usage');
        $this->db->query('DROP TABLE IF EXISTS provider_circuit_state');
    }
}
