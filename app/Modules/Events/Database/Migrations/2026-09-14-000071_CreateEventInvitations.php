<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Event invitations + the shareable per-event invite LINK (gap G5).
 *
 * `registration_policy = 'invite'` was declared but NEVER enforced: an invite-only
 * event behaved exactly like an open one. Two complementary structures make the
 * policy real, matching how invitations actually spread:
 *
 * 1. `event_invitations` — the DIRECT allow-list. One row per person invited by a
 *    known identity, through one channel:
 *      - `user`  : a known platform user (invitee_user_id);
 *      - `email` : an email address (invitee_email, lower-cased) matched to the
 *                  registering user's email;
 *      - `phone` : an E.164 phone (invitee_phone) matched to the user's phone.
 *    A direct email/phone invite is ALSO delivered (Notifications) carrying the
 *    event's shareable link, so the two mechanisms surface the same URL.
 *
 * 2. `event_invite_links` — the shareable, CLOAKED, per-event URL. Exactly one
 *    active link per event (an opaque token whose SHA-256 hash is stored; the
 *    plaintext travels in the URL and is shown once on generation). Unlike a
 *    direct invite it is REUSABLE and broadcast (social media + direct
 *    email/SMS). Its reach is bounded by a configurable `mode`:
 *      - `expiry`          : usable until `expires_at` (or manual disable);
 *      - `max_redemptions` : usable until `redeemed_count >= max_redemptions`;
 *      - `capacity`        : usable while the event has seats (registrar-enforced).
 *    `active` supports manual disable without deleting history. Someone who opens
 *    the link may register when signed in, OR be captured as a GUEST prospect
 *    owned by the link's creator (the sponsor).
 *
 * Both tables are nullable/additive — existing events are untouched, and an
 * open/closed event never consults them.
 */
final class CreateEventInvitations extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_invitations (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                channel          VARCHAR(10)  NOT NULL DEFAULT "user", -- user|email|phone
                invitee_user_id  CHAR(36)     NULL,
                invitee_email    VARCHAR(190) NULL,      -- lower-cased match key
                invitee_phone    VARCHAR(20)  NULL,      -- E.164 match key
                status           VARCHAR(12)  NOT NULL DEFAULT "pending", -- pending|accepted|revoked
                invited_by       CHAR(36)     NULL,
                accepted_by      CHAR(36)     NULL,      -- user who claimed it
                accepted_at      DATETIME     NULL,
                notified_at      DATETIME     NULL,      -- when the email/SMS invite was staged
                expires_at       DATETIME     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY ei_event_user_idx (event_id, invitee_user_id),
                KEY ei_event_email_idx (event_id, invitee_email),
                KEY ei_event_phone_idx (event_id, invitee_phone),
                KEY ei_event_status_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_invite_links (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                token            VARCHAR(80)  NOT NULL,  -- cloaked (unguessable) token; broadcast publicly, so not secret
                token_hash       CHAR(64)     NOT NULL,  -- SHA-256 of token, used as the indexed lookup key
                mode             VARCHAR(16)  NOT NULL DEFAULT "expiry", -- expiry|max_redemptions|capacity
                max_redemptions  INT UNSIGNED NULL,      -- only for mode=max_redemptions
                redeemed_count   INT UNSIGNED NOT NULL DEFAULT 0,
                active           TINYINT(1)   NOT NULL DEFAULT 1,
                expires_at       DATETIME     NULL,       -- only for mode=expiry (NULL = no expiry)
                created_by       CHAR(36)     NULL,       -- the sponsor: guest captures are owned by them
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY eil_event_uq (event_id),       -- exactly one link per event
                UNIQUE KEY eil_token_uq (token_hash),
                KEY eil_org_idx (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS event_invite_links');
        $this->db->query('DROP TABLE IF EXISTS event_invitations');
    }
}
