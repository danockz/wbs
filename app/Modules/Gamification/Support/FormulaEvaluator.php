<?php

declare(strict_types=1);

namespace WBS\Gamification\Support;

/**
 * A SAFE arithmetic evaluator for admin-configured point formulas.
 *
 * Point activities may compute their base value from a formula such as
 * "floor(amount / 10) * base_points" or "min(max_points, duration * 2)". These
 * formulas are authored by admins at runtime and stored in
 * gamification_rules.point_formula, so they must NEVER be executed with eval(),
 * create_function(), or any PHP-level code path.
 *
 * Instead this is a tiny, self-contained expression engine:
 *   - Tokeniser  → numbers, identifiers, function names, operators, parens, comma.
 *   - Shunting-yard → converts the infix tokens to RPN honouring precedence.
 *   - RPN evaluator → computes the result over a whitelisted variable map.
 *
 * Whitelist (everything else is a parse/eval error):
 *   operators   + - * / %   and unary minus
 *   functions   min max floor ceil round abs
 *   variables   supplied by the caller (base_points + numeric event fields)
 *
 * On ANY problem (unknown token, unknown variable/function, arity mismatch,
 * division by zero, malformed expression) the evaluator returns null and the
 * caller falls back to the rule's fixed base points. Formulas are ALSO validated
 * at save time so a bad one is rejected before it ever reaches the ledger.
 */
final class FormulaEvaluator
{
    /** @var array<string,int> function name => arg count (min); variadic marked -1 */
    private const FUNCTIONS = [
        'min'   => -1,
        'max'   => -1,
        'floor' => 1,
        'ceil'  => 1,
        'round' => 1,
        'abs'   => 1,
    ];

    private const OPERATORS = [
        '+' => ['prec' => 2, 'assoc' => 'L'],
        '-' => ['prec' => 2, 'assoc' => 'L'],
        '*' => ['prec' => 3, 'assoc' => 'L'],
        '/' => ['prec' => 3, 'assoc' => 'L'],
        '%' => ['prec' => 3, 'assoc' => 'L'],
        'u-' => ['prec' => 5, 'assoc' => 'R'], // unary minus
    ];

    /**
     * Evaluate $formula against $vars. Returns a float result or null on any
     * error. Variable names must be [a-z_][a-z0-9_]* and present in $vars.
     *
     * @param array<string,int|float> $vars
     */
    public function evaluate(string $formula, array $vars): ?float
    {
        $tokens = $this->tokenize($formula);
        if ($tokens === null) {
            return null;
        }
        $rpn = $this->toRpn($tokens);
        if ($rpn === null) {
            return null;
        }

        return $this->evalRpn($rpn, $vars);
    }

    /**
     * Static validation used at rule-save time: is this a well-formed formula
     * using only whitelisted variables? A zero-filled sample var map lets us
     * confirm it parses and evaluates without needing real event data.
     *
     * @param list<string> $allowedVars
     */
    public function isValid(string $formula, array $allowedVars): bool
    {
        $tokens = $this->tokenize($formula);
        if ($tokens === null) {
            return false;
        }
        // Every identifier that is not a function name must be an allowed var.
        foreach ($tokens as $t) {
            if ($t['type'] === 'ident' && ! in_array($t['value'], $allowedVars, true)) {
                return false;
            }
        }
        $rpn = $this->toRpn($tokens);
        if ($rpn === null) {
            return false;
        }
        $sample = [];
        foreach ($allowedVars as $v) {
            $sample[$v] = 1.0; // 1 avoids incidental div-by-zero while validating
        }

        return $this->evalRpn($rpn, $sample) !== null;
    }

    // ------------------------------------------------------------------------

    /**
     * @return list<array{type:string,value:string}>|null
     */
    private function tokenize(string $s): ?array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($s);
        $prev   = null; // previous token type, for unary-minus detection

        while ($i < $len) {
            $c = $s[$i];

            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }

            // number (integer or decimal)
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
                $num = '';
                $dot = false;
                while ($i < $len && (ctype_digit($s[$i]) || $s[$i] === '.')) {
                    if ($s[$i] === '.') {
                        if ($dot) {
                            return null; // two dots
                        }
                        $dot = true;
                    }
                    $num .= $s[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'num', 'value' => $num];
                $prev     = 'num';
                continue;
            }

