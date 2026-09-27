<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Immutable context handed to every lazy badge resolver (see MenuBadgeProvider).
 *
 * Carries who is asking and the ACTIVE SCOPE already resolved to a concrete set
 * of hierarchical group ids, so a resolver never has to touch the group tree
 * itself — the scope subtree is computed once per /me/menu/badges request and
 * shared by all resolvers.
 *
 *   - $scopeGroupIds === null  -> org-wide (no scope active): count everything.
 *   - $scopeGroupIds === []    -> a scope that resolves to no groups: count zero.
 *   - non-empty list           -> restrict to exactly these groups (self +
 *                                 descendants of the active scope group).
 *
 * This is the seam that makes group-related badges (e.g. "My events") reflect the
 * member's current hierarchical scope rather than a flat org-wide total.
 */
final class BadgeContext
{
    /** @param list<string>|null $scopeGroupIds resolved subtree, null = org-wide */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly ?string $scopeGroupId = null,
        public readonly ?array $scopeGroupIds = null,
    ) {
    }
}
