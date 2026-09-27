<?php

declare(strict_types=1);

namespace WBS\Journey\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rule-driven journey transitions — the PROPOSE side (assessment Option C).
 *
 * Option C wires the general RuBAC RuleEngine (facet = 'membership') to journey
 * signals. Per the redefinition decision, whether a matched rule AUTO-APPLIES or
 * only PROPOSES is configurable PER RULE, and maps onto the rule's existing
 * effect vocabulary:
 *
 *   - effect = 'adjust'          -> AUTO-APPLY the stage transition immediately;
 *   - effect = 'require_review'  -> PROPOSE: write a pending row here for a
 *                                   leader to approve/reject.
 *
 * This table is ONLY the propose queue; auto-applied transitions go straight to
 * member_journey_transitions (source='rule'). A proposal, once approved, results
 * in exactly the same transition (source='rule', with the proposal id as
 * evidence_ref). History is never rewritten.
 *
 * No schema change was needed on `rules` — it already carries facet, condition,
 * effect and effect_params. This migration adds only the proposal queue.
 */
final class CreateJourneyProposals extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS journey_stage_proposals (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                user_id            CHAR(36)     NOT NULL,
                group_id           CHAR(36)     NULL,     -- journey context (NULL = org-wide primary)
                from_stage         VARCHAR(60)  NULL,     -- current stage when proposed (may be NULL)
                to_stage           VARCHAR(60)  NOT NULL, -- proposed target stage
                direction          VARCHAR(12)  NOT NULL DEFAULT "advance",
                rule_code          VARCHAR(120) NULL,     -- the membership rule that fired
                signal_action      VARCHAR(160) NULL,     -- e.g. journey.signal.course.completed
                evidence_type      VARCHAR(40)  NULL,
                evidence_ref       VARCHAR(120) NULL,
                reason             VARCHAR(255) NULL,
                status             VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|approved|rejected|superseded
                decided_by         CHAR(36)     NULL,
                decided_at         DATETIME     NULL,
                decision_note      VARCHAR(255) NULL,
                created_at         DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY jsp_pending_idx (organization_id, status, group_id),
                KEY jsp_user_idx (organization_id, user_id, status)
                -- Dedup of open proposals is enforced in the service, because a
                -- UNIQUE key over a NULLable group_id would treat org-wide rows
                -- as always-distinct (MySQL NULLs compare unequal).
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS journey_stage_proposals');
    }
}
