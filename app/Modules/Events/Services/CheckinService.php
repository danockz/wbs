<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Gamification\Services\PointsEngine;
use WBS\Journey\Services\JourneySignalService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Attendance check-in (SRS FR-EVT-007/008).
 *
 *  - Signed, time-bound, single-use QR nonces. The QR payload carries only an
 *    opaque nonce + HMAC signature — never personal data.
 *  - Replay-proof: a nonce is consumed on first successful scan.
 *  - ONE active attendance per event/person (UNIQUE active_key) — concurrent
 *    scans across shared/cross-group events can't double-count a person.
 *  - Group attribution is recorded SEPARATELY, so a person may be credited to
 *    authorized group(s) without inflating total headcount.
 *  - Manual entry requires an authorized actor + reason.
 */
final class CheckinService
{
    private const NONCE_TTL_SECONDS = 300;

    /** Rule code awarded on a successful attendance check-in. */
    private const ATTENDANCE_RULE = 'event.attended';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly string $signingKey = 'wbs-checkin-key',
        private readonly ?PointsEngine $points = null,
        private readonly ?JourneySignalService $journeySignals = null,
    ) {
    }

    /** Issue a signed QR payload for an event check-in station. */
    public function issueNonce(string $eventId): Result
    {
        $nonce   = bin2hex(random_bytes(32));
        $now     = $this->clock->now();
        $expires = $now->modify('+' . self::NONCE_TTL_SECONDS . ' seconds');

        $this->db->table('checkin_nonces')->insert([
            'id'         => Uuid::v7(),
            'event_id'   => $eventId,
            'nonce'      => $nonce,
            'issued_at'  => $now->format('Y-m-d H:i:s.u'),
            'expires_at' => $expires->format('Y-m-d H:i:s.u'),
        ]);

        $signature = $this->sign($eventId, $nonce);

        return Result::created([
            'event_id'   => $eventId,
            'nonce'      => $nonce,
            'sig'        => $signature,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            // QR payload: opaque, no personal data.
            'qr'         => $eventId . '.' . $nonce . '.' . $signature,
        ]);
    }

    /**
     * Check a person in via a scanned QR payload.
     *
     * @param list<string> $groupAttribution authorized crediting group ids
     */
    public function checkInByQr(string $organizationId, string $qrPayload, string $userId, array $groupAttribution = [], ?string $projectCode = null): Result
    {
        $parts = explode('.', $qrPayload);
        if (count($parts) !== 3) {
            return Result::fail('BAD_QR', 'event.bad_qr', 422);
        }
        [$eventId, $nonce, $sig] = $parts;

        if (! hash_equals($this->sign($eventId, $nonce), $sig)) {
            return Result::fail('BAD_SIGNATURE', 'event.bad_signature', 422);
        }

        $now     = $this->clock->nowUtcMicro();
        $nonceRow = $this->db->table('checkin_nonces')
            ->where('nonce', $nonce)->where('event_id', $eventId)
            ->get()->getRowArray();
        if ($nonceRow === null) {
            return Result::fail('UNKNOWN_NONCE', 'event.unknown_nonce', 422);
        }
        if ($nonceRow['consumed_at'] !== null) {
            return Result::fail('NONCE_REPLAY', 'event.nonce_replay', 409);
        }
        if ($nonceRow['expires_at'] < $now) {
            return Result::fail('NONCE_EXPIRED', 'event.nonce_expired', 422);
        }

        return $this->recordAttendance($organizationId, $eventId, $userId, 'qr', $groupAttribution, null, null, $nonce, $projectCode);
    }

    /** Manual check-in — requires authorized staff and a reason. */
    public function checkInManual(string $organizationId, string $eventId, string $userId, string $staffId, string $reason, array $groupAttribution = [], ?string $projectCode = null): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'event.manual_reason_required', 422);
        }

        return $this->recordAttendance($organizationId, $eventId, $userId, 'manual', $groupAttribution, $staffId, $reason, null, $projectCode);
    }

    /**
     * Streaming / meeting-evidence check-in (MT2). Records platform attendance
     * with method `streaming` for a person whose presence was proven by a linked
     * meeting's provider evidence (not a QR nonce or staff action). Reuses the
     * same recordAttendance path so awards, journey signals, group attribution
     * and the one-active-attendance UNIQUE key all apply identically — a
     * redelivered/duplicate evidence row therefore dedupes to an idempotent
     * success rather than a second attendance.
     *
     * Only for events whose `attendance_policy = 'streaming'`; the caller (the
     * MT2 reconciler) enforces that. `qr`-style registration is NOT required
     * here (method !== 'qr'), because a streaming attendee may have joined the
     * meeting without a seat reservation.
     *
     * @param list<string> $groupAttribution
     */
    public function checkInFromMeetingEvidence(
        string $organizationId,
        string $eventId,
        string $userId,
        array $groupAttribution = [],
        ?string $projectCode = null,
    ): Result {
        return $this->recordAttendance($organizationId, $eventId, $userId, 'streaming', $groupAttribution, null, null, null, $projectCode);
    }

    /**
     * POST-EVENT ATTENDANCE RECONCILIATION (gap L6). Aggregate-only, read-only,
     * non-destructive: reconciles who ATTENDED against who REGISTERED and who
     * PAID, so the day's walk-ins and no-shows are auditable rather than silent.
     *
     * Four buckets, computed from bounded indexed reads (no identifiable rows):
     *   - matched      : present attendance WITH an active registration;
     *   - walk_in      : present attendance with NO registration (the L6 flag);
     *   - no_show      : active registrations that never checked in;
     *   - paid_absent  : distinct payers of a PAID order who never checked in
     *                    (paid-but-absent — a refund/finance follow-up signal).
     * Plus totals (present, method split, registered, paid) and a boolean
     * `reconciled` = no walk-ins AND no no-shows AND no paid-absent (attendance,
     * registration and payment all line up). Voided attendance is excluded.
     *
     * Returns NOT_FOUND for an unknown event so the surface renders a clean
     * not-found panel rather than a zeroed report.
     */
    public function reconcile(string $eventId): Result
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        // Present attendance, split by walk-in flag and method.
        $present = (int) $this->db->table('event_attendance')
            ->where('event_id', $eventId)->where('status', 'present')->countAllResults();
        $walkIn = (int) $this->db->table('event_attendance')
            ->where('event_id', $eventId)->where('status', 'present')->where('walk_in', 1)->countAllResults();
        $matched = $present - $walkIn;

        $byMethod = [];
        foreach (['qr', 'manual', 'streaming'] as $m) {
            $byMethod[$m] = (int) $this->db->table('event_attendance')
                ->where('event_id', $eventId)->where('status', 'present')->where('method', $m)->countAllResults();
        }

        // Active registrations, and how many of them checked in (no-shows = the rest).
        $registered = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('status', 'registered')->countAllResults();

        // No-shows: registered people with no present attendance row. One bounded
        // anti-join keyed on the event.
        $noShow = (int) ($this->db->query(
            'SELECT COUNT(*) AS c
             FROM event_registrations r
             WHERE r.event_id = ? AND r.status = "registered"
               AND NOT EXISTS (
                 SELECT 1 FROM event_attendance a
                 WHERE a.event_id = r.event_id AND a.user_id = r.user_id AND a.status = "present"
               )',
            [$eventId],
        )->getRowArray()['c'] ?? 0);

        // Distinct payers (paid orders) and how many never checked in.
        $paid = (int) ($this->db->query(
            'SELECT COUNT(DISTINCT user_id) AS c FROM event_orders WHERE event_id = ? AND status = "paid"',
            [$eventId],
        )->getRowArray()['c'] ?? 0);
        $paidAbsent = (int) ($this->db->query(
            'SELECT COUNT(DISTINCT o.user_id) AS c
             FROM event_orders o
             WHERE o.event_id = ? AND o.status = "paid"
               AND NOT EXISTS (
                 SELECT 1 FROM event_attendance a
                 WHERE a.event_id = o.event_id AND a.user_id = o.user_id AND a.status = "present"
               )',
            [$eventId],
        )->getRowArray()['c'] ?? 0);

        return Result::ok([
            'event_id'   => $eventId,
            'title'      => $event['title'],
            'status'     => $event['status'],
            'present'    => $present,
            'matched'    => $matched,
            'walk_in'    => $walkIn,
            'by_method'  => $byMethod,
            'registered' => $registered,
            'no_show'    => $noShow,
            'paid'       => $paid,
            'paid_absent' => $paidAbsent,
            'reconciled' => $walkIn === 0 && $noShow === 0 && $paidAbsent === 0,
        ]);
    }

    /**
     * @param list<string> $groupAttribution
     * @param ?string       $projectCode optional project this attendance belongs
     *        to; carried onto the journey signal only (explicit pass-through —
     *        events have no intrinsic project). NULL = not project-attributed.
     */
    private function recordAttendance(
        string $organizationId,
        string $eventId,
        string $userId,
        string $method,
        array $groupAttribution,
        ?string $staffId,
        ?string $reason,
        ?string $nonce,
        ?string $projectCode = null,
    ): Result {
        // Must be a valid registrant (or waitlist-promoted) for the event.
        $reg = $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)
            ->whereIn('status', ['registered'])
            ->get()->getRowArray();
        if ($reg === null && $method === 'qr') {
            return Result::fail('NOT_REGISTERED', 'event.not_registered', 409);
        }

        // WALK-IN (gap L6): a manual/streaming check-in with no active
        // registration is a walk-in. We FLAG it (not fork registration) so the
        // post-event reconciliation can split matched vs walk-in attendance and
        // registration accounting (capacity/waitlist) stays untouched.
        $walkIn = $reg === null;

        $activeKey = $eventId . ':' . $userId;
        $now       = $this->clock->nowUtcMicro();
        $id        = Uuid::v7();

        $this->db->transStart();
        try {
            $this->db->table('event_attendance')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'user_id'         => $userId,
                'method'          => $method,
                'checked_in_at'   => $now,
                'checked_in_by'   => $staffId,
                'manual_reason'   => $reason,
                'walk_in'         => $walkIn ? 1 : 0,
                'active_key'      => $activeKey,  // UNIQUE -> one active per person/event
                'status'          => 'present',
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            // Already present -> idempotent success (still add any new group attribution).
            $existing = $this->db->table('event_attendance')
                ->where('event_id', $eventId)->where('user_id', $userId)->where('status', 'present')
                ->get()->getRowArray();
            if ($existing !== null) {
                $this->addGroupAttribution($existing['id'], $eventId, $groupAttribution);
                // Re-award: idempotent per group, so already-credited groups are
                // skipped while any newly-added attribution group is now credited.
                $this->awardAttendance($organizationId, $eventId, $userId, (string) $existing['id']);

                return Result::ok(['attendance_id' => $existing['id'], 'status' => 'present'], 200, ['deduplicated' => true]);
            }

            return Result::fail('CHECKIN_FAILED', 'event.checkin_failed', 409);
        }

        if ($nonce !== null) {
            $this->db->table('checkin_nonces')->where('nonce', $nonce)->update(['consumed_at' => $now]);
        }
        $this->addGroupAttribution($id, $eventId, $groupAttribution);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('CHECKIN_FAILED', 'event.checkin_failed', 500);
        }

        // Award attendance points, crediting EVERY group this check-in touches —
        // the event's own organizing group plus each authorized attribution group
        // (design doc B.4.1). A member may attend ANY allowed group's event, so
        // these groups need NOT be their own memberships; `group_credit_mode` on
        // the rule decides per_group vs individual_once. Best-effort: a capped /
        // cooled-down / unconfigured award never fails the check-in itself.
        $award = $this->awardAttendance($organizationId, $eventId, $userId, $id);

        // Journey signal (Option C): attendance may advance the member's journey
        // (e.g. first-timer → new believer) when a membership rule matches.
        // Best-effort; never fails the check-in.
        $this->emitJourneySignal($organizationId, $eventId, $userId, $id, $projectCode);

        return Result::created(array_filter([
            'attendance_id' => $id,
            'status'        => 'present',
            'method'        => $method,
            'award'         => $award,
        ], static fn ($v): bool => $v !== null));
    }

    /**
     * Award attendance points for a check-in, crediting the event's organizing
     * group and every recorded attribution group. Returns an award summary, a
     * skip reason, or null (no points engine wired / no rule configured).
     *
     * The credited-group set is read back from the persisted rows so it reflects
     * exactly what was stored (deduped by the UNIQUE(attendance_id, group_id)),
     * and the event's own `group_id` is unioned in. Idempotent: the underlying
     * awardMultiGroup() keys each entry on (rule, subject, source_ref, group_id)
     * with source_ref `attendance:{attendanceId}`, so a redelivered/repeat scan
     * of the same attendance never double-awards a group.
     *
     * @return array<string,mixed>|null
     */
    private function awardAttendance(string $organizationId, string $eventId, string $userId, string $attendanceId): ?array
    {
        if ($this->points === null) {
            return null;
        }

        // Event's own organizing group (may be NULL for an org-wide event).
        $event    = $this->db->table('events')->select('group_id')->where('id', $eventId)->get()->getRowArray();
        $eventGrp = $event !== null && $event['group_id'] !== null && $event['group_id'] !== ''
            ? (string) $event['group_id'] : null;

        // All attribution groups recorded for this attendance.
        $rows = $this->db->table('event_attendance_group_attribution')
            ->select('group_id')->where('attendance_id', $attendanceId)
            ->get()->getResultArray();

        $groups = [];
        if ($eventGrp !== null) {
            $groups[] = $eventGrp; // primary = the organizing group
        }
        foreach ($rows as $r) {
            $g = (string) $r['group_id'];
            if ($g !== '' && ! in_array($g, $groups, true)) {
                $groups[] = $g;
            }
        }

        $res = $this->points->awardMultiGroup(
            $organizationId,
            self::ATTENDANCE_RULE,
            $userId,
            'attendance:' . $attendanceId,
            $groups,
            ['subject_type' => 'user', 'data' => ['count' => 1]],
        );

        if (! $res->ok) {
            return ['skipped' => $res->code];
        }

        return is_array($res->data) ? $res->data : null;
    }

    /**
     * Emit a membership-journey signal for a successful attendance. Journey
     * context = the member's org-wide primary journey; scope = the event's own
     * group so a group-scoped leader's rule can fire. Best-effort and fully
     * fault-isolated — a signal failure never affects the check-in.
     */
    private function emitJourneySignal(string $organizationId, string $eventId, string $userId, string $attendanceId, ?string $projectCode = null): void
    {
        if ($this->journeySignals === null) {
            return;
        }
        try {
            $event    = $this->db->table('events')->select('group_id')->where('id', $eventId)->get()->getRowArray();
            $eventGrp = $event !== null && $event['group_id'] !== null && $event['group_id'] !== ''
                ? (string) $event['group_id'] : '';

            $this->journeySignals->ingest($organizationId, [
                'action'         => 'journey.signal.event.attended',
                'user_id'        => $userId,
                'scope_group_id' => $eventGrp,
                // Explicit pass-through only — no intrinsic project on an event.
                'project_code'   => $projectCode ?? '',
                'evidence_type'  => 'event',
                'evidence_ref'   => 'attendance:' . $attendanceId,
                'attributes'     => ['event_id' => $eventId],
            ]);
        } catch (Throwable) {
            // Journey automation is best-effort.
        }
    }

    /** @param list<string> $groups */
    private function addGroupAttribution(string $attendanceId, string $eventId, array $groups): void
    {
        $now = $this->clock->nowUtcString();
        foreach (array_unique($groups) as $groupId) {
            try {
                $this->db->table('event_attendance_group_attribution')->insert([
                    'id'            => Uuid::v7(),
                    'attendance_id' => $attendanceId,
                    'event_id'      => $eventId,
                    'group_id'      => $groupId,
                    'created_at'    => $now,
                ]);
            } catch (Throwable) {
                // UNIQUE(attendance_id, group_id) -> already credited, ignore.
            }
        }
    }

    private function sign(string $eventId, string $nonce): string
    {
        return hash_hmac('sha256', $eventId . '|' . $nonce, $this->signingKey);
    }
}
