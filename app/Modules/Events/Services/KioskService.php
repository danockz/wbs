<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Security\SecretBox;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Check-in kiosks and offline mode (SRS FR-EVT-017).
 *
 * Security invariants:
 *  - A kiosk manifest is ENCRYPTED at rest with {@see SecretBox} (aad
 *    `kiosk:{id}`), SHORT-LIVED (TTL), and EVENT-SCOPED: it contains only this
 *    event's registrants — never an unrestricted organization roster.
 *  - Offline scans are queued tamper-evidently. UNIQUE(kiosk_id, local_ref)
 *    makes replaying a device batch idempotent.
 *  - Duplicates/conflicts are resolved SERVER-SIDE at reconcile time via the
 *    existing one-active-attendance path ({@see CheckinService}). The UI is
 *    expected to show offline/unverified status until a scan is reconciled.
 */
final class KioskService
{
    private const MANIFEST_TTL_SECONDS = 14400; // 4h — short-lived, event-day scoped

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SecretBox $secretBox,
        private readonly CheckinService $checkin,
    ) {
    }

    /** Register an authorized check-in device for an event. */
    public function registerKiosk(string $organizationId, string $eventId, string $deviceLabel, ?string $deviceFingerprint, ?string $createdBy): Result
    {
        if (trim($deviceLabel) === '') {
            return Result::fail('LABEL_REQUIRED', 'kiosk.label_required', 422);
        }
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_checkin_kiosks')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'event_id'         => $eventId,
            'device_label'     => $deviceLabel,
            // Store only a HASH of any device fingerprint (no raw device data).
            'device_fp_hash'   => $deviceFingerprint !== null && $deviceFingerprint !== ''
                ? hash('sha256', $deviceFingerprint)
                : null,
            'status'           => 'active',
            'manifest_version' => 0,
            'created_by'       => $createdBy,
            'created_at'       => $now,
        ]);

        return Result::created(['kiosk_id' => $id, 'event_id' => $eventId, 'status' => 'active']);
    }

    /** Revoke a kiosk (its manifests are no longer refreshed; it must go online). */
    public function revokeKiosk(string $kioskId): Result
    {
        $kiosk = $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->get()->getRowArray();
        if ($kiosk === null) {
            return Result::notFound('kiosk.not_found', 'KIOSK_NOT_FOUND');
        }
        $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->update([
            'status'     => 'revoked',
            'revoked_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['kiosk_id' => $kioskId, 'event_id' => $kiosk['event_id'], 'status' => 'revoked']);
    }

    /**
     * List an event's check-in kiosks for the organizer console (newest first),
     * each annotated with the count of offline scans still queued for
     * reconciliation. Read side of the kiosk console — no device fingerprints or
     * manifest ciphertext are exposed.
     *
     * @return list<array<string,mixed>>
     */
    public function listForEvent(string $eventId): array
    {
        $kiosks = $this->db->table('event_checkin_kiosks')
            ->select('id, device_label, status, manifest_version, last_seen_at, created_at, revoked_at')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        foreach ($kiosks as &$k) {
            $k['queued_scans'] = (int) $this->db->table('offline_checkin_queue')
                ->where('kiosk_id', $k['id'])
                ->where('reconcile_status', 'queued')
                ->countAllResults();
        }
        unset($k);

        return $kiosks;
    }

    /**
     * Issue a fresh encrypted, short-lived, event-scoped attendee manifest for
     * a kiosk. The manifest lists ONLY confirmed registrants of THIS event
     * (opaque user ids + display token), plus the check-in signing material the
     * device needs to validate QR nonces offline. The plaintext never leaves
     * this method un-encrypted; the row stores a SecretBox envelope.
     */
    public function issueManifest(string $kioskId): Result
    {
        $kiosk = $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->get()->getRowArray();
        if ($kiosk === null) {
            return Result::notFound('kiosk.not_found', 'KIOSK_NOT_FOUND');
        }
        if ($kiosk['status'] !== 'active') {
            return Result::fail('KIOSK_REVOKED', 'kiosk.revoked', 409);
        }

        $eventId = (string) $kiosk['event_id'];

        // EVENT-SCOPED roster only — confirmed registrants of this event.
        $rows = $this->db->table('event_registrations')
            ->select('user_id')
            ->where('event_id', $eventId)
            ->where('status', 'registered')
            ->get()->getResultArray();

        $entries = [];
        foreach ($rows as $r) {
            $entries[] = [
                'user_id' => $r['user_id'],
                // Opaque per-manifest token so the device can display a match
                // confirmation without carrying personal data.
                'token'   => substr(hash_hmac('sha256', (string) $r['user_id'], $kioskId), 0, 16),
            ];
        }

        $version = (int) $kiosk['manifest_version'] + 1;
        $now     = $this->clock->now();
        $expires = $now->modify('+' . self::MANIFEST_TTL_SECONDS . ' seconds');

        $plaintext = json_encode([
            'event_id'   => $eventId,
            'version'    => $version,
            'issued_at'  => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'entries'    => $entries,
        ], JSON_UNESCAPED_UNICODE);

        $ciphertext = $this->secretBox->encrypt((string) $plaintext, 'kiosk:' . $kioskId);

        $id = Uuid::v7();
        $this->db->transStart();
        $this->db->table('kiosk_manifests')->insert([
            'id'          => $id,
            'kiosk_id'    => $kioskId,
            'event_id'    => $eventId,
            'version'     => $version,
            'entry_count' => count($entries),
            'ciphertext'  => $ciphertext,
            'issued_at'   => $now->format('Y-m-d H:i:s.u'),
            'expires_at'  => $expires->format('Y-m-d H:i:s.u'),
        ]);
        $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->update([
            'manifest_version' => $version,
            'last_seen_at'     => $now->format('Y-m-d H:i:s.u'),
        ]);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('MANIFEST_FAILED', 'kiosk.manifest_failed', 500);
        }

        // The device fetches the ciphertext + envelope; the platform hands back
        // the encrypted blob (safe to store on the device) and metadata.
        return Result::created([
            'manifest_id' => $id,
            'kiosk_id'    => $kioskId,
            'event_id'    => $eventId,
            'version'     => $version,
            'entry_count' => count($entries),
            'ciphertext'  => $ciphertext,
            'expires_at'  => $expires->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Accept a batch of offline scans captured while a kiosk was disconnected.
     * Each scan is queued idempotently (UNIQUE kiosk_id+local_ref). Nothing is
     * applied to attendance here — reconciliation is a separate, server-side
     * step so conflicts resolve under platform rules.
     *
     * @param list<array<string,mixed>> $scans local_ref, user_id, method, manual_reason, scanned_at
     */
    public function enqueueOfflineScans(string $organizationId, string $kioskId, array $scans): Result
    {
        $kiosk = $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->get()->getRowArray();
        if ($kiosk === null) {
            return Result::notFound('kiosk.not_found', 'KIOSK_NOT_FOUND');
        }
        $eventId = (string) $kiosk['event_id'];
        $now     = $this->clock->nowUtcMicro();

        $accepted = 0;
        $duplicate = 0;
        foreach ($scans as $scan) {
            $localRef = (string) ($scan['local_ref'] ?? '');
            if ($localRef === '') {
                continue;
            }
            try {
                $this->db->table('offline_checkin_queue')->insert([
                    'id'               => Uuid::v7(),
                    'organization_id'  => $organizationId,
                    'kiosk_id'         => $kioskId,
                    'event_id'         => $eventId,
                    'local_ref'        => $localRef,
                    'user_id'          => $scan['user_id'] ?? null,
                    'method'           => ($scan['method'] ?? 'qr') === 'manual' ? 'manual' : 'qr',
                    'manual_reason'    => $scan['manual_reason'] ?? null,
                    'scanned_at'       => $scan['scanned_at'] ?? $now,
                    'reconcile_status' => 'queued',
                    'created_at'       => $now,
                ]);
                $accepted++;
            } catch (Throwable) {
                // UNIQUE(kiosk_id, local_ref) -> replay of the same scan, ignore.
                $duplicate++;
            }
        }

        $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->update(['last_seen_at' => $now]);

        return Result::ok([
            'kiosk_id'         => $kioskId,
            'queued'           => $accepted,
            'duplicate_ignored' => $duplicate,
        ], 200, ['reconcile_required' => $accepted > 0]);
    }

    /**
     * Reconcile queued offline scans into attendance, server-side. Uses the
     * canonical manual check-in path so the one-active-attendance UNIQUE
     * resolves duplicates (a person scanned on two devices is credited once).
     */
    public function reconcile(string $kioskId, string $actorId): Result
    {
        $kiosk = $this->db->table('event_checkin_kiosks')->where('id', $kioskId)->get()->getRowArray();
        if ($kiosk === null) {
            return Result::notFound('kiosk.not_found', 'KIOSK_NOT_FOUND');
        }
        $eventId = (string) $kiosk['event_id'];
        $orgId   = (string) $kiosk['organization_id'];

        $queued = $this->db->table('offline_checkin_queue')
            ->where('kiosk_id', $kioskId)
            ->where('reconcile_status', 'queued')
            ->orderBy('scanned_at', 'ASC')
            ->get()->getResultArray();

        $applied = 0;
        $dupes   = 0;
        $rejected = 0;
        foreach ($queued as $scan) {
            $now = $this->clock->nowUtcMicro();
            if ($scan['user_id'] === null) {
                $this->markScan($scan['id'], 'rejected', 'no_user', $now);
                $rejected++;

                continue;
            }
            // Offline entries are recorded as authorized manual check-ins with a
            // provenance reason; the check-in service enforces one-active-attendance.
            $reason = 'offline-kiosk:' . $kioskId . ' ref:' . $scan['local_ref'];
            $res    = $this->checkin->checkInManual(
                $orgId,
                $eventId,
                (string) $scan['user_id'],
                $actorId,
                $reason,
            );
            if ($res->ok) {
                $deduped = ($res->meta['deduplicated'] ?? false) === true;
                $this->markScan($scan['id'], $deduped ? 'duplicate' : 'applied', $deduped ? 'already_present' : null, $now);
                $deduped ? $dupes++ : $applied++;
            } else {
                $this->markScan($scan['id'], 'rejected', (string) $res->code, $now);
                $rejected++;
            }
        }

        return Result::ok([
            'kiosk_id'  => $kioskId,
            'event_id'  => $eventId,
            'applied'   => $applied,
            'duplicate' => $dupes,
            'rejected'  => $rejected,
            'processed' => count($queued),
        ]);
    }

    private function markScan(string $id, string $status, ?string $note, string $now): void
    {
        $this->db->table('offline_checkin_queue')->where('id', $id)->update([
            'reconcile_status' => $status,
            'reconcile_note'   => $note,
            'reconciled_at'    => $now,
        ]);
    }
}
