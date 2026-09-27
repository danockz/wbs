<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G2 — give open fraud reviews an AGING / escalation state.
 *
 * A `requires_review` award opens a `fraud_reviews` row (`status='open'`) and
 * holds the points, but nothing ever aged, reminded, or escalated it — a review
 * a human never noticed left the points stranded forever. This adds the minimal
 * state the aging sweep needs so its remind→escalate→timeout pass is bounded and
 * idempotent:
 *   - `reminded_at`    — watermark of the last reminder (re-remind only after the
 *     configured interval elapses; NULL = never reminded);
 *   - `reminder_count` — how many reminders have fired (observability + drives
 *     escalation);
 *   - `escalated_at`   — when the review crossed the escalation SLA (stamped once,
 *     so escalation notifies exactly once).
 * Indexed by (organization_id, status, created_at) for the sweep's bounded scan
 * over open reviews oldest-first. Idempotent (guarded ADD COLUMN / index).
 */
final class AddFraudReviewAging extends Migration
{
    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('fraud_reviews')) {
            return;
        }

        if (! $this->db->fieldExists('reminded_at', 'fraud_reviews')) {
            $this->db->query('ALTER TABLE fraud_reviews ADD COLUMN reminded_at DATETIME(6) NULL AFTER status');
        }
        if (! $this->db->fieldExists('reminder_count', 'fraud_reviews')) {
            $this->db->query('ALTER TABLE fraud_reviews ADD COLUMN reminder_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at');
        }
        if (! $this->db->fieldExists('escalated_at', 'fraud_reviews')) {
            $this->db->query('ALTER TABLE fraud_reviews ADD COLUMN escalated_at DATETIME(6) NULL AFTER reminder_count');
        }

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('fraud_reviews'));
        if (! in_array('fr_aging_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE fraud_reviews ADD KEY fr_aging_idx (organization_id, status, created_at)');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->tableExists('fraud_reviews')) {
            return;
        }

        $indexes = array_map(static fn ($i) => $i->name, $this->db->getIndexData('fraud_reviews'));
        if (in_array('fr_aging_idx', $indexes, true)) {
            $this->db->query('ALTER TABLE fraud_reviews DROP KEY fr_aging_idx');
        }
        foreach (['escalated_at', 'reminder_count', 'reminded_at'] as $col) {
            if ($this->db->fieldExists($col, 'fraud_reviews')) {
                $this->db->query("ALTER TABLE fraud_reviews DROP COLUMN {$col}");
            }
        }
    }
}
