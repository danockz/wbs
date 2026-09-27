<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adaptive/dynamic MFA storage (SRS: passkeys/TOTP preferred; SMS/email
 * lower-assurance only).
 *
 *  - mfa_factors holds TOTP secrets and WebAuthn credentials. TOTP secrets are
 *    ENCRYPTED at rest (SecretBox blob in secret_cipher); the platform never
 *    stores a plaintext shared secret.
 *  - recovery_codes are single-use, stored ONLY as HMAC hashes, regenerated on
 *    use. No plaintext recovery code is ever persisted.
 */
final class CreateMfa extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS mfa_factors (
                id             CHAR(36)        NOT NULL,
                user_id        CHAR(36)        NOT NULL,
                type           VARCHAR(20)     NOT NULL,   -- totp|webauthn|sms|email
                assurance      VARCHAR(10)     NOT NULL DEFAULT "high", -- high|low
                label          VARCHAR(100)    NULL,
                secret_cipher  TEXT            NULL,        -- encrypted TOTP secret (SecretBox)
                credential_id  VARBINARY(255)  NULL,        -- WebAuthn credential id
                public_key     TEXT            NULL,        -- WebAuthn COSE key
                sign_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                confirmed_at   DATETIME        NULL,
                created_at     DATETIME        NOT NULL,
                PRIMARY KEY (id),
                KEY mfa_user_idx (user_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS recovery_codes (
                id           CHAR(36)     NOT NULL,
                user_id      CHAR(36)     NOT NULL,
                code_hash    CHAR(64)     NOT NULL,   -- HMAC-SHA256, single-use
                used_at      DATETIME     NULL,
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rc_hash_uq (user_id, code_hash),
                KEY rc_user_idx (user_id, used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS recovery_codes');
        $this->db->query('DROP TABLE IF EXISTS mfa_factors');
    }
}
