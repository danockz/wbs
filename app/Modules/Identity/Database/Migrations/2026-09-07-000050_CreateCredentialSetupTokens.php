<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Credential-setup / invite tokens (SRS FR-ID account activation).
 *
 * Members created without a password — e.g. via the public group self-join
 * (`/g/{slug}/join`) or an admin invite — receive a single-use, expiring token
 * to set their first password and activate sign-in. Only the SHA-256 HASH of the
 * token is stored; the plaintext travels in the invite link and is shown/sent
 * exactly once, mirroring the refresh-token and join-token conventions.
 *
 * `purpose` distinguishes an initial invite from a later password reset so the
 * two can share this table + service without conflating flows.
 */
final class CreateCredentialSetupTokens extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS credential_setup_tokens (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                token_hash       CHAR(64)     NOT NULL,
                purpose          VARCHAR(24)  NOT NULL DEFAULT "invite", -- invite|password_reset
                expires_at       DATETIME     NOT NULL,
                consumed_at      DATETIME     NULL,
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cst_token_uq (token_hash),
                KEY cst_user_idx (user_id, consumed_at),
                KEY cst_expiry_idx (consumed_at, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS credential_setup_tokens');
    }
}
