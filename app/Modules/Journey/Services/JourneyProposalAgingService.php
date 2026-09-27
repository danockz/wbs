<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;

/**
 * J6 — AGE pending journey-stage proposals through a uniform
 * remind → escalate → timeout lifecycle.
 *
 * A membership rule with effect `require_review`/`flag` queues a PENDING
 * `journey_stage_proposals` row for a leader to approve/reject, but nothing ever
 * aged that queue: a proposal a leader never noticed sat pending forever, with no
 * reminder and no expiry. This bounded, idempotent pass scans pending proposals
 * oldest-first and, per proposal:
 *
 *   1. REMIND   — once it is older than `proposal_remind_hours`, notify the
 *      reviewing leader(s) of the proposal's group context; re-remind only after
 *      the interval elapses (watermark `reminded_at`, counter `reminder_count`).
 *   2. ESCALATE — once it is older than `proposal_escalate_hours` and has not been
 *      escalated, notify the escalation-role holders ONCE (watermark
 *      `escalated_at`), falling back to the reviewers when no escalation role is
 *      configured.
 *   3. TIMEOUT  — when `proposal_timeout_days > 0` and the proposal is older than
 *      that, TERMINATE it: mark it `rejected` (default) or `superseded` per
 *      `proposal_timeout_action`, with a system actor, so a forgotten proposal
 *      can never later drive a stale move. Default 0 = never auto-terminate (a
 *      human must decide).
 *
 * Every step is watermark-guarded, so a re-run in the same window is a no-op; a
 * proposal only advances as real time passes. Notifications are best-effort and
 * never block the sweep. Thresholds are per-org platform settings.
 *
 * This is the aging HALF of J6; supersede-on-move (marking a proposal stale the
 * moment a member advances past its target) lives in {@see JourneySignalService}
 * because that owns the propose/approve queue.
 */
