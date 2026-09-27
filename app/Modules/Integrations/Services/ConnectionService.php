<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Provider connection lifecycle (SRS FR-INT-005/006/007).
 *
 * Lifecycle: draft -> tested -> pending_approval -> active -> disabled/revoked.
 *  - A connection must pass at least one successful test before it can be
 *    submitted for approval.
 *  - Payment and notification connections require approval with SEGREGATION OF
 *    DUTIES: the approver must differ from the requester.
 *  - Credentials live only in the CredentialVault (write-only); this service
 *    never stores or returns secret material.
 *  - Ancestor->descendant reuse is granted via capability_grants without
 *    exposing the owner's credentials. A grant carries the platform's scope
 *    vocabulary (`scope_mode` + an optional hand-picked group set), so a body
 *    shares its account with ONE subgroup, with its WHOLE subtree (including
 *    subgroups created later), or with a selected list — and with nobody by
 *    default. See {@see grantCapability()}.
 */
final class ConnectionService
{
    /** Categories that mandate maker-checker approval. */
    private const APPROVAL_REQUIRED = ['payment', 'notification'];

    /** States a connection may be disabled from (a reversible pause). */
    private const DISABLEABLE_FROM = ['tested', 'pending_approval', 'active', 'failed', 'expired'];

    /** States a connection may be revoked from (terminal). Never from draft. */
    private const REVOKABLE_FROM = ['tested', 'pending_approval', 'active', 'disabled', 'failed', 'expired'];

    /** `grant_scope_groups.grant_type` for a hand-picked credential grant. */
    public const GRANT_TYPE = 'capability_grant';

