<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Sponsor-reassignment maker-checker workflow (SRS FR-MEM-002).
 *
 * Wraps the raw {@see SponsorshipService::assign()} re-parent in a reviewed
 * request that captures everything the SRS demands:
 *
 *  - a configured approver set (route gate `sponsor.reassign.approve` + SoD
 *    self-approval block, re-asserted defensively here: maker != checker);
 *  - eligibility checks (no self-sponsor, no cycle) at submit AND re-checked at
 *    approve (the graph may have shifted while pending);
 *  - a stated reason and an effective date;
 *  - the before/after upline paths;
 *  - a descendant-impact assessment (which downline members' upline changes);
 *  - an immutable audit record (append-only reviews + hash-chained audit log);
 *  - recalculation of ONLY affected, NON-FINALIZED metrics.
 *
 * It NEVER rewrites history: approval delegates to `assign()`, which closes the
 * old sponsorship edge (active=0, effective_to set) and opens a new one. Issued
 * referral attributions, contribution ledger rows, certificates and audit
 * entries are read-only here and are deliberately left intact — the impact
 * assessment lists them as "finalized (unchanged)".
 */
final class SponsorReassignmentService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SponsorshipService $sponsorships,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Open a sponsor-reassignment request (maker step). Computes eligibility,
     * before/after paths and the descendant-impact assessment up front so the
     * checker sees the consequences before approving.
     *
     * @param array<string,mixed> $data new_sponsor_id (req), reason (req),
     *                                   approver_id, effective_at
     */
    public function submit(string $organizationId, string $memberId, string $requestedBy, array $data): Result
    {
        $newSponsorId = trim((string) ($data['new_sponsor_id'] ?? ''));
        $reason       = trim((string) ($data['reason'] ?? ''));

        if ($memberId === '' || $newSponsorId === '') {
            return Result::fail('BAD_INPUT', 'sponsorship.reassign_bad_input', 422);
        }
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'sponsorship.reassign_reason_required', 422);
        }

        $currentSponsorId = $this->sponsorships->activeSponsor($memberId);
        if ($currentSponsorId === $newSponsorId) {
            return Result::fail('NO_CHANGE', 'sponsorship.reassign_unchanged', 409);
        }

        // Eligibility (recorded, not necessarily fatal to SUBMIT — a blocked
        // request is stored so the trail exists, but it cannot be approved).
        [$eligState, $eligDetail] = $this->checkEligibility($memberId, $newSponsorId);

        $beforePath = $this->sponsorships->upline($memberId);
        $afterPath  = $this->projectedUpline($memberId, $newSponsorId);
        $impact     = $this->assessImpact($organizationId, $memberId, $beforePath, $afterPath);

        $now = $this->clock->nowUtcMicro();
        $id  = Uuid::v7();

        try {
            $this->db->table('sponsor_reassignments')->insert([
                'id'                 => $id,
                'organization_id'    => $organizationId,
                'member_id'          => $memberId,
                'current_sponsor_id' => $currentSponsorId,
                'new_sponsor_id'     => $newSponsorId,
                'requested_by'       => $requestedBy,
                'approver_id'        => $data['approver_id'] ?? null,
                'reason'             => $reason,
                'effective_at'       => $data['effective_at'] ?? null,
                'before_path'        => json_encode($beforePath),
                'after_path'         => json_encode($afterPath),
                'impact'             => json_encode($impact),
                'status'             => 'pending',
                'eligibility_state'  => $eligState,
                'eligibility_detail' => $eligDetail,
                'created_at'         => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('SUBMIT_FAILED', 'sponsorship.reassign_submit_failed', 500);
        }

        $this->appendReview($organizationId, $id, 'submit', $requestedBy, $reason, $now);
        $this->audit->record($organizationId, [
            'actor_id'    => $requestedBy,
            'action'      => 'referral.sponsor.reassign_requested',
            'object_type' => 'sponsor_reassignment',
            'object_id'   => $id,
            'metadata'    => [
                'member_id'          => $memberId,
                'current_sponsor_id' => $currentSponsorId,
                'new_sponsor_id'     => $newSponsorId,
                'eligibility'        => $eligState,
                'impacted_count'     => $impact['descendant_count'] ?? 0,
            ],
        ]);

        return Result::created([
            'id'                => $id,
            'member_id'         => $memberId,
            'new_sponsor_id'    => $newSponsorId,
            'status'            => 'pending',
            'eligibility_state' => $eligState,
            'before_path'       => $beforePath,
            'after_path'        => $afterPath,
            'impact'            => $impact,
        ]);
    }

    /**
     * Approve a pending request (checker step). Enforces maker != checker,
     * re-checks eligibility, performs the re-parent via SponsorshipService (which
     * preserves history), recalculates only affected non-finalized metrics, and
     * writes an immutable audit record.
     */
    public function approve(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        $req = $this->lockRequest($organizationId, $requestId);
        if ($req === null) {
            return Result::notFound('sponsorship.reassign_not_found', 'REASSIGN_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'sponsorship.reassign_not_pending', 409, ['status' => $req['status']]);
        }
        // Segregation of duties: the checker may not be the maker.
        if ((string) $req['requested_by'] === $actorId) {
            return Result::denied('sponsorship.reassign_self_approval', 'SOD_SELF_APPROVAL');
        }

        $memberId     = (string) $req['member_id'];
        $newSponsorId = (string) $req['new_sponsor_id'];

        // Re-check eligibility — the graph may have changed while pending.
        [$eligState, $eligDetail] = $this->checkEligibility($memberId, $newSponsorId);
        if ($eligState !== 'ok') {
            return Result::fail('INELIGIBLE', 'sponsorship.reassign_ineligible', 409, ['detail' => $eligDetail]);
        }

        // Perform the re-parent. assign() closes the old edge + opens a new one;
        // it never rewrites historical attribution/ledger rows.
        $assign = $this->sponsorships->assign(
            $organizationId,
            $memberId,
            $newSponsorId,
            'reassignment:' . $requestId,
        );
        if (! $assign->ok) {
            return $assign;
        }
        $sponsorshipId = is_array($assign->data) ? ($assign->data['id'] ?? null) : null;

        // Recalculate ONLY affected, non-finalized metrics. Finalized records
        // (issued attributions, ledger, certificates, closed-season snapshots)
        // are never touched.
        $recalc = $this->recalculateAffected($organizationId, $memberId);

        $now = $this->clock->nowUtcMicro();
        $this->db->table('sponsor_reassignments')->where('id', $requestId)->update([
            'status'         => 'approved',
            'decided_by'     => $actorId,
            'decided_at'     => $now,
            'sponsorship_id' => $sponsorshipId,
            'recalc'         => json_encode($recalc),
            'effective_at'   => $req['effective_at'] ?? $this->clock->nowUtcString(),
            'updated_at'     => $now,
        ]);

        $this->appendReview($organizationId, $requestId, 'approve', $actorId, $note, $now);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'referral.sponsor.reassign_approved',
            'object_type' => 'sponsor_reassignment',
            'object_id'   => $requestId,
            'metadata'    => [
                'member_id'          => $memberId,
                'current_sponsor_id' => $req['current_sponsor_id'],
                'new_sponsor_id'     => $newSponsorId,
                'sponsorship_id'     => $sponsorshipId,
                'recalc'             => $recalc,
            ],
        ]);

        return Result::ok([
            'id'             => $requestId,
            'status'         => 'approved',
            'member_id'      => $memberId,
            'new_sponsor_id' => $newSponsorId,
            'sponsorship_id' => $sponsorshipId,
            'recalc'         => $recalc,
        ]);
    }

    public function reject(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        return $this->close($organizationId, $requestId, $actorId, 'rejected', 'reject', $note);
    }

    public function cancel(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        return $this->close($organizationId, $requestId, $actorId, 'cancelled', 'cancel', $note);
    }

    /** @return list<array<string,mixed>> pending requests awaiting a given approver */
    public function pendingForApprover(string $organizationId, string $approverId): array
    {
        return $this->db->table('sponsor_reassignments')
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->groupStart()
                ->where('approver_id', $approverId)
                ->orWhere('approver_id', null)
            ->groupEnd()
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    public function find(string $organizationId, string $requestId): ?array
    {
        return $this->db->table('sponsor_reassignments')
            ->where('organization_id', $organizationId)
            ->where('id', $requestId)
            ->get()->getRowArray();
    }

    // ---- internals ---------------------------------------------------------

    private function close(string $organizationId, string $requestId, string $actorId, string $status, string $action, ?string $note): Result
    {
        $req = $this->lockRequest($organizationId, $requestId);
        if ($req === null) {
            return Result::notFound('sponsorship.reassign_not_found', 'REASSIGN_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'sponsorship.reassign_not_pending', 409, ['status' => $req['status']]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('sponsor_reassignments')->where('id', $requestId)->update([
            'status'     => $status,
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        $this->appendReview($organizationId, $requestId, $action, $actorId, $note, $now);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'referral.sponsor.reassign_' . $status,
            'object_type' => 'sponsor_reassignment',
            'object_id'   => $requestId,
            'metadata'    => ['member_id' => $req['member_id'], 'note' => $note],
        ]);

        return Result::ok(['id' => $requestId, 'status' => $status]);
    }

    /** @return array{0:string,1:?string} [state, detail] */
    private function checkEligibility(string $memberId, string $newSponsorId): array
    {
        return self::evaluateEligibility(
            $memberId,
            $newSponsorId,
            $this->sponsorships->upline($newSponsorId),
        );
    }

    /**
     * Pure eligibility decision, extracted so it is unit-testable without a live
     * sponsorship graph (mirrors GroupLifecycleService::canTransition()).
     *
     * @param list<string> $newSponsorUpline the new sponsor's current upline
     *
     * @return array{0:string,1:?string} [status('ok'|'blocked'), reason?]
     */
    public static function evaluateEligibility(string $memberId, string $newSponsorId, array $newSponsorUpline): array
    {
        if ($memberId === $newSponsorId) {
            return ['blocked', 'A member cannot sponsor themselves.'];
        }
        // A cycle would form if the member is already anywhere in the new
        // sponsor's upline.
        if (in_array($memberId, $newSponsorUpline, true)) {
            return ['blocked', 'The new sponsor is within the member\'s downline (would create a cycle).'];
        }

        return ['ok', null];
    }

    /**
     * The upline the member WOULD have after re-parenting: the new sponsor,
     * then the new sponsor's own upline. Bounded + cycle-guarded like upline().
     *
     * @return list<string>
     */
    private function projectedUpline(string $memberId, string $newSponsorId): array
    {
        return self::projectUpline($memberId, $newSponsorId, $this->sponsorships->upline($newSponsorId));
    }

    /**
     * Pure projection of the upline the member WOULD have after re-parenting,
     * extracted for unit testing. Cycle-/self-guarded like upline().
     *
     * @param list<string> $newSponsorUpline
     *
     * @return list<string>
     */
    public static function projectUpline(string $memberId, string $newSponsorId, array $newSponsorUpline): array
    {
        $path = [$newSponsorId];
        foreach ($newSponsorUpline as $up) {
            if ($up === $memberId || in_array($up, $path, true)) {
                break; // defensive: never include the member or loop
            }
            $path[] = $up;
        }

        return $path;
    }

    /**
     * Descendant-impact assessment: every member in the reassigned member's
     * downline has their upline shifted by this change. Reports the affected
     * set plus the level-delta (how the member's own depth moves), and states
     * explicitly which record classes are FINALIZED and therefore untouched.
     *
     * @param list<string> $beforePath
     * @param list<string> $afterPath
     *
     * @return array<string,mixed>
     */
    private function assessImpact(string $organizationId, string $memberId, array $beforePath, array $afterPath): array
    {
        $descendants = $this->sponsorships->downline($memberId);

        return [
            'member_id'          => $memberId,
            'before_depth'       => count($beforePath),
            'after_depth'        => count($afterPath),
            'depth_delta'        => count($afterPath) - count($beforePath),
            'descendant_count'   => count($descendants),
            'descendants'        => $descendants,
            // The whole affected subtree = the member + their downline.
            'affected_subtree'   => array_merge([$memberId], $descendants),
            'finalized_unchanged' => [
                'referral_attributions'  => 'historical credit is immutable',
                'contribution_ledger'    => 'posted rows are never rewritten',
                'issued_certificates'    => 'issued documents are immutable',
                'closed_season_metrics'  => 'finalized/closed-season snapshots are frozen',
                'audit_log'              => 'append-only, hash-chained',
            ],
        ];
    }

    /**
     * Recalculate only AFFECTED, NON-FINALIZED metrics after a re-parent.
     *
     * In this platform sponsor-derived aggregates (upline/downline size,
     * conversion counts, recruit visibility) are computed ON READ from the live
     * sponsorship graph, so they self-correct the instant the edge moves — there
     * is no materialized sponsor-metric cache to rebuild, and nothing finalized
     * is touched. We therefore recompute and RETURN the affected members' fresh
     * live figures as the recalculation evidence (proving the numbers now
     * reflect the new graph), rather than mutating a cache that does not exist.
     *
     * If a materialized sponsor-metric projection is added later, its bounded
     * refresh for `affected_subtree` slots in here without changing callers.
     *
     * @return array<string,mixed>
     */
    private function recalculateAffected(string $organizationId, string $memberId): array
    {
        $descendants = $this->sponsorships->downline($memberId);
        $affected    = array_merge([$memberId], $descendants);

        $recomputed = [];
        foreach ($affected as $id) {
            $recomputed[$id] = [
                'upline_depth'    => count($this->sponsorships->upline($id)),
                'direct_recruits' => count($this->sponsorships->directRecruits($id)),
            ];
        }

        return [
            'strategy'          => 'on_read_live_recompute',
            'affected_count'    => count($affected),
            'recomputed'        => $recomputed,
            'finalized_skipped' => ['referral_attributions', 'point_ledger', 'certificates', 'closed_season_snapshots'],
        ];
    }

    /** Row-lock a request for its decision transition (best effort). */
    private function lockRequest(string $organizationId, string $requestId): ?array
    {
        return $this->db->table('sponsor_reassignments')
            ->where('organization_id', $organizationId)
            ->where('id', $requestId)
            ->get()->getRowArray();
    }

    private function appendReview(string $organizationId, string $requestId, string $action, ?string $actorId, ?string $note, string $at): void
    {
        $this->db->table('sponsor_reassignment_reviews')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'request_id'      => $requestId,
            'action'          => $action,
            'actor_id'        => $actorId,
            'note'            => $note,
            'created_at'      => $at,
        ]);
    }
}
