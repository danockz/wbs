<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Integration decisions (FR-REF-3b) — extend `prospect_decisions` so a decision
 * is a dated, sourced, confirmable statement about a PERSON, not just a free-text
 * row about a contact:
 *
 *   - `user_id`      — a decision may attach to a platform user directly (a
 *                      member self-declaring, or a row derived from a course
 *                      enrolment, where the member has no contact row). Kept
 *                      NULL for contact-attached rows; the service enforces
 *                      "exactly one subject" (prospect XOR user).
 *   - `prospect_id`  — made NULLABLE so user-attached rows can exist (the PK is
 *                      `id`, so this is safe; existing rows keep their value).
 *   - `status`       — pending|confirmed|rejected. A self-declaration is born
 *                      `pending` and only COUNTS once the owning mentor/sponsor
 *                      confirms it (maker-checker); staff-assisted and derived
 *                      rows are born `confirmed`.
 *   - `source`       — assisted|self|landing|event_guest|derived_course|
 *                      derived_completion|system. Who/what recorded it.
 *   - `source_ref`   — idempotency anchor for DERIVED rows (e.g. enr:<id>), the
 *                      subject of the two UNIQUE keys below. Hand-recorded rows
 *                      leave it NULL, so append-only history is untouched (MySQL
 *                      allows any number of NULLs under a UNIQUE key).
 *   - `decided_by` / `decided_at` — who confirmed/rejected a pending row, when.
 *
 * Idempotent: every column/key is added only when absent, following the
 * `resetDataCache()` + `fieldExists()`/`getIndexData()` idiom (raw DDL does not
 * invalidate CI4's connection-lifetime schema cache).
 */
final class ExtendProspectDecisions extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();

        $add = [
            'user_id'    => 'CHAR(36) NULL AFTER prospect_id',
            'status'     => "VARCHAR(20) NOT NULL DEFAULT 'confirmed'",
            'source'     => "VARCHAR(32) NOT NULL DEFAULT 'assisted'",
            'source_ref' => 'VARCHAR(160) NULL',
            'decided_by' => 'CHAR(36) NULL',
            'decided_at' => 'DATETIME NULL',
        ];
        foreach ($add as $column => $ddl) {
            if (! $this->db->fieldExists($column, 'prospect_decisions')) {
                $this->db->query("ALTER TABLE prospect_decisions ADD COLUMN $column $ddl");
            }
        }

        // prospect_id -> nullable (user-attached rows). fieldExists() cannot see
        // nullability, so read it straight from the information schema.
        $nullRow = $this->db->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prospect_decisions'
               AND COLUMN_NAME = 'prospect_id'"
        )->getRowArray();
        if (($nullRow['IS_NULLABLE'] ?? 'NO') === 'NO') {
            $this->db->query('ALTER TABLE prospect_decisions MODIFY COLUMN prospect_id CHAR(36) NULL');
        }

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('prospect_decisions'));
        $wanted  = [
            'pd_user_idx'         => 'ADD KEY pd_user_idx (user_id)',
            'pd_status_idx'       => 'ADD KEY pd_status_idx (organization_id, status)',
            'pd_prospect_uq'      => 'ADD UNIQUE KEY pd_prospect_uq (prospect_id, decision_type, source_ref)',
            'pd_user_uq'          => 'ADD UNIQUE KEY pd_user_uq (user_id, decision_type, source_ref)',
        ];
        foreach ($wanted as $name => $ddl) {
            if (! in_array($name, $indexes, true)) {
                $this->db->query("ALTER TABLE prospect_decisions $ddl");
            }
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('prospect_decisions'));
        foreach (['pd_user_uq', 'pd_prospect_uq', 'pd_status_idx', 'pd_user_idx'] as $name) {
            if (in_array($name, $indexes, true)) {
                $this->db->query("ALTER TABLE prospect_decisions DROP KEY $name");
            }
        }

        foreach (['decided_at', 'decided_by', 'source_ref', 'source', 'status', 'user_id'] as $column) {
            if ($this->db->fieldExists($column, 'prospect_decisions')) {
                $this->db->query("ALTER TABLE prospect_decisions DROP COLUMN $column");
            }
        }
    }
}
