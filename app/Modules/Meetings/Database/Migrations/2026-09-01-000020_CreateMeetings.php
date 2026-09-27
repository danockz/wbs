<?php

declare(strict_types=1);

namespace WBS\Meetings\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Meeting integrations (SRS FR-MTG-001..003).
 *
 * An adapter creates/links a provider meeting/webinar where the API allows;
 * otherwise it securely stores an authorized link/reference. Join links are
 * access-controlled and per-user/short-lived where the provider supports it.
 * A provider integration cannot confer platform attendance unless the linked
 * event's verification policy accepts the received evidence (FR-MTG-003) — so
 * attendance evidence is recorded here as CANDIDATE evidence, not applied.
 *
 *  - meetings: linked/standalone meeting record + provider + access policy.
 *  - meeting_participants: per-user access grants; join_token is short-lived.
 *  - meeting_attendance_evidence: raw provider-reported presence, pending the
 *    event verification policy (never auto-counted).
 */
final class CreateMeetings extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS meetings (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NULL,          -- linked event or standalone
                connection_id    CHAR(36)     NULL,          -- integration connection (scoped creds)
                provider         VARCHAR(40)  NOT NULL,       -- zoom|meet|teams|jitsi|link
                mode             VARCHAR(16)  NOT NULL DEFAULT "meeting", -- meeting|webinar
                title            VARCHAR(200) NOT NULL,
                external_ref     VARCHAR(255) NULL,           -- provider meeting id (NOT a secret)
                join_url         VARCHAR(1000) NULL,          -- stored authorized reference (never public secret)
                access_policy    VARCHAR(16)  NOT NULL DEFAULT "restricted", -- public|restricted
                starts_at        DATETIME     NULL,
                ends_at          DATETIME     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "scheduled", -- scheduled|live|ended|canceled
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY mt_org_idx (organization_id, status),
                KEY mt_event_idx (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS meeting_participants (
                id               CHAR(36)     NOT NULL,
                meeting_id       CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                role             VARCHAR(16)  NOT NULL DEFAULT "attendee", -- host|cohost|attendee
                join_token_hash  CHAR(64)     NULL,           -- hash of short-lived per-user join token
                token_expires_at DATETIME(6)  NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY mp_uq (meeting_id, user_id),
                KEY mp_meeting_idx (meeting_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS meeting_attendance_evidence (
                id               CHAR(36)     NOT NULL,
                meeting_id       CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NULL,           -- resolved platform user (nullable if unmatched)
                provider_ref     VARCHAR(255) NULL,           -- provider participant id/email hash
                joined_at        DATETIME(6)  NULL,
                left_at          DATETIME(6)  NULL,
                duration_secs    INT UNSIGNED NULL,
                applied          TINYINT(1)   NOT NULL DEFAULT 0, -- became platform attendance? (event policy decides)
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY mae_meeting_idx (meeting_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['meeting_attendance_evidence', 'meeting_participants', 'meetings'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
