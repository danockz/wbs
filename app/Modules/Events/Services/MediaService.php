<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Messaging\QueueService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event media (SRS FR-EVT-015).
 *
 * The binary lives in an access-controlled object store; this service records
 * only an opaque reference plus governance metadata. On registration a media
 * row starts scan_state=pending / review_state=pending and a malware-scan +
 * EXIF-strip job is enqueued. GPS/EXIF is stripped by default. Nothing becomes
 * publicly visible until it is BOTH clean and approved (enforced in
 * publicMedia()).
 */
final class MediaService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly QueueService $queue,
    ) {
    }

    /**
     * Register an uploaded object for review. Requires an upload-authorized
     * actor (route is permission-gated) and an opaque object reference.
     *
     * @param array<string,mixed> $data object_ref, source_sha256, mime_type, byte_size,
     *                                   caption, alt_text, consent_basis, visibility
     */
    public function register(string $organizationId, string $eventId, string $uploadedBy, array $data): Result
    {
        if ($uploadedBy === '') {
            return Result::fail('UPLOADER_REQUIRED', 'media.uploader_required', 422);
        }
        if (trim((string) ($data['object_ref'] ?? '')) === '') {
            return Result::fail('OBJECT_REF_REQUIRED', 'media.object_ref_required', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('event_media')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'uploaded_by'     => $uploadedBy,
            'object_ref'      => $data['object_ref'],
            'source_sha256'   => $data['source_sha256'] ?? null,
            'mime_type'       => $data['mime_type'] ?? null,
            'byte_size'       => isset($data['byte_size']) ? (int) $data['byte_size'] : null,
            'caption'         => $data['caption'] ?? null,
            'alt_text'        => $data['alt_text'] ?? null,
            'exif_stripped'   => 1, // default: GPS/EXIF stripped
            'scan_state'      => 'pending',
            'review_state'    => 'pending',
            'consent_basis'   => $data['consent_basis'] ?? null,
            // Never public on registration, regardless of requested visibility.
            'visibility'      => 'private',
            'created_at'      => $now,
        ]);

        // Malware scan + EXIF/GPS strip pipeline.
        $this->queue->enqueue(
            'event.media.scan',
            ['media_id' => $id, 'event_id' => $eventId, 'object_ref' => $data['object_ref']],
            'media',
            'media-scan:' . $id,
        );

        return Result::created(['media_id' => $id, 'scan_state' => 'pending', 'review_state' => 'pending']);
    }

    /** Worker callback: record the malware-scan verdict. */
    public function recordScan(string $mediaId, string $verdict): Result
    {
        $verdict = in_array($verdict, ['clean', 'infected', 'error'], true) ? $verdict : 'error';
        $media   = $this->db->table('event_media')->where('id', $mediaId)->get()->getRowArray();
        if ($media === null) {
            return Result::notFound('media.not_found', 'MEDIA_NOT_FOUND');
        }
        $update = ['scan_state' => $verdict, 'updated_at' => $this->clock->nowUtcMicro()];
        // An infected file is auto-rejected regardless of review.
        if ($verdict === 'infected') {
            $update['review_state'] = 'rejected';
            $update['visibility']   = 'private';
        }
        $this->db->table('event_media')->where('id', $mediaId)->update($update);

        return Result::ok(['media_id' => $mediaId, 'scan_state' => $verdict]);
    }

    /**
     * Content-review decision. Approval can only take effect once the file has
     * passed the malware scan; requested visibility is applied on approval.
     */
    public function review(string $mediaId, string $decision, ?string $visibility = null): Result
    {
        $decision = $decision === 'approve' ? 'approved' : 'rejected';
        $media    = $this->db->table('event_media')->where('id', $mediaId)->get()->getRowArray();
        if ($media === null) {
            return Result::notFound('media.not_found', 'MEDIA_NOT_FOUND');
        }
        if ($decision === 'approved' && $media['scan_state'] !== 'clean') {
            return Result::fail('SCAN_NOT_CLEAN', 'media.scan_not_clean', 409, ['scan_state' => $media['scan_state']]);
        }

        $update = ['review_state' => $decision, 'updated_at' => $this->clock->nowUtcMicro()];
        if ($decision === 'approved') {
            $update['visibility'] = in_array($visibility ?? 'group', ['private', 'group', 'public'], true) ? $visibility : 'group';
        } else {
            $update['visibility'] = 'private';
        }
        $this->db->table('event_media')->where('id', $mediaId)->update($update);

        return Result::ok(['media_id' => $mediaId, 'event_id' => $media['event_id'], 'review_state' => $decision, 'visibility' => $update['visibility']]);
    }

    /**
     * Publicly visible media for an event: only clean + approved + public.
     *
     * @return list<array<string,mixed>>
     */
    public function publicMedia(string $eventId): array
    {
        return $this->db->table('event_media')
            ->select('id, object_ref, caption, alt_text, mime_type, created_at')
            ->where('event_id', $eventId)
            ->where('scan_state', 'clean')
            ->where('review_state', 'approved')
            ->where('visibility', 'public')
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Authorized full listing (any state) for logistics/organizers — route is
     * permission-gated.
     *
     * @return list<array<string,mixed>>
     */
    public function listForReview(string $eventId): array
    {
        return $this->db->table('event_media')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }
}