    /** Bound on a hand-picked grant set: a subtree share, not a bulk import. */
    private const MAX_GRANT_GROUPS = 200;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly CredentialVault $vault,
        private readonly ?ProviderReliabilityService $reliability = null,
        // IN1/IN2: guarded-transition governance. Nullable so the atomic unit
        // tests (and any existing caller) can construct the service without them;
        // when null, disable/revoke still transition + cascade grant revocation
        // but skip the audit entry / outbox signal rather than failing.
        private readonly ?AuditLogger $audit = null,
        private readonly ?OutboxService $outbox = null,
        // Bounds credential sharing to the owner's OWN subtree. Nullable so the
        // atomic unit tests can construct the service without it; when null the
        // containment check is skipped rather than guessed.
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Configured provider connections for an organization (read-only). Selects
     * only NON-secret columns — encrypted credentials live in a separate vault
     * table and are never returned here — so the providers admin page is safe to
     * render. Optional status filter; newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, ?string $status = null): array
    {
        $q = $this->db->table('integration_connections')
            ->select('id, group_id, adapter_code, adapter_version, category, display_name, channels, settings, sender_identity, status, requested_by, approved_by, tested_at, created_at')
            ->where('organization_id', $organizationId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at', 'DESC')->get(500)->getResultArray();
    }

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, string $requestedBy, array $data): Result
    {
        if (empty($data['adapter_code']) || empty($data['category'])) {
            return Result::fail('MISSING_FIELDS', 'integration.missing_fields', 422);
        }
        $id = Uuid::v7();
        $this->db->table('integration_connections')->insert([
            'id'                   => $id,
            'organization_id'      => $organizationId,
            'group_id'             => $data['group_id'] ?? null,
            'adapter_code'         => $data['adapter_code'],
            'adapter_version'      => (int) ($data['adapter_version'] ?? 1),
            'category'             => $data['category'],
            'display_name'         => $data['display_name'] ?? null,
            'allowed_capabilities' => isset($data['allowed_capabilities']) ? json_encode($data['allowed_capabilities'], JSON_UNESCAPED_UNICODE) : null,
            'countries'            => isset($data['countries']) ? json_encode($data['countries'], JSON_UNESCAPED_UNICODE) : null,
            'currencies'           => isset($data['currencies']) ? json_encode($data['currencies'], JSON_UNESCAPED_UNICODE) : null,
            'channels'             => isset($data['channels']) ? json_encode($data['channels'], JSON_UNESCAPED_UNICODE) : null,
            'settings'             => isset($data['settings']) ? json_encode($data['settings'], JSON_UNESCAPED_UNICODE) : null,
            'sender_identity'      => $data['sender_identity'] ?? null,
            'status'               => 'draft',
            'requested_by'         => $requestedBy,
            'created_at'           => $this->clock->nowUtcString(),
        ]);

        return Result::created(['connection_id' => $id, 'status' => 'draft']);
    }

    /** Store a credential slot (encrypted, write-only). */
    public function setCredential(string $connectionId, string $slot, string $secret): Result
    {
        if ($this->find($connectionId) === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }

        return $this->vault->put($connectionId, $slot, $secret);
    }

    /** Record a connection test outcome; a pass moves draft -> tested. */
    public function recordTest(string $connectionId, string $operation, bool $pass, bool $sandbox = true, array $detail = []): Result
    {
        $c = $this->find($connectionId);
        if ($c === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }
        $this->db->table('provider_connection_tests')->insert([
            'id'            => Uuid::v7(),
            'connection_id' => $connectionId,
            'operation'     => $operation,
            'sandbox'       => $sandbox ? 1 : 0,
            'outcome'       => $pass ? 'pass' : 'fail',
            'detail'        => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
            'created_at'    => $this->clock->nowUtcString(),
        ]);

        if ($pass && $c['status'] === 'draft') {
            $this->db->table('integration_connections')->where('id', $connectionId)->update([
                'status'     => 'tested',
                'tested_at'  => $this->clock->nowUtcString(),
                'updated_at' => $this->clock->nowUtcString(),
            ]);
        }

        // FR-INT-012: a connection test is a real provider probe, so feed its
        // outcome to the per-(org,adapter) circuit breaker. A passing test also
        // clears a previously-open breaker; failures count toward tripping it.
        if ($this->reliability !== null) {
            $org   = (string) ($c['organization_id'] ?? '');
            $scope = (string) ($c['adapter_code'] ?? '');
            if ($org !== '' && $scope !== '') {
                if ($pass) {
                    $this->reliability->recordSuccess($org, $scope);
                } else {
                    $reason = is_string($detail['error'] ?? null) ? $detail['error'] : ($operation . ' test failed');
                    $this->reliability->recordFailure($org, $scope, $reason);
                }
            }
        }

        return Result::ok(['connection_id' => $connectionId, 'outcome' => $pass ? 'pass' : 'fail']);
    }

    /** Submit a tested connection for approval. */
    public function submitForApproval(string $connectionId): Result
    {
        $c = $this->find($connectionId);
        if ($c === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }
        if ($c['status'] !== 'tested') {
            return Result::fail('NOT_TESTED', 'integration.not_tested', 409, ['status' => $c['status']]);
        }
        $this->db->table('integration_connections')->where('id', $connectionId)->update([
            'status'     => 'pending_approval',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['connection_id' => $connectionId, 'status' => 'pending_approval']);
    }

    /**
     * Activate a connection. For payment/notification categories this enforces
     * maker-checker: the approver must differ from the requester.
     */
    public function activate(string $connectionId, string $approverId): Result
    {
        $c = $this->find($connectionId);
        if ($c === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }

        $needsApproval = in_array($c['category'], self::APPROVAL_REQUIRED, true);
        if ($needsApproval) {
            if ($c['status'] !== 'pending_approval') {
                return Result::fail('NOT_PENDING', 'integration.not_pending', 409, ['status' => $c['status']]);
            }
            if ((string) $c['requested_by'] === $approverId) {
                return Result::fail('SOD_SELF_APPROVAL', 'integration.self_approval', 403);
            }
        } elseif (! in_array($c['status'], ['tested', 'pending_approval'], true)) {
            return Result::fail('NOT_READY', 'integration.not_ready', 409, ['status' => $c['status']]);
        }

        $this->db->table('integration_connections')->where('id', $connectionId)->update([
            'status'      => 'active',
            'approved_by' => $needsApproval ? $approverId : null,
            'updated_at'  => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['connection_id' => $connectionId, 'status' => 'active']);
    }

    /**
     * IN1/IN2 — DISABLE a connection (reversible pause). Guarded transition:
     *   - requires a legal prior state (never from draft or a terminal state);
     *   - records a reason + actor in an immutable audit entry (mirrors
     *     AccountLifecycleService.transition);
     *   - cascades: auto-revokes this connection's active capability_grants so a
     *     paused connection can't keep serving descendant groups;
     *   - stages `connection.disabled` on the outbox so dependents (Meetings
     *     sessions/tokens — MT5 — and Streaming) can cut off.
     * Idempotent: disabling an already-disabled connection is a no-op success.
     */
    public function disable(string $connectionId, string $reason, string $actorId): Result
    {
        return $this->teardown($connectionId, 'disabled', 'connection.disabled', self::DISABLEABLE_FROM, $reason, $actorId);
    }

    /**
     * IN1/IN2 — REVOKE a connection (terminal). Same guarded/audited/cascading
     * path as disable() but the connection can never return to service, and the
     * signal is `connection.revoked`. On a compromise this is the hard cut-off;
     * pair it with a credential rotation (CredentialVault retires the old
     * version — IN3). Idempotent on an already-revoked connection.
     */
    public function revoke(string $connectionId, string $reason, string $actorId): Result
    {
        return $this->teardown($connectionId, 'revoked', 'connection.revoked', self::REVOKABLE_FROM, $reason, $actorId);
    }

    /**
     * Shared guarded-transition + cascade for disable()/revoke().
     *
     * @param list<string> $legalFrom
     */
    private function teardown(
        string $connectionId,
        string $toStatus,
        string $topic,
        array $legalFrom,
        string $reason,
        string $actorId,
    ): Result {
        $c = $this->find($connectionId);
        if ($c === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }
        $reason = trim($reason);
        if ($reason === '') {
            return Result::fail('REASON_REQUIRED', 'integration.reason_required', 422);
        }
        if ($actorId === '') {
            return Result::fail('ACTOR_REQUIRED', 'integration.actor_required', 422);
        }

        $from = (string) $c['status'];

        // Idempotent no-op if already in the target terminal/paused state.
        if ($from === $toStatus) {
            return Result::ok(['connection_id' => $connectionId, 'status' => $toStatus, 'changed' => false]);
        }
        if (! in_array($from, $legalFrom, true)) {
            return Result::fail('ILLEGAL_TRANSITION', 'integration.illegal_transition', 409, [
                'from' => $from,
                'to'   => $toStatus,
            ]);
        }

        $orgId = (string) $c['organization_id'];
        $now   = $this->clock->nowUtcString();

        $this->db->transStart();

        $this->db->table('integration_connections')->where('id', $connectionId)->update([
            'status'     => $toStatus,
            'updated_at' => $now,
        ]);

        // Cascade: auto-revoke this connection's still-active grants so a
        // disabled/revoked connection stops serving descendant groups.
        $this->db->table('capability_grants')
            ->where('connection_id', $connectionId)
            ->where('status', 'active')
            ->update(['status' => 'revoked']);
        $revokedGrants = $this->db->affectedRows();
        $revokedGrants = is_int($revokedGrants) && $revokedGrants >= 0 ? $revokedGrants : 0;

        if ($this->outbox !== null) {
            $this->outbox->stage('integration_connection', $connectionId, $topic, [
                'connection_id'   => $connectionId,
                'organization_id' => $orgId,
                'group_id'        => $c['group_id'] ?? null,
                'category'        => $c['category'] ?? null,
                'from_status'     => $from,
                'to_status'       => $toStatus,
                'reason'          => $reason,
                'actor_id'        => $actorId,
                'revoked_grants'  => $revokedGrants,
            ], $orgId);
        }

        if ($this->audit !== null) {
            $this->audit->record($orgId, [
                'actor_id'    => $actorId,
                'actor_type'  => 'user',
                'action'      => 'integration.connection.' . ($toStatus === 'revoked' ? 'revoked' : 'disabled'),
                'object_type' => 'integration_connection',
                'object_id'   => $connectionId,
                'outcome'     => 'success',
                'metadata'    => [
                    'from_status'    => $from,
                    'to_status'      => $toStatus,
                    'reason'         => $reason,
                    'revoked_grants' => $revokedGrants,
                ],
            ]);
        }

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            return Result::fail('TEARDOWN_FAILED', 'integration.teardown_failed', 500);
        }

        return Result::ok([
            'connection_id'  => $connectionId,
            'status'         => $toStatus,
            'changed'        => true,
            'revoked_grants' => $revokedGrants,
        ]);
    }

    /**
     * Grant a group time-bound, capability-limited use of this connection without
     * exposing credentials (FR-INT-007).
     *
     * How far the grant reaches below the grantee is `opts['scope_mode']`, using
     * the platform's EXISTING scope vocabulary rather than a second one:
     *
     *  - `self` (default, and the meaning of every grant written before this
     *    existed) — the named grantee group only;
     *  - `self_and_descendants` — the grantee and every subgroup, including
     *    subgroups created AFTER the grant: this is "share with my whole subtree";
     *  - `descendants_only` — subgroups but not the grantee itself;
     *  - `groups` — a hand-picked set (`opts['groups']`), stored in
     *    `grant_scope_groups` under grant_type `capability_grant`, the same table
     *    the PDP reads for role grants.
     *
     * `opts['include_crosscut']` (default OFF) extends coverage down to cross-cut
     * groups linked to a covered hierarchy node, never chaining further.
     *
     * Coverage is decided at send time by `GroupScopeResolver::grantCoversScoped()`
     * — the same call the PDP makes — so a credential grant and a role grant can
     * never disagree about what "the subtree" means.
     *
     * @param array<string,mixed> $opts scope_mode, groups, include_crosscut,
     *                                  constraints, starts_at, expires_at
     */
    public function grantCapability(string $organizationId, string $connectionId, string $granteeGroupId, string $capability, array $opts = []): Result
    {
        $c = $this->find($connectionId);
        if ($c === null) {
            return Result::notFound('integration.connection_not_found', 'CONNECTION_NOT_FOUND');
        }
        if ($c['status'] !== 'active') {
            return Result::fail('CONNECTION_INACTIVE', 'integration.connection_inactive', 409);
        }
        if ($granteeGroupId === '' || $capability === '') {
            return Result::fail('MISSING_FIELDS', 'integration.missing_fields', 422);
        }

        // Containment: a body shares its account DOWN its own subtree — never
        // sideways into a sibling branch and never up at its own parent. An
        // org-wide connection (no owning group) may grant to any group, because
        // that is the whole point of an org-wide account.
        $ownerGroup = isset($c['group_id']) && $c['group_id'] !== '' ? (string) $c['group_id'] : null;
        if ($this->groupScope !== null && $ownerGroup !== null
            && ! $this->groupScope->grantCoversScoped($ownerGroup, ScopeMode::SELF_AND_DESCENDANTS, $granteeGroupId)) {
            return Result::fail('GRANT_OUT_OF_SCOPE', 'integration.grant_out_of_scope', 403, [
                'owner_group_id'   => $ownerGroup,
                'grantee_group_id' => $granteeGroupId,
            ]);
        }

        $rawMode = $opts['scope_mode'] ?? null;
        if ($rawMode !== null && ! ScopeMode::isValid((string) $rawMode)) {
            return Result::fail('BAD_SCOPE', 'integration.grant_bad_scope', 422, ['scope_mode' => (string) $rawMode]);
        }
        $mode = ScopeMode::normalize($rawMode, null);

        // A hand-picked set is the whole substance of a GROUPS grant: empty means
        // the grant would authorize nobody, which is a mistake worth refusing
        // rather than storing.
        $groupSet = [];
        if ($mode === ScopeMode::GROUPS) {
            $raw = $opts['groups'] ?? [];
            if (is_string($raw)) {
                $raw = preg_split('/[;,\s]+/', trim($raw)) ?: [];
            }
            foreach (is_array($raw) ? $raw : [] as $gid) {
                $gid = trim((string) $gid);
                if ($gid !== '' && ! in_array($gid, $groupSet, true)) {
                    $groupSet[] = $gid;
                }
            }
            if ($groupSet === []) {
                return Result::fail('GROUPS_REQUIRED', 'integration.grant_groups_required', 422);
            }
            if (count($groupSet) > self::MAX_GRANT_GROUPS) {
                return Result::fail('TOO_MANY_GROUPS', 'integration.grant_too_many_groups', 422, ['limit' => self::MAX_GRANT_GROUPS]);
            }
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('capability_grants')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'connection_id'    => $connectionId,
            'grantee_group_id' => $granteeGroupId,
            'capability'       => $capability,
            'scope_mode'       => $mode,
            'include_crosscut' => ! empty($opts['include_crosscut']) ? 1 : 0,
            'constraints'      => isset($opts['constraints']) ? json_encode($opts['constraints'], JSON_UNESCAPED_UNICODE) : null,
            'starts_at'        => $opts['starts_at'] ?? $now,
            'expires_at'       => $opts['expires_at'] ?? null,
            'status'           => 'active',
            'created_at'       => $now,
        ]);

        foreach ($groupSet as $gid) {
            $this->db->table('grant_scope_groups')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'grant_type'      => self::GRANT_TYPE,
                'grant_id'        => $id,
                'group_id'        => $gid,
                'created_at'      => $now,
            ]);
        }

        return Result::created([
            'grant_id'         => $id,
            'capability'       => $capability,
            'scope_mode'       => $mode,
            'include_crosscut' => ! empty($opts['include_crosscut']),
            'groups'           => $groupSet,
        ]);
    }

    /**
     * The grants on one connection, newest first — what the credentials screen
     * lists under "who may send on this account". Never touches secret material.
     *
     * @return list<array<string,mixed>>
     */
    public function grantsFor(string $organizationId, string $connectionId): array
    {
        $rows = $this->db->table('capability_grants')
            ->select('id, grantee_group_id, capability, scope_mode, include_crosscut, constraints, starts_at, expires_at, status, created_at')
            ->where('organization_id', $organizationId)
            ->where('connection_id', $connectionId)
            ->orderBy('created_at', 'DESC')
            ->get(200)->getResultArray();

        foreach ($rows as $i => $g) {
            $mode = ScopeMode::normalize($g['scope_mode'] ?? null, null);
            $rows[$i]['scope_mode'] = $mode;
            $rows[$i]['groups']     = $mode === ScopeMode::GROUPS ? $this->grantGroupSet((string) $g['id']) : [];
        }

        return $rows;
    }

    /** Hand-picked group set of a GROUPS-mode grant. @return list<string> */
    private function grantGroupSet(string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', self::GRANT_TYPE)->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    public function revokeGrant(string $grantId): Result
    {
        $this->db->table('capability_grants')->where('id', $grantId)->update(['status' => 'revoked']);

        return Result::ok(['grant_id' => $grantId, 'status' => 'revoked']);
    }

    /** @return array<string,mixed>|null */
    private function find(string $connectionId): ?array
    {
        return $this->db->table('integration_connections')->where('id', $connectionId)->get()->getRowArray() ?: null;
    }
}
