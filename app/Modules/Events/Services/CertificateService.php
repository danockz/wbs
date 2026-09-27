<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Messaging\QueueService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\ImageDataUri;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event certificates (SRS FR-EVT-013).
 *
 *  - Certificates are only issued when an eligible attendance condition holds;
 *    a zero-attendance event yields NO certificates (aligns with FR-EVT-011).
 *  - Issuance is a two-phase, background-friendly flow: `requestForEvent()`
 *    creates `pending` rows for eligible attendees and enqueues a render job
 *    per certificate; the worker calls `markRendered()` then `issue()`.
 *  - Each certificate carries a public opaque `verification_id` (embedded in a
 *    QR) so a certificate can be verified without exposing internal ids, and a
 *    revoke path (`revoke()`) for the issue/revoke state machine.
 */
final class CertificateService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly QueueService $queue,
    ) {
    }

    /**
     * Create pending certificates for every eligible (present) attendee of an
     * event and enqueue a render job for each. Idempotent per (event,user).
     * Returns the count queued. Skips entirely for zero-attendance events.
     */
    public function requestForEvent(string $organizationId, string $eventId, ?string $templateId = null): Result
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        $attendees = $this->db->table('event_attendance')
            ->select('user_id')
            ->where('event_id', $eventId)
            ->where('status', 'present')
            ->get()->getResultArray();

        // FR-EVT-013 / FR-EVT-011: no certificate for a zero-attendance event.
        if ($attendees === []) {
            return Result::ok([
                'event_id' => $eventId,
                'queued'   => 0,
                'reason'   => 'no_attendance',
            ]);
        }

        // Resolve the active template (explicit > group → ancestors → org default).
        $template = $this->resolveTemplate(
            $organizationId,
            $templateId,
            $event['type'] ?? null,
            isset($event['group_id']) ? (string) $event['group_id'] : null,
        );

        $now    = $this->clock->nowUtcMicro();
        $queued = 0;
        foreach ($attendees as $a) {
            $userId = (string) $a['user_id'];
            $existing = $this->db->table('event_certificates')
                ->where('event_id', $eventId)->where('user_id', $userId)
                ->get()->getRowArray();
            if ($existing !== null) {
                continue; // idempotent
            }

            $certId = Uuid::v7();
            $verify = bin2hex(random_bytes(16)); // 32 hex chars, public opaque id
            try {
                $this->db->table('event_certificates')->insert([
                    'id'               => $certId,
                    'organization_id'  => $organizationId,
                    'event_id'         => $eventId,
                    'user_id'          => $userId,
                    'template_id'      => $template['id'] ?? null,
                    'template_version' => $template['version'] ?? null,
                    'verification_id'  => $verify,
                    'status'           => 'pending',
                    'created_at'       => $now,
                ]);
            } catch (Throwable) {
                continue; // race on UNIQUE(event,user) -> already requested
            }

            // Background render job (worker renders + calls markRendered/issue).
            $this->queue->enqueue(
                'event.certificate.render',
                ['certificate_id' => $certId, 'event_id' => $eventId, 'user_id' => $userId],
                'certificates',
                'cert:' . $certId,
            );
            $queued++;
        }

        return Result::ok(['event_id' => $eventId, 'queued' => $queued]);
    }

    /** Worker callback: record the rendered artifact reference. */
    public function markRendered(string $certificateId, string $renderRef): Result
    {
        $cert = $this->db->table('event_certificates')->where('id', $certificateId)->get()->getRowArray();
        if ($cert === null) {
            return Result::notFound('certificate.not_found', 'CERTIFICATE_NOT_FOUND');
        }
        $this->db->table('event_certificates')->where('id', $certificateId)->update([
            'render_ref' => $renderRef,
        ]);

        return Result::ok(['certificate_id' => $certificateId, 'render_ref' => $renderRef]);
    }

    /**
     * Resolve an ISSUED certificate's rendered PDF for its OWNER to download.
     *
     * Self-scoped authorization: the certificate must belong to $userId, be in
     * `issued` state, and have a rendered artifact. Returns the absolute file
     * path + a suggested download filename (the controller streams it). A
     * mismatched owner gets a 404 (not 403) so the endpoint never confirms the
     * existence of another member's certificate.
     */
    public function downloadOwn(string $certificateId, string $userId, string $storageDir): Result
    {
        if ($userId === '') {
            return Result::fail('NOT_AUTHENTICATED', 'certificate.no_user', 401);
        }

        $cert = $this->db->table('event_certificates')
            ->where('id', $certificateId)->get()->getRowArray();

        // Same 404 for "missing" and "not yours" — no existence disclosure.
        if ($cert === null || $cert['user_id'] !== $userId) {
            return Result::notFound('certificate.not_found', 'CERTIFICATE_NOT_FOUND');
        }
        if ($cert['status'] !== 'issued') {
            return Result::fail('NOT_ISSUED', 'certificate.not_issued', 409, [], ['status' => $cert['status']]);
        }
        if (empty($cert['render_ref'])) {
            return Result::fail('NOT_RENDERED', 'certificate.not_rendered', 409);
        }

        // render_ref is a path RELATIVE to the storage dir (e.g.
        // "certificates/{id}.pdf"); reject anything that escapes it.
        $ref = ltrim((string) $cert['render_ref'], '/');
        if (str_contains($ref, '..')) {
            return Result::fail('BAD_REF', 'certificate.bad_ref', 422);
        }
        $path = rtrim($storageDir, '/') . '/' . $ref;
        if (! is_file($path)) {
            return Result::fail('ARTIFACT_MISSING', 'certificate.artifact_missing', 404);
        }

        return Result::ok([
            'path'     => $path,
            'filename' => 'certificate-' . substr($certificateId, 0, 8) . '.pdf',
        ]);
    }

    /**
     * Issue a rendered certificate (signed by the configured/approved signer).
     * Moves pending -> issued.
     */
    public function issue(string $certificateId, ?string $signedBy = null, ?string $signatureRef = null): Result
    {
        $cert = $this->db->table('event_certificates')->where('id', $certificateId)->get()->getRowArray();
        if ($cert === null) {
            return Result::notFound('certificate.not_found', 'CERTIFICATE_NOT_FOUND');
        }
        if ($cert['status'] === 'issued') {
            return Result::ok(['certificate_id' => $certificateId, 'event_id' => $cert['event_id'], 'status' => 'issued'], 200, ['deduplicated' => true]);
        }
        if ($cert['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'certificate.bad_state', 409, ['status' => $cert['status']]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_certificates')->where('id', $certificateId)->update([
            'status'        => 'issued',
            'signed_by'     => $signedBy,
            'signature_ref' => $signatureRef,
            'issued_at'     => $now,
        ]);

        return Result::ok([
            'certificate_id' => $certificateId,
            'event_id'       => $cert['event_id'],
            'status'         => 'issued',
            'verification_id' => $cert['verification_id'],
        ]);
    }

    /** Revoke an issued certificate. */
    public function revoke(string $certificateId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'certificate.reason_required', 422);
        }
        $cert = $this->db->table('event_certificates')->where('id', $certificateId)->get()->getRowArray();
        if ($cert === null) {
            return Result::notFound('certificate.not_found', 'CERTIFICATE_NOT_FOUND');
        }
        $this->db->table('event_certificates')->where('id', $certificateId)->update([
            'status'        => 'revoked',
            'revoke_reason' => $reason,
            'revoked_at'    => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['certificate_id' => $certificateId, 'event_id' => $cert['event_id'], 'status' => 'revoked']);
    }

    /**
     * Public verification by the opaque verification id (from the QR). Returns
     * only non-identifying status fields — never internal ids or user PII.
     */
    public function verify(string $verificationId): Result
    {
        $cert = $this->db->table('event_certificates')
            ->select('verification_id, event_id, status, issued_at, revoked_at')
            ->where('verification_id', $verificationId)
            ->get()->getRowArray();
        if ($cert === null) {
            return Result::notFound('certificate.not_found', 'CERTIFICATE_NOT_FOUND');
        }

        return Result::ok([
            'verification_id' => $cert['verification_id'],
            'status'          => $cert['status'],
            'valid'           => $cert['status'] === 'issued',
            'issued_at'       => $cert['issued_at'],
            'revoked'         => $cert['status'] === 'revoked',
        ]);
    }

    /**
     * List an organization's certificate templates (newest version first).
     *
     * @return list<array<string,mixed>>
     */
    public function listTemplates(string $organizationId, ?string $groupId = null): array
    {
        $q = $this->db->table('certificate_templates')
            ->select('id, group_id, name, event_type, version, signer_role, status, created_at')
            ->where('organization_id', $organizationId);
        if ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }

        return $q->orderBy('name', 'ASC')
            ->orderBy('version', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function findTemplate(string $organizationId, string $templateId): ?array
    {
        $row = $this->db->table('certificate_templates')->where('id', $templateId)->get()->getRowArray();
        if ($row === null || (string) ($row['organization_id'] ?? '') !== $organizationId) {
            return null;
        }

        return $row;
    }

    /**
     * List the certificates requested/issued for an event (newest first). Read
     * side of the event certificates console.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEvent(string $eventId): array
    {
        return $this->db->table('event_certificates')
            ->select('id, user_id, template_id, template_version, verification_id, status, issued_at, revoked_at, revoke_reason, render_ref')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /** @param array<string,mixed> $data */
    public function createTemplate(string $organizationId, array $data): Result
    {
        if (trim((string) ($data['name'] ?? '')) === '' || trim((string) ($data['body_template'] ?? '')) === '') {
            return Result::fail('MISSING_FIELDS', 'certificate.template_missing_fields', 422);
        }

        // Optional design assets: logo + background. Dompdf cannot fetch remote
        // URLs (loading is disabled for safety), so each is accepted only as a
        // `data:` URI and re-validated/normalized here before storage.
        $assets = ['logo_data_uri' => null, 'background_data_uri' => null];
        foreach (['logo' => 'logo_data_uri', 'background' => 'background_data_uri'] as $inKey => $col) {
            $raw = trim((string) ($data[$col] ?? $data[$inKey] ?? ''));
            if ($raw === '') {
                continue;
            }
            $res = ImageDataUri::fromDataUri($raw);
            if (! ($res['ok'] ?? false)) {
                return Result::fail(
                    (string) ($res['error'] ?? 'IMAGE_INVALID'),
                    'certificate.' . $inKey . '_' . (string) ($res['message'] ?? 'image.invalid'),
                    422,
                    $res['context'] ?? [],
                );
            }
            $assets[$col] = $res['data_uri'];
        }

        $groupId = trim((string) ($data['group_id'] ?? ''));
        $eventType = trim((string) ($data['event_type'] ?? ''));
        $id = Uuid::v7();
        try {
            $this->db->table('certificate_templates')->insert([
                'id'                  => $id,
                'organization_id'     => $organizationId,
                'group_id'            => $groupId !== '' ? $groupId : null,
                'event_type'          => $eventType !== '' ? $eventType : null,
                'name'                => $data['name'],
                'version'             => (int) ($data['version'] ?? 1),
                'body_template'       => $data['body_template'],
                'logo_data_uri'       => $assets['logo_data_uri'],
                'background_data_uri' => $assets['background_data_uri'],
                'signer_role'         => $data['signer_role'] ?? null,
                'status'              => 'active',
                'created_at'          => $this->clock->nowUtcString(),
            ]);
        } catch (Throwable) {
            return Result::fail('TEMPLATE_EXISTS', 'certificate.template_exists', 409);
        }

        return Result::created(['template_id' => $id]);
    }

    /**
     * Edit = insert the next version of the same lineage. Prior versions stay
     * untouched (issued certificates pin template_id + template_version).
     *
     * @param array<string,mixed> $data
     */
    public function reviseTemplate(string $organizationId, string $templateId, array $data): Result
    {
        $current = $this->findTemplate($organizationId, $templateId);
        if ($current === null) {
            return Result::notFound('certificate.template_not_found', 'TEMPLATE_NOT_FOUND');
        }
        if ((string) ($current['status'] ?? '') === 'retired') {
            return Result::fail('BAD_STATE', 'certificate.template_retired', 409);
        }
        $name = trim((string) ($data['name'] ?? $current['name'] ?? ''));
        $body = (string) ($data['body_template'] ?? $current['body_template'] ?? '');
        if ($name === '' || trim($body) === '') {
            return Result::fail('MISSING_FIELDS', 'certificate.template_missing_fields', 422);
        }
        $assets = $this->parseAssets($data, $current);
        if ($assets instanceof Result) {
            return $assets;
        }
        $eventType = array_key_exists('event_type', $data)
            ? (trim((string) $data['event_type']) !== '' ? trim((string) $data['event_type']) : null)
            : ($current['event_type'] ?? null);
        $signer = array_key_exists('signer_role', $data)
            ? (trim((string) $data['signer_role']) !== '' ? trim((string) $data['signer_role']) : null)
            : ($current['signer_role'] ?? null);
        $nextVersion = ((int) ($current['version'] ?? 1)) + 1;
        $id = Uuid::v7();
        $this->db->table('certificate_templates')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'group_id'            => $current['group_id'] ?? null,
            'event_type'          => $eventType,
            'name'                => $name,
            'version'             => $nextVersion,
            'body_template'       => $body,
            'logo_data_uri'       => $assets['logo_data_uri'],
            'background_data_uri' => $assets['background_data_uri'],
            'signer_role'         => $signer,
            'status'              => 'active',
            'created_at'          => $this->clock->nowUtcString(),
        ]);

        return Result::created([
            'template_id' => $id,
            'version'     => $nextVersion,
            'supersedes'  => $templateId,
        ]);
    }

    /** Retire a version. Never hard-deletes — issued certificates keep their pin. */
    public function retireTemplate(string $organizationId, string $templateId): Result
    {
        $current = $this->findTemplate($organizationId, $templateId);
        if ($current === null) {
            return Result::notFound('certificate.template_not_found', 'TEMPLATE_NOT_FOUND');
        }
        if ((string) ($current['status'] ?? '') === 'retired') {
            return Result::ok(['template_id' => $templateId, 'status' => 'retired', 'deduplicated' => true]);
        }
        $this->db->table('certificate_templates')->where('id', $templateId)->update(['status' => 'retired']);

        return Result::ok(['template_id' => $templateId, 'status' => 'retired']);
    }

    /**
     * In-browser HTML preview with SAMPLE placeholder data. Nothing is issued
     * and no PDF is produced.
     *
     * @return Result data.html
     */
    public function previewHtml(string $organizationId, string $templateId): Result
    {
        $row = $this->findTemplate($organizationId, $templateId);
        if ($row === null) {
            return Result::notFound('certificate.template_not_found', 'TEMPLATE_NOT_FOUND');
        }
        $sample = [
            'member.preferred_name' => 'Ada Mensah',
            'member.first_name'     => 'Ada',
            'event.title'           => 'Sample Gathering',
            'event.start_local'     => '2026-10-04 09:00:00',
            'org.name'              => 'Sample Organisation',
        ];
        $body = CertificateRenderer::substitute((string) ($row['body_template'] ?? ''), $sample);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>Certificate preview</title>'
            . '<style>'
            . 'body{margin:0;background:#0b1120;font-family:system-ui,sans-serif}'
            . '.shell{max-width:900px;margin:24px auto;padding:28px;background:#fff;color:#1a1a1a;min-height:480px}'
            . '.banner{max-width:900px;margin:16px auto 0;color:#94a3b8;font-size:.85rem;text-align:center}'
            . '</style></head><body>'
            . '<p class="banner">Preview — sample data, not issued.</p>'
            . '<div class="shell">' . $body . '</div>'
            . '</body></html>';

        return Result::ok([
            'template_id' => $templateId,
            'html'        => $html,
            'version'     => (int) ($row['version'] ?? 1),
            'name'        => (string) ($row['name'] ?? ''),
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $fallback
     * @return array{logo_data_uri:?string,background_data_uri:?string}|Result
     */
    private function parseAssets(array $data, ?array $fallback = null): array|Result
    {
        $assets = [
            'logo_data_uri'       => $fallback['logo_data_uri'] ?? null,
            'background_data_uri' => $fallback['background_data_uri'] ?? null,
        ];
        foreach (['logo' => 'logo_data_uri', 'background' => 'background_data_uri'] as $inKey => $col) {
            $raw = trim((string) ($data[$col] ?? $data[$inKey] ?? ''));
            if ($raw === '') {
                continue;
            }
            $res = ImageDataUri::fromDataUri($raw);
            if (! ($res['ok'] ?? false)) {
                return Result::fail(
                    (string) ($res['error'] ?? 'IMAGE_INVALID'),
                    'certificate.' . $inKey . '_' . (string) ($res['message'] ?? 'image.invalid'),
                    422,
                    $res['context'] ?? [],
                );
            }
            $assets[$col] = $res['data_uri'];
        }

        return $assets;
    }

    /**
     * Walk the event's group → ancestors → org default. At each level prefer an
     * event_type match, then a type-less default. Explicit id always wins.
     *
     * @return array<string,mixed>|null
     */
    private function resolveTemplate(string $organizationId, ?string $templateId, ?string $eventType, ?string $groupId = null): ?array
    {
        if ($templateId !== null && $templateId !== '') {
            return $this->db->table('certificate_templates')->where('id', $templateId)->get()->getRowArray() ?: null;
        }
        $chain = [];
        if ($groupId !== null && $groupId !== '') {
            $chain[] = $groupId;
            $anc = $this->db->table('group_closure')
                ->select('ancestor_id, distance')
                ->where('descendant_id', $groupId)
                ->where('distance >', 0)
                ->orderBy('distance', 'ASC')
                ->get()->getResultArray();
            foreach ($anc as $a) {
                $id = (string) ($a['ancestor_id'] ?? '');
                if ($id !== '') {
                    $chain[] = $id;
                }
            }
        }
        foreach ($chain as $gid) {
            $hit = $this->activeAt($organizationId, $gid, $eventType)
                ?? $this->activeAt($organizationId, $gid, null);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->activeAt($organizationId, null, $eventType)
            ?? $this->activeAt($organizationId, null, null);
    }

    /** @return array<string,mixed>|null */
    private function activeAt(string $organizationId, ?string $groupId, ?string $eventType): ?array
    {
        $q = $this->db->table('certificate_templates')
            ->where('organization_id', $organizationId)
            ->where('status', 'active');
        if ($groupId === null || $groupId === '') {
            $q->where('group_id', null);
        } else {
            $q->where('group_id', $groupId);
        }
        if ($eventType === null || $eventType === '') {
            $q->where('event_type', null);
        } else {
            $q->where('event_type', $eventType);
        }

        return $q->orderBy('version', 'DESC')->get()->getRowArray() ?: null;
    }
}
