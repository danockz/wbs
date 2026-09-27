<?php

declare(strict_types=1);

namespace WBS\Identity\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Member profile photo (SRS FR-ID-008 profile). Adds an OPTIONAL photo URL to
 * users; when absent, the platform serves a deterministic, resource-light inline
 * SVG initials avatar ({@see \WBS\Shared\Support\Avatar}) — no upload pipeline,
 * no external calls, so it renders even in a network-less preview.
 *
 *  - profile_photo_url    : already-hosted image URL the member chose (nullable).
 *  - profile_photo_source : how it was set ('url'|'upload'|null) — carried so a
 *                           later upload pipeline can be introduced without a
 *                           second migration. Advisory only.
 *  - profile_photo_updated_at : last change, for cache-busting avatar responses.
 *
 * All columns nullable/defaulted; idempotent (add-if-absent), mirroring the
 * AddGroupLocation convention.
 */
final class AddUserProfilePhoto extends Migration
{
    /** @var array<string,string> column => DDL type/clause */
    private array $columns = [
        'profile_photo_url'        => 'VARCHAR(512) NULL',
        'profile_photo_source'     => 'VARCHAR(16)  NULL', // url|upload
        'profile_photo_updated_at' => 'DATETIME     NULL',
    ];

    public function up(): void
    {
        // Guard against CI4's connection-lifetime schema cache: raw CREATE/ALTER
        // queries don't invalidate it, so tableExists()/fieldExists() can read a
        // stale list (notably on migrate:refresh) and silently skip ADD COLUMN.
        $this->db->resetDataCache();
        foreach ($this->columns as $name => $ddl) {
            if (! $this->db->fieldExists($name, 'users')) {
                $this->db->query('ALTER TABLE `users` ADD COLUMN `' . $name . '` ' . $ddl);
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
            if ($this->db->fieldExists($name, 'users')) {
                $this->db->query('ALTER TABLE `users` DROP COLUMN `' . $name . '`');
            }
        }
    }
}
