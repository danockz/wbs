<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\AccessControl\Policy\AbacConditionEvaluator;
use WBS\AccessControl\Policy\RuleOutcome;
use WBS\Shared\Support\GroupScopeResolver;

/**
 * General, reusable rule engine (RuBAC) — a single evaluator any facet can use
 * (access, membership, gamification, notification, ...).
 *
 * A rule is declarative data: a FACET, an action pattern, an ABAC-grammar
 * condition tree, an effect, a priority, and a GROUP SCOPE (mode-aware) so a
 * leader's rule only bites within their own branch of the hierarchy. The engine:
 *
 *   1. loads enabled rules for (org, facet), ordered by priority ASC;
 *   2. keeps only those whose action pattern matches the action AND whose scope
 *      covers the context's target group (so an ancestor's rule reaches a
 *      descendant only when its scope_mode says so);
 *   3. evaluates each rule's condition against the context attributes;
 *   4. returns a facet-agnostic {@see RuleOutcome}.
 *
 * For the ACCESS facet the meaning is deny-overrides: if any matched rule is a
 * deny, the outcome is deny; else if any is an allow, allow; else none. Other
 * facets read `matched`/`effect` and `effect_params` as they see fit.
 *
 * Conditions are validated at write time (RuleService) and evaluated here with
 * the same {@see AbacConditionEvaluator}, so a malformed rule can never inject
 * behaviour — it simply fails to match.
 */
final class RuleEngine
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AbacConditionEvaluator $evaluator,
        private readonly GroupScopeResolver $groupScope,
    ) {
    }

    /**
     * Evaluate a facet's rules against a context.
     *
     * @param array<string,mixed> $attributes context attributes for the condition
     * @param string|null         $targetGroupId the group the action targets (for scope)
     */
    public function evaluate(
        string $organizationId,
        string $facet,
        string $action,
        array $attributes,
        ?string $targetGroupId = null,
    ): RuleOutcome {
        $rows = $this->db->table('rules')
            ->where('organization_id', $organizationId)
            ->where('facet', $facet)
            ->where('enabled', 1)
            ->orderBy('priority', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
        if ($rows === []) {
            return RuleOutcome::none();
        }

        // Pre-load the multi-group sets for any 'groups'-mode rules in one query.
        $groupsModeIds = [];
        foreach ($rows as $r) {
            if (($r['scope_mode'] ?? 'self') === 'groups') {
                $groupsModeIds[] = (string) $r['id'];
            }
        }
        $setByRule = $this->loadGroupSets($groupsModeIds);

        $matched      = [];
        $denyEffect   = null;
        $allowEffect  = null;

        foreach ($rows as $row) {
            $pattern = (string) $row['action_pattern'];
            if (! $this->actionMatches($pattern, $action)) {
                continue;
            }

            $scopeGroup = isset($row['scope_group_id']) && $row['scope_group_id'] !== ''
                ? (string) $row['scope_group_id'] : null;
            $mode = (string) ($row['scope_mode'] ?? 'self');
            $set  = $setByRule[(string) $row['id']] ?? [];
            $crosscut = ! empty($row['include_crosscut']);
            if (! $this->groupScope->grantCoversScoped($scopeGroup, $mode, $targetGroupId, $set, $crosscut)) {
                continue;
            }

            $cond = json_decode((string) $row['condition'], true);
            if (! is_array($cond)) {
                continue;
            }
            // An empty condition ({}) means "always matches within scope".
            if ($cond !== [] && ! $this->evaluator->matches($cond, $attributes)) {
                continue;
            }

            $effect  = (string) $row['effect'];
            $params  = isset($row['effect_params']) && $row['effect_params'] !== null
                ? json_decode((string) $row['effect_params'], true) : null;
            $matched[] = [
                'code'          => (string) $row['code'],
                'effect'        => $effect,
                'priority'      => (int) $row['priority'],
                'effect_params' => $params,
            ];

            if ($effect === 'deny' && $denyEffect === null) {
                $denyEffect = (string) $row['code'];
            } elseif ($effect === 'allow' && $allowEffect === null) {
                $allowEffect = (string) $row['code'];
            }
        }

        if ($matched === []) {
            return RuleOutcome::none();
        }

        // Access semantics: deny overrides allow. For non-access facets the
        // deciding effect is simply the highest-priority matched rule's effect.
        if ($denyEffect !== null) {
            return new RuleOutcome('deny', $matched, $denyEffect);
        }
        if ($allowEffect !== null) {
            return new RuleOutcome('allow', $matched, $allowEffect);
        }

        // No allow/deny (custom facet effects only): report the first matched.
        return new RuleOutcome($matched[0]['effect'], $matched, $matched[0]['code']);
    }

    /**
     * Load hand-picked group sets for the given rule ids.
     *
     * @param list<string> $ruleIds
     * @return array<string, list<string>>
     */
    private function loadGroupSets(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $rows = $this->db->table('grant_scope_groups')
            ->select('grant_id, group_id')
            ->where('grant_type', 'rule')
            ->whereIn('grant_id', $ruleIds)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['grant_id']][] = (string) $r['group_id'];
        }

        return $out;
    }

    /** Exact match, "prefix.*" wildcard, or bare "*" (any action). */
    private function actionMatches(string $pattern, string $action): bool
    {
        if ($pattern === '*' || $pattern === $action) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($action, substr($pattern, 0, -1));
        }

        return false;
    }
}
