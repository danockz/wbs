<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Event committees — an optional, pre-event project-management body per event.
 *
 * A body may run an event through a committee: a chairperson plus members, each
 * with a specific responsibility, working a plan (workstreams → tasks with
 * owners, due dates, dependencies and milestones) under the oversight of the
 * organizing group's leader. It is OPTIONAL and additive: an event with no
 * committee behaves exactly as before, and nothing here changes the day-of
 * `event_staff_roster` (who stands where on the day) or the existing budget,
 * logistics, readiness or close-out surfaces — the committee works THROUGH them.
 *
 * SCOPE: a committee lives inside the event's scope, never beside it. Its anchor
 * is `events.group_id`, snapshotted onto the committee as `scope_group_id` (NULL
 * = org-wide event), so every query and every delegation derived from it resolves
 * through the one hierarchy tree (`group_closure` + ScopeMode). `oversight_group_id`
 * is the group whose leader provides oversight — normally the same group, but a
 * regional event organized by a local assembly may be overseen one level up.
 *
 * AUTHORITY: no new permission bits (the budget stays frozen at 41). A member's
 * authority is a TIME-BOUNDED DELEGATION of an existing `event.*` capability from
 * the group leader to the chair and from the chair to members, created through
 * `DelegationService` so containment (`GroupScopeResolver::scopeContains`), depth
 * and the delegator's own authority are enforced by the ACL layer, not re-derived
 * here. `event_committee_members.delegation_id` is the handle: removing a member
 * revokes that delegation, which cascades to anything sub-delegated from it, and
 * `EventCloser` dissolves the committee at close so nothing outlives the event.
 *
 * OVERSIGHT is HIERARCHICAL GROUP CONFIG (capability `event_committee` on
 * `group_configurations`, resolved by `EffectiveConfigResolver`), never env or a
 * global, and DEFAULT OFF: with no config row the capability resolves to null and
 * committees cannot be formed, so existing bodies are unaffected.
 *
 *  - `event_committees`            one row per event (UNIQUE), chair + mandate + status
 *  - `event_committee_members`     chair and members, each with a responsibility and
 *                                  the delegation that carries their authority
 *  - `event_workstreams`           the plan's top level (a responsibility's lane)
 *  - `event_tasks`                 work items: owner, due date, status, % complete
 *  - `event_task_dependencies`     predecessor links (finish-to-start & friends);
 *                                  cycles are refused by the service
 *  - `event_milestones`            dated checkpoints that prove progress
 *  - `event_committee_decisions`   the oversight queue: what the committee wants to
 *                                  do that needs the leader's approval. Like the
 *                                  transfer queues it has NO expiry column — a
 *                                  request stays pending until a human decides it.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, safe to re-run.
 */
