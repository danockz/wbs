<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extend referral tracking for fraud detection, device uniqueness, and typed
 * redirects (adaptation of urlmanager.md / clicktracker.md into WBS\Referrals).
 *
 * Additive + reversible. Preserves every WBS privacy invariant:
 *  - referral_clicks still stores NO raw IP/UA/geo. The new device_hash is a
 *    sha256 of a NORMALIZED client-signal bundle (never the raw components),
 *    and suspicious_reason is an operator note, not echoed user input.
 *  - referral_links gains a typed-redirect discriminator (link_type) and an
 *    opaque resource_id so a cloaked code can resolve to the right platform
 *    destination without embedding a reversible payload in the URL.
 */
final class ExtendReferralsTracking extends Migration
{
    public function up(): void
    {
        // --- G2 fraud + G5 device fingerprint (privacy-safe hash) -----------
        $this->db->query('
            ALTER TABLE referral_clicks
                ADD COLUMN is_suspicious     TINYINT(1)   NOT NULL DEFAULT 0,
                ADD COLUMN suspicious_reason VARCHAR(255) NULL,
                ADD COLUMN device_hash       CHAR(64)     NULL
        ');
        $this->db->query('
            ALTER TABLE referral_clicks
                ADD KEY rc_susp_idx (link_id, is_suspicious)
        ');

        // --- G4 typed redirect ---------------------------------------------
        $this->db->query('
            ALTER TABLE referral_links
                ADD COLUMN link_type   VARCHAR(20) NOT NULL DEFAULT "member",
                ADD COLUMN resource_id CHAR(36)    NULL
        ');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE referral_clicks DROP KEY rc_susp_idx');
        $this->db->query('
            ALTER TABLE referral_clicks
                DROP COLUMN is_suspicious,
                DROP COLUMN suspicious_reason,
                DROP COLUMN device_hash
        ');
        $this->db->query('
            ALTER TABLE referral_links
                DROP COLUMN link_type,
                DROP COLUMN resource_id
        ');
    }
}
