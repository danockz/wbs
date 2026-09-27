<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Platform-owned identity (SRS FR-ID-*; NO CodeIgniter Shield).
 *
 * Tables:
 *  - users: the account. Password is Argon2id-hashed (nullable for social-only
 *    accounts). Email is unique per organization. No raw secrets stored.
 *  - user_identities: linked social sign-in identities (Google/Microsoft OIDC),
 *    keyed by (provider, subject); tokens are encrypted elsewhere/minimized.
 *  - sessions: server-side session references with device/risk metadata for
 *    adaptive MFA and revocation.
 */
final class CreateUsers extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS users (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                email            VARCHAR(190) NULL,
                email_verified   TINYINT(1)   NOT NULL DEFAULT 0,
                password_hash    VARCHAR(255) NULL,
                display_name     VARCHAR(150) NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                locale           VARCHAR(12)  NOT NULL DEFAULT "en",
                timezone         VARCHAR(64)  NOT NULL DEFAULT "UTC",
                mfa_enabled      TINYINT(1)   NOT NULL DEFAULT 0,
                last_login_at    DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY users_org_email_uq (organization_id, email),
                KEY users_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_identities (
                id               CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                provider         VARCHAR(40)  NOT NULL,   -- google|microsoft
                subject          VARCHAR(191) NOT NULL,   -- OIDC sub
                email            VARCHAR(190) NULL,
                linked_at        DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uid_provider_subject_uq (provider, subject),
                KEY uid_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS sessions (
                id               CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                ip_hash          CHAR(64)     NULL,
                user_agent_hash  CHAR(64)     NULL,
                mfa_level        VARCHAR(16)  NOT NULL DEFAULT "none", -- none|low|high
                risk_score       INT          NOT NULL DEFAULT 0,
                created_at       DATETIME     NOT NULL,
                last_seen_at     DATETIME     NOT NULL,
                revoked_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY sessions_user_idx (user_id, revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS sessions');
        $this->db->query('DROP TABLE IF EXISTS user_identities');
        $this->db->query('DROP TABLE IF EXISTS users');
    }
}
