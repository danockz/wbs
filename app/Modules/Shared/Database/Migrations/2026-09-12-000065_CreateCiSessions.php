<?php

declare(strict_types=1);

namespace WBS\Shared\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CodeIgniter session storage table for the DatabaseHandler.
 *
 * Config\Session is set to:
 *   $driver   = DatabaseHandler::class
 *   $savePath = 'ci_sessions'   (a TABLE NAME, not a directory)
 *   $matchIP  = false
 *
 * Because $matchIP is false, the PRIMARY KEY is `id` alone. If you ever flip
 * $matchIP to true, CI4 requires the PK to become (id, ip_address) — change the
 * key here to match, otherwise sessions silently fail to read.
 *
 * Schema follows the CI4 v4.x manual (Session Library → Database driver) for
 * MySQL/MariaDB. `data` uses BLOB (binary-safe serialized payload).
 */
final class CreateCiSessions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS ci_sessions (
                id         VARCHAR(128) NOT NULL,
                ip_address VARCHAR(45)  NOT NULL,
                timestamp  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP NOT NULL,
                data       BLOB         NOT NULL,
                PRIMARY KEY (id),
                KEY ci_sessions_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ci_sessions');
    }
}
