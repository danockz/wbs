<?php

declare(strict_types=1);

namespace WBS\Community\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Community feeds, posts, moderation (SRS FR-COM-001..004).
 *
 *  - community_posts: group-scoped authored content with a visibility state that
 *    the PDP evaluates BEFORE query/render (FR-COM-002). Body is sanitized at
 *    write time; body_html holds the allowlisted-sanitized render.
 *  - community_comments: threaded (parent_id) replies.
 *  - community_reactions: one reaction per (post, user, type) — UNIQUE.
 *  - community_topics + community_post_topics: hashtags/topics.
 *  - content_reports: member reports of content/users, feeding moderation.
 *  - moderation_actions: append-only evidence of every moderator action, with a
 *    mandatory reason and policy basis (FR-COM-003); nothing is silently removed.
 *
 * Visibility vocabulary (FR-COM-001): private | group | hierarchy | public | archived.
 */
final class CreateCommunity extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS community_posts (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,        -- owning group / feed scope
                author_id        CHAR(36)     NOT NULL,
                kind             VARCHAR(16)  NOT NULL DEFAULT "post",  -- post|announcement
                title            VARCHAR(200) NULL,
                body             MEDIUMTEXT   NULL,          -- raw author input (stored for edit)
                body_html        MEDIUMTEXT   NULL,          -- allowlist-sanitized render
                visibility       VARCHAR(20)  NOT NULL DEFAULT "group", -- private|group|hierarchy|public|archived
                pinned           TINYINT(1)   NOT NULL DEFAULT 0,
                locked           TINYINT(1)   NOT NULL DEFAULT 0,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|hidden|deleted
                reaction_count   INT UNSIGNED NOT NULL DEFAULT 0,
                comment_count    INT UNSIGNED NOT NULL DEFAULT 0,
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY cp_scope_idx (organization_id, group_id, status, visibility),
                KEY cp_author_idx (author_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS community_comments (
                id               CHAR(36)     NOT NULL,
                post_id          CHAR(36)     NOT NULL,
                parent_id        CHAR(36)     NULL,          -- threaded reply
                author_id        CHAR(36)     NOT NULL,
                body             MEDIUMTEXT   NULL,
                body_html        MEDIUMTEXT   NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active",
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY cc_post_idx (post_id, status),
                KEY cc_parent_idx (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS community_reactions (
                id               CHAR(36)     NOT NULL,
                post_id          CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                reaction         VARCHAR(24)  NOT NULL DEFAULT "like",
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cr_uq (post_id, user_id, reaction),
                KEY cr_post_idx (post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS community_topics (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                slug             VARCHAR(80)  NOT NULL,       -- normalized hashtag
                label            VARCHAR(120) NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ct_slug_uq (organization_id, slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS community_post_topics (
                post_id          CHAR(36)     NOT NULL,
                topic_id         CHAR(36)     NOT NULL,
                PRIMARY KEY (post_id, topic_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS content_reports (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL,       -- post|comment|user
                subject_id       CHAR(36)     NOT NULL,
                reporter_id      CHAR(36)     NOT NULL,
                reason_code      VARCHAR(40)  NOT NULL,
                detail           VARCHAR(1000) NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "open", -- open|actioned|dismissed|escalated
                created_at       DATETIME(6)  NOT NULL,
                resolved_at      DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY crp_dedupe_uq (subject_type, subject_id, reporter_id),
                KEY crp_status_idx (organization_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS moderation_actions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                moderator_id     CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL,       -- post|comment|user
                subject_id       CHAR(36)     NOT NULL,
                action           VARCHAR(24)  NOT NULL,       -- hide|delete|restore|lock|unlock|mute|ban|escalate
                reason           VARCHAR(1000) NOT NULL,      -- mandatory (FR-COM-003)
                policy_basis     VARCHAR(120) NULL,
                report_id        CHAR(36)     NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ma_subject_idx (subject_type, subject_id),
                KEY ma_org_idx (organization_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'moderation_actions', 'content_reports', 'community_post_topics',
            'community_topics', 'community_reactions', 'community_comments', 'community_posts',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
