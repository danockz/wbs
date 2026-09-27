<?php

declare(strict_types=1);

namespace WBS\Gamification\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * FR-GAM-009/010/011 — group-scoped, highly-configurable awards/ranks and
 * time-boxed group "projects" (campaigns).
 *
 * Two parts:
 *
 *  1. GROUP SCOPING of the configurable catalogs. rank_definitions,
 *     achievement_definitions and streak_definitions previously carried only
 *     organization_id, so ranks/achievements/streaks could not be configured
 *     per hierarchical group (only point rules + badges could). We add
 *     `group_id` (NULL = org-wide default) and `include_descendants` so a
 *     definition set on a parent group can apply to its subgroups. Resolution
 *     is most-specific-wins (group → nearest ancestor with include_descendants
 *     → org-wide).
 *
 *  2. GROUP CAMPAIGNS ("projects"). A hierarchical group can run a time-boxed
 *     project bundling Win/Build/Send (or any) activities toward a configurable
 *     target (points | amount | volume | count). It may include subgroups, be
 *     repeatable (win a badge each time the target is hit, up to an optional
 *     cap), optionally roll its points into the general season, and — at the
 *     end — recognize a configurable top-N members. This is what delivers
 *     "any season/duration other than the general season, per group".
 */
final class GroupScopedAwardsAndCampaigns extends Migration
{
    public function up(): void
    {
        // ---- 1. Group scoping for the configurable catalogs ----------------
        // NULL group_id keeps every existing row org-wide (backward compatible).
        foreach (['rank_definitions', 'achievement_definitions', 'streak_definitions'] as $t) {
            $this->db->query("
                ALTER TABLE {$t}
                    ADD COLUMN group_id            CHAR(36)   NULL AFTER organization_id,
                    ADD COLUMN include_descendants TINYINT(1) NOT NULL DEFAULT 1 AFTER group_id
            ");
        }
        // The old UNIQUE(organization_id, code) must become per-group so a group
        // can override an org-wide code. MySQL treats multiple NULLs as distinct,
        // so (org, NULL, code) stays unique per code for the org-wide row while
        // (org, group, code) is unique per group.
        $this->db->query('ALTER TABLE rank_definitions DROP INDEX rd_code_uq, ADD UNIQUE KEY rd_code_uq (organization_id, group_id, code)');
        $this->db->query('ALTER TABLE achievement_definitions DROP INDEX ad_code_uq, ADD UNIQUE KEY ad_code_uq (organization_id, group_id, code)');
        $this->db->query('ALTER TABLE streak_definitions DROP INDEX sd_code_uq, ADD UNIQUE KEY sd_code_uq (organization_id, group_id, code)');

        // ---- 1b. Repeatable badge wins ------------------------------------
        // badge_awards previously enforced UNIQUE(badge_id, subject_id, season_id),
        // i.e. a badge could be earned at most once per season. Campaign projects
        // require REPEATABLE badge wins, so the idempotency key now includes
        // source_ref: a normal award (source_ref NULL) still cannot duplicate
        // within a season (MySQL treats NULLs as distinct, and normal awards pass
        // NULL), while a campaign award carries a distinct source_ref per win
        // ("campaign:{id}:{index}"), letting the same badge be won repeatedly.
        $this->db->query('ALTER TABLE badge_awards DROP INDEX ba_idem_uq, ADD UNIQUE KEY ba_idem_uq (badge_id, subject_id, season_id, source_ref)');

        // ---- 2. Group campaigns ("projects") -------------------------------
        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_campaigns (
                id                  CHAR(36)     NOT NULL,
                organization_id     CHAR(36)     NOT NULL,
                group_id            CHAR(36)     NOT NULL,   -- owning hierarchical group
                include_descendants TINYINT(1)   NOT NULL DEFAULT 1, -- count subgroup members too
                season_id           CHAR(36)     NULL,       -- optional link to a general season
                code                VARCHAR(80)  NOT NULL,
                name                VARCHAR(200) NOT NULL,
                description         TEXT         NULL,
                category            VARCHAR(80)  NULL,       -- win|build|send|giving|custom label
                activity_scope      JSON         NULL,       -- list of rule codes / activity types that count
                metric              VARCHAR(16)  NOT NULL DEFAULT "points", -- points|amount|volume|count
                award_mode          VARCHAR(16)  NOT NULL DEFAULT "single", -- single|repeatable|tiered
                target_value        BIGINT       NOT NULL,   -- threshold to hit (minor units for amount); tiers use campaign_tiers
                repeatable          TINYINT(1)   NOT NULL DEFAULT 0, -- can be won multiple times (award_mode=repeatable)
                max_awards          INT UNSIGNED NULL,       -- cap on repeats (NULL = unlimited)
                badge_code          VARCHAR(80)  NULL,       -- badge granted on each target hit
                award_points        INT UNSIGNED NULL,       -- points granted on each target hit
                rollup_to_general   TINYINT(1)   NOT NULL DEFAULT 0, -- do campaign points count to the general season
                recognize_top_n     INT UNSIGNED NULL,       -- end-of-campaign top-N INDIVIDUALS to recognize
                subject_type        VARCHAR(16)  NOT NULL DEFAULT "user", -- award subject: always the individual member
                team_challenge      TINYINT(1)   NOT NULL DEFAULT 0, -- OPTIONAL team side-competition overlay
                team_mode           VARCHAR(16)  NOT NULL DEFAULT "subtree", -- subtree|adhoc (how teams form when team_challenge=1)
                recognize_top_teams INT UNSIGNED NULL,       -- end-of-campaign top-N TEAMS to recognize (team_challenge)
                team_target_value   BIGINT UNSIGNED NULL,    -- single MILESTONE target each competing GROUP/TEAM must reach (own award)
                team_badge_code     VARCHAR(64)  NULL,       -- badge granted to a GROUP/TEAM when it reaches team_target_value
                team_award_points   INT UNSIGNED NULL,       -- points granted to a GROUP/TEAM milestone (parity with individuals)
                starts_at           DATETIME     NOT NULL,   -- arbitrary duration, stored UTC
                ends_at             DATETIME     NOT NULL,
                status              VARCHAR(16)  NOT NULL DEFAULT "draft", -- draft|active|completed|cancelled
                created_by          CHAR(36)     NULL,
                created_at          DATETIME(6)  NOT NULL,
                updated_at          DATETIME(6)  NULL,
                completed_at        DATETIME(6)  NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gcmp_code_uq (organization_id, group_id, code),
                KEY gcmp_active_idx (organization_id, status, starts_at, ends_at),
                KEY gcmp_group_idx (group_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Tiered award ladder (e.g. silver/gold/diamond). Only used when a
        // campaign's award_mode = "tiered". Each tier is a threshold on the same
        // metric with its own badge/points, and is granted at most once per
        // subject (idempotency via campaign_awards.award_index = tier_position).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_tiers (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NOT NULL,
                code             VARCHAR(40)  NOT NULL,          -- silver|gold|diamond|...
                name             VARCHAR(120) NOT NULL,
                tier_position    INT UNSIGNED NOT NULL,          -- 1,2,3 ascending
                threshold_value  BIGINT       NOT NULL,          -- metric value to reach this tier
                badge_code       VARCHAR(80)  NULL,              -- badge granted on reaching this tier
                award_points     INT UNSIGNED NULL,              -- points granted on reaching this tier
                icon             VARCHAR(120) NULL,
                color            VARCHAR(24)  NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ctr_pos_uq (campaign_id, tier_position),
                UNIQUE KEY ctr_code_uq (campaign_id, code),
                KEY ctr_thr_idx (campaign_id, threshold_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Per-subject accumulated progress toward the campaign target.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_progress (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL DEFAULT "user",
                current_value    BIGINT       NOT NULL DEFAULT 0,  -- accumulated metric
                awards_count     INT UNSIGNED NOT NULL DEFAULT 0,  -- times target hit
                team_ref         CHAR(36)     NULL,               -- team this member tallies into (team_challenge)
                subject_group_id CHAR(36)     NULL,               -- the member most-specific hierarchical group at contribution time (denormalized for O(1) leaderboard reads; path resolved from groups.path)
                last_source_ref  VARCHAR(191) NULL,               -- last applied event (idempotency)
                last_progress_at DATETIME(6)  NULL,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cpg_uq (campaign_id, subject_id),
                KEY cpg_board_idx (campaign_id, current_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // AD HOC teams. When a group campaign uses team_mode="adhoc", the
        // competing teams are NOT tree subgroups — they are explicit rosters
        // aggregated from members ANYWHERE in the hierarchy. Each team is a
        // first-class subject; progress/awards/recognition all key on the team
        // id (subject_type stored as "team" on those rows to distinguish it from
        // a real group id).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_teams (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                campaign_id     CHAR(36)     NOT NULL,
                code            VARCHAR(80)  NOT NULL,
                name            VARCHAR(200) NOT NULL,
                captain_user_id CHAR(36)     NULL,
                color           VARCHAR(24)  NULL,
                icon            VARCHAR(120) NULL,
                created_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cte_code_uq (campaign_id, code),
                KEY cte_campaign_idx (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Ad hoc team roster. A user belongs to at most ONE team per campaign
        // (cam_user_uq), so a verified activity accrues unambiguously. The user
        // may be drawn from any group in the hierarchy.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_team_members (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                campaign_id     CHAR(36)     NOT NULL,
                team_id         CHAR(36)     NOT NULL,
                user_id         CHAR(36)     NOT NULL,
                added_by        CHAR(36)     NULL,
                created_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ctm_user_uq (campaign_id, user_id),
                KEY ctm_team_idx (team_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // OPTIONAL team-challenge standings. The campaign's PRIMARY subject is
        // always the individual member (campaign_progress). When a campaign has
        // team_challenge=1, each individual contribution ALSO tallies into a team
        // total here, keyed by team_ref = a group_id (team_mode=subtree) or a
        // campaign_teams.id (team_mode=adhoc). This is a side competition only —
        // it never changes an individual''s own progress or awards.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_team_standings (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NOT NULL,
                team_ref         CHAR(36)     NOT NULL,   -- group_id (subtree) or campaign_teams.id (adhoc)
                team_kind        VARCHAR(16)  NOT NULL DEFAULT "group", -- group|team
                total_value      BIGINT       NOT NULL DEFAULT 0,
                contributors     INT UNSIGNED NOT NULL DEFAULT 0,
                awards_count     INT UNSIGNED NOT NULL DEFAULT 0, -- the group/team milestone award (0 or 1) granted at team_target_value
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cts_uq (campaign_id, team_ref),
                KEY cts_board_idx (campaign_id, total_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Exactly-once dedup for progress increments. Each applied increment is
        // recorded here keyed by (campaign, subject, source_ref); a replayed
        // event clashes on the UNIQUE key and is skipped, so cumulative progress
        // stays accurate even when many members feed the SAME team row (group
        // campaigns) or events are redelivered out of order.
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_progress_events (
                id              CHAR(36)     NOT NULL,
                organization_id CHAR(36)     NOT NULL,
                campaign_id     CHAR(36)     NOT NULL,
                subject_id      CHAR(36)     NOT NULL,   -- the accrual subject (user or team)
                source_ref      VARCHAR(191) NOT NULL,
                amount          BIGINT       NOT NULL,
                created_at      DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY cpe_idem_uq (campaign_id, subject_id, source_ref),
                KEY cpe_subject_idx (campaign_id, subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Append-only record of each target hit (repeatable wins).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_awards (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL DEFAULT "user",
                award_index      INT UNSIGNED NOT NULL,          -- 1st, 2nd, ... target hit
                metric_value     BIGINT       NOT NULL,          -- value at the moment of award
                badge_award_id   CHAR(36)     NULL,              -- link to badge_awards row
                points_ledger_id CHAR(36)     NULL,              -- link to point_ledger row
                awarded_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY caw_idem_uq (campaign_id, subject_id, award_index),
                KEY caw_subject_idx (subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // End-of-campaign top-N recognition snapshot (immutable).
        $this->db->query('
            CREATE TABLE IF NOT EXISTS campaign_recognitions (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                campaign_id      CHAR(36)     NOT NULL,
                subject_id       CHAR(36)     NOT NULL,
                subject_type     VARCHAR(16)  NOT NULL DEFAULT "user",
                rank_position    INT UNSIGNED NOT NULL,
                final_value      BIGINT       NOT NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY crec_uq (campaign_id, subject_id),
                KEY crec_rank_idx (campaign_id, rank_position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['campaign_recognitions', 'campaign_awards', 'campaign_progress_events', 'campaign_team_standings', 'campaign_progress', 'campaign_team_members', 'campaign_teams', 'campaign_tiers', 'group_campaigns'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }

        $this->db->query('ALTER TABLE badge_awards DROP INDEX ba_idem_uq, ADD UNIQUE KEY ba_idem_uq (badge_id, subject_id, season_id)');

        $this->db->query('ALTER TABLE rank_definitions DROP INDEX rd_code_uq, ADD UNIQUE KEY rd_code_uq (organization_id, code)');
        $this->db->query('ALTER TABLE achievement_definitions DROP INDEX ad_code_uq, ADD UNIQUE KEY ad_code_uq (organization_id, code)');
        $this->db->query('ALTER TABLE streak_definitions DROP INDEX sd_code_uq, ADD UNIQUE KEY sd_code_uq (organization_id, code)');

        foreach (['rank_definitions', 'achievement_definitions', 'streak_definitions'] as $t) {
            $this->db->query("ALTER TABLE {$t} DROP COLUMN include_descendants, DROP COLUMN group_id");
        }
    }
}
