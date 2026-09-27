<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Delegation provenance (gap AC2).
 *
 * A delegation derived from a ROLE ASSIGNMENT or an approved ACCESS REQUEST used
 * to be inserted with parent_id = NULL, so revoking/expiring that source grant
 * could NOT cascade to the delegations carved from it (revoke() only walks
 * parent_id, which is null for role/request-rooted delegations). A delegate could
 * therefore retain lent authority after the delegator lost the authority that
 * justified the loan.
 *
 * This adds an explicit provenance link on every delegation back to the ROOT
 * grant it descends from:
 *   - source_grant_type: role_assignment | access_request | delegation
 *   - source_grant_id:   the id of that root grant
 *
 * The root id is propagated to the WHOLE sub-delegation chain (children inherit
 * their parent's source_grant_id), so a single flat
 * `WHERE source_grant_id = :id` revoke reaches the entire subtree in one step.
 *
 * Idempotent: guarded ADD COLUMN.
 */
final class AddDelegationProvenance extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('delegations')) {
            return;
        }
        if (! $this->db->fieldExists('source_grant_type', 'delegations')) {
            $this->db->query(
                "ALTER TABLE delegations ADD COLUMN source_grant_type VARCHAR(20) NULL AFTER parent_id",
            );
        }
        if (! $this->db->fieldExists('source_grant_id', 'delegations')) {
            $this->db->query(
                'ALTER TABLE delegations ADD COLUMN source_grant_id CHAR(36) NULL AFTER source_grant_type',
            );
            $this->db->query(
                'ALTER TABLE delegations ADD KEY dl_source_idx (source_grant_id)',
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('delegations')) {
            return;
        }
        if ($this->db->fieldExists('source_grant_id', 'delegations')) {
            $this->db->query('ALTER TABLE delegations DROP KEY dl_source_idx');
            $this->db->query('ALTER TABLE delegations DROP COLUMN source_grant_id');
        }
        if ($this->db->fieldExists('source_grant_type', 'delegations')) {
            $this->db->query('ALTER TABLE delegations DROP COLUMN source_grant_type');
        }
    }
}
