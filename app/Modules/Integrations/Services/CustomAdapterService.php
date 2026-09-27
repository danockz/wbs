<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Integrations\Sdk\AdapterManifest;
use WBS\Integrations\Sdk\ContractTestSuite;
use WBS\Integrations\Sdk\CustomAdapterRegistry;
use WBS\Integrations\Sdk\ManifestSigner;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Custom-adapter registration, certification and publication (SRS FR-INT-013).
 *
 * Onboards a NON-conforming provider as a versioned, signed, reviewed module
 * WITHOUT touching core business modules or any group's config workflow. The
 * lifecycle mirrors the connector-profile certification path:
 *
 *   register  ─ store the adapter's declared+validated manifest (draft)
 *   contractTest ─ run the SDK ContractTestSuite; certified only when it passes;
 *                  on pass, SIGN the manifest (ManifestSigner) → contract_tested
 *   advance   ─ move contract_tested → security_review (reviewer gate)
 *   approve   ─ security_review → approved (checker ≠ submitter)
 *   activate  ─ approved → active AND PUBLISH the manifest into
 *               provider_adapter_catalog (type='custom_adapter') so the rest of
 *               the platform consumes it exactly like a built-in adapter
 *   revoke    ─ any → revoked (and deprecate the published catalogue row)
 *
 * Every step appends to `custom_adapter_reviews` (append-only trail). Only
 * allowlisted impl classes (CustomAdapterRegistry) can be tested/activated —
 * shipping+allowlisting the class is the reviewed act; this is not runtime code
 * injection.
 */
final class CustomAdapterService
{
    /** Ordered certification states. */
    private const FLOW = ['draft', 'contract_tested', 'security_review', 'approved', 'active'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly CustomAdapterRegistry $registry,
        private readonly ContractTestSuite $suite,
        private readonly ManifestSigner $signer,
    ) {
    }

