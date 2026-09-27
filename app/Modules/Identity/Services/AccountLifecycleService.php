<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\AccessControl\Policy\AccessRequest as PdpRequest;
use WBS\AccessControl\Services\AuthorizationService;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Account lifecycle state machine + audited merge review (SRS FR-ID-009,
 * FR-ID-002).
 *
 * States: prospect, pending_verification, active, suspended, locked,
 * deactivated, anonymized, merged. Every transition demands a stated reason,
 * writes append-only evidence to `account_state_transitions`, and records an
 * immutable audit-log entry. Suspension/lock/deactivation immediately revoke
 * all sessions AND tokens while leaving accounting/audit records untouched.
 *
 * Merge is never silent: a duplicate account is retired only through a
 * maker-checker `identity_merge_requests` review — the requester cannot approve
 * their own request (enforced via the PDP's SoD combinator on
 * `access.request.approve`), and approval transitions the duplicate to `merged`
 * (merged_into_id -> primary) rather than deleting or overwriting history.
 */
final class AccountLifecycleService
{
    /** Allowed transitions: from => [to, ...]. anonymized/merged are terminal. */
    private const TRANSITIONS = [
        'prospect'             => ['pending_verification', 'active', 'deactivated'],
        'pending_verification' => ['active', 'locked', 'deactivated'],
        'active'               => ['suspended', 'locked', 'deactivated', 'anonymized', 'merged'],
        'suspended'            => ['active', 'locked', 'deactivated', 'anonymized', 'merged'],
        'locked'               => ['active', 'suspended', 'deactivated'],
        'deactivated'          => ['active', 'anonymized', 'merged'],
        'anonymized'           => [],
        'merged'               => [],
    ];

    /** States whose entry must immediately revoke live sessions + tokens. */
    private const REVOKE_ON = ['suspended', 'locked', 'deactivated', 'anonymized', 'merged'];

    public const STATES = [
        'prospect', 'pending_verification', 'active', 'suspended',
        'locked', 'deactivated', 'anonymized', 'merged',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SessionService $sessions,
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly AuthorizationService $pdp,
        private readonly ?OutboxService $outbox = null,
        // M6 verify-expiry sweep seams (config-gated, default OFF). Null → the
        // sweep is a no-op, so existing callers/tests construct unchanged.
        private readonly ?LifecycleConfigPort $config = null,
        private readonly ?VerifyReminderPort $verifyReminder = null,
    ) {
    }

    /**
     * M6 verify-expiry config capabilities (hierarchical, DEFAULT OFF):
     *   - `enabled`       — switch the sweep on for a context;
     *   - `remind_after_days` — age (since registration) before the first nudge;
     *   - `remind_every_days` — min cadence between nudges;
     *   - `expire_after_days`  — age before an un-verified account is deactivated.
     */
    public const CAP_VERIFY_ENABLED = 'identity.verify_expiry.enabled';
    public const CAP_VERIFY_REMIND_AFTER = 'identity.verify_expiry.remind_after_days';
    public const CAP_VERIFY_REMIND_EVERY = 'identity.verify_expiry.remind_every_days';
    public const CAP_VERIFY_EXPIRE_AFTER = 'identity.verify_expiry.expire_after_days';

    private const VERIFY_DEFAULTS = [
        'remind_after_days' => 3,
        'remind_every_days' => 3,
        'expire_after_days' => 30,
    ];

    /**
     * Map a target account status to its canonical teardown topic (gap ID1).
     * Returns null for states that are not a teardown (prospect / pending /
     * active / locked). `merged` is emitted separately (it carries survivor).
     */
    private const TEARDOWN_TOPIC = [
        'suspended'   => 'account.suspended',
        'deactivated' => 'account.deactivated',
        'anonymized'  => 'account.anonymized',
    ];

