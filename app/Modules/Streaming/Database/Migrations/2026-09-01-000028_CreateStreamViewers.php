<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Viewer session tracking (S3 streaming adaptation).
 *
 * Privacy-safe by construction: unlike the source spec's stream_viewers (which
 * stored raw ip_address / user_agent / city / referrer), this table stores ONLY
 * a salted ip_hash, a ua_hash, and a coarse device_type bucket. No raw IP, no
 * raw UA, no city/referrer. Concurrency is derived from joined_at/left_at.
 */
final class CreateStreamViewers extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_viewers (
                id           CHAR(36)     NOT NULL,
                stream_id    CHAR(36)     NOT NULL,
                viewer_id    CHAR(36)     NULL,       -- authenticated member, or NULL for anonymous
                ip_hash      CHAR(64)     NULL,       -- salted hash, never a raw IP
                ua_hash      CHAR(64)     NULL,
                device_type  VARCHAR(16)  NULL,       -- coarse bucket: desktop|mobile|tablet|tv|other
                joined_at    DATETIME(6)  NOT NULL,
                left_at      DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY sv_stream_idx (stream_id, left_at),
                KEY sv_stream_join_idx (stream_id, joined_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS stream_viewers');
    }
}
