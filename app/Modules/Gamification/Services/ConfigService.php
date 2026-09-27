<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Typed runtime configuration for the gamification subsystem
 * (gamification_config). Lets an admin tune behaviour — cache TTLs, evaluation
 * modes, feature toggles, archive strategy, etc. — without a deploy, mirroring
 * the reference schema's award_configuration table.
 *
 * Values are stored as text with a declared config_type and cast on read. Keys
 * flagged is_editable = 0 are read-only (system-managed) and refuse writes.
 */
final class ConfigService
{
    private const TYPES = ['string', 'integer', 'float', 'boolean', 'json'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Fetch a single config value, cast to its declared type. Returns $default
     * when the key is absent.
     */
    public function get(string $organizationId, string $key, mixed $default = null): mixed
    {
        $row = $this->db->table('gamification_config')
            ->where('organization_id', $organizationId)->where('config_key', $key)
            ->get()->getRowArray();
        if ($row === null) {
            return $default;
        }

        return $this->cast($row['config_value'], (string) $row['config_type']);
    }

    /**
     * Read a single config key with its metadata (value cast to type). 404 when
     * the key is absent — distinct from get(), which returns a bare default.
     */
    public function show(string $organizationId, string $key): Result
    {
        $row = $this->db->table('gamification_config')
            ->where('organization_id', $organizationId)->where('config_key', $key)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.config_not_found', 'CONFIG_NOT_FOUND');
        }

        return Result::ok([
            'key'         => $row['config_key'],
            'value'       => $this->cast($row['config_value'], (string) $row['config_type']),
            'type'        => $row['config_type'],
            'description' => $row['description'],
            'is_editable' => (bool) $row['is_editable'],
            'updated_at'  => $row['updated_at'],
        ]);
    }

    /**
     * Delete a config key. Read-only (is_editable = 0) keys are system-managed
     * and refuse deletion, mirroring set(). 404 when the key is absent.
     */
    public function delete(string $organizationId, string $key): Result
    {
        $row = $this->db->table('gamification_config')
            ->where('organization_id', $organizationId)->where('config_key', $key)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.config_not_found', 'CONFIG_NOT_FOUND');
        }
        if (! (bool) $row['is_editable']) {
            return Result::fail('CONFIG_READONLY', 'gamification.config_readonly', 409, ['key' => $key]);
        }

        $this->db->table('gamification_config')->where('id', $row['id'])->delete();

        return Result::ok(['key' => $key, 'deleted' => true]);
    }

    /**
     * All config for an org as a key => {value, type, description, is_editable}
     * map with values cast to their declared types.
     *
     * @return array<string,mixed>
     */
    public function all(string $organizationId): array
    {
        $rows = $this->db->table('gamification_config')
            ->where('organization_id', $organizationId)
            ->orderBy('config_key', 'ASC')->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['config_key']] = [
                'value'       => $this->cast($r['config_value'], (string) $r['config_type']),
                'type'        => $r['config_type'],
                'description' => $r['description'],
                'is_editable' => (bool) $r['is_editable'],
            ];
        }

        return $out;
    }

    /**
     * Upsert a config key. On create, the type defaults to 'string' unless a
     * valid config_type is supplied. On update, a key flagged read-only refuses
     * the write. JSON values may be passed as arrays (encoded) or strings.
     *
     * @param array<string,mixed> $opts type, description, is_editable, updated_by
     */
    public function set(string $organizationId, string $key, mixed $value, array $opts = []): Result
    {
        $key = trim($key);
        if ($key === '') {
            return Result::fail('BAD_CONFIG_KEY', 'gamification.bad_config_key', 422);
        }
        $type = $opts['type'] ?? null;
        if ($type !== null && ! in_array($type, self::TYPES, true)) {
            return Result::fail('BAD_CONFIG_TYPE', 'gamification.bad_config_type', 422, ['allowed' => self::TYPES]);
        }

        $existing = $this->db->table('gamification_config')
            ->where('organization_id', $organizationId)->where('config_key', $key)
            ->get()->getRowArray();

        if ($existing !== null && ! (bool) $existing['is_editable']) {
            return Result::fail('CONFIG_READONLY', 'gamification.config_readonly', 409, ['key' => $key]);
        }

        $type ??= ($existing['config_type'] ?? 'string');
        $stored = $this->encode($value, (string) $type);
        $now    = $this->clock->nowUtcString();

        try {
            if ($existing !== null) {
                $upd = [
                    'config_value' => $stored,
                    'config_type'  => $type,
                    'updated_by'   => $opts['updated_by'] ?? null,
                    'updated_at'   => $now,
                ];
                if (isset($opts['description'])) {
                    $upd['description'] = mb_substr((string) $opts['description'], 0, 255);
                }
                if (array_key_exists('is_editable', $opts)) {
                    $upd['is_editable'] = ! empty($opts['is_editable']) ? 1 : 0;
                }
                $this->db->table('gamification_config')->where('id', $existing['id'])->update($upd);

                return Result::ok(['key' => $key, 'updated' => true, 'value' => $this->cast($stored, (string) $type)]);
            }

            $id = Uuid::v7();
            $this->db->table('gamification_config')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'config_key'      => $key,
                'config_value'    => $stored,
                'config_type'     => $type,
                'description'     => isset($opts['description']) ? mb_substr((string) $opts['description'], 0, 255) : null,
                'is_editable'     => array_key_exists('is_editable', $opts) ? (! empty($opts['is_editable']) ? 1 : 0) : 1,
                'updated_by'      => $opts['updated_by'] ?? null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            return Result::created(['key' => $key, 'value' => $this->cast($stored, (string) $type)]);
        } catch (Throwable) {
            return Result::fail('CONFIG_SAVE_FAILED', 'gamification.config_save_failed', 500);
        }
    }

    // ------------------------------------------------------------------------

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'float'   => (float) $value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }

    private function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => (! empty($value) && $value !== 'false' && $value !== '0') ? '1' : '0',
            'json'    => is_string($value) ? $value : (json_encode($value) ?: 'null'),
            default   => (string) $value,
        };
    }
}
