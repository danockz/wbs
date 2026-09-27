<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Gamification\Support\FormulaEvaluator;

/**
 * Locks the SAFE point-formula evaluator used by configurable "variable/formula"
 * earning activities. Formulas are authored by admins at runtime, so the engine
 * must compute them WITHOUT eval() and reject anything outside the whitelist.
 *
 * Guarantees under test:
 *  - arithmetic + precedence + parentheses + unary minus + modulo,
 *  - whitelisted functions (min/max/floor/ceil/round/abs),
 *  - variable resolution from a supplied map (incl. base_points),
 *  - division/modulo by zero → null (caller falls back to base points),
 *  - unknown variables / operators / characters / functions → null,
 *  - isValid() gates unknown variables at save time.
 *
 * Pure string maths — no DB, no PHP extension required.
 *
 * @internal
 */
final class FormulaEvaluatorTest extends CIUnitTestCase
{
    private FormulaEvaluator $ev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ev = new FormulaEvaluator();
    }

    public function testGivingFormulaFloorDivideTimesBase(): void
    {
        // floor(amount / 10) * base_points  with amount=95, base=10 -> 90
        $r = $this->ev->evaluate('floor(amount / 10) * base_points', ['amount' => 95, 'base_points' => 10]);
        $this->assertSame(90.0, $r);
    }

    public function testStreamingFormulaWithMinCap(): void
    {
        // base_points * min(1, duration_minutes / 30) with base=25, dur=15 -> 12.5
        $r = $this->ev->evaluate('base_points * min(1, duration_minutes / 30)', ['base_points' => 25, 'duration_minutes' => 15]);
        $this->assertSame(12.5, $r);
    }

    public function testMaxAndClampComposition(): void
    {
        $this->assertSame(50.0, $this->ev->evaluate('min(max_points, duration * 2)', ['max_points' => 50, 'duration' => 30]));
        $this->assertSame(4.0, $this->ev->evaluate('max(a, b) - min(a, b)', ['a' => 3, 'b' => 7]));
    }

    public function testPrecedenceParenthesesUnaryMinusModulo(): void
    {
        $this->assertSame(20.0, $this->ev->evaluate('(a + b) * c', ['a' => 2, 'b' => 3, 'c' => 4]));
        $this->assertSame(-5.0, $this->ev->evaluate('-base_points + 5', ['base_points' => 10]));
        $this->assertSame(1.0, $this->ev->evaluate('10 % 3', []));
        $this->assertSame(5.0, $this->ev->evaluate('abs(-x)', ['x' => 5]));
    }

    public function testDivisionByZeroReturnsNull(): void
    {
        $this->assertNull($this->ev->evaluate('1 / 0', []));
        $this->assertNull($this->ev->evaluate('5 % 0', []));
    }

    public function testUnknownVariableReturnsNull(): void
    {
        $this->assertNull($this->ev->evaluate('mystery * 2', []));
    }

    public function testMalformedAndDisallowedInputReturnsNull(): void
    {
        $this->assertNull($this->ev->evaluate('2 ^ 3', []));          // ^ not allowed
        $this->assertNull($this->ev->evaluate("system('x')", []));    // unknown function
        $this->assertNull($this->ev->evaluate('base_points *', ['base_points' => 10])); // dangling op
        $this->assertNull($this->ev->evaluate('(1 + 2', []));         // unbalanced parens
    }

    public function testIsValidGatesUnknownVariablesAtSaveTime(): void
    {
        $allowed = ['base_points', 'amount'];
        $this->assertTrue($this->ev->isValid('floor(amount / 10) * base_points', $allowed));
        $this->assertFalse($this->ev->isValid('floor(nope / 10) * base_points', $allowed));
        $this->assertFalse($this->ev->isValid('2 ** 3', $allowed));
    }
}
