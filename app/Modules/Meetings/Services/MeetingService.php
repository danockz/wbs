<?php

declare(strict_types=1);

namespace WBS\Meetings\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Integrations\Providers\MeetingProviderRegistry;
use WBS\Integrations\Services\ProviderReliabilityService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Meeting/webinar linking + access control (SRS FR-MTG-001..003).
 *
 * Where a provider API allows, an adapter creates the meeting and stores its
 * external ref; otherwise an authorized link/reference is stored. Per-user join
 * links are short-lived: only a HASH of the join token is persisted, and the
 * plaintext is returned once to the caller.
 *
 * Provider-reported presence is recorded as CANDIDATE evidence only — it never
 * becomes platform attendance here; the linked event's verification policy
 * decides (FR-MTG-003).
 */
final class MeetingService
{
    private const PROVIDERS = ['zoom', 'meet', 'teams', 'jitsi', 'link'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?MeetingProviderRegistry $providers = null,
        private readonly ?ProviderReliabilityService $reliability = null,
        // MT2 seam: turns provider attendance EVIDENCE into platform ATTENDANCE
        // through the Events check-in path. Null → reconcileEvidence() is a no-op
        // (records stay unapplied) so the service constructs without Events wired.
        private readonly ?EventAttendancePort $attendance = null,
    ) {
    }

    /** @param array<string,mixed> $data */
    /**
     * List an organization's meetings (newest first), optionally filtered by
     * status. Read-only projection for the schedule dashboard; join_url is a
     * hosted link, safe to surface to authorized viewers.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $organizationId, ?string $status = null, int $limit = 100): array
    {
        $q = $this->db->table('meetings')
            ->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->orderBy('COALESCE(starts_at, created_at)', 'DESC', false)
            ->get(max(1, min(500, $limit)))
            ->getResultArray();
    }

    public function create(string $organizationId, array $data): Result
    {
        $provider = (string) ($data['provider'] ?? '');
        if (! in_array($provider, self::PROVIDERS, true)) {
            return Result::fail('BAD_PROVIDER', 'meeting.bad_provider', 422);
        }
        if (trim((string) ($data['title'] ?? '')) === '') {
            return Result::fail('TITLE_REQUIRED', 'meeting.title_required', 422);
        }
        $joinUrl     = $data['join_url'] ?? null;
        $externalRef = $data['external_ref'] ?? null;
        if ($joinUrl !== null && ! preg_match('#^https://#i', (string) $joinUrl)) {
            return Result::fail('BAD_JOIN_URL', 'meeting.bad_join_url', 422);
        }

        // Provider-backed creation (S2): when the caller didn't supply a link and
        // an adapter is registered for this provider, create the real meeting.
        // A provider failure surfaces as an error rather than silently storing an
        // empty meeting.
        $providerMeta = null;
        if ($joinUrl === null && $externalRef === null
            && $this->providers !== null && $this->providers->has($provider)) {
            // FR-INT-012: if this provider's circuit is open, fail fast and tell
            // the caller to use the documented fallback (a hosted join link)
            // instead of retrying a broken provider.
            if ($this->reliability !== null) {
                $gate = $this->reliability->admit($organizationId, $provider);
                if (! $gate->ok) {
                    return Result::fail('PROVIDER_UNAVAILABLE', 'meeting.provider_unavailable', 503, [], [
                        'provider' => $provider,
                        'fallback' => 'hosted_link',
                        'note'     => 'Provider circuit open or quota exhausted. Supply an authorized join_url to store a hosted-link meeting.',
                    ]);
                }
            }
            try {
                $created     = $this->providers->get($provider)->createMeeting([
                    'title'         => (string) $data['title'],
                    'mode'          => $data['mode'] ?? 'meeting',
                    'starts_at'     => $data['starts_at'] ?? null,
                    'ends_at'       => $data['ends_at'] ?? null,
                    'connection_id' => $data['connection_id'] ?? null,
                    'capabilities'  => $data['capabilities'] ?? null,
                ]);
                if ($this->reliability !== null) {
                    $this->reliability->recordSuccess($organizationId, $provider);
                }
                $externalRef  = $created->externalRef;
                $joinUrl      = $created->joinUrl;
                $providerMeta = $created->meta;
            } catch (Throwable $e) {
                if ($this->reliability !== null) {
                    $this->reliability->recordFailure($organizationId, $provider, $e->getMessage());
                }
                return Result::fail('PROVIDER_ERROR', $e->getMessage(), 502, [], ['fallback' => 'hosted_link']);
            }
        }

        $id = Uuid::v7();
        $this->db->table('meetings')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $data['event_id'] ?? null,
            'connection_id'   => $data['connection_id'] ?? null,
            'provider'        => $provider,
            'mode'            => ($data['mode'] ?? 'meeting') === 'webinar' ? 'webinar' : 'meeting',
            'title'           => mb_substr((string) $data['title'], 0, 200),
            'external_ref'    => $externalRef,
            'join_url'        => $joinUrl,
            'access_policy'   => in_array($data['access_policy'] ?? 'restricted', ['public', 'restricted'], true)
                ? $data['access_policy'] : 'restricted',
            'starts_at'       => $data['starts_at'] ?? null,
            'ends_at'         => $data['ends_at'] ?? null,
            'status'          => 'scheduled',
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(array_filter([
            'meeting_id'   => $id,
            'provider'     => $provider,
            'external_ref' => $externalRef,
            'join_url'     => $joinUrl,
            'provider_meta' => $providerMeta,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Grant a participant a short-lived per-user join token. Returns the plaintext
     * token ONCE; only its hash is stored.
     */
    public function grantAccess(string $meetingId, string $userId, string $role = 'attendee', int $ttlSeconds = 3600): Result
    {
        $meeting = $this->db->table('meetings')->where('id', $meetingId)->get()->getRowArray();
        if ($meeting === null) {
            return Result::notFound('meeting.not_found', 'MEETING_NOT_FOUND');
        }
        if (! in_array($role, ['host', 'cohost', 'attendee'], true)) {
            return Result::fail('BAD_ROLE', 'meeting.bad_role', 422);
        }

        $token   = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $token);
        $expires = $this->clock->now()->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s.u');
        $now     = $this->clock->nowUtcMicro();

