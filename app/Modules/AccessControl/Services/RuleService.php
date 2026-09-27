<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Administrative CRUD for RuBAC rules — the general, leader-authored rule engine
 * (leadership-responsibility model; extends the MAC+RBAC+ABAC PDP).
 *
 * A rule is declarative data bound to a FACET (access, membership, gamification,
 * ...), scoped to a branch of the group tree. Unlike org-wide ABAC policies, a
 * rule is authored WITHIN A LEADER'S OWN SCOPE: the issuer must hold the rule-
 * management permission covering the rule's scope, and a leader can never author
 * a rule broader than their own authority ({@see GroupScopeResolver::scopeContains}).
 *
 * Conditions are validated at write time with the SAME evaluator the engine runs
 * with, so a malformed rule is rejected on save, never silently fail-open/closed.
 * Every mutation appends a full snapshot to `rule_revisions` and a hash-chained
 * audit entry.
 */
final class RuleService
{
    /** Permission that authorizes managing rules. */
    public const MANAGE_PERMISSION = 'access.rule.manage';

    /** Facets a rule may target. Access is consumed by the PDP. */
    public const FACETS = ['access', 'membership', 'gamification', 'notification', 'events', 'contributions'];

    /** Effects. allow/deny are meaningful to every facet; others are facet-defined. */
    private const EFFECTS = ['allow', 'deny', 'flag', 'adjust', 'require_review'];

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
     * Create a rule within the issuer's scope.
     *
     * @param array<string,mixed> $data facet, code, name, effect?, action_pattern?,
     *   condition, effect_params?, scope_group_id?, scope_mode?, scope_groups?,
     *   priority?, enabled?, description?
     */
    public function create(string $organizationId, string $issuerId, array $data): Result
    {
        $facet = strtolower(trim((string) ($data['facet'] ?? '')));
        if (! in_array($facet, self::FACETS, true)) {
            return Result::fail('BAD_FACET', 'acl.rule_bad_facet', 422, ['allowed' => self::FACETS]);
        }
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            return Result::fail('CODE_REQUIRED', 'acl.rule_code_required', 422);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'acl.rule_name_required', 422);
        }

        $effect = $this->normalizeEffect($data['effect'] ?? 'deny');
        if ($effect === null) {
            return Result::fail('BAD_EFFECT', 'acl.rule_bad_effect', 422, ['allowed' => self::EFFECTS]);
        }

        // Scope resolution + authoring authority.
        $scope = $this->resolveScopeInput($data);
        if (! $scope['ok']) {
            return Result::fail('BAD_SCOPE', $scope['message'], 422, $scope['extra'] ?? []);
        }
        if (! $this->issuerCanManageScope($organizationId, $issuerId, $scope)) {
            return Result::denied('acl.rule_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        // Validate the condition tree at write time (same evaluator as runtime).
        $condition = $this->normalizeCondition($data['condition'] ?? null);
        if ($condition === false) {
            return Result::fail('BAD_CONDITION', 'acl.rule_bad_condition', 422);
        }

        $effectParams = $this->encodeJsonOrNull($data['effect_params'] ?? null);
        if ($effectParams === false) {
            return Result::fail('BAD_EFFECT_PARAMS', 'acl.rule_bad_effect_params', 422);
        }

        // Unique (org, facet, code).
        $dupe = $this->db->table('rules')
            ->where('organization_id', $organizationId)->where('facet', $facet)->where('code', $code)
            ->countAllResults() > 0;
        if ($dupe) {
            return Result::fail('DUPLICATE', 'acl.rule_duplicate', 409, ['code' => $code]);
        }

        $now = $this->clock->nowUtcMicro();
        $id  = Uuid::v7();
        $this->db->table('rules')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'facet'           => $facet,
            'code'            => $code,
            'name'            => $name,
            'description'     => isset($data['description']) ? (string) $data['description'] : null,
            'effect'          => $effect,
            'action_pattern'  => trim((string) ($data['action_pattern'] ?? '*')) ?: '*',
            'condition'       => json_encode($condition, JSON_UNESCAPED_UNICODE),
            'effect_params'   => $effectParams,
            'scope_group_id'  => $scope['group_id'],
            'scope_mode'      => $scope['mode'],
            'include_crosscut' => (int) ($scope['crosscut'] ?? false),
            'priority'        => isset($data['priority']) ? (int) $data['priority'] : self::DEFAULT_PRIORITY,
            'enabled'         => array_key_exists('enabled', $data) ? (int) (bool) $data['enabled'] : 1,
            'created_by'      => $issuerId,
            'created_at'      => $now,
        ]);
        $this->syncGroupSet($organizationId, $id, $scope);
        $this->appendRevision($organizationId, $id, 'create', $issuerId, $data['note'] ?? null, $now);
        $this->audit->record($organizationId, [
            'action'      => 'acl.rule.create',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'rule',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => ['facet' => $facet, 'code' => $code, 'effect' => $effect, 'scope_mode' => $scope['mode']],
        ]);

        return Result::created(['rule_id' => $id, 'facet' => $facet, 'code' => $code, 'status' => 'active']);
    }

    /**
     * Update a rule (must remain within the issuer's scope, both old and new).
     *
     * @param array<string,mixed> $data
     */
    public function update(string $organizationId, string $issuerId, string $ruleId, array $data): Result
    {
        $row = $this->find($organizationId, $ruleId);
        if ($row === null) {
            return Result::notFound('acl.rule_not_found', 'RULE_NOT_FOUND');
        }
        // Issuer must cover the EXISTING scope.
        if (! $this->issuerCanManageScope($organizationId, $issuerId, $this->scopeOfRow($organizationId, $row))) {
            return Result::denied('acl.rule_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        $update = [];
        if (array_key_exists('name', $data)) {
            $update['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = $data['description'] !== null ? (string) $data['description'] : null;
        }
        if (array_key_exists('effect', $data)) {
            $eff = $this->normalizeEffect($data['effect']);
            if ($eff === null) {
                return Result::fail('BAD_EFFECT', 'acl.rule_bad_effect', 422);
            }
            $update['effect'] = $eff;
        }
        if (array_key_exists('action_pattern', $data)) {
            $update['action_pattern'] = trim((string) $data['action_pattern']) ?: '*';
        }
        if (array_key_exists('condition', $data)) {
            $cond = $this->normalizeCondition($data['condition']);
            if ($cond === false) {
                return Result::fail('BAD_CONDITION', 'acl.rule_bad_condition', 422);
            }
            $update['condition'] = json_encode($cond, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('effect_params', $data)) {
            $ep = $this->encodeJsonOrNull($data['effect_params']);
            if ($ep === false) {
                return Result::fail('BAD_EFFECT_PARAMS', 'acl.rule_bad_effect_params', 422);
            }
            $update['effect_params'] = $ep;
        }
        if (array_key_exists('priority', $data)) {
            $update['priority'] = (int) $data['priority'];
        }

        // Scope change: validate the NEW scope is also within the issuer's authority.
        $scopeChanged = isset($data['scope_group_id']) || isset($data['scope_mode'])
            || isset($data['scope_groups']) || array_key_exists('include_crosscut', $data);
        $newScope = null;
        if ($scopeChanged) {
            $newScope = $this->resolveScopeInput($data);
            if (! $newScope['ok']) {
                return Result::fail('BAD_SCOPE', $newScope['message'], 422, $newScope['extra'] ?? []);
            }
            if (! $this->issuerCanManageScope($organizationId, $issuerId, $newScope)) {
                return Result::denied('acl.rule_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
            }
            $update['scope_group_id']   = $newScope['group_id'];
            $update['scope_mode']       = $newScope['mode'];
            $update['include_crosscut'] = (int) ($newScope['crosscut'] ?? false);
        }

        $now = $this->clock->nowUtcMicro();
        $update['updated_by'] = $issuerId;
        $update['updated_at'] = $now;
        $this->db->table('rules')->where('id', $ruleId)->update($update);
        if ($newScope !== null) {
            $this->syncGroupSet($organizationId, $ruleId, $newScope);
        }
        $this->appendRevision($organizationId, $ruleId, 'update', $issuerId, $data['note'] ?? null, $now);
        $this->audit->record($organizationId, [
            'action'      => 'acl.rule.update',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'rule',
            'object_id'   => $ruleId,
            'outcome'     => 'success',
            'metadata'    => ['fields' => array_keys($update)],
        ]);

        return Result::ok(['rule_id' => $ruleId, 'status' => 'updated']);
    }

    public function setEnabled(string $organizationId, string $issuerId, string $ruleId, bool $enabled): Result
    {
        $row = $this->find($organizationId, $ruleId);
        if ($row === null) {
            return Result::notFound('acl.rule_not_found', 'RULE_NOT_FOUND');
        }
        if (! $this->issuerCanManageScope($organizationId, $issuerId, $this->scopeOfRow($organizationId, $row))) {
            return Result::denied('acl.rule_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }
        $now = $this->clock->nowUtcMicro();
        $this->db->table('rules')->where('id', $ruleId)->update([
            'enabled' => (int) $enabled, 'updated_by' => $issuerId, 'updated_at' => $now,
        ]);
        $this->appendRevision($organizationId, $ruleId, $enabled ? 'enable' : 'disable', $issuerId, null, $now);
        $this->audit->record($organizationId, [
            'action'      => 'acl.rule.' . ($enabled ? 'enable' : 'disable'),
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'rule',
            'object_id'   => $ruleId,
            'outcome'     => 'success',
        ]);

        return Result::ok(['rule_id' => $ruleId, 'enabled' => $enabled]);
    }

    public function delete(string $organizationId, string $issuerId, string $ruleId): Result
    {
        $row = $this->find($organizationId, $ruleId);
        if ($row === null) {
            return Result::notFound('acl.rule_not_found', 'RULE_NOT_FOUND');
        }
        if (! $this->issuerCanManageScope($organizationId, $issuerId, $this->scopeOfRow($organizationId, $row))) {
            return Result::denied('acl.rule_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }
        $now = $this->clock->nowUtcMicro();
        // Record the terminal revision BEFORE removing the rule row.
        $this->appendRevision($organizationId, $ruleId, 'delete', $issuerId, null, $now);
        $this->db->table('rules')->where('id', $ruleId)->delete();
        $this->db->table('grant_scope_groups')
            ->where('grant_type', 'rule')->where('grant_id', $ruleId)->delete();
        $this->audit->record($organizationId, [
            'action'      => 'acl.rule.delete',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'rule',
            'object_id'   => $ruleId,
            'outcome'     => 'success',
        ]);

        return Result::ok(['rule_id' => $ruleId, 'status' => 'deleted']);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $organizationId, ?string $facet = null): array
    {
        $q = $this->db->table('rules')->where('organization_id', $organizationId);
        if ($facet !== null) {
            $q->where('facet', $facet);
        }

        return $q->orderBy('facet')->orderBy('priority', 'ASC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    public function show(string $organizationId, string $ruleId): Result
    {
        $row = $this->find($organizationId, $ruleId);
        if ($row === null) {
            return Result::notFound('acl.rule_not_found', 'RULE_NOT_FOUND');
        }
        $row['scope_groups'] = $this->groupSet($ruleId);

        return Result::ok($row);
    }

    // ---- internals ---------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function find(string $organizationId, string $ruleId): ?array
    {
        return $this->db->table('rules')
            ->where('organization_id', $organizationId)->where('id', $ruleId)
            ->get()->getRowArray() ?: null;
    }

    /**
     * Normalize scope input into ['ok','group_id','mode','groups', ...].
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function resolveScopeInput(array $data): array
    {
        $mode = ScopeMode::normalize($data['scope_mode'] ?? null, $data['include_descendants'] ?? null);
        $groupId = isset($data['scope_group_id']) && $data['scope_group_id'] !== ''
            ? (string) $data['scope_group_id'] : null;
        $groups = [];
        if ($mode === ScopeMode::GROUPS) {
            $raw = $data['scope_groups'] ?? [];
            if (! is_array($raw) || $raw === []) {
                return ['ok' => false, 'message' => 'acl.rule_groups_required'];
            }
            $groups = array_values(array_unique(array_map('strval', $raw)));
        } elseif ($groupId === null && $mode !== ScopeMode::SELF) {
            // descendants_only / self_and_descendants need an anchor group.
            return ['ok' => false, 'message' => 'acl.rule_scope_group_required', 'extra' => ['mode' => $mode]];
        }

        return [
            'ok'        => true,
            'group_id'  => $groupId,
            'mode'      => $mode,
            'groups'    => $groups,
            'crosscut'  => ! empty($data['include_crosscut']),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function scopeOfRow(string $organizationId, array $row): array
    {
        return [
            'ok'       => true,
            'group_id' => isset($row['scope_group_id']) && $row['scope_group_id'] !== '' ? (string) $row['scope_group_id'] : null,
            'mode'     => (string) ($row['scope_mode'] ?? ScopeMode::SELF),
            'groups'   => $this->groupSet((string) $row['id']),
            'crosscut' => ! empty($row['include_crosscut']),
        ];
    }

    /**
     * The issuer may manage a rule at $scope only when they hold the rule-
     * management permission AND their own grant scope CONTAINS the rule scope.
     *
     * @param array<string,mixed> $scope
     */
    private function issuerCanManageScope(string $organizationId, string $issuerId, array $scope): bool
    {
        $now = $this->clock->nowUtcString();
        $grants = $this->db->table('role_assignments ra')
            ->select('ra.scope_group_id, ra.scope_mode, ra.include_descendants, ra.include_crosscut, ra.id')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $issuerId)
            ->where('ra.organization_id', $organizationId)
            ->where('p.code', self::MANAGE_PERMISSION)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        foreach ($grants as $g) {
            $gScope = isset($g['scope_group_id']) && $g['scope_group_id'] !== '' ? (string) $g['scope_group_id'] : null;
            $gMode  = ScopeMode::normalize($g['scope_mode'] ?? null, $g['include_descendants'] ?? null);
            $gSet   = $gMode === ScopeMode::GROUPS ? $this->grantGroupSet('role_assignment', (string) $g['id']) : [];
            if ($this->groupScope->scopeContains(
                $gScope, $gMode, $gSet,
                $scope['group_id'], $scope['mode'], $scope['groups'] ?? [],
                ! empty($g['include_crosscut']), ! empty($scope['crosscut']),
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $scope */
    private function syncGroupSet(string $organizationId, string $ruleId, array $scope): void
    {
        $this->db->table('grant_scope_groups')
            ->where('grant_type', 'rule')->where('grant_id', $ruleId)->delete();
        if (($scope['mode'] ?? null) !== ScopeMode::GROUPS) {
            return;
        }
        $now = $this->clock->nowUtcMicro();
        foreach ($scope['groups'] ?? [] as $gid) {
            $this->db->table('grant_scope_groups')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'grant_type'      => 'rule',
                'grant_id'        => $ruleId,
                'group_id'        => (string) $gid,
                'created_at'      => $now,
            ]);
        }
    }

    /** @return list<string> */
    private function groupSet(string $ruleId): array
    {
        return $this->grantGroupSet('rule', $ruleId);
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

    /**
     * Validate + normalize a condition tree. An absent/empty condition becomes
     * the always-true node {"all":[]} so a scoped rule with no attribute test
     * still fires within its scope (mirrors AbacPolicyService). Returns false on
     * a structurally invalid tree.
     *
     * @return array<string,mixed>|false
     */
    private function normalizeCondition(mixed $condition): array|false
    {
        if ($condition === null || $condition === '' || $condition === []) {
            return ['all' => []];
        }
        if (is_string($condition)) {
            $decoded = json_decode($condition, true);
            if (! is_array($decoded)) {
                return false;
            }
            $condition = $decoded;
        }
        if (! is_array($condition)) {
            return false;
        }

        return $this->evaluator->isValid($condition) ? $condition : false;
    }

    private function normalizeEffect(mixed $effect): ?string
    {
        $e = strtolower(trim((string) $effect));

        return in_array($e, self::EFFECTS, true) ? $e : null;
    }

    /** @return string|null|false */
    private function encodeJsonOrNull(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_array($value)) {
            return false;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function appendRevision(
        string $organizationId,
        string $ruleId,
        string $action,
        ?string $actorId,
        ?string $note,
        string $at,
    ): void {
        $snapshot = $this->find($organizationId, $ruleId);
        if ($snapshot !== null) {
            $snapshot['scope_groups'] = $this->groupSet($ruleId);
        }
        $this->db->table('rule_revisions')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'rule_id'         => $ruleId,
            'action'          => $action,
            'snapshot'        => json_encode($snapshot ?? ['deleted' => true], JSON_UNESCAPED_UNICODE),
            'actor_id'        => $actorId,
            'note'            => $note !== null ? (string) $note : null,
            'created_at'      => $at,
        ]);
    }
}
