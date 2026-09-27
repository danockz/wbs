<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Streaming orchestration + engagement (SRS FR-STR-001..012).
 *
 * The platform ORCHESTRATES destinations, access, engagement, overlays and
 * metrics — it never proxies video ingest/playback (FR-STR-001). Nothing here
 * stores media; only references, access policy, engagement and metric snapshots.
 *
 *  - streams: lifecycle, access policy (public|restricted) independent of any
 *    provider's own policy (FR-STR-006).
 *  - stream_destinations: approved fan-out targets (YouTube/Twitch/RTMP/WebRTC…),
 *    honest per-destination capability. Provider tokens live in the credential
 *    vault, NEVER here / never in public HTML (FR-STR-002/006).
 *  - stream_chat_messages + stream_moderation: authenticated chat with audited
 *    moderation actions and visible policy reason (FR-STR-007).
 *  - stream_polls / stream_poll_votes: live polls (FR-STR-007).
 *  - stream_metric_samples: provider-sourced metric snapshots, each tagged with
 *    source + retrieval time + estimated/exact — never fabricated (FR-STR-010).
 *  - stream_archives: links to provider-retained recordings (FR-STR-012).
 */
final class CreateStreaming extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS streams (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NULL,          -- linked event or standalone
                group_id         CHAR(36)     NULL,
                created_by       CHAR(36)     NOT NULL,
                title            VARCHAR(200) NOT NULL,
                description      MEDIUMTEXT   NULL,
                access_policy    VARCHAR(16)  NOT NULL DEFAULT "restricted", -- public|restricted
                status           VARCHAR(16)  NOT NULL DEFAULT "draft",      -- draft|scheduled|live|ended|canceled
                slow_mode_secs   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                scheduled_at     DATETIME     NULL,
                started_at       DATETIME(6)  NULL,
                ended_at         DATETIME(6)  NULL,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY st_org_idx (organization_id, status),
                KEY st_event_idx (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_destinations (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,       -- youtube|twitch|facebook|vimeo|telegram|rtmp|webrtc
                connection_id    CHAR(36)     NULL,           -- integration connection (scoped creds)
                label            VARCHAR(120) NULL,
                capabilities     JSON         NULL,           -- honest declared caps for this destination
                status           VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|ready|active|error|removed
                external_ref     VARCHAR(255) NULL,           -- provider broadcast id (NOT a secret)
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY sd_stream_idx (stream_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_chat_messages (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                body             VARCHAR(2000) NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "visible", -- visible|deleted
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY scm_stream_idx (stream_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_moderation (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                moderator_id     CHAR(36)     NOT NULL,
                target_user_id   CHAR(36)     NULL,
                message_id       CHAR(36)     NULL,
                action           VARCHAR(24)  NOT NULL,       -- delete|mute|ban|slow_mode|unban
                reason           VARCHAR(500) NOT NULL,       -- visible policy reason (FR-STR-007)
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY sm_stream_idx (stream_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_bans (
                stream_id        CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                muted_until      DATETIME(6)  NULL,           -- null + banned=1 => permanent
                banned           TINYINT(1)   NOT NULL DEFAULT 0,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (stream_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_polls (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                question         VARCHAR(500) NOT NULL,
                options          JSON         NOT NULL,       -- [{key,label}]
                status           VARCHAR(16)  NOT NULL DEFAULT "open", -- open|closed
                results_revealed TINYINT(1)   NOT NULL DEFAULT 0,
                created_at       DATETIME(6)  NOT NULL,
                closed_at        DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY sp_stream_idx (stream_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_poll_votes (
                poll_id          CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                option_key       VARCHAR(64)  NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (poll_id, user_id)              -- one vote per user per poll
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_metric_samples (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                source           VARCHAR(40)  NOT NULL,       -- provider/source of the metric
                metric           VARCHAR(40)  NOT NULL,       -- concurrent_viewers|chat_rate|poll_participation
                value            DECIMAL(14,2) NULL,
                exactness        VARCHAR(12)  NOT NULL DEFAULT "estimated", -- exact|estimated|unavailable
                retrieved_at     DATETIME(6)  NOT NULL,
                note             VARCHAR(255) NULL,           -- limitation note
                PRIMARY KEY (id),
                KEY sms_stream_idx (stream_id, metric, retrieved_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_archives (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,
                external_url     VARCHAR(1000) NOT NULL,      -- provider-retained VOD reference
                access_policy    VARCHAR(16)  NOT NULL DEFAULT "restricted",
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY sa_stream_idx (stream_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'stream_archives', 'stream_metric_samples', 'stream_poll_votes', 'stream_polls',
            'stream_bans', 'stream_moderation', 'stream_chat_messages', 'stream_destinations', 'streams',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
