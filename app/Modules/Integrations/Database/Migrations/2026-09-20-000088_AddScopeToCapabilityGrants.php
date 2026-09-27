<?php

declare(strict_types=1);

namespace WBS\Integrations\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Subtree grants on provider connections (FR-INT-007).
 *
 * Each hierarchical body supplies its OWN provider credentials — its own
 * `integration_connections` row (group_id = that body) with write-only slots in
 * `connection_credentials` — and decides whether its subtree may send on them.
 * Until now a grant named exactly one grantee group, so "every cell under this
 * region" meant writing a row per cell, and cells created later inherited
 * nothing.
 *
 * This adds the platform's EXISTING scope vocabulary to `capability_grants`
 * rather than inventing a second one:
 *
 *  - `scope_mode` — `self` (the named grantee only: the legacy meaning),
 *    `self_and_descendants` (the grantee and every subgroup, including ones
 *    created later), `descendants_only` (subgroups but not the grantee itself),
 *    or `groups` (a hand-picked set in `grant_scope_groups`, grant_type
 *    `capability_grant`). NULL on rows written before this migration, which
 *    `ScopeMode::normalize(null, null)` reads as `self` — so every existing
 *    grant keeps its exact current reach and nothing widens silently.
 *  - `include_crosscut` — opt-in, default OFF, matching `role_assignments`:
 *    coverage flows down to cross-cut groups linked to a covered hierarchy node
 *    and never chains further.
 *
 * `grantee_group_id` keeps its name and its meaning as the group the grant is
 * MADE TO; `scope_mode` says how far below that group the access reaches.
 * Resolution reuses `GroupScopeResolver::grantCoversScoped()` — the same call the
 * PDP makes — so a credential grant and a role grant cannot disagree about what
 * "covers the subtree" means.
 *
 * Idempotent: add-if-absent columns, safe to re-run.
 */
final class AddScopeToCapabilityGrants extends Migration
{
    public function up(): void
    {
        // Raw DDL doesn't invalidate CI4's connection-lifetime schema cache.
        $this->db->resetDataCache();

        if ($this->db->tableExists('capability_grants')) {
            if (! $this->db->fieldExists('scope_mode', 'capability_grants')) {
                $this->db->query('ALTER TABLE `capability_grants` ADD COLUMN `scope_mode` VARCHAR(24) NULL AFTER `capability`');
            }
            if (! $this->db->fieldExists('include_crosscut', 'capability_grants')) {
                $this->db->query('ALTER TABLE `capability_grants` ADD COLUMN `include_crosscut` TINYINT(1) NOT NULL DEFAULT 0 AFTER `scope_mode`');
            }

            $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('capability_grants'));
            if (! in_array('cg_capability_idx', $existing, true)) {
                // The resolver looks grants up by (org, capability, status) and then
                // tests scope in PHP via the shared resolver.
                $this->db->query('ALTER TABLE `capability_grants` ADD KEY `cg_capability_idx` (`organization_id`, `capability`, `status`)');
            }
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('capability_grants')) {
            $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('capability_grants'));
            if (in_array('cg_capability_idx', $existing, true)) {
                $this->db->query('ALTER TABLE `capability_grants` DROP INDEX `cg_capability_idx`');
            }
            if ($this->db->fieldExists('include_crosscut', 'capability_grants')) {
                $this->db->query('ALTER TABLE `capability_grants` DROP COLUMN `include_crosscut`');
            }
            if ($this->db->fieldExists('scope_mode', 'capability_grants')) {
                $this->db->query('ALTER TABLE `capability_grants` DROP COLUMN `scope_mode`');
            }
        }

        $this->db->resetDataCache();
    }
}
