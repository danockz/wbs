<?php

declare(strict_types=1);

namespace WBS\Streaming\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * In-stream giving: authorized widget/progress-bar config + a stream<->
 * contribution link with opt-in acknowledgement preferences (SRS FR-STR-009).
 *
 *  - stream_giving_configs: per-stream authorization + widget configuration. A
 *    stream shows a cause-progress bar and a contribution widget ONLY when an
 *    authorized organizer has enabled it for a specific cause. `authorized_by`
 *    records who turned it on (accountability). `ack_show_amount_allowed` is the
 *    POLICY gate: even if a giver opts to reveal their amount, it is shown only
 *    when policy permits it here.
 *  - stream_giving_intents: the link between a live-stream giving action and the
 *    Contributions intent it created. It stores ONLY acknowledgement preferences
 *    and a deterministic pointer to the intent — never money state, which stays
 *    authoritative in the contributions/ledger tables. The acknowledgement feed
 *    joins this to `contributions` (via intent_id, state='succeeded') so it can
 *    never announce a gift that did not actually complete.
 */
final class CreateStreamGiving extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_giving_configs (
                id                      CHAR(36)        NOT NULL,
                organization_id         CHAR(36)        NOT NULL,
                stream_id               CHAR(36)        NOT NULL,
                enabled                 TINYINT(1)      NOT NULL DEFAULT 0,
                cause_id                CHAR(36)        NULL,
                progress_bar_enabled    TINYINT(1)      NOT NULL DEFAULT 1,
                widget_enabled          TINYINT(1)      NOT NULL DEFAULT 1,
                suggested_amounts       JSON            NULL,     -- [minor,...]
                min_amount_minor        BIGINT UNSIGNED NULL,
                max_amount_minor        BIGINT UNSIGNED NULL,
                currency                CHAR(3)         NULL,
                allow_anonymous         TINYINT(1)      NOT NULL DEFAULT 1,
                ack_enabled             TINYINT(1)      NOT NULL DEFAULT 1,
                ack_show_amount_allowed TINYINT(1)      NOT NULL DEFAULT 0,
                authorized_by           CHAR(36)        NULL,
                authorized_at           DATETIME        NULL,
                created_at              DATETIME        NOT NULL,
                updated_at              DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY sgc_stream_uq (stream_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS stream_giving_intents (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                stream_id        CHAR(36)     NOT NULL,
                cause_id         CHAR(36)     NOT NULL,
                intent_id        CHAR(36)     NOT NULL,
                user_id          CHAR(36)     NULL,
                amount_minor     BIGINT UNSIGNED NOT NULL,
                currency         CHAR(3)      NOT NULL,
                recognition      VARCHAR(16)  NOT NULL DEFAULT "public", -- public|group|anonymous|none
                ack_opt_in       TINYINT(1)   NOT NULL DEFAULT 0,   -- giver wants a shout-out
                ack_show_amount  TINYINT(1)   NOT NULL DEFAULT 0,   -- giver chose to reveal amount
                display_choice   VARCHAR(12)  NOT NULL DEFAULT "anonymous", -- name|anonymous
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY sgi_intent_uq (intent_id),
                KEY sgi_stream_idx (stream_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS stream_giving_intents');
        $this->db->query('DROP TABLE IF EXISTS stream_giving_configs');
    }
}