    /**
     * Register a custom adapter from an allowlisted impl class. The manifest is
     * taken from the adapter itself (single source of truth) and validated.
     *
     * @param array<string,mixed> $data impl_class (req)
     */
    public function register(string $organizationId, string $submittedBy, array $data): Result
    {
        $implClass = trim((string) ($data['impl_class'] ?? ''));
        if ($implClass === '') {
            return Result::fail('MISSING_IMPL', 'integration.custom_impl_required', 422);
        }
        if (! $this->registry->has($implClass)) {
            return Result::fail('IMPL_NOT_ALLOWLISTED', 'integration.custom_impl_not_allowlisted', 422, ['impl_class' => $implClass]);
        }

        $manifest = $this->registry->get($implClass)->manifest();
        $errors   = $manifest->validate();
        if ($errors !== []) {
            return Result::fail('INVALID_MANIFEST', 'integration.custom_manifest_invalid', 422, ['errors' => $errors]);
        }

        // One registration per (org, code, version).
        $exists = $this->db->table('custom_adapters')
            ->where('organization_id', $organizationId)
            ->where('code', $manifest->code)
            ->where('version', $manifest->version)
            ->countAllResults() > 0;
        if ($exists) {
            return Result::fail('ALREADY_REGISTERED', 'integration.custom_already_registered', 409, [
                'code' => $manifest->code, 'version' => $manifest->version,
            ]);
        }

        $now = $this->clock->nowUtcMicro();
        $id  = Uuid::v7();
        $this->db->table('custom_adapters')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'code'            => $manifest->code,
            'version'         => $manifest->version,
            'category'        => $manifest->category,
            'family'          => $manifest->family,
            'display_name'    => $manifest->displayName,
            'impl_class'      => $implClass,
            'manifest'        => json_encode($manifest->toArray(), JSON_UNESCAPED_UNICODE),
            'capabilities'    => json_encode($manifest->capabilities, JSON_UNESCAPED_UNICODE),
            'certified'       => 0,
            'status'          => 'draft',
            'submitted_by'    => $submittedBy,
            'created_at'      => $now,
        ]);
        $this->appendReview($organizationId, $id, 'register', null, 'draft', $submittedBy, null, $now);

        return Result::created([
            'adapter_id' => $id,
            'code'       => $manifest->code,
            'version'    => $manifest->version,
            'status'     => 'draft',
        ]);
    }

    /**
     * Run the SDK contract-test suite. On pass, sign the manifest and move to
     * contract_tested. On fail, the adapter stays in draft with the report.
     *
     * @param array<string,array<string,mixed>> $sampleParams optional per-op sample params
     */
    public function contractTest(string $organizationId, string $adapterId, ?string $actorId = null, array $sampleParams = []): Result
    {
        $row = $this->find($organizationId, $adapterId);
        if ($row === null) {
            return Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND');
        }
        $implClass = (string) $row['impl_class'];
        if (! $this->registry->has($implClass)) {
            return Result::fail('IMPL_NOT_ALLOWLISTED', 'integration.custom_impl_not_allowlisted', 422, ['impl_class' => $implClass]);
        }

        $adapter = $this->registry->get($implClass);
        $report  = $this->suite->run($adapter, $sampleParams);
        $now     = $this->clock->nowUtcMicro();

        $update = [
            'contract_report' => json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
            'certified'       => $report->passed() ? 1 : 0,
            'updated_at'      => $now,
        ];

        $signature = null;
        if ($report->passed()) {
            $signature          = $this->signer->sign($adapter->manifest());
            $update['signature'] = $signature;
            $update['status']    = 'contract_tested';
        }
        $this->db->table('custom_adapters')->where('id', $adapterId)->update($update);
        $this->appendReview(
            $organizationId,
            $adapterId,
            'contract_test',
            (string) $row['status'],
            $report->passed() ? 'contract_tested' : (string) $row['status'],
            $actorId,
            $report->summary(),
            $now,
        );

        return Result::ok([
            'adapter_id' => $adapterId,
            'certified'  => $report->passed(),
            'status'     => $report->passed() ? 'contract_tested' : (string) $row['status'],
            'signature'  => $signature,
            'report'     => $report->toArray(),
        ]);
    }

    /** Advance contract_tested -> security_review (a reviewer picks it up). */
    public function advance(string $organizationId, string $adapterId, string $toStatus, ?string $actorId = null): Result
    {
        $row = $this->find($organizationId, $adapterId);
        if ($row === null) {
            return Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND');
        }
        $fromIdx = array_search($row['status'], self::FLOW, true);
        $toIdx   = array_search($toStatus, self::FLOW, true);
        if ($fromIdx === false || $toIdx === false || $toIdx !== $fromIdx + 1) {
            return Result::fail('BAD_TRANSITION', 'integration.custom_bad_transition', 409, ['from' => $row['status'], 'to' => $toStatus]);
        }
        // approve/activate have dedicated methods (SoD + publish); advance only
        // covers the intermediate review handoff.
        if (in_array($toStatus, ['approved', 'active'], true)) {
            return Result::fail('USE_DEDICATED_STEP', 'integration.custom_use_dedicated', 409, ['to' => $toStatus]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('custom_adapters')->where('id', $adapterId)->update([
            'status' => $toStatus, 'updated_at' => $now,
        ]);
        $this->appendReview($organizationId, $adapterId, 'advance', (string) $row['status'], $toStatus, $actorId, null, $now);

        return Result::ok(['adapter_id' => $adapterId, 'status' => $toStatus]);
    }

    /** Approve security_review -> approved. Checker MUST differ from submitter. */
    public function approve(string $organizationId, string $adapterId, string $actorId, ?string $note = null): Result
    {
        $row = $this->find($organizationId, $adapterId);
        if ($row === null) {
            return Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND');
        }
        if ($row['status'] !== 'security_review') {
            return Result::fail('BAD_TRANSITION', 'integration.custom_bad_transition', 409, ['from' => $row['status'], 'to' => 'approved']);
        }
        if ((string) $row['submitted_by'] === $actorId) {
            return Result::denied('integration.custom_self_approval', 'SOD_SELF_APPROVAL');
        }
        if ((int) $row['certified'] !== 1) {
            return Result::fail('NOT_CERTIFIED', 'integration.custom_not_certified', 409);
        }
        // Signature must still verify against the stored manifest.
        if (! $this->signatureValid($row)) {
            return Result::fail('BAD_SIGNATURE', 'integration.custom_bad_signature', 409);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('custom_adapters')->where('id', $adapterId)->update([
            'status'     => 'approved',
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        $this->appendReview($organizationId, $adapterId, 'approve', 'security_review', 'approved', $actorId, $note, $now);

        return Result::ok(['adapter_id' => $adapterId, 'status' => 'approved']);
    }

    /**
     * Activate approved -> active AND publish the manifest to the shared
     * provider_adapter_catalog so the whole platform consumes it like a built-in.
     */
    public function activate(string $organizationId, string $adapterId, string $actorId, ?string $note = null): Result
    {
        $row = $this->find($organizationId, $adapterId);
        if ($row === null) {
            return Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND');
        }
        if ($row['status'] !== 'approved') {
            return Result::fail('BAD_TRANSITION', 'integration.custom_bad_transition', 409, ['from' => $row['status'], 'to' => 'active']);
        }
        if (! $this->signatureValid($row)) {
            return Result::fail('BAD_SIGNATURE', 'integration.custom_bad_signature', 409);
        }

        $manifest    = AdapterManifest::fromArray(json_decode((string) $row['manifest'], true) ?: []);
        $now         = $this->clock->nowUtcMicro();
        $catalogId   = $this->publishToCatalog($manifest, (string) $row['signature']);

        $this->db->table('custom_adapters')->where('id', $adapterId)->update([
            'status'     => 'active',
            'catalog_id' => $catalogId,
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        $this->appendReview($organizationId, $adapterId, 'activate', 'approved', 'active', $actorId, $note, $now);

        return Result::ok([
            'adapter_id' => $adapterId,
            'status'     => 'active',
            'catalog_id' => $catalogId,
            'code'       => $manifest->code,
            'version'    => $manifest->version,
        ]);
    }

    public function revoke(string $organizationId, string $adapterId, string $actorId, ?string $note = null): Result
    {
        $row = $this->find($organizationId, $adapterId);
        if ($row === null) {
            return Result::notFound('integration.custom_not_found', 'CUSTOM_ADAPTER_NOT_FOUND');
        }
        $now = $this->clock->nowUtcMicro();
        $this->db->table('custom_adapters')->where('id', $adapterId)->update([
            'status'     => 'revoked',
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        // Deprecate the published catalogue row (never hard-delete history).
        if (! empty($row['catalog_id'])) {
            $this->db->table('provider_adapter_catalog')->where('id', $row['catalog_id'])->update(['status' => 'revoked']);
        }
        $this->appendReview($organizationId, $adapterId, 'revoke', (string) $row['status'], 'revoked', $actorId, $note, $now);

        return Result::ok(['adapter_id' => $adapterId, 'status' => 'revoked']);
    }

    /** @return list<array<string,mixed>> */
    public function listForOrg(string $organizationId, ?string $status = null): array
    {
        $q = $this->db->table('custom_adapters')->where('organization_id', $organizationId);
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(string $organizationId, string $adapterId): ?array
    {
        return $this->db->table('custom_adapters')
            ->where('organization_id', $organizationId)
            ->where('id', $adapterId)
            ->get()->getRowArray() ?: null;
    }

    // ---- internals ---------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function signatureValid(array $row): bool
    {
        $sig = (string) ($row['signature'] ?? '');
        if ($sig === '') {
            return false;
        }
        $manifest = AdapterManifest::fromArray(json_decode((string) $row['manifest'], true) ?: []);

        return $this->signer->verify($manifest, $sig);
    }

    /** Publish (or upsert) the manifest into the shared adapter catalogue. */
    private function publishToCatalog(AdapterManifest $manifest, string $signature): string
    {
        $now = $this->clock->nowUtcString();
        $row = [
            'code'              => $manifest->code,
            'version'           => $manifest->version,
            'category'          => $manifest->category,
            'family'            => $manifest->family,
            'display_name'      => $manifest->displayName,
            'capabilities'      => json_encode($manifest->capabilities, JSON_UNESCAPED_UNICODE),
            'credential_fields' => json_encode($manifest->credentialFields, JSON_UNESCAPED_UNICODE),
            'config_schema'     => json_encode($manifest->configSchema + ['approved_hosts' => $manifest->approvedHosts, 'signature' => $signature], JSON_UNESCAPED_UNICODE),
            'webhook_verify'    => in_array('verifyWebhook', $manifest->capabilities, true) ? 'hmac_sha256' : 'none',
            'type'              => 'custom_adapter',
            'status'            => 'active',
            'docs_ref'          => $manifest->docsRef,
        ];

        $existing = $this->db->table('provider_adapter_catalog')
            ->where('code', $manifest->code)->where('version', $manifest->version)
            ->get()->getRowArray();
        if ($existing !== null) {
            $this->db->table('provider_adapter_catalog')->where('id', $existing['id'])->update($row);

            return (string) $existing['id'];
        }
        $row['id']         = Uuid::v7();
        $row['created_at'] = $now;
        $this->db->table('provider_adapter_catalog')->insert($row);

        return $row['id'];
    }

    private function appendReview(
        string $organizationId,
        string $adapterId,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $actorId,
        ?string $note,
        string $at,
    ): void {
        $this->db->table('custom_adapter_reviews')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'adapter_id'      => $adapterId,
            'action'          => $action,
            'from_status'     => $fromStatus,
            'to_status'       => $toStatus,
            'actor_id'        => $actorId,
            'note'            => $note,
            'created_at'      => $at,
        ]);
    }
}