final class CreateEventCommittees extends Migration
{
    public function up(): void
    {
        // Raw DDL doesn't invalidate CI4's connection-lifetime schema cache.
        $this->db->resetDataCache();

        // 1. event_committees --------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_committees (
                id                  CHAR(36)      NOT NULL,
                organization_id     CHAR(36)      NOT NULL,
                event_id            CHAR(36)      NOT NULL,
                scope_group_id      CHAR(36)      NULL,
                oversight_group_id  CHAR(36)      NULL,
                chair_user_id       CHAR(36)      NULL,
                formed_by           CHAR(36)      NULL,
                mandate             VARCHAR(500)  NOT NULL,
                oversight_mode      VARCHAR(24)   NOT NULL DEFAULT "formation_and_major",
                max_members         SMALLINT      UNSIGNED NOT NULL DEFAULT 12,
                allow_subdelegation TINYINT(1)    NOT NULL DEFAULT 1,
                grace_days          SMALLINT      UNSIGNED NOT NULL DEFAULT 7,
                status              VARCHAR(16)   NOT NULL DEFAULT "active",
                dissolved_at        DATETIME(6)   NULL,
                dissolved_by        CHAR(36)      NULL,
                dissolution_reason  VARCHAR(500)  NULL,
                created_at          DATETIME(6)   NOT NULL,
                updated_at          DATETIME(6)   NULL,
                created_by          CHAR(36)      NULL,
                updated_by          CHAR(36)      NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ec_event_uq (event_id),
                KEY ec_org_scope_idx (organization_id, scope_group_id, status),
                KEY ec_chair_idx (organization_id, chair_user_id),
                KEY ec_oversight_idx (organization_id, oversight_group_id, status),
                -- `groups`/`events` are reserved in MySQL 8.0.2+/9; unquoted REFERENCES groups(id) is a syntax error.
                CONSTRAINT ec_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ec_scope_group_fk FOREIGN KEY (scope_group_id) REFERENCES `groups`(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ec_oversight_group_fk FOREIGN KEY (oversight_group_id) REFERENCES `groups`(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ec_chair_fk FOREIGN KEY (chair_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ec_formed_by_fk FOREIGN KEY (formed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 2. event_committee_members -------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_committee_members (
                id                    CHAR(36)      NOT NULL,
                organization_id       CHAR(36)      NOT NULL,
                committee_id          CHAR(36)      NOT NULL,
                event_id              CHAR(36)      NOT NULL,
                user_id               CHAR(36)      NOT NULL,
                responsibility        VARCHAR(32)   NOT NULL,
                responsibility_label  VARCHAR(120)  NULL,
                is_chair              TINYINT(1)    NOT NULL DEFAULT 0,
                delegation_id         CHAR(36)      NULL,
                delegated_permission  VARCHAR(64)   NULL,
                scope_mode            VARCHAR(24)   NOT NULL DEFAULT "self",
                scope_group_id        CHAR(36)      NULL,
                effective_from        DATETIME(6)   NOT NULL,
                effective_to          DATETIME(6)   NOT NULL,
                status                VARCHAR(16)   NOT NULL DEFAULT "active",
                added_by              CHAR(36)      NULL,
                removed_by            CHAR(36)      NULL,
                removed_at            DATETIME(6)   NULL,
                removal_reason        VARCHAR(500)  NULL,
                created_at            DATETIME(6)   NOT NULL,
                updated_at            DATETIME(6)   NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ecm_member_uq (committee_id, user_id),
                KEY ecm_event_idx (organization_id, event_id, status),
                KEY ecm_user_idx (organization_id, user_id, status),
                KEY ecm_responsibility_idx (committee_id, responsibility),
                KEY ecm_delegation_idx (delegation_id),
                KEY ecm_expiry_idx (status, effective_to),
                CONSTRAINT ecm_committee_fk FOREIGN KEY (committee_id) REFERENCES event_committees(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecm_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecm_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecm_added_by_fk FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ecm_delegation_fk FOREIGN KEY (delegation_id) REFERENCES delegations(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 3. event_workstreams --------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_workstreams (
                id              CHAR(36)      NOT NULL,
                organization_id CHAR(36)      NOT NULL,
                event_id        CHAR(36)      NOT NULL,
                committee_id    CHAR(36)      NULL,
                name            VARCHAR(160)  NOT NULL,
                description     TEXT          NULL,
                owner_user_id   CHAR(36)      NULL,
                responsibility  VARCHAR(32)   NULL,
                status          VARCHAR(16)   NOT NULL DEFAULT "open",
                progress_pct    SMALLINT      NOT NULL DEFAULT 0,
                task_count      INT           UNSIGNED NOT NULL DEFAULT 0,
                done_count      INT           UNSIGNED NOT NULL DEFAULT 0,
                weight          SMALLINT      UNSIGNED NOT NULL DEFAULT 1,
                start_date      DATE          NULL,
                due_date        DATE          NULL,
                sort_order      INT           NOT NULL DEFAULT 0,
                created_at      DATETIME(6)   NOT NULL,
                updated_at      DATETIME(6)   NULL,
                created_by      CHAR(36)      NULL,
                updated_by      CHAR(36)      NULL,
                PRIMARY KEY (id),
                KEY ews_event_idx (organization_id, event_id, sort_order),
                KEY ews_committee_idx (committee_id),
                KEY ews_owner_idx (organization_id, owner_user_id),
                CONSTRAINT ews_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ews_committee_fk FOREIGN KEY (committee_id) REFERENCES event_committees(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ews_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 4. event_milestones ---------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_milestones (
                id              CHAR(36)      NOT NULL,
                organization_id CHAR(36)      NOT NULL,
                event_id        CHAR(36)      NOT NULL,
                committee_id    CHAR(36)      NULL,
                workstream_id   CHAR(36)      NULL,
                title           VARCHAR(200)  NOT NULL,
                description     TEXT          NULL,
                due_at          DATE          NOT NULL,
                status          VARCHAR(16)   NOT NULL DEFAULT "pending",
                weight          SMALLINT      UNSIGNED NOT NULL DEFAULT 1,
                evidence        VARCHAR(500)  NULL,
                met_at          DATETIME(6)   NULL,
                met_by          CHAR(36)      NULL,
                sort_order      INT           NOT NULL DEFAULT 0,
                created_at      DATETIME(6)   NOT NULL,
                updated_at      DATETIME(6)   NULL,
                created_by      CHAR(36)      NULL,
                updated_by      CHAR(36)      NULL,
                PRIMARY KEY (id),
                KEY em_event_idx (organization_id, event_id, due_at),
                KEY em_status_idx (organization_id, event_id, status),
                KEY em_committee_idx (committee_id),
                KEY em_workstream_idx (workstream_id),
                CONSTRAINT em_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT em_committee_fk FOREIGN KEY (committee_id) REFERENCES event_committees(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT em_workstream_fk FOREIGN KEY (workstream_id) REFERENCES event_workstreams(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT em_met_by_fk FOREIGN KEY (met_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 5. event_tasks --------------------------------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_tasks (
                id                 CHAR(36)      NOT NULL,
                organization_id    CHAR(36)      NOT NULL,
                event_id           CHAR(36)      NOT NULL,
                committee_id       CHAR(36)      NULL,
                workstream_id      CHAR(36)      NULL,
                milestone_id       CHAR(36)      NULL,
                title              VARCHAR(200)  NOT NULL,
                description        TEXT          NULL,
                assignee_user_id   CHAR(36)      NULL,
                responsibility     VARCHAR(32)   NULL,
                status             VARCHAR(16)   NOT NULL DEFAULT "todo",
                priority           VARCHAR(8)    NOT NULL DEFAULT "normal",
                progress_pct       TINYINT       UNSIGNED NOT NULL DEFAULT 0,
                due_at             DATE          NULL,
                estimated_hours    DECIMAL(6,2)  NULL,
                actual_hours       DECIMAL(6,2)  NULL,
                blocked_reason     VARCHAR(500)  NULL,
                blocked_at         DATETIME(6)   NULL,
                started_at         DATETIME(6)   NULL,
                completed_at       DATETIME(6)   NULL,
                completed_by       CHAR(36)      NULL,
                sort_order         INT           NOT NULL DEFAULT 0,
                created_at         DATETIME(6)   NOT NULL,
                updated_at         DATETIME(6)   NULL,
                created_by         CHAR(36)      NULL,
                updated_by         CHAR(36)      NULL,
                PRIMARY KEY (id),
                KEY et_event_idx (organization_id, event_id, status),
                KEY et_workstream_idx (workstream_id, sort_order),
                KEY et_committee_idx (committee_id),
                KEY et_assignee_idx (organization_id, assignee_user_id, status),
                KEY et_due_idx (organization_id, event_id, due_at),
                KEY et_milestone_idx (milestone_id),
                CONSTRAINT et_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT et_committee_fk FOREIGN KEY (committee_id) REFERENCES event_committees(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT et_workstream_fk FOREIGN KEY (workstream_id) REFERENCES event_workstreams(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT et_milestone_fk FOREIGN KEY (milestone_id) REFERENCES event_milestones(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT et_assignee_fk FOREIGN KEY (assignee_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT et_completed_by_fk FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 6. event_task_dependencies --------------------------------------------
        // `task_id` is the successor: it may not start until `depends_on_task_id`
        // (the predecessor) reaches the required state. Cycles are refused by
        // EventWorkService before insert, so the graph stays a DAG.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_task_dependencies (
                id                 CHAR(36)     NOT NULL,
                organization_id    CHAR(36)     NOT NULL,
                event_id           CHAR(36)     NOT NULL,
                task_id            CHAR(36)     NOT NULL,
                depends_on_task_id CHAR(36)     NOT NULL,
                dependency_type    VARCHAR(20)  NOT NULL DEFAULT "finish_to_start",
                lag_days           SMALLINT     NOT NULL DEFAULT 0,
                created_at         DATETIME(6)  NOT NULL,
                created_by         CHAR(36)     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY etd_pair_uq (task_id, depends_on_task_id),
                KEY etd_predecessor_idx (depends_on_task_id),
                KEY etd_event_idx (organization_id, event_id),
                CONSTRAINT etd_task_fk FOREIGN KEY (task_id) REFERENCES event_tasks(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT etd_depends_on_fk FOREIGN KEY (depends_on_task_id) REFERENCES event_tasks(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT etd_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 7. event_committee_decisions ------------------------------------------
        // The oversight queue: a decision the committee wants to take that the
        // configured oversight mode says the leader must approve. `required_permission`
        // names the EXISTING capability the approver must hold over the oversight
        // group (e.g. event.expense.approve for a budget decision), so approving a
        // committee request demands the same authority as doing the thing directly.
        // No expiry column: it stays pending until a human decides it.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_committee_decisions (
                id                  CHAR(36)      NOT NULL,
                organization_id     CHAR(36)      NOT NULL,
                event_id            CHAR(36)      NOT NULL,
                committee_id        CHAR(36)      NOT NULL,
                kind                VARCHAR(32)   NOT NULL,
                title               VARCHAR(200)  NOT NULL,
                detail              TEXT          NULL,
                amount              DECIMAL(14,2) NULL,
                required_permission VARCHAR(64)   NULL,
                oversight_group_id  CHAR(36)      NULL,
                requested_by        CHAR(36)      NOT NULL,
                status              VARCHAR(16)   NOT NULL DEFAULT "pending",
                decided_by          CHAR(36)      NULL,
                decided_at          DATETIME(6)   NULL,
                decision_note       VARCHAR(500)  NULL,
                effect_json         JSON          NULL,
                effect_applied      TINYINT(1)    NOT NULL DEFAULT 0,
                created_at          DATETIME(6)   NOT NULL,
                updated_at          DATETIME(6)   NULL,
                PRIMARY KEY (id),
                KEY ecd_committee_idx (committee_id, status),
                KEY ecd_event_idx (organization_id, event_id, status),
                KEY ecd_decider_idx (organization_id, decided_by),
                KEY ecd_pending_idx (organization_id, oversight_group_id, status),
                CONSTRAINT ecd_committee_fk FOREIGN KEY (committee_id) REFERENCES event_committees(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecd_event_fk FOREIGN KEY (event_id) REFERENCES `events`(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecd_requested_by_fk FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                CONSTRAINT ecd_decided_by_fk FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT,
                CONSTRAINT ecd_oversight_group_fk FOREIGN KEY (oversight_group_id) REFERENCES `groups`(id) ON DELETE SET NULL ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->resetDataCache();
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        // Reverse order: children first so the FKs drop cleanly.
        $this->db->query('DROP TABLE IF EXISTS event_committee_decisions');
        $this->db->query('DROP TABLE IF EXISTS event_task_dependencies');
        $this->db->query('DROP TABLE IF EXISTS event_tasks');
        $this->db->query('DROP TABLE IF EXISTS event_milestones');
        $this->db->query('DROP TABLE IF EXISTS event_workstreams');
        $this->db->query('DROP TABLE IF EXISTS event_committee_members');
        $this->db->query('DROP TABLE IF EXISTS event_committees');

        $this->db->resetDataCache();
    }
}
