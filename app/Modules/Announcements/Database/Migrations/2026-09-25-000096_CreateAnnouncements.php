<?php

declare(strict_types=1);

namespace WBS\Announcements\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * First-class announcements, wired as an extension of notification campaigns:
 * same SoD (send vs approve), optional fan-out through NotificationService, in-app
 * inbox with must-ack. Targeting is AND across dimensions; named users are always
 * included. Hierarchy mode is chosen per announcement (not a global default).
 */
final class CreateAnnouncements extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        if (! $this->db->tableExists('announcements')) {
            $this->db->query('
                CREATE TABLE IF NOT EXISTS announcements (
                    id               CHAR(36)     NOT NULL,
                    organization_id  CHAR(36)     NOT NULL,
                    group_id         CHAR(36)     NULL,
                    campaign_id      CHAR(36)     NULL,
                    title            VARCHAR(200) NOT NULL,
                    body             MEDIUMTEXT   NOT NULL,
                    notify           TINYINT(1)   NOT NULL DEFAULT 0,
                    template_key     VARCHAR(80)  NULL,
                    channel          VARCHAR(16)  NULL,
                    status           VARCHAR(24)  NOT NULL DEFAULT "draft",
                    requested_by     CHAR(36)     NOT NULL,
                    approved_by      CHAR(36)     NULL,
                    audience_count   INT UNSIGNED NULL,
                    starts_at        DATETIME     NULL,
                    ends_at          DATETIME     NULL,
                    published_at     DATETIME     NULL,
                    created_at       DATETIME     NOT NULL,
                    updated_at       DATETIME     NULL,
                    PRIMARY KEY (id),
                    KEY ann_org_idx (organization_id, status, ends_at),
                    KEY ann_group_idx (group_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        if (! $this->db->tableExists('announcement_targets')) {
            $this->db->query('
                CREATE TABLE IF NOT EXISTS announcement_targets (
                    id               CHAR(36)     NOT NULL,
                    announcement_id  CHAR(36)     NOT NULL,
                    kind             VARCHAR(24)  NOT NULL,
                    ref              VARCHAR(80)  NOT NULL,
                    scope_mode       VARCHAR(32)  NOT NULL DEFAULT "",
                    PRIMARY KEY (id),
                    KEY ant_ann_idx (announcement_id, kind)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        if (! $this->db->tableExists('announcement_audience')) {
            $this->db->query('
                CREATE TABLE IF NOT EXISTS announcement_audience (
                    announcement_id  CHAR(36)     NOT NULL,
                    user_id          CHAR(36)     NOT NULL,
                    PRIMARY KEY (announcement_id, user_id),
                    KEY ana_user_idx (user_id, announcement_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }

        if (! $this->db->tableExists('announcement_receipts')) {
            $this->db->query('
                CREATE TABLE IF NOT EXISTS announcement_receipts (
                    announcement_id  CHAR(36)     NOT NULL,
                    user_id          CHAR(36)     NOT NULL,
                    seen_at          DATETIME     NULL,
                    acked_at         DATETIME     NULL,
                    PRIMARY KEY (announcement_id, user_id),
                    KEY anr_user_open_idx (user_id, acked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        foreach (['announcement_receipts', 'announcement_audience', 'announcement_targets', 'announcements'] as $t) {
            if ($this->db->tableExists($t)) {
                $this->db->query("DROP TABLE {$t}");
            }
        }
    }
}
