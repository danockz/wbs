<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rule-Based Access Control + richer scope model (leadership-responsibility
 * model; extends SRS FR-ACL-002/003 MAC+RBAC+ABAC PDP).
 *
 * Two additions:
 *
 * 1. SCOPE MODE on every grant table. The historical `include_descendants`
 *    boolean only expressed two of the three scopes leaders actually use, so we
 *    add `scope_mode` (self | self_and_descendants | descendants_only | groups)
 *    to role_assignments, access_requests, delegations and break_glass_sessions.
 *    `include_descendants` is kept and back-filled so old rows keep working; new
 *    code reads `scope_mode`. A companion `grant_scope_groups` table carries the
 *    hand-picked group set for `groups` mode (polymorphic by grant_type+grant_id).
 *
 * 2. RULES — a general, reusable rule engine. A `rules` row is a declarative
 *    (condition -> effect) statement bound to a FACET (access, membership,
 *    gamification, ...) and an action pattern, scoped to a branch of the group
 *    tree so a LEADER can author guardrails within their own scope. The access
 *    facet is consumed by the PDP (deny-overrides, before RBAC); other facets
 *    call the same RuleEngine. Conditions reuse the ABAC predicate grammar, so
 *    they remain data, never code. Append-only authorship trail in
 *    `rule_revisions`.
 *
 * Idempotent: guarded ADD COLUMN + CREATE TABLE IF NOT EXISTS.
 */
final class CreateRuleBasedAccess extends Migration
{
    /** Grant tables that gain a scope_mode column. */
    private const SCOPED_TABLES = [
        'role_assignments',
        'access_requests',
        'delegations',
        'break_glass_sessions',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        // 1) scope_mode on each grant table, back-filled from include_descendants.
        foreach (self::SCOPED_TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('scope_mode', $table)) {
                $this->db->query(
                    "ALTER TABLE {$table} ADD COLUMN scope_mode VARCHAR(24) NOT NULL DEFAULT 'self' "
                    . 'AFTER scope_group_id',
                );
            }
            // Back-fill: rows that opted into descendants become self_and_descendants.
            if ($this->db->fieldExists('include_descendants', $table)) {
                $this->db->query(
                    "UPDATE {$table} SET scope_mode = 'self_and_descendants' "
                    . "WHERE include_descendants = 1 AND scope_mode = 'self'",
                );
            }
        }

        // 2) Hand-picked multi-group sets for scope_mode = 'groups'.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS grant_scope_groups (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                grant_type       VARCHAR(32)  NOT NULL,   -- role_assignment|access_request|delegation|break_glass|rule
                grant_id         CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gsg_uq (grant_type, grant_id, group_id),
                KEY gsg_grant_idx (grant_type, grant_id),
                KEY gsg_group_idx (organization_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // 3) General rule engine.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS rules (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                facet            VARCHAR(32)  NOT NULL,   -- access|membership|gamification|notification|...
                code             VARCHAR(120) NOT NULL,   -- unique per (org, facet)
                name             VARCHAR(150) NOT NULL,
                description      VARCHAR(500) NULL,
                effect           VARCHAR(16)  NOT NULL DEFAULT "deny", -- allow|deny (access); allow|deny|flag|adjust (other facets)
                action_pattern   VARCHAR(160) NOT NULL DEFAULT "*",    -- exact | prefix.* | *
                `condition`      JSON         NOT NULL,   -- ABAC predicate tree (data, not code)
                effect_params    JSON         NULL,       -- optional payload for non-access effects (e.g. points delta)
                scope_group_id   CHAR(36)     NULL,       -- null = org-wide
                scope_mode       VARCHAR(24)  NOT NULL DEFAULT "self",
                priority         INT          NOT NULL DEFAULT 100, -- lower runs first
                enabled          TINYINT(1)   NOT NULL DEFAULT 1,
                created_by       CHAR(36)     NULL,
                updated_by       CHAR(36)     NULL,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY rules_code_uq (organization_id, facet, code),
                KEY rules_facet_idx (organization_id, facet, enabled, priority),
                KEY rules_scope_idx (organization_id, scope_group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Append-only authorship / change trail for rules.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS rule_revisions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                rule_id          CHAR(36)     NOT NULL,
                action           VARCHAR(24)  NOT NULL,   -- create|update|enable|disable|delete
                snapshot         JSON         NOT NULL,   -- full rule state after the action
                actor_id         CHAR(36)     NULL,
                note             VARCHAR(500) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY rr_rule_idx (rule_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        $this->db->query('DROP TABLE IF EXISTS rule_revisions');
        $this->db->query('DROP TABLE IF EXISTS rules');
        $this->db->query('DROP TABLE IF EXISTS grant_scope_groups');
        foreach (self::SCOPED_TABLES as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('scope_mode', $table)) {
                $this->db->query("ALTER TABLE {$table} DROP COLUMN scope_mode");
            }
        }
    }
}
