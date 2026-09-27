<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Dated DECISIONS a prospect/contact confirms during follow-up (FR-MEM-*,
 * discipleship follow-up & reporting). A prospect may confirm at least three
 * decisions over time, each with its own date; one canonical decision type is
 * `join_group` (the decision to join a group, relevant when they are not yet a
 * member).
 *
 * Decision TYPES are org-configurable (seeded defaults: faith milestones +
 * join_group) — stored as a code here, resolved against a config catalog, so no
 * enum lock-in. The row is append-only history: recording the same decision type
 * again captures a new dated occurrence (e.g. rededication), never overwrites.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
final class CreateProspectDecisions extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS prospect_decisions (
                id                CHAR(36)     NOT NULL,
                organization_id   CHAR(36)     NOT NULL,
                prospect_id       CHAR(36)     NOT NULL,
                decision_type     VARCHAR(40)  NOT NULL,   -- e.g. salvation|rededication|water_baptism|holy_spirit_baptism|join_group
                decision_date     DATE         NOT NULL,   -- the date the decision was made (may be historic)
                target_group_id   CHAR(36)     NULL,       -- for join_group: which group they decided to join
                note              VARCHAR(500) NULL,
                recorded_by       CHAR(36)     NULL,        -- member/staff who recorded it
                recorded_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY pd_prospect_idx (prospect_id, decision_date),
                KEY pd_type_idx (organization_id, decision_type),
                KEY pd_group_idx (target_group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS prospect_decisions');
    }
}
