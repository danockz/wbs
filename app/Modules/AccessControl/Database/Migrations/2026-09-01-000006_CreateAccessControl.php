<?php

declare(strict_types=1);

namespace WBS\AccessControl\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Authorization storage for the MAC + RBAC + ABAC Policy Decision Point
 * (SRS: default-deny PDP).
 *
 *  - permissions: the global catalogue of fine-grained actions. `code` is
 *    GLOBALLY unique (tests must reuse existing rows).
 *  - roles + role_permissions: RBAC. Roles are org-scoped; a role grants a set
 *    of permissions.
 *  - role_assignments: which subject (user) holds which role, optionally scoped
 *    to a group subtree for hierarchical delegation.
 *  - security_labels + object_labels: MAC. Subjects and objects carry a
 *    classification level; a subject may only reach objects at or below its
 *    clearance.
 *  - abac_policies: attribute rules (JSON conditions) that can further allow or
 *    deny based on request/context attributes.
 */
final class CreateAccessControl extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS permissions (
                id           CHAR(36)     NOT NULL,
                code         VARCHAR(120) NOT NULL,   -- e.g. contribution.refund.approve
                description  VARCHAR(255) NULL,
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY perm_code_uq (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS roles (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,
                name             VARCHAR(120) NOT NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY role_org_code_uq (organization_id, code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id        CHAR(36)     NOT NULL,
                permission_id  CHAR(36)     NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS role_assignments (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,   -- user id
                role_id          CHAR(36)     NOT NULL,
                scope_group_id   CHAR(36)     NULL,        -- null = org-wide
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ra_uq (subject_id, role_id, scope_group_id),
                KEY ra_subject_idx (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS security_labels (
                id           CHAR(36)     NOT NULL,
                code         VARCHAR(40)  NOT NULL,   -- public|internal|confidential|restricted
                level        INT UNSIGNED NOT NULL,   -- higher = more sensitive
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY seclabel_code_uq (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS subject_clearances (
                subject_id   CHAR(36)     NOT NULL,
                level        INT UNSIGNED NOT NULL,
                PRIMARY KEY (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS object_labels (
                object_type  VARCHAR(80)  NOT NULL,
                object_id    CHAR(36)     NOT NULL,
                level        INT UNSIGNED NOT NULL,
                PRIMARY KEY (object_type, object_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS abac_policies (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                code             VARCHAR(80)  NOT NULL,
                effect           VARCHAR(10)  NOT NULL DEFAULT "allow", -- allow|deny
                action_pattern   VARCHAR(120) NOT NULL,   -- e.g. contribution.* or event.rsvp
                `condition`      JSON         NOT NULL,    -- attribute predicate tree (reserved word -> backticked)
                priority         INT          NOT NULL DEFAULT 100, -- lower runs first
                enabled          TINYINT(1)   NOT NULL DEFAULT 1,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY abac_org_code_uq (organization_id, code),
                KEY abac_action_idx (organization_id, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'abac_policies', 'object_labels', 'subject_clearances', 'security_labels',
            'role_assignments', 'role_permissions', 'roles', 'permissions',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
