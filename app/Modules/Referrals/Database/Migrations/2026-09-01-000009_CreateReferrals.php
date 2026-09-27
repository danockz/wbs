<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Referrals & unilevel sponsorship (SRS FR-MEM-*, referral link threat model).
 *
 *  - referral_links: cloaked, opaque short codes. The code reveals nothing about
 *    the referrer; clicks are rate-limited and IPs are stored only as salted
 *    hashes.
 *  - referral_clicks: consent-tagged click log; exact IP is never stored raw.
 *  - sponsorships: the acyclic unilevel sponsor graph. Exactly ONE active
 *    sponsor per member (single-active), enforced by a unique partial-style
 *    guard (app-maintained active flag + unique index).
 *  - prospects: consent-gated captured leads awaiting conversion.
 *  - referral_attributions: credit assigned ONLY after a configured conversion
 *    event (verified registration, RSVP, attendance, completion, contribution).
 */
final class CreateReferrals extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS referral_links (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                referrer_id      CHAR(36)     NOT NULL,
                code             VARCHAR(24)  NOT NULL,   -- opaque, cloaked
                campaign         VARCHAR(80)  NULL,
                landing          VARCHAR(255) NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rl_code_uq (code),
                KEY rl_referrer_idx (referrer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS referral_clicks (
                id            CHAR(36)     NOT NULL,
                link_id       CHAR(36)     NOT NULL,
                ip_hash       CHAR(64)     NULL,       -- salted hash, never raw IP
                ua_hash       CHAR(64)     NULL,
                consent       TINYINT(1)   NOT NULL DEFAULT 0,
                created_at    DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY rc_link_idx (link_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS sponsorships (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                member_id        CHAR(36)     NOT NULL,
                sponsor_id       CHAR(36)     NOT NULL,
                active           TINYINT(1)   NOT NULL DEFAULT 1,
                effective_from   DATETIME     NOT NULL,
                effective_to     DATETIME     NULL,
                reason           VARCHAR(255) NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY sp_member_idx (member_id, active),
                KEY sp_sponsor_idx (sponsor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // Single active sponsor per member: app maintains active_key = member_id
        // when active, NULL when historical; UNIQUE index enforces one active.
        $this->db->query('ALTER TABLE sponsorships ADD COLUMN active_key CHAR(36) NULL');
        $this->db->query('ALTER TABLE sponsorships ADD UNIQUE KEY sp_active_uq (active_key)');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS prospects (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                referrer_id      CHAR(36)     NULL,
                link_id          CHAR(36)     NULL,
                email_hash       CHAR(64)     NULL,       -- dedupe without storing raw contact
                display_name     VARCHAR(150) NULL,
                consent          TINYINT(1)   NOT NULL DEFAULT 0,
                state            VARCHAR(20)  NOT NULL DEFAULT "captured", -- captured|converted|rejected
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY pr_referrer_idx (referrer_id),
                KEY pr_state_idx (organization_id, state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS referral_attributions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                referrer_id      CHAR(36)     NOT NULL,
                converted_user_id CHAR(36)    NOT NULL,
                link_id          CHAR(36)     NULL,
                campaign         VARCHAR(80)  NULL,
                conversion_type  VARCHAR(40)  NOT NULL,   -- registration|rsvp|attendance|completion|contribution
                source_ref       VARCHAR(191) NOT NULL,   -- idempotency for the conversion event
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ra_idem_uq (converted_user_id, conversion_type, source_ref),
                KEY ra_referrer_idx (referrer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['referral_attributions', 'prospects', 'sponsorships', 'referral_clicks', 'referral_links'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