        $existing = $this->db->table('meeting_participants')
            ->where('meeting_id', $meetingId)->where('user_id', $userId)->get()->getRowArray();
        $row = [
            'meeting_id'       => $meetingId,
            'user_id'          => $userId,
            'role'             => $role,
            'join_token_hash'  => $hash,
            'token_expires_at' => $expires,
        ];
        if ($existing === null) {
            $row['id']         = Uuid::v7();
            $row['created_at'] = $now;
            $this->db->table('meeting_participants')->insert($row);
        } else {
            $this->db->table('meeting_participants')->where('id', $existing['id'])->update($row);
        }

        return Result::created([
            'meeting_id'  => $meetingId,
            'user_id'     => $userId,
            'join_token'  => $token,        // returned once, never stored in plaintext
            'expires_at'  => $expires,
            'role'        => $role,
        ]);
    }

    /** Verify a presented join token (constant-time) and that it is unexpired. */
    public function verifyJoinToken(string $meetingId, string $userId, string $token): Result
    {
        $p = $this->db->table('meeting_participants')
            ->where('meeting_id', $meetingId)->where('user_id', $userId)->get()->getRowArray();
        if ($p === null || $p['join_token_hash'] === null) {
            return Result::denied('meeting.no_grant', 'NO_GRANT');
        }
        if ($p['token_expires_at'] !== null && $p['token_expires_at'] < $this->clock->nowUtcMicro()) {
            return Result::denied('meeting.token_expired', 'TOKEN_EXPIRED');
        }
        if (! hash_equals($p['join_token_hash'], hash('sha256', $token))) {
            return Result::denied('meeting.token_invalid', 'TOKEN_INVALID');
        }

        return Result::ok(['allowed' => true, 'role' => $p['role']]);
    }

    /**
     * Record provider-reported presence as CANDIDATE evidence. This never becomes
     * platform attendance here — the linked event's verification policy applies it
     * separately (FR-MTG-003).
     *
     * @param array<string,mixed> $data
     */
    public function recordAttendanceEvidence(string $meetingId, array $data): Result
    {
        $id = Uuid::v7();
        $this->db->table('meeting_attendance_evidence')->insert([
            'id'            => $id,
            'meeting_id'    => $meetingId,
            'user_id'       => $data['user_id'] ?? null,
            'provider_ref'  => $data['provider_ref'] ?? null,
            'joined_at'     => $data['joined_at'] ?? null,
            'left_at'       => $data['left_at'] ?? null,
            'duration_secs' => isset($data['duration_secs']) ? (int) $data['duration_secs'] : null,
            'applied'       => 0,
            'created_at'    => $this->clock->nowUtcMicro(),
        ]);

        return Result::created(['evidence_id' => $id, 'applied' => false]);
    }

    /**
     * MT5 — react to a linked event being cancelled (E-B1 emits `event.cancelled`).
     *
     * A cancelled event's video room must not stay enterable: every meeting linked
     * to the event that is still SCHEDULED or LIVE is moved to `canceled`, and all
     * outstanding per-user join tokens for those meetings are EXPIRED so a
     * previously-issued token can no longer be verified (verifyJoinToken already
     * rejects an expired token). Meetings already `ended`/`canceled` are left
     * alone, which also makes this idempotent — a redelivered `event.cancelled`
     * finds nothing left to cancel and expires 0 tokens.
     *
     * Attendance evidence and participant history are preserved (only the token
     * expiry is stamped) so post-hoc reconciliation/reporting still works.
     *
     * SYSTEM authority, fault-isolated caller (JobRouter).
     *
     * @return Result data: meetings_canceled, tokens_expired
     */
    public function onEventCancelled(string $organizationId, string $eventId, string $reasonCode): Result
    {
        if ($organizationId === '' || $eventId === '') {
            return Result::fail('MEETING_TEARDOWN_BAD_INPUT', 'meeting.teardown_bad_input', 422);
        }

        $now = $this->clock->nowUtcMicro();

        // Live/scheduled meetings linked to the dead event.
        $meetings = $this->db->table('meetings')
            ->where('organization_id', $organizationId)
            ->where('event_id', $eventId)
            ->whereIn('status', ['scheduled', 'live'])
            ->get()->getResultArray();

        $canceled      = 0;
        $tokensExpired = 0;
        foreach ($meetings as $m) {
            $meetingId = (string) $m['id'];

            // Expire every unexpired join token on this meeting so no held token
            // can still be redeemed after the event dies.
            $this->db->table('meeting_participants')
                ->where('meeting_id', $meetingId)
                ->where('token_expires_at >', $now)
                ->update(['token_expires_at' => $now]);
            $tokensExpired += $this->db->affectedRows();

            $this->db->table('meetings')->where('id', $meetingId)->update([
                'status'     => 'canceled',
                'updated_at' => $now,
            ]);
            $canceled++;
        }

        return Result::ok([
            'meetings_canceled' => $canceled,
            'tokens_expired'    => $tokensExpired,
            'reason_code'       => $reasonCode,
        ]);
    }

    /**
     * MT2 — reconcile unapplied meeting attendance EVIDENCE into platform
     * ATTENDANCE. The `meeting_attendance_evidence` table has always carried an
     * `applied` flag that NOTHING ever flipped: provider presence was captured
     * but never became attendance. This bounded, idempotent pass processes rows
     * with `applied = 0`:
     *
     *   - it must resolve to a platform user (`user_id` not null) and a meeting
     *     LINKED to an event (`meetings.event_id` not null);
     *   - the linked event must have `attendance_policy = 'streaming'` — the
     *     policy that says "meeting presence IS attendance" (FR-MTG-003). For a
     *     `checkin`/`manual` event the evidence is advisory only, so it is marked
     *     applied WITHOUT creating attendance (it never becomes a QR/manual seat);
     *   - a streaming row is recorded through the Events check-in path (points,
     *     journey signal, group attribution, one-active UNIQUE key), which dedupes
     *     a second evidence row for the same person to an idempotent success.
     *
     * Either way the evidence row is stamped `applied = 1` so it is never
     * reprocessed. Bounded by `limit`; scoped to one org or all. SYSTEM authority.
     *
     * @return Result data: applied_attendance, marked_advisory, skipped_unresolved
     */
    public function reconcileEvidence(?string $organizationId, int $limit = 500): Result
    {
        $limit = max(1, min(2000, $limit));

        // Join evidence -> meeting -> event so one bounded read carries the
        // linked event id + its attendance policy + org.
        $q = $this->db->table('meeting_attendance_evidence mae')
            ->select('mae.id AS evidence_id, mae.user_id, mae.meeting_id, m.event_id,'
                . ' e.organization_id, e.attendance_policy, e.group_id')
            ->join('meetings m', 'm.id = mae.meeting_id', 'left')
            ->join('events e', 'e.id = m.event_id', 'left')
            ->where('mae.applied', 0)
            ->orderBy('mae.created_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('e.organization_id', $organizationId);
        }
        $rows = $q->get($limit)->getResultArray();

        $applied    = 0;
        $advisory   = 0;
        $unresolved = 0;

        foreach ($rows as $r) {
            $evidenceId = (string) $r['evidence_id'];
            $userId     = (string) ($r['user_id'] ?? '');
            $eventId    = (string) ($r['event_id'] ?? '');
            $orgId      = (string) ($r['organization_id'] ?? '');
            $policy     = (string) ($r['attendance_policy'] ?? '');

            // No platform user, no linked event, or no owning org → cannot become
            // attendance. Leave the row UNAPPLIED so a later match (e.g. once the
            // provider ref is resolved to a user) can still reconcile it.
            if ($userId === '' || $eventId === '' || $orgId === '') {
                $unresolved++;

                continue;
            }

            if ($policy === 'streaming') {
                // Streaming: presence IS attendance, but we can only record it
                // through the Events check-in path. With no port wired we cannot
                // apply it, so leave the row unapplied for a later pass rather
                // than losing the evidence to a premature `advisory` mark.
                if ($this->attendance === null) {
                    $unresolved++;

                    continue;
                }
                $groupAttribution = ($r['group_id'] ?? null) !== null ? [(string) $r['group_id']] : [];
                $res = $this->attendance->recordFromMeetingEvidence($orgId, $eventId, $userId, $groupAttribution);
                // A failure (e.g. transient) leaves the row unapplied for retry.
                if (! $res->ok) {
                    $unresolved++;

                    continue;
                }
                $applied++;
            } else {
                // Non-streaming policy: evidence is advisory, never a seat.
                $advisory++;
            }

            $this->db->table('meeting_attendance_evidence')
                ->where('id', $evidenceId)
                ->update(['applied' => 1]);
        }

        return Result::ok([
            'applied_attendance'  => $applied,
            'marked_advisory'     => $advisory,
            'skipped_unresolved'  => $unresolved,
        ]);
    }

    public function transition(string $meetingId, string $status): Result
    {
        if (! in_array($status, ['scheduled', 'live', 'ended', 'canceled'], true)) {
            return Result::fail('BAD_STATUS', 'meeting.bad_status', 422);
        }
        $this->db->table('meetings')->where('id', $meetingId)->update([
            'status'     => $status,
            'updated_at' => $this->clock->nowUtcMicro(),
        ]);

        return Result::ok(['meeting_id' => $meetingId, 'status' => $status]);
    }
}
