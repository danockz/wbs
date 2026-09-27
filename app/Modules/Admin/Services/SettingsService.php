<?php

declare(strict_types=1);

namespace WBS\Admin\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Platform settings + feature flags (SRS FR-ACL-007 auditable administration).
 *
 * Every change bumps a version and writes an append-only config_audit row with
 * before/after values, so administration is fully auditable and nothing changes
 * silently. Values are JSON so callers keep type fidelity.
 */
final class SettingsService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * All platform settings for an organization, decoded, for the settings admin
     * page. Read-only. Returns rows shaped {key, value, version, updated_at}.
     *
     * @return list<array<string,mixed>>
     */
    public function listSettings(string $organizationId): array
    {
        $rows = $this->db->table('platform_settings')
            ->select('setting_key, value_json, version, updated_at')
            ->where('organization_id', $organizationId)
            ->orderBy('setting_key', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $r): array {
            $decoded = $r['value_json'] === null ? null : json_decode((string) $r['value_json'], true);

            return [
                'key'        => (string) $r['setting_key'],
                'value'      => $decoded,
                'version'    => (int) ($r['version'] ?? 0),
                'updated_at' => $r['updated_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * All feature flags for an organization (org-wide + group overrides) for the
     * settings admin page. Read-only. Newest overrides first, org defaults shown.
     *
     * @return list<array<string,mixed>>
     */
    public function listFlags(string $organizationId): array
    {
        return $this->db->table('feature_flags')
            ->select('flag_key, group_id, enabled, description, updated_at')
            ->where('organization_id', $organizationId)
            ->orderBy('flag_key', 'ASC')
            ->orderBy('group_id', 'ASC')
            ->get()->getResultArray();
    }

    public function get(string $organizationId, string $key, mixed $default = null): mixed
    {
        $row = $this->db->table('platform_settings')
            ->where('organization_id', $organizationId)->where('setting_key', $key)
            ->get()->getRowArray();
        if ($row === null || $row['value_json'] === null) {
            return $default;
        }

        return json_decode((string) $row['value_json'], true);
    }

    public function set(string $organizationId, string $key, mixed $value, ?string $actorId = null): Result
    {
        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('platform_settings')
            ->where('organization_id', $organizationId)->where('setting_key', $key)
            ->get()->getRowArray();

        $newJson = json_encode($value);
        $this->db->transStart();
        if ($existing === null) {
            $this->db->table('platform_settings')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'setting_key'     => $key,
                'value_json'      => $newJson,
                'version'         => 1,
                'updated_by'      => $actorId,
                'updated_at'      => $now,
            ]);
            $old = null;
            $ver = 1;
        } else {
            $ver = (int) $existing['version'] + 1;
            $this->db->table('platform_settings')->where('id', $existing['id'])->update([
                'value_json' => $newJson,
                'version'    => $ver,
                'updated_by' => $actorId,
                'updated_at' => $now,
            ]);
            $old = $existing['value_json'];
        }
        $this->audit($organizationId, $actorId, 'setting', $key, $old, $newJson, $now);
        $this->db->transComplete();

        return Result::ok(['key' => $key, 'version' => $ver]);
    }

    public function setFlag(string $organizationId, string $flagKey, bool $enabled, ?string $groupId = null, ?string $actorId = null, ?string $description = null): Result
    {
        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('feature_flags')
            ->where('organization_id', $organizationId)->where('flag_key', $flagKey)
            ->where('group_id', $groupId)->get()->getRowArray();

        $this->db->transStart();
        if ($existing === null) {
            $this->db->table('feature_flags')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'flag_key'        => $flagKey,
                'group_id'        => $groupId,
                'enabled'         => $enabled ? 1 : 0,
                'description'     => $description,
                'updated_by'      => $actorId,
                'updated_at'      => $now,
            ]);
            $old = null;
        } else {
            $this->db->table('feature_flags')->where('id', $existing['id'])->update([
                'enabled'    => $enabled ? 1 : 0,
                'updated_by' => $actorId,
                'updated_at' => $now,
            ]);
            $old = json_encode((bool) $existing['enabled']);
        }
        $this->audit($organizationId, $actorId, 'flag', $flagKey . ($groupId ? ":{$groupId}" : ''), $old, json_encode($enabled), $now);
        $this->db->transComplete();

        return Result::ok(['flag' => $flagKey, 'enabled' => $enabled]);
    }

    /**
     * Resolve a flag: a group-scoped row wins over the org-wide default. If a
     * group override exists it takes precedence; otherwise the org default (or
     * false) applies.
     */
    public function isEnabled(string $organizationId, string $flagKey, ?string $groupId = null): bool
    {
        if ($groupId !== null) {
            $scoped = $this->db->table('feature_flags')
                ->where('organization_id', $organizationId)->where('flag_key', $flagKey)
                ->where('group_id', $groupId)->get()->getRowArray();
            if ($scoped !== null) {
                return (bool) $scoped['enabled'];
            }
        }
        $orgWide = $this->db->table('feature_flags')
            ->where('organization_id', $organizationId)->where('flag_key', $flagKey)
            ->where('group_id', null)->get()->getRowArray();

        return $orgWide !== null && (bool) $orgWide['enabled'];
    }

    private function audit(string $org, ?string $actor, string $type, string $key, ?string $old, ?string $new, string $now): void
    {
        $this->db->table('config_audit')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $org,
            'actor_id'        => $actor,
            'target_type'     => $type,
            'target_key'      => mb_substr($key, 0, 160),
            'old_value'       => $old,
            'new_value'       => $new,
            'created_at'      => $now,
        ]);
    }
}
