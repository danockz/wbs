<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Prospect-transfer maker-checker workflow (FR-REF-7 review path).
 *
 * An inactivity transfer moves a person between groups AND re-parents their
 * sponsor, so a body can require a second leader to sign off before it happens.
 * Whether review is required is HIERARCHICAL GROUP CONFIG —
 * `referrals.prospect_transfer.requires_review`, default OFF — resolved by
 * {@see ProspectTransferService}; this service is the queue that OFF skips.
 *
 * Mirrors {@see SponsorReassignmentService} deliberately, so the two queues gate,
 * read and behave the same way:
 *
 *  - a configured approver set (route gate `sponsor.reassign.approve` + SoD
 *    self-approval block, re-asserted defensively here: maker != checker);
 *  - eligibility computed at submit AND **re-checked at approve** — however long
 *    the request sat in the queue, the contact may have been followed up since or
 *    the receiving mentor may have left their group;
 *  - a stated reason, the trigger that produced it, and the evaluation snapshot
 *    (threshold in force + observed inactivity) so the checker sees the
 *    consequences before deciding;
 *  - an immutable trail: append-only `prospect_transfer_reviews` + hash-chained
 *    audit log;
 *
 * There is deliberately NO expiry: a request stays pending until a human decides
 * it. What protects against stale facts is the eligibility RE-CHECK at approve —
 * a request queued against a world that has since changed is refused and marked
 * blocked, not silently actioned and not silently dropped;
 *  - approval DELEGATES to {@see ProspectTransferService::apply()}, which ends
 *    the old membership and opens the new one exactly as an automatic transfer
 *    would. Nothing here rewrites history, and issued attributions, points and
 *    certificates stay where they happened.
 *
 * A blocked request is still STORED (so the trail exists and the maker sees why)
 * but cannot be approved — same rule as sponsor reassignment.
 */
