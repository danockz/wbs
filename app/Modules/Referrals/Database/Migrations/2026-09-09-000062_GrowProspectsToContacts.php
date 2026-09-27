<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Grow `prospects` into a first-class CONTACT / address-book record (FR-MEM-*,
 * outreach & follow-up). Per the "extend, don't fork" decision we do NOT create a
 * parallel contacts table: a prospect already models a consent-gated captured
 * lead, so we enrich it with the fields a member/staff address book needs for a
 * birds-eye view of their downline and easy follow-up + reporting.
 *
 * Privacy: a contact's location is PRIVATE. Precise GPS is only stored when the
 * prospect verbally consents AND the member confirms via a checkbox (both flags
 * below); the actual coordinates live in the Geo `addresses` row referenced by
 * `address_id`, and precision is coarsened by LocationService when consent is
 * absent. No raw personal GPS is ever stored without consent.
 *
 * All columns nullable / defaulted so existing prospects keep working. Idempotent:
 * each column is only added when absent, so a partial run can resume safely.
 */
final class GrowProspectsToContacts extends Migration
{
    /** @var array<string,string> column => DDL type/clause */
    private array $columns = [
        // Ownership & placement -------------------------------------------------
        'owner_user_id'      => 'CHAR(36) NULL',          // member/staff who manages this contact
        'assigned_group_id'  => 'CHAR(36) NULL',          // hierarchical group this contact is tracked under
        'linked_user_id'     => 'CHAR(36) NULL',          // platform user this contact maps to (for event/course registrations)
        'created_by'         => 'CHAR(36) NULL',          // who created the record (staff bulk vs member)
        'source'             => "VARCHAR(24) NOT NULL DEFAULT 'member'", // member|staff_bulk|link|import

        // Contact details (address-book) ---------------------------------------
        // display_name already exists on prospects; email_hash for dedupe stays.
        'full_name'          => 'VARCHAR(150) NULL',
        'phone'              => 'VARCHAR(40)  NULL',
        'email'              => 'VARCHAR(190) NULL',
        'address_id'         => 'CHAR(36) NULL',          // -> Geo addresses (private location)
        'notes'              => 'TEXT NULL',

        // Follow-up triage (birds-eye of downline) -----------------------------
        'journey_stage'      => "VARCHAR(40) NOT NULL DEFAULT 'prospect'", // mirrors Journey stage codes
        'temperature'        => "VARCHAR(8)  NOT NULL DEFAULT 'cold'",     // hot|warm|cold
        'last_contacted_at'  => 'DATETIME NULL',
        'next_follow_up_at'  => 'DATETIME NULL',
        'follow_up_count'    => 'INT UNSIGNED NOT NULL DEFAULT 0',

        // What they were invited to (optional) ---------------------------------
        'invite_context_type' => "VARCHAR(16) NULL",      // cause|course|event|group|null
        'invite_context_id'   => 'CHAR(36) NULL',

        // Coordinate-tagging consent (verbal + member checkbox) ----------------
        // coords_consent_verbal  = prospect verbally agreed to have coords tagged
        // coords_consent_confirmed = the member ticked the confirmation box
        // Both must be true before precise coords are persisted on the address.
        'coords_consent_verbal'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'coords_consent_confirmed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'coords_consent_at'        => 'DATETIME NULL',

        'updated_at'         => 'DATETIME NULL',
    ];

    /** @var array<string,string> index name => DDL */
    private array $indexes = [
        'pr_owner_idx'      => 'ADD KEY pr_owner_idx (owner_user_id)',
        'pr_group_idx'      => 'ADD KEY pr_group_idx (assigned_group_id)',
        'pr_linked_idx'     => 'ADD KEY pr_linked_idx (linked_user_id)',
        'pr_temp_idx'       => 'ADD KEY pr_temp_idx (organization_id, temperature)',
        'pr_followup_idx'   => 'ADD KEY pr_followup_idx (organization_id, next_follow_up_at)',
        'pr_stage_idx2'     => 'ADD KEY pr_stage_idx2 (organization_id, journey_stage)',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach ($this->columns as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'prospects')) {
                $this->db->query('ALTER TABLE `prospects` ADD COLUMN `' . $name . '` ' . $ddl);
            }
        }

        $existing = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('prospects'),
        );
        foreach ($this->indexes as $idx => $ddl) {
            if (! in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE `prospects` ' . $ddl);
            }
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $existing = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('prospects'),
        );
        foreach (array_keys($this->indexes) as $idx) {
            if (in_array($idx, $existing, true)) {
                $this->db->query('ALTER TABLE `prospects` DROP INDEX `' . $idx . '`');
            }
        }
        foreach (array_keys($this->columns) as $name) {
            if ($this->db->fieldExists($name, 'prospects')) {
                $this->db->query('ALTER TABLE `prospects` DROP COLUMN `' . $name . '`');
            }
        }
    }
}
