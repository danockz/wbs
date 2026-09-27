<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Co-hosts + versioned overlays (SRS FR-STR-008).
 *
 *  - stream_cohosts: presenters authorized to join the WebRTC source feed before
 *    relay fan-out. A join token is short-lived and only its hash is stored; the
 *    plaintext is returned once. Roles: host|cohost|guest.
 *  - stream_overlays: overlay instances of a fixed, reviewed type vocabulary
 *    (lower_third | cause_progress | quote_card). Config is DATA, not code, and is
 *    sanitized at write time. Each edit creates a NEW version (version bumps,
 *    history preserved) — historical composited output stays reproducible.
 *
 * Overlays are composited in the relay layer; nothing here executes user code.
 */
final class CreateStreamOverlays extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_cohosts (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                role             VARCHAR(16)  NOT NULL DEFAULT "cohost", -- host|cohost|guest
                status           VARCHAR(16)  NOT NULL DEFAULT "invited", -- invited|joined|left|removed
                join_token_hash  CHAR(64)     NULL,   -- sha256 of short-lived join token
                token_expires_at DATETIME(6)  NULL,
                invited_at       DATETIME(6)  NOT NULL,
                joined_at        DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY sch_uq (stream_id, user_id),
                KEY sch_stream_idx (stream_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_overlays (
                id               CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                overlay_type     VARCHAR(24)  NOT NULL,       -- lower_third|cause_progress|quote_card
                config_json      JSON         NULL,           -- sanitized data (no code)
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                visible          TINYINT(1)   NOT NULL DEFAULT 0,
                created_by       CHAR(36)     NULL,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY so_stream_idx (stream_id, overlay_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['stream_overlays', 'stream_cohosts'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
