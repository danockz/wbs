<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Identity support tables (SRS §9.2 Identity entities).
 *
 *  - access_tokens: hashed API/bearer tokens (never store the raw token) with
 *    scopes + expiry + revocation.
 *  - user_consents: versioned, purpose-tagged consent records (privacy/comms).
 *  - user_preferences: locale/timezone/accessibility + free-form settings.
 *  - security_events: append-only auth/security telemetry feeding the risk
 *    engine and audits (login success/failure, mfa, password reset, lockouts).
 *  - login_attempts: short-horizon counters for lockout / credential-stuffing
 *    detection (privacy-minimized: only hashes).
 */
final class CreateIdentitySupport extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS access_tokens (
                id               CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                name             VARCHAR(120) NULL,
                token_hash       CHAR(64)     NOT NULL,   -- sha256 of the raw token
                scopes           JSON         NULL,
                last_used_at     DATETIME     NULL,
                expires_at       DATETIME     NULL,
                revoked_at       DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY at_hash_uq (token_hash),
                KEY at_user_idx (user_id, revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_consents (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                purpose          VARCHAR(60)  NOT NULL,   -- privacy|comms|marketing|location|...
                policy_version   VARCHAR(40)  NOT NULL,
                granted          TINYINT(1)   NOT NULL DEFAULT 1,
                granted_at       DATETIME     NOT NULL,
                revoked_at       DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY uc_user_idx (user_id, purpose)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS user_preferences (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                locale           VARCHAR(12)  NULL,
                timezone         VARCHAR(64)  NULL,
                accessibility    JSON         NULL,
                settings         JSON         NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY up_user_uq (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS security_events (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NULL,
                user_id          CHAR(36)     NULL,
                type             VARCHAR(40)  NOT NULL,   -- login.success|login.failure|mfa.challenge|...
                ip_hash          CHAR(64)     NULL,
                user_agent_hash  CHAR(64)     NULL,
                risk_score       INT          NULL,
                detail           JSON         NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY se_user_idx (user_id, type, created_at),
                KEY se_type_idx (type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS login_attempts (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NULL,
                email_hash       CHAR(64)     NULL,       -- hashed identifier, never raw
                ip_hash          CHAR(64)     NULL,
                outcome          VARCHAR(12)  NOT NULL,   -- success|failure
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY la_email_idx (email_hash, created_at),
                KEY la_ip_idx (ip_hash, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['login_attempts', 'security_events', 'user_preferences', 'user_consents', 'access_tokens'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