final class ProspectTransferReviewService
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ProspectTransferService $transfers,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Queue a transfer that {@see ProspectTransferService::evaluate()} already
     * said was due. This is the automatic path's review branch: the follower's
     * touch produced a verdict, and this subtree wants a checker to confirm it.
     *
     * Idempotent per (prospect, receiving mentor): a second invite from the same
     * mentor while a request is pending returns the EXISTING request rather than
     * stacking duplicates in the queue.
     *
     * @param array<string,mixed> $contact    the prospects row
     * @param array<string,mixed> $evaluation evaluate() result (due = true)
     * @param array<string,mixed> $trigger    {type?, id?, note?, actor_id?}
     */
    public function submitFromEvaluation(
        string $organizationId,
        array $contact,
        array $evaluation,
        array $trigger = [],
    ): Result {
        if (empty($evaluation['due'])) {
            return Result::fail('NOT_DUE', 'contact.transfer_not_due', 409, ['reason' => $evaluation['reason'] ?? '']);
        }

        $prospectId  = (string) ($contact['id'] ?? '');
        $newMentorId = (string) ($evaluation['to_owner_user_id'] ?? $trigger['actor_id'] ?? '');
        if ($prospectId === '' || $newMentorId === '') {
            return Result::fail('BAD_INPUT', 'contact.transfer_bad_input', 422);
        }

        $existing = $this->openRequestFor($organizationId, $prospectId, $newMentorId);
        if ($existing !== null) {
            return Result::ok([
                'request_id' => (string) $existing['id'],
                'status'     => (string) $existing['status'],
                'duplicate'  => true,
            ]);
        }

        return $this->open($organizationId, $prospectId, $newMentorId, $newMentorId, [
            'reason'      => (string) ($trigger['note'] ?? '') !== ''
                ? (string) $trigger['note']
                : 'Inactivity transfer: ' . (int) ($evaluation['days_inactive'] ?? 0) . ' days quiet (policy '
                    . (int) ($evaluation['threshold_weeks'] ?? 0) . ' weeks)',
            'trigger'     => $trigger,
            'evaluation'  => $evaluation,
            'approver_id' => $trigger['approver_id'] ?? null,
        ]);
    }

    /**
     * Maker step: a leader proposes that a contact move to another mentor. The
     * proposal is judged by the SAME policy as an automatic transfer — a body
     * with transfers off, or a contact still inside the window, produces a
     * stored-but-blocked request rather than a side door around the rule. (A
     * pure sponsor re-parent with no group move belongs to
     * {@see SponsorReassignmentService} instead.)
     *
     * @param array<string,mixed> $data to_owner_user_id (req), reason (req), approver_id, trigger_type, trigger_id
     */
    public function submit(string $organizationId, string $prospectId, string $requestedBy, array $data): Result
    {
        $newMentorId = trim((string) ($data['to_owner_user_id'] ?? ''));
        $reason      = trim((string) ($data['reason'] ?? ''));
        if ($prospectId === '' || $newMentorId === '' || $requestedBy === '') {
            return Result::fail('BAD_INPUT', 'contact.transfer_bad_input', 422);
        }
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'contact.transfer_reason_required', 422);
        }

        $contact = $this->contact($organizationId, $prospectId);
        if ($contact === null) {
            return Result::notFound('contact.not_found', 'CONTACT_NOT_FOUND');
        }

        // The proposal is judged by the SAME policy as an automatic transfer, so
        // the evaluation is recorded verbatim — including when it says the contact
        // is still inside the window (the request is stored but cannot be
        // approved; a pure sponsor re-parent belongs to the reassignment queue).
        $evaluation = $this->transfers->evaluate($organizationId, $contact, $newMentorId);

        $existing = $this->openRequestFor($organizationId, $prospectId, $newMentorId);
        if ($existing !== null) {
            return Result::fail('ALREADY_PENDING', 'contact.transfer_already_pending', 409, [
                'request_id' => (string) $existing['id'],
            ]);
        }

        return $this->open($organizationId, $prospectId, $newMentorId, $requestedBy, [
            'reason'      => $reason,
            'trigger'     => [
                'type'     => (string) ($data['trigger_type'] ?? 'manual'),
                'id'       => $data['trigger_id'] ?? null,
                'actor_id' => $requestedBy,
            ],
            'evaluation'  => $evaluation,
            'approver_id' => $data['approver_id'] ?? null,
        ]);
    }

    /**
     * Checker step: approve and APPLY. Re-checks the policy and the facts first —
     * a request is only as good as the world it was made in, however long it sat
     * in the queue (there is no TTL: a human decides, the re-check protects).
     */
    public function approve(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        $req = $this->lockRequest($organizationId, $requestId);
        if ($req === null) {
            return Result::notFound('contact.transfer_request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ((string) $req['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'contact.transfer_bad_state', 409, ['status' => (string) $req['status']]);
        }
        // Segregation of duties, re-asserted defensively (the PDP also blocks it).
        if ((string) $req['requested_by'] === $actorId) {
            return Result::fail('SELF_APPROVAL', 'contact.transfer_self_approval', 403);
        }
        // Re-check against CURRENT state: the contact may have been followed up
        // since, the receiving mentor may have lost their home group, or the
        // subtree's policy may have changed while the request sat in the queue.
        $contact = $this->contact($organizationId, (string) $req['prospect_id']);
        if ($contact === null) {
            return Result::notFound('contact.not_found', 'CONTACT_NOT_FOUND');
        }
        $evaluation = $this->transfers->evaluate($organizationId, $contact, (string) $req['to_owner_user_id']);
        if (empty($evaluation['due'])) {
            // Recorded, not silently dropped: the request stays pending with the
            // reason visible, so it can be approved if the facts change (e.g. the
            // contact goes quiet again) or rejected/cancelled to clear the queue.
            $this->markBlocked($organizationId, $requestId, (string) $evaluation['reason'], $actorId);

            return Result::fail('NO_LONGER_ELIGIBLE', 'contact.transfer_no_longer_eligible', 409, [
                'reason' => (string) $evaluation['reason'],
            ]);
        }
        if (($evaluation['to_group_id'] ?? null) === null) {
            $this->markBlocked($organizationId, $requestId, (string) $evaluation['reason'], $actorId);

            return Result::fail('NO_TARGET_GROUP', 'contact.transfer_no_group', 409, [
                'reason' => (string) $evaluation['reason'],
            ]);
        }

        // Apply with the request as provenance. evaluate() is authoritative for
        // the CURRENT threshold/inactivity, so the trail records the truth at the
        // moment of approval, not the numbers from when it was queued.
        $applied = $this->transfers->apply($organizationId, (string) $req['prospect_id'], (string) $req['to_owner_user_id'], $evaluation, [
            'type'       => (string) ($req['trigger_type'] ?? 'manual'),
            'id'         => $req['trigger_id'] ?? null,
            'actor_id'   => $actorId,
            'note'       => $note,
            'request_id' => $requestId,
        ]);
        if (! $applied->ok) {
            return $applied;
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('prospect_transfer_requests')->where('id', $requestId)->update([
            'status'            => 'approved',
            'eligibility_state' => 'ok',
            'decided_by'        => $actorId,
            'decided_at'        => $now,
            'transfer_id'       => $applied->data['transfer_id'] ?? null,
            'threshold_weeks'   => (int) ($evaluation['threshold_weeks'] ?? 0),
            'days_inactive'     => isset($evaluation['days_inactive']) ? (int) $evaluation['days_inactive'] : null,
            'evaluation'        => json_encode($evaluation),
            'updated_at'        => $now,
        ]);
        $this->appendReview($organizationId, $requestId, 'approve', $actorId, $note);

        $this->audit?->record($organizationId, [
            'action'      => 'prospect.transfer.approved',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'prospect_transfer_request',
            'object_id'   => $requestId,
            'outcome'     => 'success',
            'metadata'    => [
                'prospect_id'      => (string) $req['prospect_id'],
                'to_owner_user_id' => (string) $req['to_owner_user_id'],
                'requested_by'     => (string) $req['requested_by'],
                'transfer_id'      => $applied->data['transfer_id'] ?? null,
            ],
        ]);

        return Result::ok($applied->data + ['request_id' => $requestId, 'status' => 'approved']);
    }

    /** Checker step: refuse. The contact stays exactly where it was. */
    public function reject(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        return $this->close($organizationId, $requestId, $actorId, 'rejected', 'reject', $note, true);
    }

    /** Maker (or authorized staff): withdraw a pending request. */
    public function cancel(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        return $this->close($organizationId, $requestId, $actorId, 'cancelled', 'cancel', $note, false);
    }

    /**
     * Requests awaiting the current user as checker: those nominating them, plus
     * unassigned ones. Newest first, bounded.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForApprover(string $organizationId, string $approverId, int $limit = 100): array
    {
        return $this->db->table('prospect_transfer_requests')
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->groupStart()
                ->where('approver_id', $approverId)->orWhere('approver_id', null)
            ->groupEnd()
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null request + its review trail */
    public function find(string $organizationId, string $requestId): ?array
    {
        $req = $this->db->table('prospect_transfer_requests')
            ->where('organization_id', $organizationId)
            ->where('id', $requestId)
            ->get()->getRowArray();
        if ($req === null) {
            return null;
        }
        $req['reviews'] = $this->db->table('prospect_transfer_reviews')
            ->where('request_id', $requestId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        return $req;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Insert the request + its opening review row. Eligibility is recorded, not
     * enforced: a blocked request is stored so the trail exists and the maker sees
     * why, but {@see approve()} will refuse it.
     *
     * @param array<string,mixed> $ctx reason, trigger, evaluation, approver_id
     */
    private function open(string $organizationId, string $prospectId, string $newMentorId, string $requestedBy, array $ctx): Result
    {
        $evaluation = is_array($ctx['evaluation'] ?? null) ? $ctx['evaluation'] : [];
        $trigger    = is_array($ctx['trigger'] ?? null) ? $ctx['trigger'] : [];
        $contact    = $this->contact($organizationId, $prospectId);

        $eligible = ! empty($evaluation['due']);
        $now      = $this->clock->nowUtcMicro();
        $id       = Uuid::v7();

        $this->db->transStart();
        $this->db->table('prospect_transfer_requests')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'prospect_id'        => $prospectId,
            'linked_user_id'     => $contact['linked_user_id'] ?? ($evaluation['linked_user_id'] ?? null),
            'from_group_id'      => $evaluation['from_group_id'] ?? ($contact['assigned_group_id'] ?? null),
            'to_group_id'        => (string) ($evaluation['to_group_id'] ?? ''),
            'from_owner_user_id' => $evaluation['from_owner_user_id'] ?? ($contact['owner_user_id'] ?? null),
            'to_owner_user_id'   => $newMentorId,
            'requested_by'       => $requestedBy,
            'approver_id'        => isset($ctx['approver_id']) && $ctx['approver_id'] !== '' ? (string) $ctx['approver_id'] : null,
            'reason'             => mb_substr((string) ($ctx['reason'] ?? ''), 0, 500),
            'trigger_type'       => isset($trigger['type']) ? (string) $trigger['type'] : null,
            'trigger_id'         => isset($trigger['id']) ? (string) $trigger['id'] : null,
            'threshold_weeks'    => isset($evaluation['threshold_weeks']) ? (int) $evaluation['threshold_weeks'] : null,
            'days_inactive'      => isset($evaluation['days_inactive']) ? (int) $evaluation['days_inactive'] : null,
            'evaluation'         => json_encode($evaluation),
            'status'             => 'pending',
            'eligibility_state'  => $eligible ? 'ok' : 'blocked',
            'eligibility_detail' => $eligible ? null : mb_substr((string) ($evaluation['reason'] ?? ''), 0, 500),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $this->appendReview($organizationId, $id, 'submit', $requestedBy, $ctx['reason'] ?? null);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('SUBMIT_FAILED', 'contact.transfer_submit_failed', 500);
        }

        $this->audit?->record($organizationId, [
            'action'      => 'prospect.transfer.requested',
            'actor_id'    => $requestedBy,
            'actor_type'  => 'user',
            'object_type' => 'prospect_transfer_request',
            'object_id'   => $id,
            'outcome'     => $eligible ? 'success' : 'blocked',
            'metadata'    => [
                'prospect_id'       => $prospectId,
                'to_owner_user_id'  => $newMentorId,
                'to_group_id'       => $evaluation['to_group_id'] ?? null,
                'eligibility_state' => $eligible ? 'ok' : 'blocked',
                'eligibility_detail' => $eligible ? null : ($evaluation['reason'] ?? null),
                'trigger_type'      => $trigger['type'] ?? null,
            ],
        ]);

        return Result::created([
            'request_id'        => $id,
            'status'            => 'pending',
            'eligibility_state' => $eligible ? 'ok' : 'blocked',
            'eligibility_detail' => $eligible ? null : ($evaluation['reason'] ?? null),
            'duplicate'         => false,
        ]);
    }

    private function close(
        string $organizationId,
        string $requestId,
        string $actorId,
        string $status,
        string $action,
        ?string $note,
        bool $checkerOnly,
    ): Result {
        $req = $this->lockRequest($organizationId, $requestId);
        if ($req === null) {
            return Result::notFound('contact.transfer_request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ((string) $req['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'contact.transfer_bad_state', 409, ['status' => (string) $req['status']]);
        }
        if ($checkerOnly && (string) $req['requested_by'] === $actorId) {
            return Result::fail('SELF_APPROVAL', 'contact.transfer_self_approval', 403);
        }

        $this->setStatus($organizationId, $requestId, $status, $actorId);
        $this->appendReview($organizationId, $requestId, $action, $actorId, $note);

        $this->audit?->record($organizationId, [
            'action'      => 'prospect.transfer.' . $status,
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'prospect_transfer_request',
            'object_id'   => $requestId,
            'outcome'     => 'success',
            'metadata'    => ['prospect_id' => (string) $req['prospect_id'], 'note' => $note],
        ]);

        return Result::ok(['request_id' => $requestId, 'status' => $status]);
    }

    private function setStatus(string $organizationId, string $requestId, string $status, ?string $actorId): void
    {
        $this->db->table('prospect_transfer_requests')->where('id', $requestId)->update([
            'status'     => $status,
            'decided_by' => $actorId,
            'decided_at' => $this->clock->nowUtcMicro(),
            'updated_at' => $this->clock->nowUtcMicro(),
        ]);
    }

    private function markBlocked(string $organizationId, string $requestId, string $detail, string $actorId): void
    {
        $now = $this->clock->nowUtcMicro();
        $this->db->table('prospect_transfer_requests')->where('id', $requestId)->update([
            'eligibility_state'  => 'blocked',
            'eligibility_detail' => mb_substr($detail, 0, 500),
            'updated_at'         => $now,
        ]);
        $this->appendReview($organizationId, $requestId, 'block', $actorId, $detail);
    }

    private function appendReview(string $organizationId, string $requestId, string $action, ?string $actorId, ?string $note): void
    {
        $this->db->table('prospect_transfer_reviews')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'request_id'      => $requestId,
            'action'          => $action,
            'actor_id'        => $actorId,
            'note'            => $note !== null ? mb_substr($note, 0, 500) : null,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function lockRequest(string $organizationId, string $requestId): ?array
    {
        return $this->db->table('prospect_transfer_requests')
            ->where('organization_id', $organizationId)
            ->where('id', $requestId)
            ->get()->getRowArray();
    }

    /** @return array<string,mixed>|null */
    private function contact(string $organizationId, string $prospectId): ?array
    {
        return $this->db->table('prospects')
            ->where('organization_id', $organizationId)
            ->where('id', $prospectId)
            ->get()->getRowArray();
    }

    /** The still-pending request for this (prospect, receiving mentor), if any. */
    private function openRequestFor(string $organizationId, string $prospectId, string $newMentorId): ?array
    {
        return $this->db->table('prospect_transfer_requests')
            ->where('organization_id', $organizationId)
            ->where('prospect_id', $prospectId)
            ->where('to_owner_user_id', $newMentorId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'DESC')
            ->get()->getRowArray();
    }
}
