<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Administrative CRUD for ABAC policies (SRS: ABAC in the MAC+RBAC+ABAC PDP).
 *
 * A policy is an organization-wide attribute rule the PDP consults at decision
 * time: an `effect` (allow|deny), an `action_pattern` (exact or `prefix.*`), a
 * declarative `condition` tree, a `priority` (lower runs first) and an `enabled`
 * flag. Because a policy influences authorization org-wide, managing them
 * requires an ORG-WIDE grant of the management permission (the PDP coverage rule
 * against a null target, via {@see GroupScopeResolver::grantCovers()}).
 *
 * The critical safety property: every condition tree is validated through the
 * SAME {@see AbacConditionEvaluator} the PDP evaluates with, at WRITE time. A
 * malformed policy is rejected on save rather than silently fail-closing (and
 * possibly denying legitimate access) at decision time. Conditions are
 * declarative DATA — never code — so there is no eval() path here either.
 *
 * One subtlety this service guards against: the PDP evaluator treats a BARE
 * empty condition ({}) as fail-safe FALSE (it never matches). An admin who omits
 * conditions almost always means "apply this policy unconditionally to the
 * action pattern", so an absent/empty condition is normalized on write to the
 * canonical always-true node {"all":[]} — otherwise the policy would save
 * cleanly yet silently never fire.
 *
 * `deny`-effect policies are never blocked from being created (deny is always
 * safe to add); it is broad `allow` policies that carry risk, so those are the
 * ones the org-wide management gate and the audit trail protect.
 */
final class AbacPolicyService
{
    /** Permission that authorizes managing ABAC policies. */
    public const MANAGE_PERMISSION = 'access.policy.manage';

