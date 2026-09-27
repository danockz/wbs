<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Recurring giving commitments (pledges). REMINDER-ONLY: nothing is ever
 * auto-charged (no payment method is vaulted anywhere in this design). The due
 * processor sends a reminder notification (preference-gated + deduped) and
 * advances the next due date; the member completes payment through the normal
 * contribution flow.
 *
 * Adaptation notes vs. GivingsLibrary:
 *  - Result returns (no throw), UUIDv7 ids, Clock DI, org-scoped, minor units.
 *  - Reminders go through NotificationService (which itself stages the outbox
 *    dispatch), never an inline Events::trigger.
 */
final class CommitmentService
{
    private const FREQUENCIES = ['monthly', 'quarterly', 'annual', 'one_time'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly CauseService $causes,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<string,mixed> $data cause_id, amount_minor, currency, frequency
     */
    public function create(string $organizationId, string $subjectId, array $data): Result
    {
        $causeId = (string) ($data['cause_id'] ?? '');
        $amount  = (int) ($data['amount_minor'] ?? 0);
        if ($causeId === '' || $subjectId === '') {
            return Result::fail('BAD_COMMITMENT', 'commitment.cause_subject_required', 422);
        }
        if ($amount <= 0) {
            return Result::fail('BAD_AMOUNT', 'commitment.amount_positive', 422);
        }
        $frequency = (string) ($data['frequency'] ?? 'monthly');
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            return Result::fail('BAD_FREQUENCY', 'commitment.bad_frequency', 422, ['allowed' => self::FREQUENCIES]);
        }

        $cause = $this->causes->find($causeId);
        if ($cause === null || (string) ($cause['organization_id'] ?? '') !== $organizationId) {
            return Result::notFound('commitment.cause_not_found', 'CAUSE_NOT_FOUND');
        }
        if (($cause['status'] ?? '') !== 'active') {
            return Result::fail('CAUSE_CLOSED', 'commitment.cause_not_active', 409);
        }

        $today = substr($this->clock->nowUtcString(), 0, 10);
        $id    = Uuid::v7();
        $now   = $this->clock->nowUtcString();

        $this->db->table('giving_commitments')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'subject_id'      => $subjectId,
            'cause_id'        => $causeId,
            'amount_minor'    => $amount,
            'currency'        => strtoupper((string) ($data['currency'] ?? $cause['currency'] ?? 'GHS')),
            'frequency'       => $frequency,
            'status'          => 'active',
            'next_due_at'     => $this->nextDueDate($frequency, $today),
            'started_at'      => $now,
            'created_at'      => $now,
        ]);

        return Result::created(['commitment_id' => $id, 'frequency' => $frequency]);
    }

    /** Cancel a commitment the subject owns. */
    public function cancel(string $organizationId, string $subjectId, string $commitmentId): Result
    {
        $row = $this->db->table('giving_commitments')
            ->where('organization_id', $organizationId)->where('id', $commitmentId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('commitment.not_found', 'COMMITMENT_NOT_FOUND');
        }
        if ((string) $row['subject_id'] !== $subjectId) {
            return Result::denied('commitment.not_owner', 'NOT_OWNER');
        }
        if ($row['status'] !== 'active') {
            return Result::ok(['commitment_id' => $commitmentId, 'status' => $row['status']], 200, ['noop' => true]);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('giving_commitments')->where('id', $commitmentId)->update([
            'status'     => 'cancelled',
            'ended_at'   => $now,
            'updated_at' => $now,
        ]);

        return Result::ok(['commitment_id' => $commitmentId, 'status' => 'cancelled']);
    }

    /** @return list<array<string,mixed>> Active commitments for a subject. */
    public function listForSubject(string $organizationId, string $subjectId): array
    {
        return $this->db->table('giving_commitments')
            ->where('organization_id', $organizationId)->where('subject_id', $subjectId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Cancel every ACTIVE commitment for a subject (Theme B consumer — C8).
     *
     * Invoked by the JobRouter on account teardown (deactivate / suspend /
     * anonymize / merge): a gone member's recurring commitments must stop
     * reminding forever. This is a SYSTEM-authority action — no owner check
     * (unlike `cancel`, which requires the giver) because the account is gone and
     * there is no human actor. Idempotent: only `active` rows are flipped, so a
     * redelivered teardown event is a no-op. Charges nothing (commitments are
     * reminder-only today).
     *
     * @return int number of commitments cancelled
     */
    public function cancelActiveForSubject(string $organizationId, string $subjectId, string $reasonCode): int
    {
        if ($organizationId === '' || $subjectId === '') {
            return 0;
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('giving_commitments')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->where('status', 'active')
            ->update([
                'status'     => 'cancelled',
                'ended_at'   => $now,
                'updated_at' => $now,
            ]);

        return max(0, (int) $this->db->affectedRows());
    }

    /**
     * Cron entry point. For every active commitment whose next_due_at has
     * passed: stage a reminder (outbox) and advance/complete it. Charges
     * nothing. Returns the number processed.
     */
    public function processDue(?string $organizationId = null, int $limit = 500): int
    {
        $today = substr($this->clock->nowUtcString(), 0, 10);
        $q     = $this->db->table('giving_commitments')
            ->where('status', 'active')
            ->where('next_due_at <=', $today)
            ->where('next_due_at IS NOT NULL');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $due = $q->orderBy('next_due_at', 'ASC')->limit($limit)->get()->getResultArray();

        $now = $this->clock->nowUtcString();
        foreach ($due as $c) {
            // Reminder-only. dedupe_key keyed on the due date so re-runs on the
            // same day do not double-notify.
            $this->notifications->send(
                (string) $c['organization_id'],
                (string) $c['subject_id'],
                'email',
                'partnership_commitment_due',
                [
                    'priority'   => 'normal',
                    'dedupe_key' => 'commitment_due:' . $c['id'] . ':' . $c['next_due_at'],
                    'context'    => [
                        'commitment_id' => $c['id'],
                        'cause_id'      => $c['cause_id'],
                        'amount_minor'  => (int) $c['amount_minor'],
                        'currency'      => $c['currency'],
                    ],
                ],
            );

            $completed = $c['frequency'] === 'one_time';
            $this->db->table('giving_commitments')->where('id', $c['id'])->update([
                'status'           => $completed ? 'completed' : 'active',
                'next_due_at'      => $completed ? null : $this->nextDueDate((string) $c['frequency'], (string) $c['next_due_at']),
                'ended_at'         => $completed ? $now : null,
                'last_reminded_at' => $now,
                'updated_at'       => $now,
            ]);
        }

        return count($due);
    }

    private function nextDueDate(string $frequency, string $fromDate): ?string
    {
        $ts = strtotime($fromDate) ?: time();

        return match ($frequency) {
            'monthly'   => date('Y-m-d', strtotime('+1 month', $ts)),
            'quarterly' => date('Y-m-d', strtotime('+3 months', $ts)),
            'annual'    => date('Y-m-d', strtotime('+1 year', $ts)),
            default     => null, // one_time
        };
    }
}
