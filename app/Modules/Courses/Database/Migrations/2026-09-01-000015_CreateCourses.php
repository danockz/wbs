<?php

declare(strict_types=1);

namespace WBS\Courses\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Courses and learning (SRS FR-CRS-001..005).
 *
 *  - courses/course_modules/lessons: content structure with drip-feed unlock.
 *  - enrollments/learning_progress: per-learner state; progress unique per
 *    (enrollment, lesson).
 *  - course_completions: rule-based, IDEMPOTENT completion (UNIQUE per
 *    enrollment) that awards progress/points/badges only after verification.
 *  - learning_content_connections / learning_experience_statements: optional,
 *    policy-gated SCORM/xAPI/LTI interoperability behind a custom adapter
 *    boundary (never executes untrusted packages with platform credentials).
 */
final class CreateCourses extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS courses (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,       -- owning group
                title            VARCHAR(200) NOT NULL,
                slug             VARCHAR(200) NULL,
                category         VARCHAR(80)  NULL,
                description      MEDIUMTEXT   NULL,
                delivery_mode    VARCHAR(20)  NOT NULL DEFAULT "self_paced", -- self_paced|instructor_led|physical|virtual|blended
                prerequisites    JSON         NULL,        -- list of course ids
                enrollment_policy VARCHAR(20) NOT NULL DEFAULT "open", -- open|invite|approval
                completion_rule  JSON         NULL,        -- required_lessons, min_score, attendance, review
                points_rule_code VARCHAR(80)  NULL,        -- gamification rule to award on completion
                badge_code       VARCHAR(80)  NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|published|archived
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY courses_org_idx (organization_id, status),
                KEY courses_group_idx (group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS course_modules (
                id               CHAR(36)     NOT NULL,
                course_id        CHAR(36)     NOT NULL,
                title            VARCHAR(200) NOT NULL,
                position         INT UNSIGNED NOT NULL DEFAULT 0,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY cm_course_idx (course_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS lessons (
                id               CHAR(36)     NOT NULL,
                course_id        CHAR(36)     NOT NULL,
                module_id        CHAR(36)     NULL,
                title            VARCHAR(200) NOT NULL,
                position         INT UNSIGNED NOT NULL DEFAULT 0,
                required         TINYINT(1)   NOT NULL DEFAULT 1,
                drip_unlock_at   DATETIME     NULL,        -- absolute unlock time
                drip_offset_days INT          NULL,        -- or relative to enrollment
                content_ref      VARCHAR(191) NULL,        -- private object id
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY les_course_idx (course_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS enrollments (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                course_id        CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                cohort_id        CHAR(36)     NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- pending|active|completed|withdrawn
                enrolled_at      DATETIME     NOT NULL,
                completed_at     DATETIME     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY enr_uq (course_id, user_id),
                KEY enr_user_idx (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS learning_progress (
                id               CHAR(36)     NOT NULL,
                enrollment_id    CHAR(36)     NOT NULL,
                lesson_id        CHAR(36)     NOT NULL,
                state            VARCHAR(16)  NOT NULL DEFAULT "started", -- started|completed
                score            INT          NULL,
                completed_at     DATETIME     NULL,
                updated_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY lp_uq (enrollment_id, lesson_id),
                KEY lp_enrollment_idx (enrollment_id, state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS course_completions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                course_id        CHAR(36)     NOT NULL,
                enrollment_id    CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                final_score      INT          NULL,
                verified         TINYINT(1)   NOT NULL DEFAULT 1,
                override_reason  VARCHAR(255) NULL,
                override_by      CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cc_enrollment_uq (enrollment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS learning_content_connections (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,
                standard         VARCHAR(10)  NOT NULL,   -- scorm|xapi|lti
                registration_ref VARCHAR(191) NULL,        -- validated package/tool id
                enabled          TINYINT(1)   NOT NULL DEFAULT 0, -- policy-gated
                settings         JSON         NULL,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY lcc_org_idx (organization_id, standard)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS learning_experience_statements (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                course_id        CHAR(36)     NULL,
                verb             VARCHAR(60)  NOT NULL,   -- completed|attempted|passed
                object_ref       VARCHAR(191) NOT NULL,
                result           JSON         NULL,
                source_ref       VARCHAR(191) NULL,        -- idempotency for inbound statements
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY les_source_uq (organization_id, source_ref),
                KEY les_user_idx (user_id, verb)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'learning_experience_statements', 'learning_content_connections', 'course_completions',
            'learning_progress', 'enrollments', 'lessons', 'course_modules', 'courses',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
