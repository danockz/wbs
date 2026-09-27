<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Fraud-review actor trail (gap G3).
 *
 * The two "held → final" doors were asymmetric: `approveAward` records the
 * approver (on the review row's reason) while `clearHeld` recorded no actor at
 * all — a weaker audit trail for an equivalent money-adjacent action. This adds
 * an explicit `resolved_by` column so BOTH doors (and `rejectAward`) stamp who
 * resolved the review.
 *
 * Idempotent: guarded ADD COLUMN.
 */
final class AddFraudReviewResolvedBy extends Migration
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
        if (! $this->db->fieldExists('resolved_by', 'fraud_reviews')) {
            $this->db->query(
                'ALTER TABLE fraud_reviews ADD COLUMN resolved_by CHAR(36) NULL AFTER resolved_at',
            );
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        if (! $this->db->tableExists('fraud_reviews')) {
            return;
        }
        if ($this->db->fieldExists('resolved_by', 'fraud_reviews')) {
            $this->db->query('ALTER TABLE fraud_reviews DROP COLUMN resolved_by');
        }
    }
}
