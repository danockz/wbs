<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Integrations\Canonical\ProfileValidator;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * No-code connector profile lifecycle (SRS FR-INT-002/004).
 *
 * Every profile is validated server-side by the ProfileValidator before it can
 * be stored, and follows the certification path
 * draft -> sandbox_verified -> security_review -> finance_review -> approved ->
 * active. A profile change creates a NEW version and never alters historical
 * transactions. Only organization administrators reach approval steps (enforced
 * by the controller/authorization layer, not here).
 */
final class ProfileService
{
    /** Ordered certification states. */
    private const FLOW = [
        'draft', 'sandbox_verified', 'security_review', 'finance_review', 'approved', 'active',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ProfileValidator $validator,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, string $ownerId, array $data): Result
    {
        $validation = $this->validator->validate($data);
        if (! $validation->ok) {
            return $validation;
        }

        $version = (int) ($this->db->table('connector_profiles')
            ->selectMax('version')
            ->where('organization_id', $organizationId)->where('code', $data['code'] ?? '')
            ->get()->getRowArray()['version'] ?? 0) + 1;

        $id = Uuid::v7();
        $this->db->table('connector_profiles')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'code'             => $data['code'],
            'version'          => $version,
            'family'           => $data['family'],
            'canonical_op'     => $data['canonical_op'],
            'http_method'      => $data['http_method'] ?? null,
            'approved_host'    => $data['approved_host'],
            'request_mapping'  => isset($data['request_mapping']) ? json_encode($data['request_mapping'], JSON_UNESCAPED_UNICODE) : null,
            'response_mapping' => isset($data['response_mapping']) ? json_encode($data['response_mapping'], JSON_UNESCAPED_UNICODE) : null,
            'status_mapping'   => isset($data['status_mapping']) ? json_encode($data['status_mapping'], JSON_UNESCAPED_UNICODE) : null,
            'signature_algo'   => $data['signature_algo'] ?? null,
            'owner_id'         => $ownerId,
            'status'           => 'draft',
            'created_at'       => $this->clock->nowUtcString(),
        ]);

        return Result::created(['profile_id' => $id, 'version' => $version, 'status' => 'draft']);
    }

    /** Ordered certification states, exposed for the console's "next step" control. */
    public const CERT_FLOW = self::FLOW;

    /**
     * List an organization's connector profiles for the authoring console,
     * newest first. Resource-light: one bounded read; the (large) JSON mapping
     * columns are omitted from the list — the console shows lifecycle + routing,
     * not the full mapping blob. Each row is annotated with its `next_status`
     * (the single legal forward transition) so the view needs no flow logic.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, int $limit = 200): array
    {
        $rows = $this->db->table('connector_profiles')
            ->select('id, code, version, family, canonical_op, http_method, approved_host, signature_algo, status, created_at, updated_at')
            ->where('organization_id', $organizationId)
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min(500, $limit)))
            ->get()->getResultArray();

        foreach ($rows as &$r) {
            $idx = array_search($r['status'] ?? '', self::FLOW, true);
            $r['next_status'] = ($idx !== false && $idx < count(self::FLOW) - 1) ? self::FLOW[$idx + 1] : null;
            $r['is_terminal'] = in_array($r['status'] ?? '', ['revoked', 'deprecated'], true);
        }
        unset($r);

        return $rows;
    }

    /** Advance a profile one step along the certification flow. */
    public function advance(string $profileId, string $toStatus): Result
    {
        $p = $this->find($profileId);
        if ($p === null) {
            return Result::notFound('integration.profile_not_found', 'PROFILE_NOT_FOUND');
        }
        $fromIdx = array_search($p['status'], self::FLOW, true);
        $toIdx   = array_search($toStatus, self::FLOW, true);
        if ($fromIdx === false || $toIdx === false || $toIdx !== $fromIdx + 1) {
            return Result::fail('BAD_TRANSITION', 'integration.bad_transition', 409, ['from' => $p['status'], 'to' => $toStatus]);
        }
        $this->db->table('connector_profiles')->where('id', $profileId)->update([
            'status'     => $toStatus,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['profile_id' => $profileId, 'status' => $toStatus]);
    }

    public function revoke(string $profileId): Result
    {
        $this->db->table('connector_profiles')->where('id', $profileId)->update([
            'status'     => 'revoked',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['profile_id' => $profileId, 'status' => 'revoked']);
    }

    /** @return array<string,mixed>|null */
    private function find(string $profileId): ?array
    {
        return $this->db->table('connector_profiles')->where('id', $profileId)->get()->getRowArray() ?: null;
    }
}
