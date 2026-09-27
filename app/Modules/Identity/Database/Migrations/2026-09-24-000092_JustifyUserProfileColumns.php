<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 000092 — JUSTIFY every `users` column in the schema itself (onboarding
 * field-sync decision: "member profile data must be clarified/justified in the
 * schema to be reflected in the controller(s)/frontend").
 *
 * Each column carries a machine-readable classification as a COLUMN COMMENT:
 *
 *   SELF-SERVICE   — editable by the member on /me/profile (or /me/photo).
 *   VERIFICATION   — sign-in / verified identity; changing it requires an
 *                    admin or a re-verification flow, NEVER plain self-service
 *                    (surfaced read-only on /me/profile).
 *   REGISTRATION   — captured once at registration for policy (age/jurisdiction);
 *                    admin/identity flow to change afterwards.
 *   SECURITY       — credential factors; managed by dedicated security flows.
 *   ADMIN          — lifecycle/organizational state; staff-admin only, with
 *                    append-only evidence in account_state_transitions.
 *   SYSTEM         — platform-owned bookkeeping (timestamps, watermarks).
 *
 * The prose form of this table also ships in docs/CAPTURE_FIELD_MATRIX.md and
 * the profile UI labels the VERIFICATION fields read-only with an explanation
 * (Identity.profile.identityReadonlyNote).
 *
 * COMMENT-only migration: it re-states each column's CURRENT type (collected
 * from 000005 / 000036 / 000066 / 000080) + attaches the comment. Conformance
 * safe: MODIFY COLUMN statements are ignored by the schema-conformance column
 * parser (it tracks CREATE TABLE + ADD COLUMN only), and no type/default/index
 * changes. Idempotent: MODIFY with the same type+comment is a no-op.
 */
