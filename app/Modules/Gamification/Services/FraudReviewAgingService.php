<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;

/**
 * G2 — AGE open fraud reviews through a uniform remind → escalate → timeout
 * lifecycle.
 *
 * A `requires_review` award opens a `fraud_reviews` row (`status='open'`) and
 * HOLDS the points; nothing ever aged that queue, so a review a human never
 * noticed stranded the points forever (never spendable, never rejected). This
 * bounded, idempotent pass scans open reviews oldest-first and, per review:
 *
 *   1. REMIND   — once it is older than `held_review_remind_hours`, notify the
 *      approver-role holders; re-remind only after the interval elapses
 *      (watermark `reminded_at`, counter `reminder_count`).
 *   2. ESCALATE — once it is older than `held_review_escalate_hours` and has not
 *      been escalated, notify the escalation-role holders ONCE (watermark
 *      `escalated_at`).
 *   3. TIMEOUT  — when `held_review_timeout_days > 0` and the review is older than
 *      that, AUTO-REJECT it (post a compensating reversal via PointsEngine so the
 *      held points never become spendable) with a system actor. Default 0 = never
 *      auto-reject (a human must decide).
 *
 * Every step is watermark-guarded, so a re-run in the same window is a no-op; a
 * review only advances as real time passes. Notifications are best-effort and
 * never block the sweep. All thresholds are per-org `gamification_config`.
 */