final class JourneyProposalAgingService
{
    private const REMIND_HOURS_DEFAULT   = 48;
    private const ESCALATE_HOURS_DEFAULT = 120; // 5 days
    private const TIMEOUT_DAYS_DEFAULT   = 0;   // 0 = never auto-terminate
    private const SYSTEM_ACTOR           = 'system';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?ProposalConfigPort $config = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    /**
     * Age the pending proposal queue for one org (or every org when null).
     *
     * @return array{scanned:int, reminded:int, escalated:int, timed_out:int}
     */
    public function sweepPendingProposals(?string $organizationId = null, int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));

        $q = $this->db->table('journey_stage_proposals')
            ->where('status', 'pending')
            ->orderBy('created_at', 'ASC')
            ->limit($limit);
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $proposals = $q->get()->getResultArray();

        $scanned   = 0;
        $reminded  = 0;
        $escalated = 0;
        $timedOut  = 0;

        $nowTs = $this->clock->now()->getTimestamp();

        // Per-org threshold cache so a multi-org sweep reads config once per org.
        $cfg = [];

        foreach ($proposals as $p) {
            $scanned++;
            $orgId = (string) $p['organization_id'];
            $cfg[$orgId] ??= $this->thresholds($orgId);
            [$remindHours, $escalateHours, $timeoutDays, $timeoutAction] = $cfg[$orgId];

            $createdTs = $this->ts((string) ($p['created_at'] ?? ''));
            if ($createdTs === null) {
                continue; // unparseable — skip rather than mis-age.
            }
            $ageSeconds = $nowTs - $createdTs;

            // 1. TIMEOUT (terminal) — auto-terminate a very old proposal, if enabled.
            if ($timeoutDays > 0 && $ageSeconds >= $timeoutDays * 86400) {
                if ($this->timeout($p, $timeoutAction)) {
                    $timedOut++;
                }
                continue; // resolved — no remind/escalate for a closed proposal.
            }

            // 2. ESCALATE (once) — past the escalation SLA and not yet escalated.
            if ($ageSeconds >= $escalateHours * 3600 && empty($p['escalated_at'])) {
                $this->escalate($p);
                $escalated++;
                continue; // one action per proposal per pass.
            }

            // 3. REMIND — past the reminder SLA and due for a (re-)reminder.
            if ($ageSeconds >= $remindHours * 3600 && $this->remindDue($p, $remindHours, $nowTs)) {
                $this->remind($p);
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
     * Resolved per-org thresholds: [remindHours, escalateHours, timeoutDays, timeoutAction].
     *
     * @return array{0:int,1:int,2:int,3:string}
     */
    private function thresholds(string $organizationId): array
    {
        $remind   = self::REMIND_HOURS_DEFAULT;
        $escalate = self::ESCALATE_HOURS_DEFAULT;
        $timeout  = self::TIMEOUT_DAYS_DEFAULT;
        $action   = 'rejected';
        if ($this->config !== null) {
            $remind   = max(1, (int) $this->config->get($organizationId, 'journey.proposal_remind_hours', $remind));
            $escalate = max(1, (int) $this->config->get($organizationId, 'journey.proposal_escalate_hours', $escalate));
            $timeout  = max(0, (int) $this->config->get($organizationId, 'journey.proposal_timeout_days', $timeout));
            $action   = (string) $this->config->get($organizationId, 'journey.proposal_timeout_action', $action);
        }
        // Only the two terminal states are valid; anything else falls back to reject.
        if (! in_array($action, ['rejected', 'superseded'], true)) {
            $action = 'rejected';
        }

        return [$remind, $escalate, $timeout, $action];
    }

    /** A reminder is due when never reminded, or last reminded > interval ago. */
    private function remindDue(array $proposal, int $remindHours, int $nowTs): bool
    {
        if (empty($proposal['reminded_at'])) {
            return true;
        }
        $last = $this->ts((string) $proposal['reminded_at']);

        return $last === null || ($nowTs - $last) >= $remindHours * 3600;
    }

    private function remind(array $proposal): void
    {
        $recipients = $this->reviewers(
            (string) $proposal['organization_id'],
            $proposal['group_id'] !== null && $proposal['group_id'] !== '' ? (string) $proposal['group_id'] : null,
        );
        $this->notifyAll($proposal, $recipients, 'journey_proposal_reminder', 'normal');

        $this->db->table('journey_stage_proposals')->where('id', $proposal['id'])->where('status', 'pending')->update([
            'reminded_at'    => $this->clock->nowUtcMicro(),
            'reminder_count' => (int) ($proposal['reminder_count'] ?? 0) + 1,
        ]);
    }

    private function escalate(array $proposal): void
    {
        $orgId   = (string) $proposal['organization_id'];
        $groupId = $proposal['group_id'] !== null && $proposal['group_id'] !== '' ? (string) $proposal['group_id'] : null;

        // Escalate to the configured escalation role; fall back to the reviewing
        // leaders so an org without a distinct escalation role still gets a nudge.
        $escRole = $this->config !== null
            ? (string) $this->config->get($orgId, 'journey.proposal_escalate_role', '')
            : '';
        $recipients = $escRole !== ''
            ? $this->roleHolders($orgId, $escRole)
            : $this->reviewers($orgId, $groupId);
        $this->notifyAll($proposal, $recipients, 'journey_proposal_escalation', 'high');

        $this->db->table('journey_stage_proposals')->where('id', $proposal['id'])->where('status', 'pending')->update([
            'escalated_at' => $this->clock->nowUtcMicro(),
        ]);
    }

    /**
     * Auto-terminate a very old proposal so it can never later drive a stale move.
     * No transition happens — the proposal simply leaves the pending queue.
     */
    private function timeout(array $proposal, string $action): bool
    {
        $this->db->table('journey_stage_proposals')->where('id', $proposal['id'])->where('status', 'pending')->update([
            'status'        => $action, // rejected | superseded
            'decided_by'    => self::SYSTEM_ACTOR,
            'decided_at'    => $this->clock->nowUtcString(),
            'decision_note' => 'Auto-' . $action . ': proposal timeout',
        ]);

        return true;
    }

    /**
     * The reviewing leaders for a proposal's group context: active `leader`
     * memberships of that group. Org-wide proposals (no group) route to holders of
     * the configured `proposal_review_role`, or nobody when unset.
     *
     * @return list<string>
     */
    private function reviewers(string $organizationId, ?string $groupId): array
    {
        if ($groupId === null) {
            $role = $this->config !== null
                ? (string) $this->config->get($organizationId, 'journey.proposal_review_role', '')
                : '';

            return $role !== '' ? $this->roleHolders($organizationId, $role) : [];
        }

        try {
            $rows = $this->db->table('group_members')
                ->select('user_id')
                ->where('organization_id', $organizationId)
                ->where('group_id', $groupId)
                ->where('membership_type', 'leader')
                ->where('status', 'active')
                ->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }

        return $this->uniqueIds($rows, 'user_id');
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

        return $this->uniqueIds($rows, 'subject_id');
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function uniqueIds(array $rows, string $col): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$col] ?? '');
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Best-effort fan-out of one proposal notification to each recipient. A
     * missing NotificationService or a per-recipient failure never aborts the
     * sweep.
     *
     * @param list<string> $recipients
     */
    private function notifyAll(array $proposal, array $recipients, string $category, string $priority): void
    {
        if ($this->notifications === null || $recipients === []) {
            return;
        }
        $orgId      = (string) $proposal['organization_id'];
        $proposalId = (string) $proposal['id'];
        $context    = [
            'proposal_id' => $proposalId,
            'user_id'     => $proposal['user_id'] ?? null,
            'to_stage'    => $proposal['to_stage'] ?? null,
            'group_id'    => $proposal['group_id'] ?? null,
        ];
        foreach ($recipients as $userId) {
            try {
                $this->notifications->send($orgId, $userId, 'in_app', $category, [
                    'priority'   => $priority,
                    // One delivery per (proposal, category, recipient); re-reminders
                    // vary by the reminder counter so a later reminder is distinct.
                    'dedupe_key' => $category . ':' . $proposalId . ':' . $userId
                        . ':' . (int) ($proposal['reminder_count'] ?? 0),
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
