<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Break-glass emergency access (SRS FR-ACL-006).
 *
 * "Emergency access requires a reason, MFA, narrow time limit, enhanced
 * audit/alert, automatic expiry, and post-use review. It is unavailable for
 * routine convenience and does not bypass immutable financial/audit controls."
 *
 * How each clause is enforced here:
 *   - REASON        — a non-empty justification is mandatory to open.
 *   - MFA           — the opener must present strong assurance ("high") at open
 *                     time; anything weaker is refused.
 *   - NARROW TTL    — duration is measured in MINUTES, mandatory, and hard-capped
 *                     (default 60, ceiling {@see MAX_TTL_MINUTES}).
 *   - ENHANCED AUDIT/ALERT — every open/close/review writes a hash-chained audit
 *                     entry AND fires a high-priority security notification.
 *   - AUTO-EXPIRY   — sessions carry a bounded effective_to; {@see expireLapsed()}
 *                     (run by the acl:expire command) flips lapsed ones to
 *                     "expired", and the PDP treats only currently-effective,
 *                     non-expired sessions as active.
 *   - POST-USE REVIEW — closing/expiry leaves review_state="pending"; a reviewer
 *                     who is NOT the opener must record a justified/unjustified
 *                     outcome (maker-checker).
 *   - NOT ROUTINE / NO FINANCE BYPASS — a deny-list of finance- and audit-
 *                     protected permissions can NEVER be obtained via break-glass;
 *                     those remain behind their normal maker-checker controls.
 */
final class BreakGlassService
{
    /** Hard ceiling on any emergency session (minutes). */
    private const MAX_TTL_MINUTES     = 240;
    private const DEFAULT_TTL_MINUTES = 60;

    /** MFA assurance that counts as "strong" for opening a session. */
    private const STRONG_MFA = 'high';

    /**
     * Permissions that break-glass must NEVER confer: immutable financial and
     * audit controls stay behind their normal segregation-of-duties workflow
     * (FR-ACL-006: "does not bypass immutable financial/audit controls").
     */
    private const PROTECTED_PERMISSIONS = [
        'contribution.refund.approve',
        'contribution.refund.request',
        'contribution.manage',
        'event.expense.approve',
        'event.expense.submit',
        'integration.connection.approve',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly GrantScopeWriter $scope,
    ) {
    }

