<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\AccessControl\Policy\Combinators\SegregationOfDutiesCombinator;
use WBS\AccessControl\Policy\Decision;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\ScopeMode;

/**
 * Policy Decision Point composing MAC + RBAC + ABAC with DEFAULT-DENY
 * (SRS: MAC+RBAC+ABAC PDP).
 *
 * Evaluation order (first decisive result wins):
 *   1. MAC    — subject clearance must dominate the object's classification;
 *               otherwise DENY regardless of roles.
 *   2. SoD    — approval actions deny self-approval (maker != checker).
 *   3. RuBAC  — leader-authored, group-scoped rules (deny overrides, then allow).
 *   4. ABAC   — org-wide explicit deny policies (deny overrides), then allow.
 *   5. RBAC   — subject must hold a role granting the permission.
 *   6. default DENY.
 *
 * A RuBAC or ABAC deny always beats an RBAC allow. RuBAC runs before org-wide
 * ABAC so a leader's scoped guardrail can deny within their branch even when a
 * broad org policy or role would otherwise allow. If nothing grants, the request
 * is denied — nothing is implicitly permitted.
 */
final class AuthorizationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AbacConditionEvaluator $abac,
        private readonly SegregationOfDutiesCombinator $sod,
        private readonly ?GroupScopeResolver $groupScope = null,
        private readonly ?RuleEngine $rules = null,
    ) {
    }

    public function decide(AccessRequest $req): Decision
    {
        // 1) MAC — mandatory classification check.
        $mac = $this->macCheck($req);
        if ($mac !== null) {
            return $mac;
        }

        // 2) Segregation of duties for approval actions.
        $sod = $this->sod->evaluate($req);
        if ($sod !== null) {
            return $sod;
        }

        // 3) RuBAC — leader-authored, group-scoped rules (deny overrides).
        if ($this->rules !== null) {
            $targetGroupId = $req->attr('group_id');
            $targetGroupId = $targetGroupId !== null && $targetGroupId !== '' ? (string) $targetGroupId : null;
            $outcome = $this->rules->evaluate(
                $req->organizationId,
                'access',
                $req->action,
                $req->attributes + ['subject_id' => $req->subjectId],
                $targetGroupId,
            );
            if ($outcome->denied()) {
                return Decision::deny('RUBAC_DENY:' . (string) $outcome->decidingCode, 'rubac');
            }
            // A rule ALLOW still requires the subject to actually hold access via
            // RBAC/ABAC below — a scoped rule is a guardrail, not a grant — UNLESS
            // it is a self-standing allow. We treat rule allow as a positive
            // signal recorded but not sufficient on its own; RBAC/ABAC decide.
        }

        // 4) ABAC — org-wide deny overrides, then explicit allow.
        [$abacDeny, $abacAllow] = $this->abacCheck($req);
        if ($abacDeny) {
            return Decision::deny('ABAC_DENY', 'abac');
        }
        if ($abacAllow) {
            return Decision::permit('ABAC_ALLOW', 'abac');
        }

        // 5) RBAC — role must grant the permission.
        if ($this->rbacGrants($req)) {
            return Decision::permit('RBAC_GRANT', 'rbac');
        }

        // 6) Default deny.
        return Decision::deny('default_deny', 'default');
    }

    public function isAllowed(AccessRequest $req): bool
    {
        return $this->decide($req)->isPermitted();
    }

    /** Returns a DENY Decision if MAC blocks, else null. */
    private function macCheck(AccessRequest $req): ?Decision
    {
        if ($req->objectType === null || $req->objectId === null) {
            return null; // no labelled object -> MAC not applicable
        }

        $label = $this->db->table('object_labels')
            ->select('level')
            ->where('object_type', $req->objectType)
            ->where('object_id', $req->objectId)
            ->get()->getRowArray();
        if ($label === null) {
            return null; // unlabelled object -> defer to RBAC/ABAC
        }

        $clearance = $this->db->table('subject_clearances')
            ->select('level')
            ->where('subject_id', $req->subjectId)
            ->get()->getRowArray();
        $subjectLevel = $clearance !== null ? (int) $clearance['level'] : 0;

        if ($subjectLevel < (int) $label['level']) {
            return Decision::deny('MAC_CLEARANCE', 'mac');
        }

        return null;
    }

    /** @return array{0:bool,1:bool} [denyMatched, allowMatched] */
    private function abacCheck(AccessRequest $req): array
    {
        $rows = $this->db->table('abac_policies')
            ->where('organization_id', $req->organizationId)
            ->where('enabled', 1)
            ->orderBy('priority', 'ASC')
            ->get()->getResultArray();

        $denyMatched  = false;
        $allowMatched = false;

        foreach ($rows as $row) {
            if (! $this->actionMatches((string) $row['action_pattern'], $req->action)) {
                continue;
            }
            $cond = json_decode((string) $row['condition'], true);
            if (! is_array($cond) || ! $this->abac->matches($cond, $req->attributes)) {
                continue;
            }
            if ($row['effect'] === 'deny') {
                $denyMatched = true;
            } else {
                $allowMatched = true;
            }
        }

        return [$denyMatched, $allowMatched];
    }

    private function rbacGrants(AccessRequest $req): bool
    {
        // UTC with microseconds: every grant/session window is stored in UTC and
        // the break-glass window is DATETIME(6), so a server-local, second-
        // precision clock (date()) would both skew by timezone AND lose a
        // same-second emergency session for the rest of that second. Match the
        // stored precision exactly.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        // The action's TARGET group, if the caller declared one (e.g. a service
        // authorizing management of a group-scoped follow-up type / activity
        // category / campaign). Absent => an unscoped/org-wide action, which
        // only an org-wide grant satisfies.
        $targetGroupId = $req->attr('group_id');
        $targetGroupId = $targetGroupId !== null && $targetGroupId !== '' ? (string) $targetGroupId : null;

        // Coarse capability gate: a route filter that cannot know the resource's
        // target group asks "does this subject hold the permission in ANY scope?"
        // (attribute scope_check=any). It admits group-scoped admins past the
        // gate; the authoritative per-group check is then done in the service
        // (StreamService pattern). Org-wide-only endpoints omit this attribute,
        // so a group-scoped grant will NOT satisfy them.
        $scopeAny = $req->attr('scope_check') === 'any';

        // Role-based grants. Only ACTIVE assignments within their effective
        // window count (FR-ACL-003/004: expiring, revocable grants). Legacy rows
        // have status='active' + NULL effective_to, so they always qualify. We
        // fetch the scope columns and enforce hierarchy in PHP so a grant only
        // authorizes within the branch it was scoped to.
        $roleGrants = $this->db->table('role_assignments ra')
            ->select('ra.id, ra.scope_group_id, ra.scope_mode, ra.include_descendants, ra.include_crosscut')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $req->subjectId)
            ->where('ra.organization_id', $req->organizationId)
            ->where('p.code', $req->action)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->groupStart()
                ->where('ra.effective_from IS NULL')
                ->orWhere('ra.effective_from <=', $now)
            ->groupEnd()
            ->get()->getResultArray();
        if ($scopeAny ? $roleGrants !== [] : $this->anyGrantCovers($roleGrants, $targetGroupId, 'role_assignment')) {
            return true;
        }

        // Direct, exceptional permission grant (FR-ACL-003/004): an approved,
        // unexpired `permission` access request that has not been revoked. These
        // are scoped the same way as role assignments.
        $directGrants = $this->db->table('access_requests')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut')
            ->where('organization_id', $req->organizationId)
            ->where('subject_id', $req->subjectId)
            ->where('grant_type', 'permission')
            ->where('permission_code', $req->action)
            ->where('status', 'approved')
            ->groupStart()
                ->where('effective_to IS NULL')
                ->orWhere('effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        if ($scopeAny ? $directGrants !== [] : $this->anyGrantCovers($directGrants, $targetGroupId, 'access_request')) {
            return true;
        }

        // Delegated authority (FR-ACL-005): an active, unexpired delegation TO
        // this subject confers the permission within its own scope, exactly like
        // a role/direct grant. Chains are already validated at creation time, so
        // here we only need to honour any currently-effective delegation.
        $delegated = $this->db->table('delegations')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut')
            ->where('organization_id', $req->organizationId)
            ->where('delegate_id', $req->subjectId)
            ->where('permission_code', $req->action)
            ->where('status', 'active')
            ->where('effective_from <=', $now)
            ->where('effective_to >', $now)
            ->get()->getResultArray();

        if ($scopeAny ? $delegated !== [] : $this->anyGrantCovers($delegated, $targetGroupId, 'delegation')) {
            return true;
        }

        // Break-glass emergency access (FR-ACL-006): an active, unexpired session
        // confers exactly its one permission within its scope for a narrow
        // window. Financial/audit-protected permissions can never be granted this
        // way (enforced when the session is opened), so honouring active sessions
        // here does not bypass those immutable controls.
        $breakGlass = $this->db->table('break_glass_sessions')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut')
            ->where('organization_id', $req->organizationId)
            ->where('subject_id', $req->subjectId)
            ->where('permission_code', $req->action)
            ->where('status', 'active')
            ->where('effective_from <=', $now)
            ->where('effective_to >', $now)
            ->get()->getResultArray();

        return $scopeAny ? $breakGlass !== [] : $this->anyGrantCovers($breakGlass, $targetGroupId, 'break_glass');
    }

    /**
     * True when at least one grant row covers the target group. Delegates the
     * hierarchy rule to the shared resolver so the PDP and every service agree
     * on what "covers" means. When no resolver is wired (constructed without
     * one), fall back to the legacy org-wide behaviour: any matching grant
     * authorizes regardless of scope.
     *
     * Mode-aware: honours scope_mode (self / self_and_descendants /
     * descendants_only / groups), falling back to the legacy include_descendants
     * boolean for rows written before scope_mode existed. For 'groups' mode the
     * hand-picked set is loaded from grant_scope_groups keyed by $grantType.
     *
     * @param list<array<string,mixed>> $grants
     */
    private function anyGrantCovers(array $grants, ?string $targetGroupId, string $grantType): bool
    {
        if ($this->groupScope === null) {
            return $grants !== [];
        }

        foreach ($grants as $g) {
            $scope = isset($g['scope_group_id']) && $g['scope_group_id'] !== '' ? (string) $g['scope_group_id'] : null;
            $mode  = ScopeMode::normalize($g['scope_mode'] ?? null, $g['include_descendants'] ?? null);
            $set   = $mode === ScopeMode::GROUPS && isset($g['id'])
                ? $this->grantGroupSet($grantType, (string) $g['id'])
                : [];
            $crosscut = ! empty($g['include_crosscut']);
            if ($this->groupScope->grantCoversScoped($scope, $mode, $targetGroupId, $set, $crosscut)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function grantGroupSet(string $grantType, string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', $grantType)->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    /** Supports exact match or a trailing ".*" wildcard prefix. */
    private function actionMatches(string $pattern, string $action): bool
    {
        if ($pattern === $action) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1); // keep the dot

            return str_starts_with($action, $prefix);
        }

        return false;
    }
}
