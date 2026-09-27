<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\Audit\Services\AuditLogger;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Access-request / approval workflow (SRS FR-ACL-004).
 *
 *  - submit(): a request must carry requested scope, business reason, duration
 *    and a nominated approver. On submit we run CONFLICT DETECTION (would the
 *    grant create a segregation-of-duties conflict?) and flag it for the
 *    reviewer — we never silently grant.
 *  - approve()/reject(): MAKER-CHECKER via the PDP. The reviewer must hold
 *    `access.request.approve`, and the SoD combinator (action
 *    `access.request.approve`) denies self-approval — a requester can never
 *    approve their own access. Approval materializes an EXPIRING, audited
 *    role_assignment linked back to the request (FR-ACL-003).
 *  - renew()/revoke(): extend or cut an active grant; both are audited.
 *  - Every state change appends an immutable review row, writes an audit-log
 *    entry, and stages a notification.
 *
 * All privileged actions are authorized through the injected PDP, so this
 * service cannot be used to bypass default-deny.
 */
final class AccessRequestService
{
    /** Permission that lets a subject review access requests. */
    private const REVIEW_PERMISSION = 'access.request.approve';

    /**
     * Finance-adjacent permissions whose holder should not simultaneously hold
     * the opposing side. Used for conflict detection at request time.
     *
     * @var array<string,string>
     */
    private const CONFLICTING_PAIRS = [
        'contribution.refund.request' => 'contribution.refund.approve',
        'contribution.refund.approve' => 'contribution.refund.request',
        'event.expense.submit'        => 'event.expense.approve',
        'event.expense.approve'       => 'event.expense.submit',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuthorizationService $pdp,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly GrantScopeWriter $scope,
        private readonly ?DelegationService $delegations = null,
    ) {
    }

    /**
     * Submit an access request. Validates the target grant, records the
     * justification/duration/approver, and flags SoD conflicts for the reviewer.
     *
     * @param array<string,mixed> $data grant_type, role_id|permission_code,
     *   scope_group_id, scope_mode?, include_descendants, scope_groups?,
     *   include_crosscut?, reason, duration_days, approver_id
     */
    public function submit(string $organizationId, string $subjectId, string $requestedBy, array $data): Result
    {
        if ($subjectId === '') {
            return Result::fail('SUBJECT_REQUIRED', 'access.subject_required', 422);
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'access.reason_required', 422);
        }

        $grantType = ($data['grant_type'] ?? 'role') === 'permission' ? 'permission' : 'role';
        $roleId    = null;
        $permCode  = null;

        if ($grantType === 'role') {
            $roleId = (string) ($data['role_id'] ?? '');
            if ($roleId === '') {
                return Result::fail('ROLE_REQUIRED', 'access.role_required', 422);
            }
            $role = $this->db->table('roles')
                ->where('id', $roleId)->where('organization_id', $organizationId)
                ->get()->getRowArray();
            if ($role === null) {
                return Result::notFound('access.role_not_found', 'ROLE_NOT_FOUND');
            }
        } else {
            $permCode = (string) ($data['permission_code'] ?? '');
            if ($permCode === '') {
                return Result::fail('PERMISSION_REQUIRED', 'access.permission_required', 422);
            }
            $perm = $this->db->table('permissions')->where('code', $permCode)->get()->getRowArray();
            if ($perm === null) {
                return Result::notFound('access.permission_not_found', 'PERMISSION_NOT_FOUND');
            }
        }

        // Full scope model (mode + hand-picked set + opt-in cross-cut).
        $scope = $this->scope->parse($data);
        if (! $scope['ok']) {
            return Result::fail('BAD_SCOPE', $scope['message'], 422, $scope['extra'] ?? []);
        }

