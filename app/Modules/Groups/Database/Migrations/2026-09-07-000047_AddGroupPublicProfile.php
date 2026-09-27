<?php

declare(strict_types=1);

namespace WBS\Groups\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Public/landing-page profile fields for groups (group-specific public page).
 *
 * The base `groups` table is a structural/hierarchy record; a public landing
 * page needs presentational + contact content that does not belong in that core
 * shape. All columns are nullable so existing groups keep working; a group is
 * publicly viewable when status = 'active' (see GroupPublicService).
 *
 * `hero_theme` selects one of the bundled landing design templates
 * (aurora | sunrise | forest | slate) so each group can pick its own look.
 * Idempotent: each column is only added when absent, so a partially-applied
 * earlier run can be resumed safely.
 */
final class AddGroupPublicProfile extends Migration
{
    /** @var array<string,string> column => DDL type/clause */
    private array $columns = [
        'tagline'          => "VARCHAR(180) NULL AFTER `name`",
        'description'      => 'MEDIUMTEXT NULL',
        'location_text'    => 'VARCHAR(200) NULL',
        'contact_email'    => 'VARCHAR(190) NULL',
        'contact_phone'    => 'VARCHAR(40)  NULL',
        'website_url'      => 'VARCHAR(300) NULL',
        'announcement'     => 'TEXT NULL',                                      // single current announcement/banner
        'hero_theme'       => "VARCHAR(24) NOT NULL DEFAULT 'aurora'",          // aurora|sunrise|forest|slate
        'cover_image_url'  => 'VARCHAR(500) NULL',
        'public_join'      => 'TINYINT(1) NOT NULL DEFAULT 1',                  // show the join CTA publicly
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach ($this->columns as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'groups')) {
                $this->db->query(
                    'ALTER TABLE `groups` ADD COLUMN `' . $name . '` ' . $ddl
                );
            }
        }
    }

    public function down(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach (array_keys($this->columns) as $name) {
            if ($this->db->fieldExists($name, 'groups')) {
                $this->db->query('ALTER TABLE `groups` DROP COLUMN `' . $name . '`');
            }
        }
    }
}
