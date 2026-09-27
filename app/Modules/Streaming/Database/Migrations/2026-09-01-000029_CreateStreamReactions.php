<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Live reactions (S4 streaming adaptation).
 *
 * One row per reaction from an authenticated viewer. Counts are derived by
 * aggregation (never a denormalized +1 counter, unlike the source spec). Rate
 * limiting is enforced at the service/route layer.
 */
final class CreateStreamReactions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_reactions (
                id             CHAR(36)     NOT NULL,
                stream_id      CHAR(36)     NOT NULL,
                user_id        CHAR(36)     NOT NULL,
                reaction_type  VARCHAR(24)  NOT NULL,   -- like|love|clap|celebrate|amen|wow
                created_at     DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY sr_stream_idx (stream_id, created_at),
                KEY sr_stream_type_idx (stream_id, reaction_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS stream_reactions');
    }
}
