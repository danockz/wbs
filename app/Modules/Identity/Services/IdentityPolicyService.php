<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Per-jurisdiction identity policy (SRS FR-ID-002).
 *
 * Resolves the effective uniqueness / minor-gating policy for a country code,
 * falling back to the organization's "*" default and finally to safe built-in
 * defaults when nothing is configured. Also provides CRUD for administrators to
 * configure country-specific rules (min age, default phone region, uniqueness
 * switches, minor allowance).
 */
final class IdentityPolicyService
{
    /** Safe built-in defaults when no row exists for org/country or "*". */
    private const DEFAULTS = [
        'min_age'              => 13,
        'phone_default_region' => null,
        'require_email_unique' => 1,
        'require_phone_unique' => 1,
        'allow_minor'          => 0,
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Effective policy for a jurisdiction: exact country row, else the org "*"
     * default row, else built-in defaults.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $organizationId, ?string $countryCode = null): array
    {
        $country = strtoupper(trim((string) $countryCode));

        if ($country !== '' && $country !== '*') {
            $row = $this->db->table('identity_policies')
                ->where('organization_id', $organizationId)->where('country_code', $country)
                ->get()->getRowArray();
            if ($row !== null) {
                return $this->cast($row);
            }
        }

        $default = $this->db->table('identity_policies')
            ->where('organization_id', $organizationId)->where('country_code', '*')
            ->get()->getRowArray();
        if ($default !== null) {
            return $this->cast($default);
        }

        return self::DEFAULTS + ['country_code' => $country !== '' ? $country : '*'];
    }

    /**
     * Create or update the policy for a country code (upsert on org+country).
     *
     * @param array<string,mixed> $data
     */
    public function upsert(string $organizationId, string $countryCode, array $data): Result
    {
        $country = strtoupper(trim($countryCode)) ?: '*';
        $now     = $this->clock->nowUtcString();

        $fields = [
            'min_age'              => isset($data['min_age']) ? max(0, (int) $data['min_age']) : self::DEFAULTS['min_age'],
            'phone_default_region' => isset($data['phone_default_region']) && $data['phone_default_region'] !== ''
                ? strtoupper((string) $data['phone_default_region'])
                : null,
            'require_email_unique' => ! empty($data['require_email_unique']) ? 1 : 0,
            'require_phone_unique' => ! empty($data['require_phone_unique']) ? 1 : 0,
            'allow_minor'          => ! empty($data['allow_minor']) ? 1 : 0,
            'notes'                => isset($data['notes']) ? (string) $data['notes'] : null,
            'updated_at'           => $now,
        ];

        $existing = $this->db->table('identity_policies')
            ->where('organization_id', $organizationId)->where('country_code', $country)
            ->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('identity_policies')->where('id', $existing['id'])->update($fields);

            return Result::ok(['policy_id' => $existing['id'], 'country_code' => $country] + $fields);
        }

        $id = Uuid::v7();
        $this->db->table('identity_policies')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'country_code'    => $country,
            'created_at'      => $now,
        ] + $fields);

        return Result::created(['policy_id' => $id, 'country_code' => $country] + $fields);
    }

    /** @return list<array<string,mixed>> */
    public function listForOrg(string $organizationId): array
    {
        $rows = $this->db->table('identity_policies')
            ->where('organization_id', $organizationId)
            ->orderBy('country_code', 'ASC')
            ->get()->getResultArray();

        return array_map([$this, 'cast'], $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cast(array $row): array
    {
        $row['min_age']              = (int) $row['min_age'];
        $row['require_email_unique'] = (bool) $row['require_email_unique'];
        $row['require_phone_unique'] = (bool) $row['require_phone_unique'];
        $row['allow_minor']          = (bool) $row['allow_minor'];

        return $row;
    }
}