        // Conflict detection (SoD): would this grant give the subject both sides
        // of a maker-checker pair?
        [$conflictState, $conflictDetail] = $this->detectConflict($organizationId, $subjectId, $grantType, $roleId, $permCode);

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('access_requests')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'subject_id'          => $subjectId,
            'requested_by'        => $requestedBy,
            'grant_type'          => $grantType,
            'role_id'             => $roleId,
            'permission_code'     => $permCode,
            'reason'              => $reason,
            'duration_days'       => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            'approver_id'         => $data['approver_id'] ?? null,
            'status'              => 'pending',
            'conflict_state'      => $conflictState,
            'conflict_detail'     => $conflictDetail,
            'created_at'          => $now,
        ] + $this->scope->columns($scope));
        $this->scope->syncGroupSet($organizationId, 'access_request', $id, $scope);

        $this->audit->record($organizationId, [
            'actor_id'    => $requestedBy,
            'action'      => 'access.request.submitted',
            'object_type' => 'access_request',
            'object_id'   => $id,
            'metadata'    => ['subject_id' => $subjectId, 'grant_type' => $grantType, 'conflict' => $conflictState],
        ]);

        // Notify the nominated approver (best-effort; never blocks the request).
        if (! empty($data['approver_id'])) {
            $this->notifyReviewer($organizationId, (string) $data['approver_id'], $id, $conflictState);
        }

        return Result::created([
            'request_id'     => $id,
            'status'         => 'pending',
            'conflict_state' => $conflictState,
            'conflict_detail' => $conflictDetail,
        ]);
    }

    /**
     * Approve a pending request. Enforces maker-checker through the PDP:
     * the actor must hold REVIEW_PERMISSION and must not be the requester or the
     * subject (SoD action `access.request.approve`). Materializes an expiring,
     * audited role assignment linked to the request.
     */
    public function approve(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        $req = $this->lockRequest($requestId);
        if ($req === null) {
            return Result::notFound('access.request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'access.bad_state', 409, ['status' => $req['status']]);
        }

        // Maker-checker via the PDP. submitted_by/subject flow as attributes so
        // the SoD combinator can deny self-approval; the RBAC layer requires the
        // reviewer permission.
        $decision = $this->pdp->decide(new AccessRequest(
            $organizationId,
            $actorId,
            self::REVIEW_PERMISSION,
            'access_request',
            $requestId,
            [
                'submitted_by' => $req['requested_by'],
                'subject_id'   => $req['subject_id'],
            ],
        ));
        if (! $decision->isPermitted()) {
            $this->db->transComplete();

            return Result::denied('access.approve_denied', 'APPROVE_DENIED');
        }
        // Belt-and-braces: the subject of the grant may never approve their own.
        if ((string) $req['subject_id'] === $actorId) {
            $this->db->transComplete();

            return Result::fail('SOD_SELF_APPROVAL', 'access.sod_self_approval', 403);
        }

        $now      = $this->clock->nowUtcString();
        $nowMicro = $this->clock->nowUtcMicro();
        $from     = $now;
        $to       = null;
        if ($req['duration_days'] !== null) {
            $to = $this->clock->now()->modify('+' . (int) $req['duration_days'] . ' days')->format('Y-m-d H:i:s');
        }

        $assignmentId = null;
        // Only role grants materialize a role_assignment; permission grants are
        // recorded as approved requests consulted by the PDP's direct-grant path.
        if ($req['grant_type'] === 'role') {
            $assignmentId = $this->materializeRoleAssignment($organizationId, $req, $actorId, $requestId, $from, $to);
        }

        $this->db->table('access_requests')->where('id', $requestId)->update([
            'status'         => 'approved',
            'decided_by'     => $actorId,
            'decided_at'     => $nowMicro,
            'assignment_id'  => $assignmentId,
            'effective_from' => $from,
            'effective_to'   => $to,
            'updated_at'     => $nowMicro,
        ]);
        $this->appendReview($organizationId, $requestId, 'approve', $actorId, $note, $nowMicro);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('APPROVE_FAILED', 'access.approve_failed', 500);
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'access.request.approved',
            'object_type' => 'access_request',
            'object_id'   => $requestId,
            'metadata'    => ['subject_id' => $req['subject_id'], 'assignment_id' => $assignmentId, 'effective_to' => $to],
        ]);
        $this->notifyOutcome($organizationId, (string) $req['subject_id'], $requestId, 'approved');

        return Result::ok([
            'request_id'    => $requestId,
            'status'        => 'approved',
            'assignment_id' => $assignmentId,
            'effective_to'  => $to,
        ]);
    }

    /** Reject a pending request (maker-checker, same authorization as approve). */
    public function reject(string $organizationId, string $requestId, string $actorId, ?string $note = null): Result
    {
        $req = $this->lockRequest($requestId);
        if ($req === null) {
            return Result::notFound('access.request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'access.bad_state', 409, ['status' => $req['status']]);
        }

        $decision = $this->pdp->decide(new AccessRequest(
            $organizationId,
            $actorId,
            self::REVIEW_PERMISSION,
            'access_request',
            $requestId,
            ['submitted_by' => $req['requested_by'], 'subject_id' => $req['subject_id']],
        ));
        if (! $decision->isPermitted()) {
            $this->db->transComplete();

            return Result::denied('access.reject_denied', 'REJECT_DENIED');
        }

        $nowMicro = $this->clock->nowUtcMicro();
        $this->db->table('access_requests')->where('id', $requestId)->update([
            'status'     => 'rejected',
            'decided_by' => $actorId,
            'decided_at' => $nowMicro,
            'updated_at' => $nowMicro,
        ]);
        $this->appendReview($organizationId, $requestId, 'reject', $actorId, $note, $nowMicro);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REJECT_FAILED', 'access.reject_failed', 500);
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'access.request.rejected',
            'object_type' => 'access_request',
            'object_id'   => $requestId,
            'metadata'    => ['subject_id' => $req['subject_id']],
        ]);
        $this->notifyOutcome($organizationId, (string) $req['subject_id'], $requestId, 'rejected');

        return Result::ok(['request_id' => $requestId, 'status' => 'rejected']);
    }

    /**
     * Revoke an approved/active grant early. Deactivates the assignment and
     * marks the request revoked; audited and notified.
     */
    public function revoke(string $organizationId, string $requestId, string $actorId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'access.reason_required', 422);
        }
        $req = $this->lockRequest($requestId);
        if ($req === null) {
            return Result::notFound('access.request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ($req['status'] !== 'approved') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'access.bad_state', 409, ['status' => $req['status']]);
        }

        $nowMicro = $this->clock->nowUtcMicro();
        $now      = $this->clock->nowUtcString();
        if ($req['assignment_id'] !== null) {
            $this->db->table('role_assignments')->where('id', $req['assignment_id'])->update([
                'status'     => 'revoked',
                'revoked_at' => $now,
            ]);
        }
        $this->db->table('access_requests')->where('id', $requestId)->update([
            'status'     => 'revoked',
            'updated_at' => $nowMicro,
        ]);
        $this->appendReview($organizationId, $requestId, 'revoke', $actorId, $reason, $nowMicro);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REVOKE_FAILED', 'access.revoke_failed', 500);
        }

        // Cascade to delegations carved from this grant (gap AC2). A delegation
        // may be rooted on the access_request itself (a `permission` grant) or on
        // the role_assignment it materialized (a `role` grant) — revoke both.
        $cascaded = 0;
        if ($this->delegations !== null) {
            $cascaded += $this->delegations->revokeBySourceGrant($organizationId, 'access_request', $requestId, $actorId, 'source access request revoked');
            if ($req['assignment_id'] !== null) {
                $cascaded += $this->delegations->revokeBySourceGrant($organizationId, 'role_assignment', (string) $req['assignment_id'], $actorId, 'source access request revoked');
            }
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'access.request.revoked',
            'object_type' => 'access_request',
            'object_id'   => $requestId,
            'metadata'    => ['subject_id' => $req['subject_id'], 'reason' => $reason, 'delegations_cascaded' => $cascaded],
        ]);
        $this->notifyOutcome($organizationId, (string) $req['subject_id'], $requestId, 'revoked');

        return Result::ok(['request_id' => $requestId, 'status' => 'revoked', 'delegations_cascaded' => $cascaded]);
    }

    /**
     * Renew an approved grant by extending its expiry. Requires reviewer
     * authorization (maker-checker) and a positive duration.
     */
    public function renew(string $organizationId, string $requestId, string $actorId, int $extraDays, ?string $note = null): Result
    {
        if ($extraDays <= 0) {
            return Result::fail('BAD_DURATION', 'access.bad_duration', 422);
        }
        $req = $this->lockRequest($requestId);
        if ($req === null) {
            return Result::notFound('access.request_not_found', 'REQUEST_NOT_FOUND');
        }
        if ($req['status'] !== 'approved') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'access.bad_state', 409, ['status' => $req['status']]);
        }

        $decision = $this->pdp->decide(new AccessRequest(
            $organizationId,
            $actorId,
            self::REVIEW_PERMISSION,
            'access_request',
            $requestId,
            ['submitted_by' => $req['requested_by'], 'subject_id' => $req['subject_id']],
        ));
        if (! $decision->isPermitted()) {
            $this->db->transComplete();

            return Result::denied('access.renew_denied', 'RENEW_DENIED');
        }

        // Base the new expiry on the later of now / current expiry.
        $base = $req['effective_to'] !== null && $req['effective_to'] > $this->clock->nowUtcString()
            ? new \DateTimeImmutable((string) $req['effective_to'])
            : $this->clock->now();
        $newTo    = $base->modify('+' . $extraDays . ' days')->format('Y-m-d H:i:s');
        $nowMicro = $this->clock->nowUtcMicro();

        if ($req['assignment_id'] !== null) {
            $this->db->table('role_assignments')->where('id', $req['assignment_id'])->update([
                'effective_to' => $newTo,
                'status'       => 'active',
            ]);
        }
        $this->db->table('access_requests')->where('id', $requestId)->update([
            'effective_to' => $newTo,
            'updated_at'   => $nowMicro,
        ]);
        $this->appendReview($organizationId, $requestId, 'renew', $actorId, $note, $nowMicro);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('RENEW_FAILED', 'access.renew_failed', 500);
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'access.request.renewed',
            'object_type' => 'access_request',
            'object_id'   => $requestId,
            'metadata'    => ['subject_id' => $req['subject_id'], 'effective_to' => $newTo],
        ]);

        return Result::ok(['request_id' => $requestId, 'status' => 'approved', 'effective_to' => $newTo]);
    }

    /**
     * Expire lapsed grants and stale pending requests. Idempotent; intended to
     * be run periodically (see AccessExpireCommand). Returns counts.
     */
    public function expireLapsed(?string $organizationId = null): Result
    {
        $now = $this->clock->nowUtcString();

        // 1) Deactivate role_assignments past their effective_to.
        $assignQ = $this->db->table('role_assignments')
            ->where('status', 'active')
            ->where('effective_to IS NOT NULL')
            ->where('effective_to <=', $now);
        if ($organizationId !== null) {
            $assignQ->where('organization_id', $organizationId);
        }
        $expiredAssignments = $assignQ->update(['status' => 'expired']);

        // 2) Mark the owning approved requests expired.
        $reqQ = $this->db->table('access_requests')
            ->where('status', 'approved')
            ->where('effective_to IS NOT NULL')
            ->where('effective_to <=', $now);
        if ($organizationId !== null) {
            $reqQ->where('organization_id', $organizationId);
        }
        $expiredRequests = $reqQ->update(['status' => 'expired', 'updated_at' => $this->clock->nowUtcMicro()]);

        // 3) Cascade to delegations whose source grant is no longer live (gap
        // AC2): a delegation must not outlive the role assignment / access
        // request it was carved from, even when that grant lapses via the sweep
        // rather than an explicit revoke. Expire any active delegation whose
        // source grant is not currently active/approved.
        $cascaded = 0;
        if ($this->delegations !== null) {
            $cascaded = $this->delegations->expireOrphanedBySource($organizationId, $now);
        }

        return Result::ok([
            'expired_assignments'  => $this->db->affectedRows() >= 0 ? (bool) $expiredAssignments : false,
            'expired_requests'     => (bool) $expiredRequests,
            'delegations_cascaded' => $cascaded,
            'as_of'                => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function pendingForApprover(string $organizationId, string $approverId): array
    {
        return $this->db->table('access_requests')
            ->where('organization_id', $organizationId)
            ->where('approver_id', $approverId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    public function find(string $requestId): ?array
    {
        $req = $this->db->table('access_requests')->where('id', $requestId)->get()->getRowArray();
        if ($req === null) {
            return null;
        }
        $req['reviews'] = $this->db->table('access_request_reviews')
            ->where('request_id', $requestId)->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        return $req;
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Detect whether granting this role/permission would give the subject both
     * sides of a conflicting maker-checker pair.
     *
     * @return array{0:string,1:?string} [conflict_state, detail]
     */
    private function detectConflict(string $organizationId, string $subjectId, string $grantType, ?string $roleId, ?string $permCode): array
    {
        // Resolve the permission codes this grant would confer.
        $granted = [];
        if ($grantType === 'permission' && $permCode !== null) {
            $granted[] = $permCode;
        } elseif ($grantType === 'role' && $roleId !== null) {
            $rows = $this->db->table('role_permissions rp')
                ->select('p.code')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('rp.role_id', $roleId)
                ->get()->getResultArray();
            $granted = array_map(static fn ($r) => (string) $r['code'], $rows);
        }

        // Permissions the subject already effectively holds.
        $held = $this->effectivePermissions($organizationId, $subjectId);

        foreach ($granted as $code) {
            $opposite = self::CONFLICTING_PAIRS[$code] ?? null;
            if ($opposite !== null && in_array($opposite, $held, true)) {
                return ['flagged', sprintf('Grant of "%s" conflicts with already-held "%s" (segregation of duties).', $code, $opposite)];
            }
            // Also conflict if the SAME request bundles both sides.
            if ($opposite !== null && in_array($opposite, $granted, true)) {
                return ['flagged', sprintf('Requested role bundles conflicting duties "%s" and "%s".', $code, $opposite)];
            }
        }

        return ['none', null];
    }

    /** @return list<string> permission codes the subject currently holds via active assignments */
    private function effectivePermissions(string $organizationId, string $subjectId): array
    {
        $now  = $this->clock->nowUtcString();
        $rows = $this->db->table('role_assignments ra')
            ->select('p.code')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $subjectId)
            ->where('ra.organization_id', $organizationId)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        return array_values(array_unique(array_map(static fn ($r) => (string) $r['code'], $rows)));
    }

    /**
     * Create the expiring role assignment; idempotent on the UNIQUE triple.
     * Copies the FULL scope model (mode + hand-picked group set + cross-cut)
     * from the approved request onto the materialized assignment, so an
     * approval never silently narrows the requested scope.
     */
    private function materializeRoleAssignment(string $organizationId, array $req, string $actorId, string $requestId, string $from, ?string $to): string
    {
        $now = $this->clock->nowUtcString();

        // Rebuild the request's scope from its stored columns + hand-picked set.
        $scope = $this->scope->parse([
            'scope_group_id'      => $req['scope_group_id'] ?? null,
            'scope_mode'          => $req['scope_mode'] ?? null,
            'include_descendants' => $req['include_descendants'] ?? 0,
            'include_crosscut'    => $req['include_crosscut'] ?? 0,
            'scope_groups'        => $this->scope->groupSet('access_request', (string) $req['id']),
        ]);
        $scopeCols = $this->scope->columns($scope);

        $existing = $this->db->table('role_assignments')
            ->where('subject_id', $req['subject_id'])
            ->where('role_id', $req['role_id'])
            ->where('scope_group_id', $req['scope_group_id'])
            ->get()->getRowArray();

        if ($existing !== null) {
            // Re-activate/refresh the existing assignment with the new lifecycle.
            $this->db->table('role_assignments')->where('id', $existing['id'])->update([
                'status'          => 'active',
                'source'          => 'request',
                'issued_by'       => $actorId,
                'request_id'      => $requestId,
                'effective_from'  => $from,
                'effective_to'    => $to,
                'revoked_at'      => null,
            ] + $scopeCols);
            $this->scope->syncGroupSet($organizationId, 'role_assignment', (string) $existing['id'], $scope);

            return (string) $existing['id'];
        }

        $id = Uuid::v7();
        $this->db->table('role_assignments')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'subject_id'          => $req['subject_id'],
            'role_id'             => $req['role_id'],
            'status'              => 'active',
            'source'              => 'request',
            'issued_by'           => $actorId,
            'request_id'          => $requestId,
            'effective_from'      => $from,
            'effective_to'        => $to,
            'created_at'          => $now,
        ] + $scopeCols);
        $this->scope->syncGroupSet($organizationId, 'role_assignment', $id, $scope);

        return $id;
    }

    /** @return array<string,mixed>|null starts a transaction + row lock */
    private function lockRequest(string $requestId): ?array
    {
        $this->db->transStart();

        return $this->db->query('SELECT * FROM access_requests WHERE id = ? FOR UPDATE', [$requestId])->getRowArray();
    }

    private function appendReview(string $organizationId, string $requestId, string $action, string $actorId, ?string $note, string $now): void
    {
        $this->db->table('access_request_reviews')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'request_id'      => $requestId,
            'action'          => $action,
            'actor_id'        => $actorId,
            'note'            => $note,
            'created_at'      => $now,
        ]);
    }

    private function notifyReviewer(string $organizationId, string $approverId, string $requestId, string $conflictState): void
    {
        try {
            $this->notifications->send($organizationId, $approverId, 'in_app', 'access_request', [
                'priority'   => 'normal',
                'dedupe_key' => 'access-review:' . $requestId,
                'context'    => ['request_id' => $requestId, 'conflict' => $conflictState],
            ]);
        } catch (Throwable) {
            // Notification failure must never block the workflow.
        }
    }

    private function notifyOutcome(string $organizationId, string $subjectId, string $requestId, string $outcome): void
    {
        try {
            $this->notifications->send($organizationId, $subjectId, 'in_app', 'access_request_outcome', [
                'priority'   => 'normal',
                'dedupe_key' => 'access-outcome:' . $requestId . ':' . $outcome,
                'context'    => ['request_id' => $requestId, 'outcome' => $outcome],
            ]);
        } catch (Throwable) {
            // best-effort
        }
    }
}