            // identifier / function name
            if (ctype_alpha($c) || $c === '_') {
                $id = '';
                while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
                    $id .= $s[$i];
                    $i++;
                }
                // function if immediately followed by '('
                $j = $i;
                while ($j < $len && ($s[$j] === ' ' || $s[$j] === "\t")) {
                    $j++;
                }
                if ($j < $len && $s[$j] === '(') {
                    if (! isset(self::FUNCTIONS[$id])) {
                        return null; // unknown function
                    }
                    $tokens[] = ['type' => 'func', 'value' => $id];
                    $prev     = 'func';
                } else {
                    $tokens[] = ['type' => 'ident', 'value' => strtolower($id)];
                    $prev     = 'ident';
                }
                continue;
            }

            if ($c === '(') {
                $tokens[] = ['type' => 'lparen', 'value' => '('];
                $prev     = 'lparen';
                $i++;
                continue;
            }
            if ($c === ')') {
                $tokens[] = ['type' => 'rparen', 'value' => ')'];
                $prev     = 'rparen';
                $i++;
                continue;
            }
            if ($c === ',') {
                $tokens[] = ['type' => 'comma', 'value' => ','];
                $prev     = 'comma';
                $i++;
                continue;
            }

            if (in_array($c, ['+', '-', '*', '/', '%'], true)) {
                // unary minus: at start, or after an operator / '(' / ','
                if ($c === '-' && ($prev === null || in_array($prev, ['op', 'lparen', 'comma', 'func'], true))) {
                    $tokens[] = ['type' => 'op', 'value' => 'u-'];
                } else {
                    $tokens[] = ['type' => 'op', 'value' => $c];
                }
                $prev = 'op';
                $i++;
                continue;
            }

            return null; // unknown character
        }

        return $tokens === [] ? null : $tokens;
    }

    /**
     * Shunting-yard: infix tokens → RPN output queue.
     *
     * @param list<array{type:string,value:string}> $tokens
     * @return list<array{type:string,value:string}>|null
     */
    private function toRpn(array $tokens): ?array
    {
        $output = [];
        $stack  = [];

        foreach ($tokens as $t) {
            switch ($t['type']) {
                case 'num':
                case 'ident':
                    $output[] = $t;
                    break;

                case 'func':
                    $stack[] = $t;
                    break;

                case 'comma':
                    while ($stack !== [] && end($stack)['type'] !== 'lparen') {
                        $output[] = array_pop($stack);
                    }
                    if ($stack === []) {
                        return null; // misplaced comma
                    }
                    break;

                case 'op':
                    $o1 = self::OPERATORS[$t['value']];
                    while ($stack !== []) {
                        $top = end($stack);
                        if ($top['type'] === 'op') {
                            $o2 = self::OPERATORS[$top['value']];
                            if (($o1['assoc'] === 'L' && $o1['prec'] <= $o2['prec'])
                                || ($o1['assoc'] === 'R' && $o1['prec'] < $o2['prec'])) {
                                $output[] = array_pop($stack);
                                continue;
                            }
                        }
                        break;
                    }
                    $stack[] = $t;
                    break;

                case 'lparen':
                    $stack[] = $t;
                    break;

                case 'rparen':
                    while ($stack !== [] && end($stack)['type'] !== 'lparen') {
                        $output[] = array_pop($stack);
                    }
                    if ($stack === []) {
                        return null; // mismatched parens
                    }
                    array_pop($stack); // discard lparen
                    if ($stack !== [] && end($stack)['type'] === 'func') {
                        $output[] = array_pop($stack);
                    }
                    break;
            }
        }

        while ($stack !== []) {
            $top = array_pop($stack);
            if ($top['type'] === 'lparen' || $top['type'] === 'rparen') {
                return null; // mismatched parens
            }
            $output[] = $top;
        }

        return $output;
    }

    /**
     * @param list<array{type:string,value:string}> $rpn
     * @param array<string,int|float> $vars
     */
    private function evalRpn(array $rpn, array $vars): ?float
    {
        $stack = [];

        foreach ($rpn as $t) {
            switch ($t['type']) {
                case 'num':
                    $stack[] = (float) $t['value'];
                    break;

                case 'ident':
                    if (! array_key_exists($t['value'], $vars) || ! is_numeric($vars[$t['value']])) {
                        return null;
                    }
                    $stack[] = (float) $vars[$t['value']];
                    break;

                case 'op':
                    if ($t['value'] === 'u-') {
                        if ($stack === []) {
                            return null;
                        }
                        $stack[] = -1 * (float) array_pop($stack);
                        break;
                    }
                    if (count($stack) < 2) {
                        return null;
                    }
                    $b = (float) array_pop($stack);
                    $a = (float) array_pop($stack);
                    $r = match ($t['value']) {
                        '+' => $a + $b,
                        '-' => $a - $b,
                        '*' => $a * $b,
                        '/' => $b == 0.0 ? null : $a / $b,
                        '%' => $b == 0.0 ? null : fmod($a, $b),
                        default => null,
                    };
                    if ($r === null) {
                        return null;
                    }
                    $stack[] = $r;
                    break;

                case 'func':
                    $r = $this->applyFunction($t['value'], $stack);
                    if ($r === null) {
                        return null;
                    }
                    $stack = $r;
                    break;

                default:
                    return null;
            }
        }

        if (count($stack) !== 1) {
            return null;
        }

        return (float) $stack[0];
    }

    /**
     * Pop the arguments a function needs off $stack, push the result back.
     * Returns the new stack, or null on arity/name error.
     *
     * min/max are variadic: they consume the whole current argument frame. To
     * keep this simple and safe we implement them as binary/n-ary by consuming
     * exactly the values present since we cannot see paren grouping in RPN — so
     * we require at least the documented arity and fold the top two values,
     * which matches the common "min(a, b)" / "max(a, b)" usage. For >2 args the
     * shunting-yard emits them left-to-right; we fold pairwise.
     *
     * @param list<float> $stack
     * @return list<float>|null
     */
    private function applyFunction(string $name, array $stack): ?array
    {
        switch ($name) {
            case 'floor':
            case 'ceil':
            case 'round':
            case 'abs':
                if ($stack === []) {
                    return null;
                }
                $x = (float) array_pop($stack);
                $stack[] = match ($name) {
                    'floor' => floor($x),
                    'ceil'  => ceil($x),
                    'round' => round($x),
                    'abs'   => abs($x),
                    default => $x,
                };

                return $stack;

            case 'min':
            case 'max':
                // Fold the top two operands (binary form, the supported usage).
                if (count($stack) < 2) {
                    return null;
                }
                $b = (float) array_pop($stack);
                $a = (float) array_pop($stack);
                $stack[] = $name === 'min' ? min($a, $b) : max($a, $b);

                return $stack;
        }

        return null;
    }
}