final class FraudReviewAgingService
{
    private const REMIND_HOURS_DEFAULT   = 24;
    private const ESCALATE_HOURS_DEFAULT = 72;
    private const TIMEOUT_DAYS_DEFAULT   = 0; // 0 = never auto-reject
    private const SYSTEM_ACTOR           = 'system';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?ReviewConfigPort $config = null,
        private readonly ?ReviewRejectionPort $points = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    /**
     * Age the open fraud-review queue for one org (or every org when null).
     *
     * @return array{scanned:int, reminded:int, escalated:int, timed_out:int}
     */
    public function sweepOpenReviews(?string $organizationId = null, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));

        $q = $this->db->table('fraud_reviews')
            ->where('status', 'open')
            ->orderBy('created_at', 'ASC')
            ->limit($limit);
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $reviews = $q->get()->getResultArray();

        $scanned   = 0;
        $reminded  = 0;
        $escalated = 0;
        $timedOut  = 0;

        $nowTs = $this->clock->now()->getTimestamp();

        // Per-org threshold cache so a multi-org sweep reads config once per org.
        $cfg = [];

        foreach ($reviews as $r) {
            $scanned++;
            $orgId = (string) $r['organization_id'];
            $cfg[$orgId] ??= $this->thresholds($orgId);
            [$remindHours, $escalateHours, $timeoutDays] = $cfg[$orgId];

            $createdTs = $this->ts((string) ($r['created_at'] ?? ''));
            if ($createdTs === null) {
                continue; // unparseable — skip rather than mis-age.
            }
            $ageSeconds = $nowTs - $createdTs;

            // 1. TIMEOUT (terminal) — auto-reject a very old review, if enabled.
            if ($timeoutDays > 0 && $ageSeconds >= $timeoutDays * 86400) {
                if ($this->timeout($r)) {
                    $timedOut++;
                }
                continue; // resolved — no remind/escalate for a closed review.
            }

            // 2. ESCALATE (once) — past the escalation SLA and not yet escalated.
            if ($ageSeconds >= $escalateHours * 3600 && empty($r['escalated_at'])) {
                $this->escalate($r);
                $escalated++;
                continue; // one action per review per pass.
            }

            // 3. REMIND — past the reminder SLA and due for a (re-)reminder.
            if ($ageSeconds >= $remindHours * 3600 && $this->remindDue($r, $remindHours, $nowTs)) {
                $this->remind($r);
                $reminded++;
            }
        }

        return [
            'scanned'   => $scanned,
            'reminded'  => $reminded,
            'escalated' => $escalated,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * Resolved per-org thresholds: [remindHours, escalateHours, timeoutDays].
     *
     * @return array{0:int,1:int,2:int}
     */
    private function thresholds(string $organizationId): array
    {
        $remind   = self::REMIND_HOURS_DEFAULT;
        $escalate = self::ESCALATE_HOURS_DEFAULT;
        $timeout  = self::TIMEOUT_DAYS_DEFAULT;
        if ($this->config !== null) {
            $remind   = max(1, (int) $this->config->get($organizationId, 'held_review_remind_hours', $remind));
            $escalate = max(1, (int) $this->config->get($organizationId, 'held_review_escalate_hours', $escalate));
            $timeout  = max(0, (int) $this->config->get($organizationId, 'held_review_timeout_days', $timeout));
        }

        return [$remind, $escalate, $timeout];
    }

    /** A reminder is due when never reminded, or last reminded > interval ago. */
    private function remindDue(array $review, int $remindHours, int $nowTs): bool
    {
        if (empty($review['reminded_at'])) {
            return true;
        }
        $last = $this->ts((string) $review['reminded_at']);

        return $last === null || ($nowTs - $last) >= $remindHours * 3600;
    }

    private function remind(array $review): void
    {
        $recipients = $this->roleHolders(
            (string) $review['organization_id'],
            (string) ($review['assigned_role'] ?? ''),
        );
        $this->notifyAll($review, $recipients, 'gamification_review_reminder', 'normal');

        $this->db->table('fraud_reviews')->where('id', $review['id'])->where('status', 'open')->update([
            'reminded_at'    => $this->clock->nowUtcMicro(),
            'reminder_count' => (int) ($review['reminder_count'] ?? 0) + 1,
        ]);
    }

    private function escalate(array $review): void
    {
        $orgId = (string) $review['organization_id'];
        // Escalate to the configured escalation role, falling back to the approver
        // role so an org without a distinct escalation role still gets a nudge.
        $escRole = $this->config !== null
            ? (string) $this->config->get($orgId, 'held_review_escalate_role', '')
            : '';
        $recipients = $this->roleHolders($orgId, $escRole !== '' ? $escRole : (string) ($review['assigned_role'] ?? ''));
        $this->notifyAll($review, $recipients, 'gamification_review_escalation', 'high');

        $this->db->table('fraud_reviews')->where('id', $review['id'])->where('status', 'open')->update([
            'escalated_at' => $this->clock->nowUtcMicro(),
        ]);
    }

    /** Auto-reject via the points engine so the held points never go spendable. */
    private function timeout(array $review): bool
    {
        $ledgerId = (string) ($review['ledger_id'] ?? '');
        if ($ledgerId === '' || $this->points === null) {
            // No ledger link or no engine wired: mark the review rejected directly
            // so it still leaves the open queue (points stay held, never spendable).
            $this->db->table('fraud_reviews')->where('id', $review['id'])->where('status', 'open')->update([
                'status'      => 'rejected',
                'reason'      => 'rejected:review_timeout',
                'resolved_at' => $this->clock->nowUtcMicro(),
                'resolved_by' => self::SYSTEM_ACTOR,
            ]);

            return true;
        }

        $res = $this->points->rejectAward($ledgerId, self::SYSTEM_ACTOR, 'review_timeout');

        return $res->ok;
    }

    /**
     * Active users holding a role CODE in an org (via role_assignments → roles).
     * Empty role code or no holders → empty list (a best-effort notification).
     *
     * @return list<string>
     */
    private function roleHolders(string $organizationId, string $roleCode): array
    {
        if ($roleCode === '') {
            return [];
        }
        try {
            $rows = $this->db->table('role_assignments ra')
                ->select('ra.subject_id')
                ->join('roles r', 'r.id = ra.role_id', 'inner')
                ->where('ra.organization_id', $organizationId)
                ->where('r.code', $roleCode)
                ->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row['subject_id'] ?? '');
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Best-effort fan-out of one review notification to each recipient. A missing
     * NotificationService or a per-recipient failure never aborts the sweep.
     *
     * @param list<string> $recipients
     */
    private function notifyAll(array $review, array $recipients, string $category, string $priority): void
    {
        if ($this->notifications === null || $recipients === []) {
            return;
        }
        $orgId    = (string) $review['organization_id'];
        $reviewId = (string) $review['id'];
        $context  = [
            'review_id' => $reviewId,
            'ledger_id' => $review['ledger_id'] ?? null,
            'reason'    => $review['reason'] ?? null,
        ];
        foreach ($recipients as $userId) {
            try {
                $this->notifications->send($orgId, $userId, 'in_app', $category, [
                    'priority'   => $priority,
                    // One delivery per (review, category, recipient): re-reminders
                    // vary the category suffix so a later reminder is distinct.
                    'dedupe_key' => $category . ':' . $reviewId . ':' . $userId
                        . ':' . (int) ($review['reminder_count'] ?? 0),
                    'context'    => $context,
                ]);
            } catch (Throwable) {
                // best-effort — swallow and continue.
            }
        }
    }

    /** Parse a stored UTC timestamp to an epoch, or null when unparseable. */
    private function ts(string $stamp): ?int
    {
        if ($stamp === '') {
            return null;
        }
        $t = strtotime($stamp . ' UTC');

        return $t === false ? null : $t;
    }
}
