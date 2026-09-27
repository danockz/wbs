<?php

declare(strict_types=1);

namespace WBS\Geo\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Venue enrichment + venue↔group assignment (SRS §8.3, location-based
 * discovery). Adds the descriptive/operational columns the venue CRUD needs
 * (type, status, contact, amenities, hours, accessibility) and a join table so
 * a venue can serve one or more groups with an assignment role.
 *
 * Venue data is non-personal facility data — unlike personal location it is not
 * consent-gated — so precise coordinates and public discovery are allowed per
 * the venue's own discovery_status.
 */
final class EnhanceVenues extends Migration
{
    public function up(): void
    {
        // Descriptive/operational columns (idempotent-ish: IF NOT EXISTS on MariaDB 10.5+/MySQL 8).
        $cols = [
            "ADD COLUMN venue_type VARCHAR(40) NOT NULL DEFAULT 'other' AFTER name",
            "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER venue_type",
            'ADD COLUMN address_text VARCHAR(255) NULL AFTER status',
            'ADD COLUMN contact_phone VARCHAR(40) NULL AFTER address_text',
            'ADD COLUMN contact_email VARCHAR(190) NULL AFTER contact_phone',
            'ADD COLUMN website VARCHAR(255) NULL AFTER contact_email',
            'ADD COLUMN operating_hours JSON NULL AFTER website',
            'ADD COLUMN accessibility_features JSON NULL AFTER operating_hours',
            'ADD COLUMN parking_info VARCHAR(255) NULL AFTER accessibility_features',
            'ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER parking_info',
            'ADD COLUMN created_by CHAR(36) NULL AFTER version',
            'ADD COLUMN updated_by CHAR(36) NULL AFTER created_by',
            'ADD COLUMN deleted_at DATETIME NULL AFTER updated_at',
        ];
        foreach ($cols as $clause) {
            // Guard each column so re-running does not fatally error.
            try {
                $this->db->query('ALTER TABLE venues ' . $clause);
            } catch (\Throwable $e) {
                // Column already exists — ignore.
            }
        }

        $this->db->query('CREATE INDEX venues_type_status_idx ON venues (venue_type, status)');
        $this->db->query('CREATE INDEX venues_deleted_idx ON venues (deleted_at)');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS venue_group_assignments (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                venue_id         CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                assignment_type  VARCHAR(20)  NOT NULL DEFAULT "primary", -- primary|secondary|overflow
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY vga_uq (venue_id, group_id),
                KEY vga_group_idx (group_id),
                KEY vga_org_idx (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS venue_group_assignments');

        foreach ([
            'venues_type_status_idx', 'venues_deleted_idx',
        ] as $idx) {
            try {
                $this->db->query("DROP INDEX {$idx} ON venues");
            } catch (\Throwable $e) {
            }
        }

        foreach ([
            'venue_type', 'status', 'address_text', 'contact_phone', 'contact_email',
            'website', 'operating_hours', 'accessibility_features', 'parking_info',
            'version', 'created_by', 'updated_by', 'deleted_at',
        ] as $col) {
            try {
                $this->db->query("ALTER TABLE venues DROP COLUMN {$col}");
            } catch (\Throwable $e) {
            }
        }
    }
}
