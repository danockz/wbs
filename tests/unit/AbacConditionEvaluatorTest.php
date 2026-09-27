<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\AccessControl\Policy\AbacConditionEvaluator;

/**
 * Locks the ABAC condition evaluator — the attribute-matching core of the PDP
 * (SRS: MAC+RBAC+ABAC, default-deny). Conditions are declarative DATA, never
 * code, so the evaluator must:
 *  - AND/OR/NOT compose correctly,
 *  - compare with the documented operator set,
 *  - be numeric-safe (relational ops only apply to numbers), and
 *  - FAIL SAFE (return false) on unknown structure/operators.
 *
 * A regression here silently widens access, so these are security-critical.
 * Pure logic — no DB.
 *
 * @internal
 */
final class AbacConditionEvaluatorTest extends CIUnitTestCase
{
    private AbacConditionEvaluator $ev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ev = new AbacConditionEvaluator();
    }

    public function testEqualityAndInequality(): void
    {
        $this->assertTrue($this->ev->matches(['attr' => 'role', 'op' => 'eq', 'value' => 'finance'], ['role' => 'finance']));
        $this->assertFalse($this->ev->matches(['attr' => 'role', 'op' => 'eq', 'value' => 'finance'], ['role' => 'member']));
        $this->assertTrue($this->ev->matches(['attr' => 'role', 'op' => 'ne', 'value' => 'finance'], ['role' => 'member']));
    }

    public function testNumericComparisonsRequireNumbers(): void
    {
        $this->assertTrue($this->ev->matches(['attr' => 'amount', 'op' => 'lte', 'value' => 50000], ['amount' => 50000]));
        $this->assertFalse($this->ev->matches(['attr' => 'amount', 'op' => 'gt', 'value' => 50000], ['amount' => 50000]));
        // Non-numeric actual must NOT satisfy a relational op (fail safe).
        $this->assertFalse($this->ev->matches(['attr' => 'amount', 'op' => 'lte', 'value' => 50000], ['amount' => 'lots']));
    }

    public function testInAndNotIn(): void
    {
        $this->assertTrue($this->ev->matches(['attr' => 'ch', 'op' => 'in', 'value' => ['a', 'b']], ['ch' => 'a']));
        $this->assertFalse($this->ev->matches(['attr' => 'ch', 'op' => 'in', 'value' => ['a', 'b']], ['ch' => 'z']));
        $this->assertTrue($this->ev->matches(['attr' => 'ch', 'op' => 'nin', 'value' => ['a', 'b']], ['ch' => 'z']));
    }

    public function testExists(): void
    {
        $this->assertTrue($this->ev->matches(['attr' => 'group_id', 'op' => 'exists', 'value' => true], ['group_id' => 'g1']));
        $this->assertFalse($this->ev->matches(['attr' => 'group_id', 'op' => 'exists', 'value' => true], []));
        $this->assertTrue($this->ev->matches(['attr' => 'group_id', 'op' => 'exists', 'value' => false], []));
    }

    public function testAllAnyNotComposition(): void
    {
        $cond = ['all' => [
            ['attr' => 'amount', 'op' => 'lte', 'value' => 50000],
            ['any' => [
                ['attr' => 'role', 'op' => 'eq', 'value' => 'finance'],
                ['attr' => 'role', 'op' => 'eq', 'value' => 'treasurer'],
            ]],
        ]];

        $this->assertTrue($this->ev->matches($cond, ['amount' => 40000, 'role' => 'treasurer']));
        $this->assertFalse($this->ev->matches($cond, ['amount' => 60000, 'role' => 'treasurer'])); // amount fails
        $this->assertFalse($this->ev->matches($cond, ['amount' => 40000, 'role' => 'member']));    // role fails

        $this->assertTrue($this->ev->matches(['not' => ['attr' => 'role', 'op' => 'eq', 'value' => 'member']], ['role' => 'finance']));
    }

    public function testEmptyAllIsVacuouslyTrueButEmptyAnyIsFalse(): void
    {
        $this->assertTrue($this->ev->matches(['all' => []], []));
        $this->assertFalse($this->ev->matches(['any' => []], []));
    }

    public function testUnknownStructureAndOperatorFailSafe(): void
    {
        $this->assertFalse($this->ev->matches([], ['role' => 'finance']));
        $this->assertFalse($this->ev->matches(['attr' => 'role', 'op' => 'regex', 'value' => '.*'], ['role' => 'finance']));
        $this->assertFalse($this->ev->matches(['garbage' => true], []));
    }

    // --- write-time structural validation (ABAC policy CRUD) ------------------

    public function testIsValidAcceptsWellFormedTrees(): void
    {
        $this->assertTrue($this->ev->isValid([])); // empty = no constraint
        $this->assertTrue($this->ev->isValid(['attr' => 'amount', 'op' => 'lte', 'value' => 50000]));
        $this->assertTrue($this->ev->isValid(['attr' => 'role', 'op' => 'in', 'value' => ['finance', 'treasurer']]));
        $this->assertTrue($this->ev->isValid(['attr' => 'flag', 'op' => 'exists', 'value' => true]));
        $this->assertTrue($this->ev->isValid(['all' => [
            ['attr' => 'amount', 'op' => 'lte', 'value' => 50000],
            ['any' => [['attr' => 'role', 'op' => 'eq', 'value' => 'finance']]],
            ['not' => ['attr' => 'role', 'op' => 'eq', 'value' => 'member']],
        ]]));
    }

    public function testIsValidRejectsMalformedTrees(): void
    {
        // unknown operator
        $this->assertFalse($this->ev->isValid(['attr' => 'x', 'op' => 'regex', 'value' => '.*']));
        // in/nin require an array value
        $this->assertFalse($this->ev->isValid(['attr' => 'role', 'op' => 'in', 'value' => 'finance']));
        // exists requires a boolean value
        $this->assertFalse($this->ev->isValid(['attr' => 'flag', 'op' => 'exists', 'value' => 'yes']));
        // empty attr name
        $this->assertFalse($this->ev->isValid(['attr' => '', 'op' => 'eq', 'value' => 1]));
        // empty group child list
        $this->assertFalse($this->ev->isValid(['all' => []]));
        $this->assertFalse($this->ev->isValid(['any' => []]));
        // non-condition child inside a group
        $this->assertFalse($this->ev->isValid(['all' => ['not-an-array']]));
        // garbage / unknown structure
        $this->assertFalse($this->ev->isValid(['garbage' => true]));
        // not with an empty body
        $this->assertFalse($this->ev->isValid(['not' => []]));
    }

    public function testValidatorAndEvaluatorShareTheSameOperatorSet(): void
    {
        // Every operator the validator accepts must be one the evaluator can run
        // (fail-safe): a validated policy can never contain an op the PDP treats
        // as unknown. We assert the documented op list is exactly the constant.
        $this->assertSame(
            ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'nin', 'exists'],
            AbacConditionEvaluator::OPERATORS,
        );
    }
}