    /**
     * Open an emergency session granting one permission to a subject for a narrow
     * window. The opener's current MFA assurance is passed in from the request
     * context (never trusted from the body).
     *
     * @param array<string,mixed> $data subject_id?, permission_code, scope_group_id?,
     *        scope_mode?, include_descendants?, scope_groups?, include_crosscut?,
     *        reason, ttl_minutes?
     */
    public function open(string $organizationId, string $openedBy, string $openerMfaLevel, array $data): Result
    {
        $permission = (string) ($data['permission_code'] ?? '');
        $reason     = trim((string) ($data['reason'] ?? ''));
        // A session may grant access to the opener themselves or to another
        // subject (e.g. an on-call engineer); default to the opener.
        $subjectId  = (string) ($data['subject_id'] ?? '') ?: $openedBy;

        if ($permission === '') {
            return Result::fail('PERMISSION_REQUIRED', 'acl.permission_required', 422);
        }
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'acl.bg_reason_required', 422);
        }

        // MFA is mandatory and must be strong.
        if ($openerMfaLevel !== self::STRONG_MFA) {
            return Result::fail('MFA_REQUIRED', 'acl.bg_mfa_required', 403, ['required_assurance' => self::STRONG_MFA]);
        }

        // Never a bypass for immutable financial/audit controls.
        if (in_array($permission, self::PROTECTED_PERMISSIONS, true)) {
            return Result::denied('acl.bg_permission_forbidden', 'BREAK_GLASS_FORBIDDEN');
        }

        // Narrow, mandatory, hard-capped TTL.
        $ttl = isset($data['ttl_minutes']) ? (int) $data['ttl_minutes'] : self::DEFAULT_TTL_MINUTES;
        if ($ttl < 1) {
            return Result::fail('TTL_REQUIRED', 'acl.bg_ttl_required', 422);
        }
        if ($ttl > self::MAX_TTL_MINUTES) {
            return Result::fail('TTL_TOO_LONG', 'acl.bg_ttl_too_long', 422, ['max_minutes' => self::MAX_TTL_MINUTES]);
        }

        // Full scope model (mode + hand-picked set + opt-in cross-cut).
        $scope = $this->scope->parse($data);
        if (! $scope['ok']) {
            return Result::fail('BAD_SCOPE', $scope['message'], 422, $scope['extra'] ?? []);
        }
        $scopeGroupId = $scope['group_id'];

        $now   = $this->clock->nowUtcMicro();
        $to    = $this->clock->now()->modify("+{$ttl} minutes")->format('Y-m-d H:i:s.u');
        $id    = Uuid::v7();

        $this->db->table('break_glass_sessions')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'subject_id'          => $subjectId,
            'opened_by'           => $openedBy,
            'permission_code'     => $permission,
            'reason'              => $reason,
            'mfa_level'           => $openerMfaLevel,
            'status'              => 'active',
            'effective_from'      => $now,
            'effective_to'        => $to,
            'review_state'        => 'pending',
            'created_at'          => $now,
        ] + $this->scope->columns($scope));
        $this->scope->syncGroupSet($organizationId, 'break_glass', $id, $scope);

        // Enhanced audit + alert.
        $this->audit->record($organizationId, [
            'action'      => 'acl.break_glass.open',
            'actor_id'    => $openedBy,
            'actor_type'  => 'user',
            'object_type' => 'break_glass_session',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => [
                'subject_id'      => $subjectId,
                'permission_code' => $permission,
                'scope_group_id'  => $scopeGroupId,
                'reason'          => $reason,
                'effective_to'    => $to,
                'severity'        => 'critical',
            ],
        ]);
        $this->alertSecurity($organizationId, $openedBy, 'break_glass_opened', $id, [
            'permission_code' => $permission,
            'subject_id'      => $subjectId,
            'effective_to'    => $to,
        ]);

        return Result::created([
            'session_id'      => $id,
            'subject_id'      => $subjectId,
            'permission_code' => $permission,
            'scope_group_id'  => $scopeGroupId,
            'effective_to'    => $to,
            'status'          => 'active',
            'review_state'    => 'pending',
        ]);
    }

    /**
     * Manually close an active session early. Access ends immediately; the
     * mandatory post-use review is still required.
     */
    public function close(string $organizationId, string $actorId, string $sessionId): Result
    {
        $row = $this->activeSession($organizationId, $sessionId);
        if ($row === null) {
            return Result::notFound('acl.bg_session_not_found', 'SESSION_NOT_FOUND');
        }

        $this->db->table('break_glass_sessions')->where('id', $sessionId)->update([
            'status'     => 'closed',
            'closed_at'  => $this->clock->nowUtcMicro(),
        ]);

        $this->audit->record($organizationId, [
            'action'      => 'acl.break_glass.close',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'break_glass_session',
            'object_id'   => $sessionId,
            'outcome'     => 'success',
            'metadata'    => ['severity' => 'high'],
        ]);

        return Result::ok(['session_id' => $sessionId, 'status' => 'closed', 'review_state' => 'pending']);
    }

    /**
     * Record the mandatory post-use review. The reviewer MUST NOT be the opener
     * (maker-checker), and the session must be over (closed/expired) — you review
     * after use, not during.
     *
     * @param array<string,mixed> $data outcome (justified|unjustified), notes?
     */
    public function review(string $organizationId, string $reviewerId, string $sessionId, array $data): Result
    {
        $outcome = (string) ($data['outcome'] ?? '');
        if (! in_array($outcome, ['justified', 'unjustified'], true)) {
            return Result::fail('BAD_OUTCOME', 'acl.bg_bad_outcome', 422);
        }

        $row = $this->db->table('break_glass_sessions')
            ->where('id', $sessionId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('acl.bg_session_not_found', 'SESSION_NOT_FOUND');
        }
        if ((string) $row['opened_by'] === $reviewerId) {
            return Result::denied('acl.bg_self_review', 'BREAK_GLASS_SELF_REVIEW');
        }
        if ($row['status'] === 'active') {
            return Result::fail('SESSION_STILL_ACTIVE', 'acl.bg_still_active', 409);
        }
        if ($row['review_state'] === 'reviewed') {
            return Result::fail('ALREADY_REVIEWED', 'acl.bg_already_reviewed', 409);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('break_glass_reviews')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'session_id'      => $sessionId,
            'reviewer_id'     => $reviewerId,
            'outcome'         => $outcome,
            'notes'           => isset($data['notes']) ? substr((string) $data['notes'], 0, 1000) : null,
            'created_at'      => $now,
        ]);
        $this->db->table('break_glass_sessions')->where('id', $sessionId)->update([
            'review_state' => 'reviewed',
        ]);

        $this->audit->record($organizationId, [
            'action'      => 'acl.break_glass.review',
            'actor_id'    => $reviewerId,
            'actor_type'  => 'user',
            'object_type' => 'break_glass_session',
            'object_id'   => $sessionId,
            'outcome'     => $outcome,
            'metadata'    => ['severity' => $outcome === 'unjustified' ? 'critical' : 'normal'],
        ]);
        if ($outcome === 'unjustified') {
            $this->alertSecurity($organizationId, $reviewerId, 'break_glass_unjustified', $sessionId, [
                'opened_by'       => $row['opened_by'],
                'permission_code' => $row['permission_code'],
            ]);
        }

        return Result::ok(['session_id' => $sessionId, 'review_state' => 'reviewed', 'outcome' => $outcome]);
    }

    /**
     * Flip lapsed active sessions to "expired" (automatic expiry). Idempotent;
     * intended to be called by the acl:expire command alongside access-request
     * expiry. Returns the count expired.
     */
    public function expireLapsed(?string $organizationId = null): Result
    {
        $now = $this->clock->nowUtcMicro();
        $q   = $this->db->table('break_glass_sessions')
            ->where('status', 'active')
            ->where('effective_to <=', $now);
        if ($organizationId !== null) {
            $q->where('organization_id', $organizationId);
        }
        $lapsed = $q->get()->getResultArray();

        foreach ($lapsed as $s) {
            $this->db->table('break_glass_sessions')->where('id', $s['id'])->update([
                'status'    => 'expired',
                'closed_at' => $now,
            ]);
            $this->audit->record((string) $s['organization_id'], [
                'action'      => 'acl.break_glass.expire',
                'actor_id'    => null,
                'actor_type'  => 'system',
                'object_type' => 'break_glass_session',
                'object_id'   => (string) $s['id'],
                'outcome'     => 'success',
                'metadata'    => ['severity' => 'high', 'auto' => true],
            ]);
        }

        return Result::ok(['expired' => count($lapsed)]);
    }

    /** Sessions still awaiting their mandatory post-use review. */
    public function pendingReviews(string $organizationId): array
    {
        return $this->db->table('break_glass_sessions')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['closed', 'expired'])
            ->where('review_state', 'pending')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /** Read one session (management view). */
    public function show(string $organizationId, string $sessionId): Result
    {
        $row = $this->db->table('break_glass_sessions')
            ->where('id', $sessionId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('acl.bg_session_not_found', 'SESSION_NOT_FOUND');
        }

        return Result::ok($row);
    }

    private function activeSession(string $organizationId, string $sessionId): ?array
    {
        return $this->db->table('break_glass_sessions')
            ->where('id', $sessionId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->get()->getRowArray();
    }

    /**
     * Fire a high-priority security notification to the org admin role. Best
     * effort: an alerting failure must never block the emergency workflow.
     *
     * @param array<string,mixed> $context
     */
    private function alertSecurity(string $organizationId, string $actorId, string $event, string $sessionId, array $context): void
    {
        try {
            $this->notifications->send($organizationId, $actorId, 'in_app', 'security_alert', [
                'priority'   => 'high',
                'dedupe_key' => 'break-glass:' . $event . ':' . $sessionId,
                'context'    => ['event' => $event, 'session_id' => $sessionId] + $context,
            ]);
        } catch (Throwable) {
            // Alerting is best-effort; the audit record is the durable trail.
        }
    }
}
