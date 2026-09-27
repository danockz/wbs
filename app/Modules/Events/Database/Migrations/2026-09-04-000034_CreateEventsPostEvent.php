<?php

declare(strict_types=1);

namespace WBS\Events\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Events post-event cluster: feedback & quizzes, certificates, mobilization
 * report snapshots, and event media (SRS FR-EVT-012/013/014/015).
 *
 * Invariants:
 *  - Feedback/quiz responses carry respondent type, visibility label, rubric/
 *    score, version; access follows labels and aggregation thresholds (FR-EVT-012).
 *  - Certificates are versioned, verification-ID/QR bearing, issue/revoke state
 *    machine; NEVER generated for a zero-attendance event (FR-EVT-013 / -011).
 *  - Media rows track review state, EXIF/GPS-strip flag, consent/rights, an
 *    immutable source trace (sha-256), and visibility (FR-EVT-015). The binary
 *    itself lives in an access-controlled object store; only an opaque ref is here.
 *  - The mobilization report (FR-EVT-014) is computed on demand from live data,
 *    but an immutable point-in-time SNAPSHOT can be persisted for roll-up.
 */
final class CreateEventsPostEvent extends Migration
{
    public function up(): void
    {
        // ---- FR-EVT-012: feedback & quizzes -------------------------------

        // A versioned quiz/feedback form definition attached to an event.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_feedback_forms (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                kind             VARCHAR(16)  NOT NULL DEFAULT "feedback", -- feedback|quiz
                title            VARCHAR(200) NOT NULL,
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                visibility       VARCHAR(16)  NOT NULL DEFAULT "group", -- public|group|private
                scoring          VARCHAR(16)  NOT NULL DEFAULT "none",  -- none|automatic|reviewed
                pass_score       INT UNSIGNED NULL,       -- for quizzes
                min_aggregate_n  INT UNSIGNED NOT NULL DEFAULT 5, -- aggregation threshold
                status           VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|open|closed
                created_by       CHAR(36)     NULL,
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NULL,
                PRIMARY KEY (id),
                KEY eff_event_idx (event_id, kind, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Questions belonging to a form version. `answer_key`/`points` only used
        // by quizzes; rubric holds review guidance for reviewed scoring.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_feedback_questions (
                id               CHAR(36)     NOT NULL,
                form_id          CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                ordinal          INT UNSIGNED NOT NULL DEFAULT 0,
                prompt           VARCHAR(500) NOT NULL,
                qtype            VARCHAR(16)  NOT NULL DEFAULT "rating", -- rating|text|choice|boolean
                choices          JSON         NULL,
                answer_key       VARCHAR(255) NULL,       -- quiz correct answer (never returned to respondent)
                points           INT UNSIGNED NOT NULL DEFAULT 0,
                rubric           TEXT         NULL,
                required         TINYINT(1)   NOT NULL DEFAULT 0,
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                KEY efq_form_idx (form_id, ordinal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // One submission per respondent per form (UNIQUE), with respondent type
        // and a visibility label. `score`/`review_state` support auto/reviewed.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_feedback_responses (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                form_id          CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                respondent_id    CHAR(36)     NULL,       -- null = anonymous
                respondent_type  VARCHAR(20)  NOT NULL DEFAULT "attendee", -- attendee|staff|guest
                visibility       VARCHAR(16)  NOT NULL DEFAULT "group",
                form_version     INT UNSIGNED NOT NULL DEFAULT 1,
                score            INT UNSIGNED NULL,       -- computed for quizzes
                max_score        INT UNSIGNED NULL,
                passed           TINYINT(1)   NULL,
                review_state     VARCHAR(16)  NOT NULL DEFAULT "final", -- final|pending_review|reviewed
                teaching_impact  VARCHAR(16)  NULL,       -- self-assessed impact bucket
                submitted_at     DATETIME(6)  NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY efr_one_uq (form_id, respondent_id),
                KEY efr_event_idx (event_id, form_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_feedback_answers (
                id               CHAR(36)     NOT NULL,
                response_id      CHAR(36)     NOT NULL,
                question_id      CHAR(36)     NOT NULL,
                rating           INT UNSIGNED NULL,
                answer_text      TEXT         NULL,
                answer_choice    VARCHAR(255) NULL,
                awarded_points   INT UNSIGNED NULL,       -- quiz: points earned on this question
                is_correct       TINYINT(1)   NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY efa_one_uq (response_id, question_id),
                KEY efa_q_idx (question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-013: certificates -------------------------------------

        // Versioned certificate template per event type.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS certificate_templates (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_type       VARCHAR(40)  NULL,       -- null = default template
                name             VARCHAR(200) NOT NULL,
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                body_template    MEDIUMTEXT   NOT NULL,   -- HTML/markup with {{placeholders}}
                signer_role      VARCHAR(80)  NULL,       -- authorized signing role
                status           VARCHAR(16)  NOT NULL DEFAULT "active", -- active|retired
                created_at       DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ct_type_ver_uq (organization_id, event_type, version),
                KEY ct_status_idx (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Issued certificates. verification_id is public + carried in the QR;
        // state machine issued->revoked. UNIQUE(event,user) = one per attendee.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_certificates (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NOT NULL,
                template_id      CHAR(36)     NULL,
                template_version INT UNSIGNED NULL,
                verification_id  CHAR(32)     NOT NULL,   -- public opaque id (in QR)
                signed_by        CHAR(36)     NULL,
                signature_ref    VARCHAR(191) NULL,       -- signing-workflow reference
                render_ref       VARCHAR(191) NULL,       -- stored rendered artifact ref
                status           VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|issued|revoked
                issued_at        DATETIME(6)  NULL,
                revoked_at       DATETIME(6)  NULL,
                revoke_reason    VARCHAR(255) NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ec_person_uq (event_id, user_id),
                UNIQUE KEY ec_verify_uq (verification_id),
                KEY ec_status_idx (event_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-014: mobilization report snapshot ---------------------

        // Immutable point-in-time snapshot for roll-up. The live report is
        // computed on demand; snapshots persist a version for ancestor roll-up.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_report_snapshots (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NULL,       -- organizing group at snapshot time
                metrics          JSON         NOT NULL,   -- aggregate figures (no identifiable rows)
                generated_by     CHAR(36)     NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ers_event_idx (event_id, created_at),
                KEY ers_group_idx (group_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // ---- FR-EVT-015: event media --------------------------------------

        $this->db->query('
            CREATE TABLE IF NOT EXISTS event_media (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                event_id         CHAR(36)     NOT NULL,
                uploaded_by      CHAR(36)     NOT NULL,
                object_ref       VARCHAR(191) NOT NULL,   -- opaque store ref (access-controlled)
                source_sha256    CHAR(64)     NULL,       -- immutable source trace
                mime_type        VARCHAR(100) NULL,
                byte_size        BIGINT UNSIGNED NULL,
                caption          VARCHAR(500) NULL,
                alt_text         VARCHAR(500) NULL,
                exif_stripped    TINYINT(1)   NOT NULL DEFAULT 1, -- GPS/EXIF stripped by default
                scan_state       VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|clean|infected|error
                review_state     VARCHAR(16)  NOT NULL DEFAULT "pending", -- pending|approved|rejected
                consent_basis    VARCHAR(120) NULL,       -- rights/consent metadata
                visibility       VARCHAR(16)  NOT NULL DEFAULT "private", -- private|group|public
                created_at       DATETIME(6)  NOT NULL,
                updated_at       DATETIME(6)  NULL,
                PRIMARY KEY (id),
                KEY em_event_idx (event_id, review_state, visibility),
                KEY em_scan_idx (scan_state)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach ([
            'event_media', 'event_report_snapshots',
            'event_certificates', 'certificate_templates',
            'event_feedback_answers', 'event_feedback_responses',
            'event_feedback_questions', 'event_feedback_forms',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
