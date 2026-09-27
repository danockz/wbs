<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M5 — give a member journey a DORMANCY watermark.
 *
 * There was no "lapsed/dormant" model on any axis and no sweep to age inactivity
 * into one: a member who stopped participating stayed `active` forever, and the
 * involvement board's `cold` band was the only (read-only) signal. This adds the
 * minimal state the dormancy sweep needs on `member_journeys`:
 *   - `dormancy_state` — 'active' | 'dormant' (a soft, reversible re-engagement
 *     axis distinct from the hard `status` lifecycle; a dormant member is still a
 *     member);
 *   - `dormant_since` — when the sweep first marked them dormant (cleared on
 *     re-engagement), so the "how long lapsed" tile and re-activation are exact.
 * Indexed by (organization_id, dormancy_state) for the board's dormant tile and
 * the sweep's bounded scan. Idempotent.
 */
final class AddJourneyDormancy extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('dormancy_state', 'member_journeys')) {
            $this->db->query(
                'ALTER TABLE member_journeys ADD COLUMN dormancy_state VARCHAR(8) NOT NULL DEFAULT "active" AFTER status'
            );
        }
        if (! $this->db->fieldExists('dormant_since', 'member_journeys')) {
            $this->db->query(
                'ALTER TABLE member_journeys ADD COLUMN dormant_since DATETIME NULL AFTER dormancy_state'
            );
        }
        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('member_journeys'),
        );
        if (! in_array('mj_dormancy_idx', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE member_journeys ADD KEY mj_dormancy_idx (organization_id, dormancy_state)'
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
            $this->db->getIndexData('member_journeys'),
        );
        if (in_array('mj_dormancy_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE member_journeys DROP KEY mj_dormancy_idx');
        }
        if ($this->db->fieldExists('dormant_since', 'member_journeys')) {
            $this->db->query('ALTER TABLE member_journeys DROP COLUMN dormant_since');
        }
        if ($this->db->fieldExists('dormancy_state', 'member_journeys')) {
            $this->db->query('ALTER TABLE member_journeys DROP COLUMN dormancy_state');
        }
    }
}
