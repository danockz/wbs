<?php

declare(strict_types=1);

namespace WBS\Contributions\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * C4 — give `reconciliation_cases` an idempotency handle.
 *
 * The reconciliation pass must be safe to run on every schedule tick without
 * opening a fresh duplicate case for the same discrepancy each time. This adds a
 * stable `dedupe_ref` (e.g. `missing_ledger:contribution:<id>`,
 * `orphan_webhook:webhook:<id>`) plus a UNIQUE index over
 * (organization_id, dedupe_ref) so at most ONE case per discrepancy can exist,
 * and the service can `INSERT ... ` and treat a collision as "already open".
 * Idempotent.
 */
final class AddReconciliationDedupeRef extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('dedupe_ref', 'reconciliation_cases')) {
            $this->db->query(
                'ALTER TABLE reconciliation_cases ADD COLUMN dedupe_ref VARCHAR(191) NULL AFTER kind'
            );
        }
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('reconciliation_cases'),
        );
        if (! in_array('rc_dedupe_uq', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE reconciliation_cases ADD UNIQUE KEY rc_dedupe_uq (organization_id, dedupe_ref)'
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('reconciliation_cases'),
        );
        if (in_array('rc_dedupe_uq', $indexes, true)) {
            $this->db->query('ALTER TABLE reconciliation_cases DROP KEY rc_dedupe_uq');
        }
        if ($this->db->fieldExists('dedupe_ref', 'reconciliation_cases')) {
            $this->db->query('ALTER TABLE reconciliation_cases DROP COLUMN dedupe_ref');
        }
    }
}
