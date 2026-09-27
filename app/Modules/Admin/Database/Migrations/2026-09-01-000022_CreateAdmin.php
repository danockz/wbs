<?php

declare(strict_types=1);

namespace WBS\Admin\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Platform administration: settings, feature flags, group configuration
 * inheritance (SRS FR-GRP-006, FR-ACL-007, FR-RL-003).
 *
 *  - platform_settings: single-org key/value config, versioned via updated_at +
 *    an append-only history for auditable administration.
 *  - feature_flags: org-wide toggles with optional group-scope override rows.
 *  - group_configurations: per-group values for inheritable capabilities. Each
 *    capability declares an inheritance mode; the effective-config resolver walks
 *    the group ancestry and returns value + source group + version + decision.
 *  - config_audit: append-only record of every setting/flag/config change so
 *    administration is auditable (secure defaults, no silent change).
 */
final class CreateAdmin extends Migration
{
    public function up(): void
    {
        $this->db->query('
            CREATE TABLE IF NOT EXISTS platform_settings (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                setting_key      VARCHAR(120) NOT NULL,
                value_json       JSON         NULL,
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                updated_by       CHAR(36)     NULL,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ps_key_uq (organization_id, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS feature_flags (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                flag_key         VARCHAR(120) NOT NULL,
                group_id         CHAR(36)     NULL,           -- null = org-wide default
                enabled          TINYINT(1)   NOT NULL DEFAULT 0,
                description      VARCHAR(255) NULL,
                updated_by       CHAR(36)     NULL,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ff_scope_uq (organization_id, flag_key, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS group_configurations (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                group_id         CHAR(36)     NOT NULL,
                capability       VARCHAR(80)  NOT NULL,       -- payment|notifications|templates|retention|branding|integrations
                inheritance_mode VARCHAR(40)  NOT NULL DEFAULT "ancestor_default_child_override",
                                                             -- inherit_only|ancestor_default_child_override|child_owned|not_inheritable
                value_json       JSON         NULL,
                version          INT UNSIGNED NOT NULL DEFAULT 1,
                updated_by       CHAR(36)     NULL,
                updated_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY gcfg_uq (group_id, capability)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->query('
            CREATE TABLE IF NOT EXISTS config_audit (
                id               CHAR(36)     NOT NULL,
                organization_id  CHAR(36)     NOT NULL,
                actor_id         CHAR(36)     NULL,
                target_type      VARCHAR(24)  NOT NULL,       -- setting|flag|group_config
                target_key       VARCHAR(160) NOT NULL,
                old_value        JSON         NULL,
                new_value        JSON         NULL,
                created_at       DATETIME(6)  NOT NULL,
                PRIMARY KEY (id),
                KEY ca_org_idx (organization_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        foreach (['config_audit', 'group_configurations', 'feature_flags', 'platform_settings'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t}");
        }
    }
}
