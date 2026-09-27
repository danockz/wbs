<?php

declare(strict_types=1);

namespace WBS\Notifications\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Notifications: templates, preferences, campaigns, deliveries (SRS FR-NOT-*).
 *
 *  - Templates are versioned by org/group/channel/locale/category with an
 *    allowlisted placeholder syntax rendered server-side (no code execution).
 *  - Preferences are per-channel/per-category with quiet hours, frequency caps,
 *    digest, and a hard opt-out/STOP that group policy can never override.
 *  - Deliveries snapshot the intended recipient + template version + rendered
 *    content fingerprint; a queued audience is immutable for reporting.
 */
final class CreateNotifications extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_templates (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                key_name         VARCHAR(80)  NOT NULL,   -- logical template key
                channel          VARCHAR(16)  NOT NULL,   -- email|sms|inapp|push
                locale           VARCHAR(20)  NOT NULL DEFAULT "en",
                category         VARCHAR(40)  NOT NULL,   -- security|event|contribution|...
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                subject          VARCHAR(255) NULL,
                body             MEDIUMTEXT   NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|active|retired
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY nt_ver_uq (organization_id, key_name, channel, locale, version),
                KEY nt_lookup_idx (organization_id, key_name, channel, locale, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_preferences (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                channel          VARCHAR(16)  NOT NULL,
                category         VARCHAR(40)  NOT NULL,
                opted_in         TINYINT(1)   NOT NULL DEFAULT 1,
                stopped          TINYINT(1)   NOT NULL DEFAULT 0,  -- hard STOP / legal opt-out
                quiet_start      TIME         NULL,
                quiet_end        TIME         NULL,
                timezone         VARCHAR(64)  NULL,
                digest_frequency VARCHAR(16)  NOT NULL DEFAULT "instant", -- instant|daily|weekly|off
                preferred_endpoint VARCHAR(255) NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY np_uq (organization_id, user_id, channel, category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Verified endpoints — a channel is usable only after verification.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_channels (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                channel          VARCHAR(16)  NOT NULL,
                endpoint_hash    CHAR(64)     NOT NULL,   -- hashed address/number
                verified_at      DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY nc_uq (organization_id, user_id, channel, endpoint_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_campaigns (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                name             VARCHAR(150) NOT NULL,
                template_key     VARCHAR(80)  NOT NULL,
                channel          VARCHAR(16)  NOT NULL,
                category         VARCHAR(40)  NOT NULL,
                priority         VARCHAR(16)  NOT NULL DEFAULT "normal", -- essential|high|normal|low
                audience_filter  JSON         NULL,       -- AND/OR filter tree
                status           VARCHAR(20)  NOT NULL DEFAULT "draft", -- draft|pending_approval|approved|queued|sent|cancelled
                requested_by     CHAR(36)     NULL,
                approved_by      CHAR(36)     NULL,       -- must differ from requested_by (SoD)
                audience_count   INT UNSIGNED NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY ncmp_org_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_deliveries (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NULL,       -- null for transactional
                user_id          CHAR(36)     NULL,
                channel          VARCHAR(16)  NOT NULL,
                category         VARCHAR(40)  NOT NULL,
                template_version INT UNSIGNED NULL,
                recipient_snapshot JSON       NULL,       -- immutable audience snapshot
                content_fingerprint CHAR(64)  NULL,       -- sha256 of rendered content
                provider_request_id VARCHAR(191) NULL,
                dedupe_key       VARCHAR(191) NULL,        -- idempotent send
                status           VARCHAR(20)  NOT NULL DEFAULT "queued", -- queued|sent|delivered|bounced|failed|suppressed|deferred|digest_pending|digested
                suppression_reason VARCHAR(60) NULL,       -- opt_out|quiet_hours|frequency_cap|no_route|no_consent|...
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY nd_dedupe_uq (dedupe_key),
                KEY nd_user_idx (user_id, category, created_at),
                KEY nd_campaign_idx (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS notification_suppressions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NULL,
                endpoint_hash    CHAR(64)     NULL,
                scope            VARCHAR(20)  NOT NULL DEFAULT "all", -- all|category|channel
                reason           VARCHAR(60)  NOT NULL,   -- do_not_contact|complaint|hard_bounce|stop
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY nsup_lookup_idx (organization_id, user_id, scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'notification_suppressions', 'notification_deliveries', 'notification_campaigns',
            'notification_channels', 'notification_preferences', 'notification_templates',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
