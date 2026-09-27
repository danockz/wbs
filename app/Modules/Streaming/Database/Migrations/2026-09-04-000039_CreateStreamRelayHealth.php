<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;
use Throwable;

/**
 * Relay health monitoring + failure response (SRS FR-STR-013).
 *
 *  - stream_relay_health: append-only relay heartbeat/health samples per stream
 *    (optionally per destination). Each sample carries a status
 *    (healthy|degraded|down), an optional latency and a source note, so health
 *    is observed from real signals rather than assumed.
 *  - stream_relay_incidents: a mid-session relay failure. Records detection
 *    time, severity, the affected destinations, the alert delivery log
 *    (in-app + configured fallback), whether the documented single-destination
 *    BYPASS was activated (and to which destination), and resolution. While an
 *    incident is open the stream's metric tracking is honestly marked degraded.
 *  - ALTER streams: `relay_state` (healthy|degraded|down), `degraded_since`, and
 *    `bypass_destination_id` so the dashboard and metric reads can reflect the
 *    true tracking state instead of silently implying normal operation.
 *
 * ALTER precedent exists across the codebase. down() is best-effort.
 */
final class CreateStreamRelayHealth extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE streams
            ADD COLUMN relay_state          VARCHAR(12) NOT NULL DEFAULT "healthy" AFTER status,
            ADD COLUMN degraded_since        DATETIME   NULL AFTER relay_state,
            ADD COLUMN bypass_destination_id CHAR(36)   NULL AFTER degraded_since
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_relay_health (
                id               CHAR(36)      NOT NULL,
                organization_id  CHAR(36)      NOT NULL,
                stream_id        CHAR(36)      NOT NULL,
                destination_id   CHAR(36)      NULL,
                status           VARCHAR(12)   NOT NULL,        -- healthy|degraded|down
                latency_ms       INT           NULL,
                source           VARCHAR(40)   NOT NULL DEFAULT "heartbeat", -- heartbeat|provider|manual
                note             VARCHAR(255)  NULL,
                created_at       DATETIME(6)   NOT NULL,
                PRIMARY KEY (id),
                KEY srh_stream_idx (stream_id, created_at),
                KEY srh_dest_idx (destination_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_relay_incidents (
                id                    CHAR(36)     NOT NULL,
                organization_id       CHAR(36)     NOT NULL,
                stream_id             CHAR(36)     NOT NULL,
                severity              VARCHAR(12)  NOT NULL DEFAULT "critical", -- warning|critical
                status                VARCHAR(12)  NOT NULL DEFAULT "open",     -- open|acknowledged|resolved
                cause                 VARCHAR(255) NULL,
                affected_destinations JSON         NULL,
                detected_by           VARCHAR(24)  NOT NULL DEFAULT "monitor",  -- monitor|manual
                detected_at           DATETIME(6)  NOT NULL,
                alerts_sent           JSON         NULL,        -- [{channel,target,at,ok}]
                acknowledged_by       CHAR(36)     NULL,
                acknowledged_at       DATETIME(6)  NULL,
                bypass_activated      TINYINT(1)   NOT NULL DEFAULT 0,
                bypass_destination_id CHAR(36)     NULL,
                bypass_activated_at   DATETIME(6)  NULL,
                metrics_degraded      TINYINT(1)   NOT NULL DEFAULT 1,
                resolved_by           CHAR(36)     NULL,
                resolved_at           DATETIME(6)  NULL,
                resolution_note       VARCHAR(255) NULL,
                created_at            DATETIME(6)  NOT NULL,
                updated_at            DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY sri_stream_status_idx (stream_id, status),
                KEY sri_org_status_idx (organization_id, status, detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        try {
            $this->db->query('ALTER TABLE streams
                DROP COLUMN relay_state,
                DROP COLUMN degraded_since,
                DROP COLUMN bypass_destination_id
            ');
        } catch (Throwable) {
            // columns may not exist
        }
        $this->db->query('DROP TABLE IF EXISTS stream_relay_incidents');
        $this->db->query('DROP TABLE IF EXISTS stream_relay_health');
    }
}
