<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;
use Throwable;

/**
 * Full group membership record (SRS FR-GRP-003).
 *
 * The original `group_members` was minimal (group/user/role/joined_at). FR-GRP-003
 * requires a membership to record: person/group, membership TYPE, role
 * assignment, joining/leaving/EFFECTIVE dates, status, SOURCE, permission SCOPE,
 * and APPROVAL EVIDENCE. One person may belong to many groups/activity groups/
 * departments/teams, subject to conflict rules — so uniqueness is on ONE ACTIVE
 * membership per (person, group, membership_type), not per (group, user).
 *
 * Enforcement follows the established single-active pattern (member_sponsorships):
 * an app-maintained `active_key` (a hash of person+group+type while active, NULL
 * when historical) + a UNIQUE index. This lets a person hold many memberships
 * yet never two ACTIVE memberships of the same type in the same group, while
 * preserving leavers as historical rows.
 *
 * ALTER precedent exists across Geo/Referrals/Gamification/AccessControl/Identity
 * migrations. down() is best-effort.
 */
final class EnhanceGroupMemberships extends Migration
{
    public function up(): void
    {
        // Drop the old (group_id, user_id) uniqueness — a person may re-join a
        // group after leaving (new effective-dated row) and may hold different
        // membership types in the same group.
        try {
            $this->db->query('ALTER TABLE group_members DROP INDEX gm_uq');
        } catch (Throwable) {
            // index may already be gone
        }

        $this->db->query('ALTER TABLE group_members
            ADD COLUMN membership_type VARCHAR(24)  NOT NULL DEFAULT "member" AFTER role,
            ADD COLUMN status          VARCHAR(16)  NOT NULL DEFAULT "active"  AFTER membership_type,
            ADD COLUMN source          VARCHAR(24)  NOT NULL DEFAULT "manual"  AFTER status,
            ADD COLUMN permission_scope JSON        NULL AFTER source,
            ADD COLUMN effective_from  DATETIME     NULL AFTER permission_scope,
            ADD COLUMN effective_to    DATETIME     NULL AFTER effective_from,
            ADD COLUMN left_at         DATETIME     NULL AFTER effective_to,
            ADD COLUMN leave_reason    VARCHAR(255) NULL AFTER left_at,
            ADD COLUMN approval_state  VARCHAR(16)  NOT NULL DEFAULT "approved" AFTER leave_reason,
            ADD COLUMN approved_by     CHAR(36)     NULL AFTER approval_state,
            ADD COLUMN approved_at     DATETIME     NULL AFTER approved_by,
            ADD COLUMN approval_ref    CHAR(36)     NULL AFTER approved_at,
            ADD COLUMN approval_evidence JSON       NULL AFTER approval_ref,
            ADD COLUMN added_by        CHAR(36)     NULL AFTER approval_evidence,
            ADD COLUMN active_key      CHAR(64)     NULL AFTER added_by,
            ADD COLUMN updated_at      DATETIME     NULL AFTER joined_at
        ');

        // Existing rows: backfill effective_from from joined_at and set the
        // active_key so the one-active guard holds for legacy data.
        $this->db->query('UPDATE group_members
            SET effective_from = joined_at,
                active_key = SHA2(CONCAT(user_id, ":", group_id, ":", membership_type), 256)
            WHERE status = "active" AND active_key IS NULL');

        $this->db->query('CREATE UNIQUE INDEX gm_active_uq ON group_members (active_key)');
        $this->db->query('CREATE INDEX gm_group_status_idx ON group_members (group_id, membership_type, status)');
        $this->db->query('CREATE INDEX gm_user_status_idx ON group_members (user_id, status)');

        // Conflict rules: membership-type pairs that may not be held ACTIVE by the
        // same person at the same time (SRS FR-GRP-003 "subject to conflict rules").
        // Configurable per organization; empty table = no conflicts enforced.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_membership_conflicts (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                type_a           VARCHAR(24)  NOT NULL,
                type_b           VARCHAR(24)  NOT NULL,
                scope            VARCHAR(16)  NOT NULL DEFAULT "global", -- global|same_group|same_branch
                reason           VARCHAR(255) NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gmc_pair_uq (organization_id, type_a, type_b, scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Append-only membership event trail (join/leave/role/scope/approval),
        // so approval evidence and lifecycle are auditable at the membership level.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_membership_events (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                membership_id    CHAR(36)     NOT NULL,
                action           VARCHAR(24)  NOT NULL, -- requested|approved|rejected|joined|role_changed|scope_changed|left|reactivated
                actor_id         CHAR(36)     NULL,
                detail           JSON         NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY gme_membership_idx (membership_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['gm_active_uq', 'gm_group_status_idx', 'gm_user_status_idx'] as $idx) {
            try {
                $this->db->query("DROP INDEX {$idx} ON group_members");
            } catch (Throwable) {
                // index may not exist
            }
        }
        try {
            $this->db->query('ALTER TABLE group_members
                DROP COLUMN membership_type,
                DROP COLUMN status,
                DROP COLUMN source,
                DROP COLUMN permission_scope,
                DROP COLUMN effective_from,
                DROP COLUMN effective_to,
                DROP COLUMN left_at,
                DROP COLUMN leave_reason,
                DROP COLUMN approval_state,
                DROP COLUMN approved_by,
                DROP COLUMN approved_at,
                DROP COLUMN approval_ref,
                DROP COLUMN approval_evidence,
                DROP COLUMN added_by,
                DROP COLUMN active_key,
                DROP COLUMN updated_at
            ');
            // Restore the original uniqueness (best-effort).
            $this->db->query('CREATE UNIQUE INDEX gm_uq ON group_members (group_id, user_id)');
        } catch (Throwable) {
            // columns may not exist
        }

        foreach (['group_membership_events', 'group_membership_conflicts'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