    private const EFFECTS       = ['allow', 'deny'];
    private const DEFAULT_PRIORITY = 100;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupScopeResolver $groupScope,
        private readonly AuditLogger $audit,
        private readonly AbacConditionEvaluator $evaluator,
    ) {
    }

    /**
     * Create a policy.
     *
     * @param array<string,mixed> $data code, effect?, action_pattern, condition,
     *                                   priority?, enabled?, description?
     */
    public function create(string $organizationId, string $issuerId, array $data): Result
    {
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.policy_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $code = strtolower(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            return Result::fail('CODE_REQUIRED', 'acl.policy_code_required', 422);
        }
        $actionPattern = trim((string) ($data['action_pattern'] ?? ''));
        if ($actionPattern === '') {
            return Result::fail('ACTION_REQUIRED', 'acl.policy_action_required', 422);
        }

        $effect = $this->normalizeEffect($data['effect'] ?? 'allow');
        if ($effect === null) {
            return Result::fail('BAD_EFFECT', 'acl.policy_bad_effect', 422);
        }

        $condition = $this->extractCondition($data['condition'] ?? null);
        if ($condition === false) {
            return Result::fail('BAD_CONDITION', 'acl.policy_bad_condition', 422);
        }
        // WRITE-TIME validation through the PDP's own evaluator.
        if (! $this->evaluator->isValid($condition)) {
            return Result::fail('INVALID_CONDITION', 'acl.policy_invalid_condition', 422);
        }

        $dupe = $this->db->table('abac_policies')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();
        if ($dupe !== null) {
            return Result::fail('POLICY_EXISTS', 'acl.policy_exists', 409);
        }

        $now = $this->clock->nowUtcString();
        $id  = Uuid::v7();
        $this->db->table('abac_policies')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'code'            => $code,
            'description'     => $this->normalizeDescription($data['description'] ?? null),
            'effect'          => $effect,
            'action_pattern'  => $actionPattern,
            'condition'       => json_encode($condition, JSON_UNESCAPED_UNICODE),
            'priority'        => $this->normalizePriority($data['priority'] ?? null),
            'enabled'         => array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->recordAudit($organizationId, $issuerId, 'acl.policy.create', $id, [
            'code' => $code, 'effect' => $effect, 'action_pattern' => $actionPattern,
        ]);

        return Result::created(['policy_id' => $id, 'code' => $code, 'effect' => $effect]);
    }

    /**
     * Update a policy's mutable fields (never its code).
     *
     * @param array<string,mixed> $data effect?, action_pattern?, condition?,
     *                                   priority?, enabled?, description?
     */
    public function update(string $organizationId, string $issuerId, string $policyId, array $data): Result
    {
        $policy = $this->findPolicy($organizationId, $policyId);
        if ($policy === null) {
            return Result::notFound('acl.policy_not_found', 'POLICY_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.policy_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $update = ['updated_at' => $this->clock->nowUtcString()];

        if (array_key_exists('effect', $data)) {
            $effect = $this->normalizeEffect($data['effect']);
            if ($effect === null) {
                return Result::fail('BAD_EFFECT', 'acl.policy_bad_effect', 422);
            }
            $update['effect'] = $effect;
        }
        if (array_key_exists('action_pattern', $data)) {
            $ap = trim((string) $data['action_pattern']);
            if ($ap === '') {
                return Result::fail('ACTION_REQUIRED', 'acl.policy_action_required', 422);
            }
            $update['action_pattern'] = $ap;
        }
        if (array_key_exists('condition', $data)) {
            $condition = $this->extractCondition($data['condition']);
            if ($condition === false) {
                return Result::fail('BAD_CONDITION', 'acl.policy_bad_condition', 422);
            }
            if (! $this->evaluator->isValid($condition)) {
                return Result::fail('INVALID_CONDITION', 'acl.policy_invalid_condition', 422);
            }
            $update['condition'] = json_encode($condition, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('priority', $data)) {
            $update['priority'] = $this->normalizePriority($data['priority']);
        }
        if (array_key_exists('enabled', $data)) {
            $update['enabled'] = (int) (bool) $data['enabled'];
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = $this->normalizeDescription($data['description']);
        }

        $this->db->table('abac_policies')->where('id', $policyId)->update($update);
        $this->recordAudit($organizationId, $issuerId, 'acl.policy.update', $policyId, array_diff_key($update, ['updated_at' => true, 'condition' => true]));

        return Result::ok(['policy_id' => $policyId, 'status' => 'updated']);
    }

    /**
     * Enable/disable a policy without deleting it (soft toggle). The PDP only
     * consults enabled policies, so this is the safe way to retire a rule while
     * keeping it for audit/history.
     */
    public function setEnabled(string $organizationId, string $issuerId, string $policyId, bool $enabled): Result
    {
        $policy = $this->findPolicy($organizationId, $policyId);
        if ($policy === null) {
            return Result::notFound('acl.policy_not_found', 'POLICY_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.policy_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $this->db->table('abac_policies')->where('id', $policyId)->update([
            'enabled'    => (int) $enabled,
            'updated_at' => $this->clock->nowUtcString(),
        ]);
        $this->recordAudit($organizationId, $issuerId, 'acl.policy.set_enabled', $policyId, ['enabled' => $enabled]);

        return Result::ok(['policy_id' => $policyId, 'enabled' => $enabled]);
    }

    /** Hard-delete a policy. History lives in the audit chain. */
    public function delete(string $organizationId, string $issuerId, string $policyId): Result
    {
        $policy = $this->findPolicy($organizationId, $policyId);
        if ($policy === null) {
            return Result::notFound('acl.policy_not_found', 'POLICY_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.policy_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $this->db->table('abac_policies')->where('id', $policyId)->delete();
        $this->recordAudit($organizationId, $issuerId, 'acl.policy.delete', $policyId, ['code' => $policy['code']]);

        return Result::ok(['policy_id' => $policyId, 'status' => 'deleted']);
    }

    /**
     * List policies (management view), evaluation order first.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $organizationId): array
    {
        return $this->db->table('abac_policies')
            ->where('organization_id', $organizationId)
            ->orderBy('priority', 'ASC')
            ->orderBy('code', 'ASC')
            ->get()->getResultArray();
    }

    /** Read one policy. */
    public function show(string $organizationId, string $policyId): Result
    {
        $policy = $this->findPolicy($organizationId, $policyId);
        if ($policy === null) {
            return Result::notFound('acl.policy_not_found', 'POLICY_NOT_FOUND');
        }

        return Result::ok($policy);
    }

    // ---------------------------------------------------------------------

    /**
     * True when the issuer holds the policy-management permission at ORG-WIDE
     * scope (only a null grant scope covers an org-wide action).
     */
    private function issuerCanManage(string $organizationId, string $issuerId): bool
    {
        $now = $this->clock->nowUtcString();

        $grants = $this->db->table('role_assignments ra')
            ->select('ra.scope_group_id, ra.include_descendants')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $issuerId)
            ->where('ra.organization_id', $organizationId)
            ->where('p.code', self::MANAGE_PERMISSION)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        foreach ($grants as $g) {
            $scope = isset($g['scope_group_id']) && $g['scope_group_id'] !== '' ? (string) $g['scope_group_id'] : null;
            $incl  = ! empty($g['include_descendants']);
            if ($this->groupScope->grantCovers($scope, $incl, null)) {
                return true;
            }
        }

        return false;
    }

    /** Canonical "applies unconditionally" tree — a vacuous AND is always true. */
    private const UNCONDITIONAL = ['all' => []];

    /**
     * Coerce the condition input to an array tree. Accepts an array or a JSON
     * string. An absent/empty condition means the policy applies UNCONDITIONALLY
     * to its action pattern, so it is normalized to the canonical always-true
     * node {@see UNCONDITIONAL} — NOT the bare empty tree, which the PDP
     * evaluator treats as fail-safe false (and would make the policy silently
     * never fire). Returns false on anything else (or invalid JSON).
     *
     * @return array<string,mixed>|false
     */
    private function extractCondition(mixed $condition): array|false
    {
        if ($condition === null || $condition === '' || $condition === []) {
            return self::UNCONDITIONAL;
        }
        if (is_string($condition)) {
            $decoded = json_decode($condition, true);
            if (! is_array($decoded)) {
                return false;
            }

            return $decoded === [] ? self::UNCONDITIONAL : $decoded;
        }

        return is_array($condition) ? $condition : false;
    }

    private function normalizeEffect(mixed $effect): ?string
    {
        $effect = strtolower(trim((string) $effect));

        return in_array($effect, self::EFFECTS, true) ? $effect : null;
    }

    private function normalizePriority(mixed $priority): int
    {
        if ($priority === null || $priority === '') {
            return self::DEFAULT_PRIORITY;
        }

        return max(0, (int) $priority);
    }

    private function normalizeDescription(mixed $d): ?string
    {
        $d = $d === null ? '' : trim((string) $d);

        return $d === '' ? null : $d;
    }

    /** @return array<string,mixed>|null */
    private function findPolicy(string $organizationId, string $policyId): ?array
    {
        return $this->db->table('abac_policies')
            ->where('id', $policyId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
    }

    /** @param array<string,mixed> $metadata */
    private function recordAudit(string $organizationId, string $issuerId, string $action, string $objectId, array $metadata): void
    {
        $this->audit->record($organizationId, [
            'action'      => $action,
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'abac_policy',
            'object_id'   => $objectId,
            'outcome'     => 'success',
            'metadata'    => $metadata,
        ]);
    }
}