    /**
     * States from which a return to `active` is a REACTIVATION — i.e. the account
     * was previously TORN DOWN and its belongings/journeys should be restored
     * (M10, the inverse of the teardown cascade). Kept symmetric with
     * TEARDOWN_TOPIC: only `suspended` / `deactivated` fan out a teardown, so only
     * those return-paths fan out a restore. Coming to `active` from
     * `pending_verification` / `prospect` is a normal first activation (nothing
     * was torn down), and `locked` never triggers a teardown either — neither
     * emits a restore signal. `anonymized` is terminal and cannot return.
     *
     * @var list<string>
     */
    private const REACTIVATE_FROM = ['suspended', 'deactivated'];

    /**
     * Transition an account to a new state with a stated reason + evidence.
     *
     * @param array<string,mixed> $opts actor_id, approval_ref, evidence[]
     */
    public function transition(string $organizationId, string $userId, string $toStatus, string $reason, array $opts = []): Result
    {
        $toStatus = strtolower(trim($toStatus));
        $reason   = trim($reason);
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'identity.status_reason_required', 422);
        }
        if (! in_array($toStatus, self::STATES, true)) {
            return Result::fail('BAD_STATUS', 'identity.status_unknown', 422, ['status' => $toStatus]);
        }

        $user = $this->db->table('users')
            ->where('id', $userId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($user === null) {
            return Result::notFound('identity.user_not_found', 'USER_NOT_FOUND');
        }

        $from = (string) ($user['status'] ?? '');
        if ($from === $toStatus) {
            return Result::fail('NO_CHANGE', 'identity.status_unchanged', 409, ['status' => $from]);
        }
        $allowed = self::TRANSITIONS[$from] ?? null;
        if ($allowed === null) {
            // Unknown current state (legacy free-string) — permit forward moves
            // but never resurrect a terminal state.
            $allowed = ['active', 'suspended', 'locked', 'deactivated', 'anonymized', 'merged'];
        }
        if (! in_array($toStatus, $allowed, true)) {
            return Result::fail('ILLEGAL_TRANSITION', 'identity.status_illegal_transition', 409, [
                'from' => $from,
                'to'   => $toStatus,
            ]);
        }

        $now      = $this->clock->nowUtcString();
        $nowMicro = $this->clock->nowUtcMicro();
        $actorId  = isset($opts['actor_id']) ? (string) $opts['actor_id'] : null;

        $update = [
            'status'            => $toStatus,
            'status_reason'     => $reason,
            'status_changed_at' => $now,
            'status_changed_by' => $actorId,
            'updated_at'        => $now,
        ];
        if ($toStatus === 'anonymized') {
            $update = $this->scrubPii($update, $userId) + ['anonymized_at' => $now];
        }
        if ($toStatus === 'merged' && ! empty($opts['merged_into_id'])) {
            $update['merged_into_id'] = (string) $opts['merged_into_id'];
        }

        $this->db->transStart();
        try {
            $this->db->table('users')->where('id', $userId)->update($update);

            $this->db->table('account_state_transitions')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'user_id'         => $userId,
                'from_status'     => $from !== '' ? $from : null,
                'to_status'       => $toStatus,
                'reason'          => $reason,
                'actor_id'        => $actorId,
                'approval_ref'    => $opts['approval_ref'] ?? null,
                'evidence'        => isset($opts['evidence']) ? json_encode($opts['evidence']) : null,
                'created_at'      => $nowMicro,
            ]);

            // Immediate revocation for security-relevant states. Accounting and
            // audit records are deliberately left intact.
            if (in_array($toStatus, self::REVOKE_ON, true)) {
                $this->sessions->revokeAllForUser($userId);
                $this->tokens->revokeAllForUser($userId);
            }

            // Canonical teardown signal (gap ID1) — the Theme-B emitter every
            // dependent module subscribes to. Staged INSIDE the transaction so
            // the event is durable exactly with the state change (no signal on a
            // rolled-back transition, no lost signal on a committed one).
            // `merged` is emitted by the merge flow (it carries the survivor);
            // here we cover suspended / deactivated / anonymized.
            if ($this->outbox !== null && isset(self::TEARDOWN_TOPIC[$toStatus])) {
                $this->outbox->stage('account', $userId, self::TEARDOWN_TOPIC[$toStatus], [
                    'user_id'         => $userId,
                    'organization_id' => $organizationId,
                    'from_status'     => $from !== '' ? $from : null,
                    'to_status'       => $toStatus,
                    'actor_id'        => $actorId,
                    'reason'          => $reason,
                    'source_ref'      => 'account_transition:' . $userId . ':' . $toStatus,
                ], $organizationId);
            }

            // M10 — REACTIVATION signal: returning to `active` from a torn-down
            // state fans out the INVERSE of the teardown (resume paused journeys,
            // restore teardown-ended memberships), so a returning member is not
            // left half-restored. Staged in-transaction like the teardown signal.
            // First activation (from pending_verification/prospect) is not a
            // reactivation and emits nothing.
            if ($this->outbox !== null && $toStatus === 'active' && in_array($from, self::REACTIVATE_FROM, true)) {
                $this->outbox->stage('account', $userId, 'account.reactivated', [
                    'user_id'         => $userId,
                    'organization_id' => $organizationId,
                    'from_status'     => $from,
                    'to_status'       => $toStatus,
                    'actor_id'        => $actorId,
                    'reason'          => $reason,
                    'source_ref'      => 'account_transition:' . $userId . ':reactivated',
                ], $organizationId);
            }

            // Merge carries the survivor so consumers can re-point the loser's
            // belongings to it (through their own authorized path — never a blind
            // UPDATE). Emitted only when a survivor is known.
            if ($this->outbox !== null && $toStatus === 'merged' && ! empty($opts['merged_into_id'])) {
                $survivor = (string) $opts['merged_into_id'];
                $this->outbox->stage('account', $userId, 'account.merged', [
                    'loser_user_id'    => $userId,
                    'survivor_user_id' => $survivor,
                    'organization_id'  => $organizationId,
                    'actor_id'         => $actorId,
                    'approval_ref'     => $opts['approval_ref'] ?? null,
                    'source_ref'       => 'account_merge:' . $userId . ':' . $survivor,
                ], $organizationId);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('TRANSITION_FAILED', 'identity.status_transition_failed', 500);
        }
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('TRANSITION_FAILED', 'identity.status_transition_failed', 500);
        }

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'identity.account.status_changed',
            'object_type' => 'user',
            'object_id'   => $userId,
            'metadata'    => ['from' => $from, 'to' => $toStatus, 'reason' => $reason, 'approval_ref' => $opts['approval_ref'] ?? null],
        ]);

        return Result::ok(['user_id' => $userId, 'from' => $from, 'to' => $toStatus]);
    }

    /** Convenience wrappers — each still requires a reason. */
    public function suspend(string $organizationId, string $userId, string $reason, ?string $actorId = null): Result
    {
        return $this->transition($organizationId, $userId, 'suspended', $reason, ['actor_id' => $actorId]);
    }

    public function lock(string $organizationId, string $userId, string $reason, ?string $actorId = null): Result
    {
        return $this->transition($organizationId, $userId, 'locked', $reason, ['actor_id' => $actorId]);
    }

    public function reactivate(string $organizationId, string $userId, string $reason, ?string $actorId = null): Result
    {
        return $this->transition($organizationId, $userId, 'active', $reason, ['actor_id' => $actorId]);
    }

    public function deactivate(string $organizationId, string $userId, string $reason, ?string $actorId = null): Result
    {
        return $this->transition($organizationId, $userId, 'deactivated', $reason, ['actor_id' => $actorId]);
    }

    public function anonymize(string $organizationId, string $userId, string $reason, ?string $actorId = null): Result
    {
        return $this->transition($organizationId, $userId, 'anonymized', $reason, ['actor_id' => $actorId]);
    }

    /**
     * M6 — verify-expiry sweep. Config-gated (DEFAULT OFF) and idempotent. For
     * `pending_verification` accounts:
     *   - **remind** those older than `remind_after_days` whose last reminder was
     *     more than `remind_every_days` ago (a nudge, deduped per round via the
     *     verify_reminded_at watermark + notification dedupe key);
     *   - **expire** those older than `expire_after_days` — a SYSTEM-authority
     *     `pending_verification → deactivated` transition through the normal
     *     transition() path (audited + append-only evidence + session/token
     *     revoke), reason `verify_expired`.
     *
     * Bounded per pass. Reminders never fire for an account already old enough to
     * expire (it is expired instead). Returns headline counts.
     *
     * @return array{scanned:int, reminded:int, expired:int, skipped_gated:int}
     */
    public function processVerifyExpiry(?string $organizationId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        if ($this->config === null) {
            return ['scanned' => 0, 'reminded' => 0, 'expired' => 0, 'skipped_gated' => 0];
        }

        $q = $this->db->table('users')
            ->where('status', 'pending_verification')
            ->orderBy('created_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $rows = $q->get($limit)->getResultArray();

        $now      = $this->clock->nowUtcString();
        $nowTs    = strtotime($now) ?: time();
        $scanned  = 0;
        $reminded = 0;
        $expired  = 0;
        $skipped  = 0;
        $ctxCfg   = []; // per-org resolved config cache

        foreach ($rows as $u) {
            $scanned++;
            $org = (string) $u['organization_id'];
            if (! isset($ctxCfg[$org])) {
                $ctxCfg[$org] = $this->verifyExpiryConfig($org);
            }
            $cfg = $ctxCfg[$org];
            if (! $cfg['enabled']) {
                $skipped++;
                continue;
            }

            $createdTs = strtotime((string) ($u['created_at'] ?? '')) ?: $nowTs;
            $ageDays   = (int) floor(($nowTs - $createdTs) / 86400);

            // Expire first: an account past the expiry window is deactivated, not
            // reminded.
            if ($ageDays >= $cfg['expire_after_days']) {
                $res = $this->transition($org, (string) $u['id'], 'deactivated', 'verify_expired', [
                    'actor_id' => null,
                ]);
                if ($res->ok && ($res->meta['deduplicated'] ?? false) !== true) {
                    $expired++;
                }
                continue;
            }

            // Remind: old enough for a first nudge, and cadence elapsed.
            if ($ageDays < $cfg['remind_after_days']) {
                continue;
            }
            $lastRemind = (string) ($u['verify_reminded_at'] ?? '');
            $dueForNudge = $lastRemind === ''
                || (($nowTs - (strtotime($lastRemind) ?: 0)) >= $cfg['remind_every_days'] * 86400);
            if (! $dueForNudge) {
                continue;
            }

            $round = (int) ($u['verify_reminder_count'] ?? 0) + 1;
            if ($this->verifyReminder !== null) {
                $this->verifyReminder->remindVerify(
                    $org,
                    (string) $u['id'],
                    'verify_reminder:' . $u['id'] . ':' . $round,
                    ['round' => $round, 'age_days' => $ageDays],
                );
            }
            $this->db->table('users')->where('id', $u['id'])->update([
                'verify_reminded_at'    => $now,
                'verify_reminder_count' => $round,
                'updated_at'            => $now,
            ]);
            $reminded++;
        }

        return ['scanned' => $scanned, 'reminded' => $reminded, 'expired' => $expired, 'skipped_gated' => $skipped];
    }

    /**
     * Resolve the verify-expiry config for an org context (default OFF, tunable
     * windows over the built-in defaults).
     *
     * @return array{enabled:bool, remind_after_days:int, remind_every_days:int, expire_after_days:int}
     */
    private function verifyExpiryConfig(string $organizationId): array
    {
        $root = $this->config?->orgRootGroup($organizationId);
        $out  = ['enabled' => false] + self::VERIFY_DEFAULTS;
        if ($root === null || $this->config === null) {
            return $out;
        }

        $enabled = $this->config->value($root, self::CAP_VERIFY_ENABLED);
        if (is_array($enabled)) {
            $enabled = $enabled['value'] ?? null;
        }
        $out['enabled'] = $enabled === true || $enabled === 1 || $enabled === '1'
            || (is_string($enabled) && in_array(strtolower($enabled), ['true', 'on', 'yes'], true));

        foreach ([
            'remind_after_days' => self::CAP_VERIFY_REMIND_AFTER,
            'remind_every_days' => self::CAP_VERIFY_REMIND_EVERY,
            'expire_after_days' => self::CAP_VERIFY_EXPIRE_AFTER,
        ] as $key => $cap) {
            $v = $this->config->value($root, $cap);
            if (is_array($v)) {
                $v = $v['value'] ?? null;
            }
            if (is_numeric($v)) {
                $out[$key] = max(1, (int) $v);
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> the transition history for an account. */
    public function history(string $organizationId, string $userId): array
    {
        return $this->db->table('account_state_transitions')
            ->where('organization_id', $organizationId)->where('user_id', $userId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    // ------------------------------------------------------------------ merge

    /**
     * Open a merge REVIEW (never merges immediately). Records the primary and
     * duplicate accounts, a justification, and any detected conflicts.
     */
    public function submitMerge(string $organizationId, string $primaryUserId, string $duplicateUserId, string $requestedBy, string $reason): Result
    {
        $reason = trim($reason);
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'identity.merge_reason_required', 422);
        }
        if ($primaryUserId === '' || $duplicateUserId === '' || $primaryUserId === $duplicateUserId) {
            return Result::fail('BAD_PAIR', 'identity.merge_bad_pair', 422);
        }

        $primary   = $this->userInOrg($organizationId, $primaryUserId);
        $duplicate = $this->userInOrg($organizationId, $duplicateUserId);
        if ($primary === null || $duplicate === null) {
            return Result::notFound('identity.user_not_found', 'USER_NOT_FOUND');
        }
        foreach (['merged', 'anonymized'] as $terminal) {
            if ($primary['status'] === $terminal || $duplicate['status'] === $terminal) {
                return Result::fail('TERMINAL_STATE', 'identity.merge_terminal_state', 409, ['status' => $terminal]);
            }
        }

        // Non-blocking conflict surface for the reviewer (both have a password,
        // divergent verified emails/phones, etc.).
        $conflict = [];
        if (! empty($primary['password_hash']) && ! empty($duplicate['password_hash'])) {
            $conflict[] = 'both_have_credentials';
        }
        if (($primary['email'] ?? null) && ($duplicate['email'] ?? null) && $primary['email'] !== $duplicate['email']) {
            $conflict[] = 'divergent_email';
        }
        if (($primary['phone'] ?? null) && ($duplicate['phone'] ?? null) && $primary['phone'] !== $duplicate['phone']) {
            $conflict[] = 'divergent_phone';
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('identity_merge_requests')->insert([
            'id'                => $id,
            'organization_id'   => $organizationId,
            'primary_user_id'   => $primaryUserId,
            'duplicate_user_id' => $duplicateUserId,
            'reason'            => $reason,
            'requested_by'      => $requestedBy,
            'status'            => 'pending',
            'conflict_detail'   => $conflict !== [] ? json_encode($conflict) : null,
            'created_at'        => $now,
        ]);
        $this->appendMergeReview($organizationId, $id, 'submit', $requestedBy, $reason, $now);

        $this->audit->record($organizationId, [
            'actor_id'    => $requestedBy,
            'action'      => 'identity.merge.submitted',
            'object_type' => 'identity_merge_request',
            'object_id'   => $id,
            'metadata'    => ['primary' => $primaryUserId, 'duplicate' => $duplicateUserId, 'conflict' => $conflict],
        ]);

        return Result::created([
            'merge_request_id' => $id,
            'status'           => 'pending',
            'conflict_detail'  => $conflict,
        ]);
    }

    /**
     * Approve a merge review (maker-checker). The actor must not be the
     * requester (SoD via the PDP + a belt-and-braces guard). On success the
     * duplicate account transitions to `merged` (merged_into_id -> primary);
     * historical records are never rewritten.
     */
    public function approveMerge(string $organizationId, string $mergeRequestId, string $actorId, ?string $note = null): Result
    {
        $this->db->transStart();
        $req = $this->db->table('identity_merge_requests')
            ->where('id', $mergeRequestId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($req === null) {
            $this->db->transComplete();

            return Result::notFound('identity.merge_not_found', 'MERGE_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            $this->db->transComplete();

            return Result::fail('BAD_STATE', 'identity.merge_bad_state', 409, ['status' => $req['status']]);
        }

        // Maker-checker through the PDP: reviewer permission + SoD self-approval
        // denial (requester == actor is rejected).
        $decision = $this->pdp->decide(new PdpRequest(
            $organizationId,
            $actorId,
            'access.request.approve',
            'identity_merge_request',
            $mergeRequestId,
            ['submitted_by' => $req['requested_by'], 'subject_id' => $req['requested_by']],
        ));
        if (! $decision->isPermitted()) {
            $this->db->transComplete();

            return Result::denied('identity.merge_approve_denied', 'MERGE_APPROVE_DENIED');
        }
        if ((string) $req['requested_by'] === $actorId) {
            $this->db->transComplete();

            return Result::fail('SOD_SELF_APPROVAL', 'identity.merge_self_approval', 403);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('identity_merge_requests')->where('id', $mergeRequestId)->update([
            'status'     => 'approved',
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        $this->appendMergeReview($organizationId, $mergeRequestId, 'approve', $actorId, $note, $now);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('MERGE_APPROVE_FAILED', 'identity.merge_approve_failed', 500);
        }

        // Retire the duplicate via the lifecycle state machine (its own audit +
        // transition evidence + session/token revocation).
        $merge = $this->transition($organizationId, (string) $req['duplicate_user_id'], 'merged', 'Approved merge into ' . $req['primary_user_id'], [
            'actor_id'       => $actorId,
            'approval_ref'   => $mergeRequestId,
            'merged_into_id' => (string) $req['primary_user_id'],
            'evidence'       => ['merge_request_id' => $mergeRequestId, 'primary_user_id' => $req['primary_user_id']],
        ]);

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'identity.merge.approved',
            'object_type' => 'identity_merge_request',
            'object_id'   => $mergeRequestId,
            'metadata'    => [
                'primary'           => $req['primary_user_id'],
                'duplicate'         => $req['duplicate_user_id'],
                // ID2: the approval retires the duplicate and emits account.merged,
                // but the belongings re-point (memberships, grants, contributions,
                // enrollments, registrations, referrals, points, journeys, prefs)
                // is performed by each owning module's Phase-2 consumer through its
                // authorized write path — NOT here (a blind UPDATE would launder
                // authority/PII onto a different identity). Record that state so the
                // audit trail is honest about what a merge did and did not move.
                'belongings_repointed' => false,
            ],
        ]);

        return Result::ok([
            'merge_request_id' => $mergeRequestId,
            'status'           => 'approved',
            'duplicate_merged' => $merge->ok,
            // ID2: make the approve response honest — the duplicate is retired and
            // `account.merged` is staged on the outbox, but its belongings are NOT
            // yet re-pointed to the survivor (each owning module does that on the
            // account.merged event, via its authorized path, in Phase 2). Operators
            // who approved the merge must not be misled into thinking history moved.
            'belongings_repointed' => false,
            'belongings_note'      => 'identity.merge.belongings_pending',
            'merged_event_staged'  => $this->outbox !== null,
        ]);
    }

    public function rejectMerge(string $organizationId, string $mergeRequestId, string $actorId, ?string $note = null): Result
    {
        return $this->closeMerge($organizationId, $mergeRequestId, $actorId, 'rejected', 'reject', $note);
    }

    public function cancelMerge(string $organizationId, string $mergeRequestId, string $actorId, ?string $note = null): Result
    {
        return $this->closeMerge($organizationId, $mergeRequestId, $actorId, 'cancelled', 'cancel', $note);
    }

    /** @return array<string,mixed>|null a merge request with its review trail. */
    public function findMerge(string $organizationId, string $mergeRequestId): ?array
    {
        $req = $this->db->table('identity_merge_requests')
            ->where('id', $mergeRequestId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($req === null) {
            return null;
        }
        $req['reviews'] = $this->db->table('identity_merge_reviews')
            ->where('merge_request_id', $mergeRequestId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        return $req;
    }

    /** @return list<array<string,mixed>> pending merge reviews for the org. */
    public function pendingMerges(string $organizationId): array
    {
        return $this->db->table('identity_merge_requests')
            ->where('organization_id', $organizationId)->where('status', 'pending')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    // --------------------------------------------------------------- internals

    private function closeMerge(string $organizationId, string $mergeRequestId, string $actorId, string $status, string $action, ?string $note): Result
    {
        $req = $this->db->table('identity_merge_requests')
            ->where('id', $mergeRequestId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($req === null) {
            return Result::notFound('identity.merge_not_found', 'MERGE_NOT_FOUND');
        }
        if ($req['status'] !== 'pending') {
            return Result::fail('BAD_STATE', 'identity.merge_bad_state', 409, ['status' => $req['status']]);
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('identity_merge_requests')->where('id', $mergeRequestId)->update([
            'status'     => $status,
            'decided_by' => $actorId,
            'decided_at' => $now,
            'updated_at' => $now,
        ]);
        $this->appendMergeReview($organizationId, $mergeRequestId, $action, $actorId, $note, $now);

        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'identity.merge.' . $status,
            'object_type' => 'identity_merge_request',
            'object_id'   => $mergeRequestId,
            'metadata'    => ['note' => $note],
        ]);

        return Result::ok(['merge_request_id' => $mergeRequestId, 'status' => $status]);
    }

    private function appendMergeReview(string $organizationId, string $mergeRequestId, string $action, ?string $actorId, ?string $note, string $at): void
    {
        $this->db->table('identity_merge_reviews')->insert([
            'id'               => Uuid::v7(),
            'organization_id'  => $organizationId,
            'merge_request_id' => $mergeRequestId,
            'action'           => $action,
            'actor_id'         => $actorId,
            'note'             => $note,
            'created_at'       => $at,
        ]);
    }

    /**
     * Replace directly-identifying columns with irreversible tombstones. Rows in
     * accounting/audit tables reference the user by id and are left intact.
     *
     * @param array<string,mixed> $update
     *
     * @return array<string,mixed>
     */
    private function scrubPii(array $update, string $userId): array
    {
        $tag = 'anon-' . substr(hash('sha256', $userId), 0, 16);

        return $update + [
            'email'          => null,
            'email_verified' => 0,
            'phone'          => null,
            'phone_verified' => 0,
            'phone_input'    => null,
            'phone_region'   => null,
            'display_name'   => $tag,
            'password_hash'  => null,
            'date_of_birth'  => null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function userInOrg(string $organizationId, string $userId): ?array
    {
        return $this->db->table('users')
            ->where('id', $userId)->where('organization_id', $organizationId)
            ->get()->getRowArray() ?: null;
    }
}
