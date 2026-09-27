<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Per-hierarchical-group calendar settings + the group-id set a calendar view
 * should query.
 *
 * Settings row is optional: missing → walk ancestors (nearest first) → defaults
 * (label empty, timezone empty = UTC bucket, kinds empty = all types).
 *
 * Scope expansion is a handful of indexed reads (memberships + closure), never
 * a recursive walk.
 */
final class CalendarSettingsService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Active membership group ids for a user in an org.
     *
     * @return list<string>
     */
    public function membershipGroupIds(string $organizationId, string $userId): array
    {
        if ($organizationId === '' || $userId === '') {
            return [];
        }
        $rows = $this->db->table('group_members')
            ->select('group_id')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get()->getResultArray();
        $ids = [];
        foreach ($rows as $r) {
            $id = (string) ($r['group_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Seed groups plus every descendant (self included, distance 0).
     *
     * @param list<string> $seedIds
     * @return list<string>
     */
    public function expandSubtrees(array $seedIds): array
    {
        $seedIds = array_values(array_unique(array_filter($seedIds, static fn ($id) => $id !== '')));
        if ($seedIds === []) {
            return [];
        }
        $out = [];
        foreach ($seedIds as $seed) {
            $rows = $this->db->table('group_closure')
                ->select('descendant_id')
                ->where('ancestor_id', $seed)
                ->get()->getResultArray();
            if ($rows === []) {
                $out[$seed] = true;
                continue;
            }
            foreach ($rows as $r) {
                $id = (string) ($r['descendant_id'] ?? '');
                if ($id !== '') {
                    $out[$id] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * Seed groups plus every ANCESTOR (self included). The calendar looks UP:
     * a cell sees its own events and each higher-group event that applies to it
     * because it is a descendant of that higher group — never sibling/child
     * events further down the tree.
     *
     * @param list<string> $seedIds
     * @return list<string>
     */
    public function expandAncestors(array $seedIds): array
    {
        $seedIds = array_values(array_unique(array_filter($seedIds, static fn ($id) => $id !== '')));
        if ($seedIds === []) {
            return [];
        }
        $out = [];
        foreach ($seedIds as $seed) {
            $out[$seed] = true;
            foreach ($this->ancestorIds($seed) as $id) {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Ancestors nearest-first, excluding self.
     *
     * @return list<string>
     */
    public function ancestorIds(string $groupId): array
    {
        if ($groupId === '') {
            return [];
        }
        $rows = $this->db->table('group_closure')
            ->select('ancestor_id, distance')
            ->where('descendant_id', $groupId)
            ->where('distance >', 0)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();
        $ids = [];
        foreach ($rows as $r) {
            $id = (string) ($r['ancestor_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Resolved settings for a group (own row, else nearest ancestor, else defaults).
     *
     * @return array{group_id:string,display_label:?string,timezone:?string,visible_kinds:list<string>,inherited_from:?string}
     */
    public function resolve(string $organizationId, string $groupId): array
    {
        $empty = [
            'group_id'        => $groupId,
            'display_label'   => null,
            'timezone'        => null,
            'visible_kinds'   => [],
            'inherited_from'  => null,
        ];
        if ($organizationId === '' || $groupId === '') {
            return $empty;
        }
        $chain = array_merge([$groupId], $this->ancestorIds($groupId));
        foreach ($chain as $gid) {
            $row = $this->db->table('group_calendar_settings')
                ->where('organization_id', $organizationId)
                ->where('group_id', $gid)
                ->get()->getRowArray();
            if ($row === null) {
                continue;
            }

            return [
                'group_id'       => $groupId,
                'display_label'  => ($row['display_label'] ?? null) !== null && (string) $row['display_label'] !== ''
                    ? (string) $row['display_label'] : null,
                'timezone'       => ($row['timezone'] ?? null) !== null && (string) $row['timezone'] !== ''
                    ? (string) $row['timezone'] : null,
                'visible_kinds'  => $this->decodeKinds($row['visible_kinds'] ?? null),
                'inherited_from' => $gid === $groupId ? null : $gid,
            ];
        }

        return $empty;
    }

    /** Own row only (the editor), never inherited. */
    public function findOwn(string $organizationId, string $groupId): ?array
    {
        if ($organizationId === '' || $groupId === '') {
            return null;
        }

        return $this->db->table('group_calendar_settings')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->get()->getRowArray() ?: null;
    }

    /**
     * Upsert the settings row for one group.
     *
     * @param array<string,mixed> $data display_label, timezone, visible_kinds (list or CSV)
     */
    public function upsert(string $organizationId, string $groupId, array $data, ?string $actorId): Result
    {
        if ($organizationId === '' || $groupId === '') {
            return Result::fail('GROUP_REQUIRED', 'calendar.group_required', 422);
        }
        $tz = trim((string) ($data['timezone'] ?? ''));
        if ($tz !== '' && ! $this->validTimezone($tz)) {
            return Result::fail('TIMEZONE_INVALID', 'calendar.timezone_invalid', 422);
        }
        $kinds = $this->normalizeKinds($data['visible_kinds'] ?? null);
        $label = trim((string) ($data['display_label'] ?? ''));
        $now   = $this->clock->nowUtcString();

        $existing = $this->findOwn($organizationId, $groupId);
        $payload  = [
            'display_label' => $label !== '' ? $label : null,
            'timezone'      => $tz !== '' ? $tz : null,
            'visible_kinds' => $kinds === [] ? null : json_encode($kinds, JSON_UNESCAPED_UNICODE),
            'updated_by'    => $actorId,
            'updated_at'    => $now,
        ];
        if ($existing !== null) {
            $this->db->table('group_calendar_settings')
                ->where('id', (string) $existing['id'])
                ->update($payload);

            return Result::ok(['id' => (string) $existing['id'], 'group_id' => $groupId]);
        }
        $id = Uuid::v7();
        $this->db->table('group_calendar_settings')->insert($payload + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $groupId,
            'created_at'      => $now,
        ]);

        return Result::created(['id' => $id, 'group_id' => $groupId]);
    }

    /**
     * Re-bucket an event start (UTC "Y-m-d H:i:s") into a display timezone.
     * Returns [Y-m-d, H:i] or null when the stamp is unusable.
     *
     * @return array{0:string,1:string}|null
     */
    public static function localStamp(?string $startsAtUtc, ?string $timezone): ?array
    {
        $raw = trim((string) $startsAtUtc);
        if ($raw === '' || strlen($raw) < 19) {
            return null;
        }
        try {
            $utc = new \DateTimeImmutable(substr($raw, 0, 19), new \DateTimeZone('UTC'));
            $tz  = ($timezone !== null && $timezone !== '')
                ? new \DateTimeZone($timezone)
                : new \DateTimeZone('UTC');
            $local = $utc->setTimezone($tz);

            return [$local->format('Y-m-d'), $local->format('H:i')];
        } catch (\Throwable) {
            return [substr($raw, 0, 10), substr($raw, 11, 5)];
        }
    }

    /** @return list<string> */
    private function decodeKinds(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->normalizeKinds($raw);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return [];
        }
        $decoded = json_decode($s, true);

        return is_array($decoded) ? $this->normalizeKinds($decoded) : $this->normalizeKinds($s);
    }

    /** @return list<string> */
    private function normalizeKinds(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/[,\s]+/', $raw) ?: [];
        } elseif (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = [];
        }
        $out = [];
        foreach ($parts as $p) {
            $v = strtolower(trim((string) $p));
            if ($v !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v) === 1) {
                $out[$v] = true;
            }
        }

        return array_keys($out);
    }

    private function validTimezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
