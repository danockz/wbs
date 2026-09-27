<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Group hierarchy (SRS FR-GRP-*): configurable levels 1–9, acyclic, with a
 * closure-table projection for O(1) ancestor/descendant queries.
 *
 *  - groups: the node. `depth` is 1..max_group_depth (org-configured). `path`
 *    is a materialized "/a/b/c/" string for prefix scans and cycle detection.
 *  - group_closure: (ancestor, descendant, distance) rows for every
 *    ancestor→descendant pair including self (distance 0). Enables subtree and
 *    ancestor lookups without recursion.
 *  - group_members: membership with a role tag; a user may belong to many
 *    groups.
 */
final class CreateGroups extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `groups` (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                parent_id        CHAR(36)     NULL,
                name             VARCHAR(150) NOT NULL,
                slug             VARCHAR(160) NOT NULL,
                type             VARCHAR(40)  NULL,
                depth            TINYINT UNSIGNED NOT NULL DEFAULT 1,
                path             VARCHAR(512) NOT NULL DEFAULT "/",
                leader_user_id   CHAR(36)     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY groups_org_slug_uq (organization_id, slug),
                KEY groups_parent_idx (parent_id),
                KEY groups_path_idx (organization_id, path(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_closure (
                ancestor_id    CHAR(36)     NOT NULL,
                descendant_id  CHAR(36)     NOT NULL,
                distance       INT UNSIGNED NOT NULL,
                PRIMARY KEY (ancestor_id, descendant_id),
                KEY gc_desc_idx (descendant_id, distance)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_members (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                role             VARCHAR(40)  NOT NULL DEFAULT "member",
                joined_at        DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gm_uq (group_id, user_id),
                KEY gm_user_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS group_members');
        $this->db->query('DROP TABLE IF EXISTS group_closure');
        $this->db->query('DROP TABLE IF EXISTS `groups`');
    }
}
