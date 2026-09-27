<?php

declare(strict_types=1);

namespace WBS\Referrals\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Prospect-transfer maker-checker workflow (FR-REF-7 review path).
 *
 * An inactivity transfer moves a person between groups AND re-parents their
 * sponsor, so a body can require that a second leader reviews it before it takes
 * effect. Whether review is required is HIERARCHICAL GROUP CONFIG
 * (`referrals.prospect_transfer.requires_review`, default OFF ⇒ transfers apply
 * immediately as before), never env or a global.
 *
 * Mirrors the sponsor-reassignment maker-checker model exactly (000052), so the
 * two queues behave, gate and read the same way:
 *  - `prospect_transfer_requests` — one row per request (pending → approved |
 *    rejected | cancelled), carrying the evaluation snapshot that justified it
 *    (threshold in force, observed inactivity, from/to group+owner, trigger) so a
 *    checker sees the consequences before deciding. There is NO expiry column: a
 *    request stays pending until a human decides it, and what protects against
 *    stale facts is the eligibility re-check the service performs at approve;
 *  - `prospect_transfer_reviews`  — append-only decision trail.
 *
 * The applied provenance stays in `prospect_group_transfers` (000086), which
 * gains a nullable `request_id` back-reference: a reviewed transfer points at the
 * request that authorized it, an automatic one leaves it NULL. Requests never
 * rewrite history — approving delegates to `ProspectTransferService::apply()`,
 * which ends the old membership and opens the new one exactly as it always did.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS + add-if-absent column, safe to re-run.
 */
final class CreateProspectTransferRequests extends Migration
{
    public function up(): void
    {
        // Raw DDL doesn't invalidate CI4's connection-lifetime schema cache.
        $this->db->resetDataCache();

        $this->db->query('
            CREATE TABLE IF NOT EXISTS prospect_transfer_requests (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                prospect_id        CHAR(36)     NOT NULL,
                linked_user_id     CHAR(36)     NULL,
                from_group_id      CHAR(36)     NULL,
                to_group_id        CHAR(36)     NOT NULL,
                from_owner_user_id CHAR(36)     NULL,
                to_owner_user_id   CHAR(36)     NOT NULL,
                requested_by       CHAR(36)     NOT NULL,
                approver_id        CHAR(36)     NULL,
                reason             VARCHAR(500) NOT NULL,
                trigger_type       VARCHAR(24)  NULL,
                trigger_id         CHAR(36)     NULL,
                threshold_weeks    INT UNSIGNED NULL,
                days_inactive      INT UNSIGNED NULL,
                evaluation         JSON         NULL,
                status             VARCHAR(16)  NOT NULL DEFAULT "pending",
                eligibility_state  VARCHAR(16)  NOT NULL DEFAULT "ok",
                eligibility_detail VARCHAR(500) NULL,
                decided_by         CHAR(36)     NULL,
                decided_at         DATETIME(6)  NULL,
                transfer_id        CHAR(36)     NULL,
                created_at         DATETIME(6)  NOT NULL,
                updated_at         DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY ptr_prospect_idx (organization_id, prospect_id, status),
                KEY ptr_approver_idx (organization_id, approver_id, status),
                KEY ptr_status_idx (organization_id, status, created_at),
                KEY ptr_to_group_idx (organization_id, to_group_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS prospect_transfer_reviews (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                request_id      CHAR(36)     NOT NULL,
                action          VARCHAR(16)  NOT NULL,
                actor_id        CHAR(36)     NULL,
                note            VARCHAR(500) NULL,
                created_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ptrev_request_idx (request_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Link an applied transfer back to the request that authorized it.
        if ($this->db->tableExists('prospect_group_transfers')
            && ! $this->db->fieldExists('request_id', 'prospect_group_transfers')) {
            $this->db->query('ALTER TABLE `prospect_group_transfers` ADD COLUMN `request_id` CHAR(36) NULL AFTER `sponsorship_id`');
            $this->db->query('ALTER TABLE `prospect_group_transfers` ADD KEY `pgt_request_idx` (`request_id`)');
        }

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('prospect_group_transfers')) {
            $existing = array_map(static fn ($i) => $i->name, $this->db->getIndexData('prospect_group_transfers'));
            if (in_array('pgt_request_idx', $existing, true)) {
                $this->db->query('ALTER TABLE `prospect_group_transfers` DROP INDEX `pgt_request_idx`');
            }
            if ($this->db->fieldExists('request_id', 'prospect_group_transfers')) {
                $this->db->query('ALTER TABLE `prospect_group_transfers` DROP COLUMN `request_id`');
            }
        }

        $this->db->query('DROP TABLE IF EXISTS prospect_transfer_reviews');
        $this->db->query('DROP TABLE IF EXISTS prospect_transfer_requests');
        $this->db->resetDataCache();
    }
}
