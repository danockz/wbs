<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Refresh token family tracking (SRS FR-ID-006).
 *
 * Refresh tokens ROTATE on every use and support replay detection: all tokens
 * derived from one original grant share a `family_id`. When a refresh token that
 * has already been rotated (or revoked) is presented again, the entire family is
 * revoked — this defeats stolen-token replay.
 *
 * Only the SHA-256 hash of the raw token is stored; the plaintext is returned to
 * the client exactly once. `replaced_by` links a rotated token to its successor
 * so the chain is auditable.
 */
final class CreateRefreshTokens extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS refresh_tokens (
                id               CHAR(36)     NOT NULL,
                family_id        CHAR(36)     NOT NULL,   -- shared across a rotation chain
                user_id          CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                token_hash       CHAR(64)     NOT NULL,   -- sha256 of the raw refresh token
                access_token_id  CHAR(36)     NULL,       -- access token minted alongside
                scopes           JSON         NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|rotated|revoked
                replaced_by      CHAR(36)     NULL,       -- successor token id after rotation
                expires_at       DATETIME     NOT NULL,
                created_at       DATETIME     NOT NULL,
                used_at          DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rt_hash_uq (token_hash),
                KEY rt_family_idx (family_id, status),
                KEY rt_user_idx (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS refresh_tokens');
    }
}