final class JustifyUserProfileColumns extends Migration
{
    /**
     * column => "full-type restatement + COMMENT '…'". Types MUST match the
     * effective DDL after every prior users migration (see class docblock).
     *
     * @var array<string,string>
     */
    private array $specs = [
        'id' => "CHAR(36) NOT NULL COMMENT 'SYSTEM: UUIDv7 primary key.'",
        'organization_id' => "CHAR(36) NOT NULL COMMENT 'SYSTEM: owning organization (single-org platform, still carried for every row).'",
        'email' => "VARCHAR(190) NULL COMMENT 'VERIFICATION: primary sign-in identifier. Set at registration or invite; changing it requires an admin/identity re-verification flow, never plain self-service. Null only for contact-promoted placeholder accounts (*@contacts.invalid is stored IN this column; a contact with no email gets the placeholder).'",
        'email_verified' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'VERIFICATION: set by the system after a verification loop (markEmailVerified). Not user-editable.'",
        'password_hash' => "VARCHAR(255) NULL COMMENT 'SECURITY: bcrypt/argon hash. Set via set-password/invite; changed only through the authenticated password flow; never displayed or exposed in profile payloads.'",
        'display_name' => "VARCHAR(150) NULL COMMENT 'SELF-SERVICE: member-editable name, edited on /me/profile (updateProfile).'",
        'status' => "VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT 'ADMIN: lifecycle state (prospect, pending_verification, active, suspended, merged, ...). Admin or verified-channel transitions only; every change writes an account_state_transitions row.'",
        'locale' => "VARCHAR(12) NOT NULL DEFAULT 'en' COMMENT 'SELF-SERVICE: UI language, edited on /me/profile. Defaults to organizations.default_locale at registration / group join / contact promotion.'",
        'timezone' => "VARCHAR(64) NOT NULL DEFAULT 'UTC' COMMENT 'SELF-SERVICE: display time zone, edited on /me/profile. Defaults to organizations.timezone at registration / group join / contact promotion.'",
        'mfa_enabled' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'SECURITY: whether a second factor is enrolled; managed by the security/MFA flow, not the profile editor.'",
        'last_login_at' => "DATETIME NULL COMMENT 'SYSTEM: audit watermark written by the authentication service on each login.'",
        'created_at' => "DATETIME NOT NULL COMMENT 'SYSTEM: row creation timestamp (UTC).'",
        'updated_at' => "DATETIME NOT NULL COMMENT 'SYSTEM: last write timestamp (UTC); every users writer must set it explicitly.'",
        'phone' => "VARCHAR(20) NULL COMMENT 'VERIFICATION: E.164 normalized phone (unique per organization via users_org_phone_uq). Collected at registration/join/promotion; shown READ-ONLY on /me/profile — changing it requires an admin or re-verification flow.'",
        'phone_verified' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'VERIFICATION: set after an OTP/verification loop. Not user-editable.'",
        'phone_input' => "VARCHAR(40) NULL COMMENT 'VERIFICATION: the phone exactly as the person entered it (evidence for support/re-normalization); never used for uniqueness.'",
        'phone_region' => "VARCHAR(4) NULL COMMENT 'VERIFICATION: default region used to normalize phone_input to E.164 (identity_policies.phone_default_region).'",
        'date_of_birth' => "DATE NULL COMMENT 'REGISTRATION: captured once at self-registration for age policy (identity_policies minimum age, is_minor derivation). Shown READ-ONLY on /me/profile — an admin/identity flow changes it.'",
        'is_minor' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'REGISTRATION: derived at registration from date_of_birth + the jurisdiction policy; recomputed by admin flows, never self-edited.'",
        'country_code' => "VARCHAR(2) NULL COMMENT 'REGISTRATION: jurisdiction (ISO-3166 alpha-2) used to pick the identity policy at registration. Shown READ-ONLY on /me/profile.'",
        'status_reason' => "VARCHAR(255) NULL COMMENT 'ADMIN: why the current status was entered (registration, group_join, contact_promotion, admin action...). Written by every users writer alongside the transition row.'",
        'status_changed_at' => "DATETIME NULL COMMENT 'ADMIN: when the current status took effect; set together with status/status_reason.'",
        'status_changed_by' => "CHAR(36) NULL COMMENT 'ADMIN: actor UUID for an admin-driven status change; null for self-service/system transitions.'",
        'merged_into_id' => "CHAR(36) NULL COMMENT 'ADMIN: surviving account when this one was merged away (identity merge evidence).'",
        'anonymized_at' => "DATETIME NULL COMMENT 'ADMIN: set when the account was anonymized (privacy erasure); the row is kept as a tombstone.'",
        'profile_photo_url' => "VARCHAR(512) NULL COMMENT 'SELF-SERVICE: already-hosted photo URL, set/removed via /me/photo on /me/profile.'",
        'profile_photo_source' => "VARCHAR(16) NULL COMMENT 'SELF-SERVICE: how the photo was set (url|upload); advisory for a later upload pipeline.'",
        'profile_photo_updated_at' => "DATETIME NULL COMMENT 'SYSTEM: last photo change, for avatar cache-busting.'",
        'verify_reminded_at' => "DATETIME NULL COMMENT 'SYSTEM: watermark — when the last pending-verification reminder was sent (expiry sweep cadence).'",
        'verify_reminder_count' => "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'SYSTEM: how many verification reminders have gone out (cadence ladder + observability).'",
    ];

    public function up(): void
    {
        // Part A rule: this migration branches on fieldExists(), so the
        // connection-lifetime schema cache MUST be reset first (otherwise a
        // stale field list silently skips every MODIFY on migrate:refresh).
        $this->db->resetDataCache();
        foreach ($this->specs as $name => $ddl) {
            if ($this->db->fieldExists($name, 'users')) {
                $this->db->query('ALTER TABLE `users` MODIFY COLUMN `' . $name . '` ' . $ddl);
            }
        }
    }

    public function down(): void
    {
        // Strip the classification comments (restate the same types, no COMMENT).
        $this->db->resetDataCache();
        foreach ($this->specs as $name => $ddl) {
            if ($this->db->fieldExists($name, 'users')) {
                // "CHAR(36) NOT NULL COMMENT '…'" -> "CHAR(36) NOT NULL"
                $type = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'$/", '', $ddl) ?? $ddl;
                $this->db->query('ALTER TABLE `users` MODIFY COLUMN `' . $name . '` ' . $type);
            }
        }
    }
}
