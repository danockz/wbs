<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cross-cutting groups (leadership-responsibility model).
 *
 * The group hierarchy (migration 000007) is a strict single-parent tree
 * (region -> branch -> cell). Some groups, however, CUT ACROSS that tree — a
 * worship team, a youth network, a choir — drawing members from many different
 * branches/cells. Such a group is still an ordinary `groups` row (typically
 * type='team'/'ministry'/'network'); what makes it "cross-cutting" is that it is
 * LINKED to the hierarchy node(s) it spans.
 *
 * `group_crosscut_links` records those many-to-many links:
 *   - crosscut_group_id -> the orthogonal group (the team/ministry/network);
 *   - hierarchy_group_id -> a node of the tree it spans.
 *
 * A single cross-cut group may span many hierarchy nodes, and a hierarchy node
 * may host many cross-cut groups. Coverage flows DOWN-ONLY: a leader whose scope
 * covers a hierarchy node ALSO covers the cross-cut groups attached to that node
 * (only when their grant opts in via `include_crosscut`). A cross-cut group's own
 * leadership does NOT thereby gain the hierarchy.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
final class CreateGroupCrosscutLinks extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_crosscut_links (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                crosscut_group_id   CHAR(36)     NOT NULL,   -- the orthogonal (team/ministry/network) group
                hierarchy_group_id  CHAR(36)     NOT NULL,   -- a hierarchy node it spans
                created_by          CHAR(36)     NULL,
                created_at          DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gcl_uq (crosscut_group_id, hierarchy_group_id),
                KEY gcl_hier_idx (organization_id, hierarchy_group_id),
                KEY gcl_cross_idx (organization_id, crosscut_group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS group_crosscut_links');
    }
}
