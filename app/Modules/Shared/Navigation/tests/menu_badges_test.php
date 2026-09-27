<?php

declare(strict_types=1);

/**
 * MENU BADGE registry unit test — the pure server side of the lazy badge endpoint
 * (docs/DYNAMIC-MENU-DESIGN.md §6/§8.5). Proves MenuBadgeProvider + BadgeContext
 * behave as the design requires, WITHOUT a framework or DB (resolvers are plain
 * closures here; the real ones are wired in Shared\Config\Services):
 *
 *   - count()/all() dispatch to the registered resolver by provider-id;
 *   - a null / zero / negative count is normalized to "no badge" (null / omitted)
 *     so the UI shows no pill for an empty count;
 *   - a resolver that returns null because it does not apply (feature off, no
 *     scope, denied) is honoured — a badge never leaks a count;
 *   - a throwing resolver is swallowed to null — a badge never breaks the menu;
 *   - BadgeContext carries the HIERARCHICAL-GROUP scope subtree, and the empty-set
 *     vs null distinction (scope-with-no-groups => 0, no-scope => org-wide) is
 *     preserved for resolvers to honour.
 *
 *   php app/Modules/Shared/Navigation/tests/menu_badges_test.php
 */

require __DIR__ . '/../BadgeContext.php';
require __DIR__ . '/../MenuBadgeProvider.php';

use WBS\Shared\Navigation\BadgeContext;
use WBS\Shared\Navigation\MenuBadgeProvider;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$ctx = new BadgeContext('org-1', 'user-1', null, null);

echo "dispatch + normalization\n";
$p = new MenuBadgeProvider([
    'a.count'  => static fn (BadgeContext $c): ?int => 3,
    'b.zero'   => static fn (BadgeContext $c): ?int => 0,
    'c.null'   => static fn (BadgeContext $c): ?int => null,
    'd.neg'    => static fn (BadgeContext $c): ?int => -5,
    'e.throws' => static function (BadgeContext $c): ?int { throw new RuntimeException('boom'); },
]);
chk('known id returns its count', $p->count('a.count', $ctx) === 3);
chk('zero normalized to null (no pill)', $p->count('b.zero', $ctx) === null);
chk('null passes through as null', $p->count('c.null', $ctx) === null);
chk('negative normalized to null', $p->count('d.neg', $ctx) === null);
chk('throwing resolver swallowed to null', $p->count('e.throws', $ctx) === null);
chk('unknown id returns null', $p->count('z.unknown', $ctx) === null);

echo "registry introspection\n";
chk('has() true for known', $p->has('a.count'));
chk('has() false for unknown', ! $p->has('nope'));
chk('ids() sorted + complete', $p->ids() === ['a.count', 'b.zero', 'c.null', 'd.neg', 'e.throws']);

echo "all() omits null/zero, keeps positives\n";
$all = $p->all($ctx);
chk('all() keeps only positive counts', $all === ['a.count' => 3], json_encode($all));

echo "register() adds/overrides\n";
$p->register('a.count', static fn (BadgeContext $c): ?int => 42);
chk('register overrides an existing id', $p->count('a.count', $ctx) === 42);
$p->register('f.new', static fn (BadgeContext $c): ?int => 7);
chk('register adds a new id', $p->count('f.new', $ctx) === 7);

echo "BadgeContext scope semantics (hierarchical groups)\n";
$orgWide = new BadgeContext('org-1', 'user-1', null, null);
$noGroup = new BadgeContext('org-1', 'user-1', 'g-empty', []);
$subtree = new BadgeContext('org-1', 'user-1', 'g-root', ['g-root', 'g-child-1', 'g-child-2']);
chk('org-wide carries null scope + null group set', $orgWide->scopeGroupId === null && $orgWide->scopeGroupIds === null);
chk('scope-with-no-groups carries empty array (=> 0), not null', $noGroup->scopeGroupIds === []);
chk('active subtree carries self + descendants', $subtree->scopeGroupIds === ['g-root', 'g-child-1', 'g-child-2']);

// A resolver can branch on the scope semantics exactly as the real events one does.
$scopeAware = new MenuBadgeProvider([
    'events.mine_upcoming' => static function (BadgeContext $c): ?int {
        if (is_array($c->scopeGroupIds) && $c->scopeGroupIds === []) {
            return null; // scope with no groups -> nothing (never widen to org-wide)
        }
        // org-wide -> a big number; a 3-group subtree -> a smaller number (illustrative).
        return $c->scopeGroupIds === null ? 9 : count($c->scopeGroupIds);
    },
]);
chk('resolver sees org-wide (null) distinctly', $scopeAware->count('events.mine_upcoming', $orgWide) === 9);
chk('resolver sees empty scope as no-badge', $scopeAware->count('events.mine_upcoming', $noGroup) === null);
chk('resolver sees the subtree size', $scopeAware->count('events.mine_upcoming', $subtree) === 3);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
