<?php

declare(strict_types=1);

namespace WBS\AccessControl\Policy;

/**
 * Evaluates an ABAC condition tree against request attributes (SRS: ABAC).
 *
 * Conditions are declarative DATA, never code (mirrors the UPAF safety stance):
 *   {"all":[ {"attr":"amount","op":"lte","value":50000},
 *            {"any":[ {"attr":"role","op":"eq","value":"finance"} ]} ]}
 *
 * Supported ops: eq, ne, lt, lte, gt, gte, in, nin, exists. Boolean groupings:
 * all (AND), any (OR), not. Unknown structure evaluates to false (fail safe).
 */
final class AbacConditionEvaluator
{
    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $attributes
     */
    public function matches(array $condition, array $attributes): bool
    {
        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $sub) {
                if (! is_array($sub) || ! $this->matches($sub, $attributes)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $sub) {
                if (is_array($sub) && $this->matches($sub, $attributes)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($condition['not']) && is_array($condition['not'])) {
            return ! $this->matches($condition['not'], $attributes);
        }

        if (isset($condition['attr'], $condition['op'])) {
            return $this->compare(
                $attributes[$condition['attr']] ?? null,
                (string) $condition['op'],
                $condition['value'] ?? null,
            );
        }

        return false;
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            'eq'     => $actual == $expected,
            'ne'     => $actual != $expected,
            'lt'     => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte'    => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'gt'     => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte'    => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'in'     => is_array($expected) && in_array($actual, $expected, true),
            'nin'    => is_array($expected) && ! in_array($actual, $expected, true),
            'exists' => ($expected ? $actual !== null : $actual === null),
            default  => false,
        };
    }

    /**
     * Comparison ops a leaf predicate may use. Kept in one place so the
     * write-time validator and the runtime evaluator can never drift apart.
     */
    public const OPERATORS = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'nin', 'exists'];

    /**
     * Structurally validate a condition tree at WRITE time (ABAC policy CRUD),
     * so a malformed policy is rejected on save rather than silently
     * fail-closing at decision time. Grammar mirrors {@see matches()} exactly:
     *
     *   - group nodes: {"all":[...]} | {"any":[...]} | {"not":{...}} whose
     *     children are themselves valid condition nodes;
     *   - leaf nodes:  {"attr":"<name>","op":"<known-op>","value":<any>} where
     *     `in`/`nin` require an array value and `exists` a boolean value.
     *
     * An empty tree ({}) is allowed and means "no attribute constraint".
     *
     * @param array<string,mixed> $condition
     */
    public function isValid(array $condition): bool
    {
        if ($condition === []) {
            return true; // no constraint
        }

        if (isset($condition['all']) || isset($condition['any'])) {
            $key = isset($condition['all']) ? 'all' : 'any';
            if (! is_array($condition[$key]) || $condition[$key] === []) {
                return false;
            }
            foreach ($condition[$key] as $sub) {
                if (! is_array($sub) || ! $this->isValid($sub)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($condition['not'])) {
            return is_array($condition['not']) && $condition['not'] !== [] && $this->isValid($condition['not']);
        }

        if (isset($condition['attr'], $condition['op'])) {
            if (! is_string($condition['attr']) || $condition['attr'] === '') {
                return false;
            }
            $op = (string) $condition['op'];
            if (! in_array($op, self::OPERATORS, true)) {
                return false;
            }
            if (($op === 'in' || $op === 'nin') && ! is_array($condition['value'] ?? null)) {
                return false;
            }
            if ($op === 'exists' && ! is_bool($condition['value'] ?? null)) {
                return false;
            }

            return true;
        }

        return false;
    }
}
