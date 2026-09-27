<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * User preferences (SRS FR-ID: user_preferences). Phase 1 needs locale; the same
 * upsert covers timezone/accessibility/settings later.
 *
 * The unique key is user_preferences.user_id (UNIQUE up_user_uq), so writes are
 * idempotent upserts. organization_id is resolved from users when the row is
 * created so the record stays tenant-scoped.
 */
final class UserPreferenceService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function forUser(string $userId): ?array
    {
        $row = $this->db->table('user_preferences')
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /** The stored locale for a user, or null when unset. */
    public function localeFor(string $userId): ?string
    {
        $row = $this->forUser($userId);
        $loc = $row['locale'] ?? null;

        return (is_string($loc) && $loc !== '') ? $loc : null;
    }

    /** Idempotent upsert of the user's preferred locale. */
    public function setLocale(string $userId, string $locale): bool
    {
        return $this->set($userId, ['locale' => $locale]);
    }

    /**
     * Upsert arbitrary preference columns for a user (locale/timezone/...).
     *
     * @param array<string,mixed> $fields
     */
    public function set(string $userId, array $fields): bool
    {
        $now      = $this->clock->now()->format('Y-m-d H:i:s');
        $existing = $this->forUser($userId);

        if ($existing !== null) {
            return $this->db->table('user_preferences')
                ->where('user_id', $userId)
                ->update($fields + ['updated_at' => $now]);
        }

        // Resolve the tenant for a fresh row.
        $orgId = (string) ($this->db->table('users')
            ->select('organization_id')
            ->where('id', $userId)
            ->get()
            ->getRowArray()['organization_id'] ?? '');

        if ($orgId === '') {
            return false; // unknown user — nothing to attach the pref to
        }

        return $this->db->table('user_preferences')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $orgId,
            'user_id'         => $userId,
            'updated_at'      => $now,
        ] + $fields);
    }
}
