<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * J6 — give the journey proposal queue an AGING / escalation state.
 *
 * A rule that PROPOSES (effect require_review|flag) writes a pending
 * `journey_stage_proposals` row for a leader to approve/reject, but nothing ever
 * aged that queue: a proposal a leader never noticed sat `pending` forever, and
 * `superseded` was a declared status value NOTHING ever set. This adds the
 * minimal state the aging sweep needs so its remind → escalate → timeout pass is
 * bounded and idempotent:
 *   - `reminded_at`    — watermark of the last reminder to the reviewing leader
 *     (re-remind only after the configured interval elapses; NULL = never);
 *   - `reminder_count` — how many reminders have fired (observability);
 *   - `escalated_at`   — when the proposal crossed the escalation SLA (stamped
 *     once, so escalation notifies exactly once).
 *
 * Indexed by (organization_id, status, created_at) for the sweep's bounded scan
 * over pending proposals oldest-first (the existing jsp_pending_idx is keyed for
 * a leader's per-group queue, not an org-wide oldest-first age scan). Idempotent
 * (guarded ADD COLUMN / index).
 */
final class AddJourneyProposalAging extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('journey_stage_proposals')) {
            return;
        }

        if (! $this->db->fieldExists('reminded_at', 'journey_stage_proposals')) {
            $this->db->query('ALTER TABLE journey_stage_proposals ADD COLUMN reminded_at DATETIME(6) NULL AFTER status');
        }
        if (! $this->db->fieldExists('reminder_count', 'journey_stage_proposals')) {
            $this->db->query('ALTER TABLE journey_stage_proposals ADD COLUMN reminder_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at');
        }
        if (! $this->db->fieldExists('escalated_at', 'journey_stage_proposals')) {
            $this->db->query('ALTER TABLE journey_stage_proposals ADD COLUMN escalated_at DATETIME(6) NULL AFTER reminder_count');
        }

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('journey_stage_proposals'));
        if (! in_array('jsp_aging_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE journey_stage_proposals ADD KEY jsp_aging_idx (organization_id, status, created_at)');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->tableExists('journey_stage_proposals')) {
            return;
        }

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('journey_stage_proposals'));
        if (in_array('jsp_aging_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE journey_stage_proposals DROP KEY jsp_aging_idx');
        }
        foreach (['escalated_at', 'reminder_count', 'reminded_at'] as $col) {
            if ($this->db->fieldExists($col, 'journey_stage_proposals')) {
                $this->db->query("ALTER TABLE journey_stage_proposals DROP COLUMN {$col}");
            }
        }
    }
}
