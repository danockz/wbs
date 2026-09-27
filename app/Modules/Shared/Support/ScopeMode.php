<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * How a grant/rule's group scope projects onto the hierarchy (SRS FR-ACL-003;
 * leadership-responsibility model).
 *
 * A responsibility a leader assigns is operational within a defined scope at the
 * leader's discretion. That scope can be:
 *
 *   - SELF                 — only the named group itself (no subgroups).
 *   - SELF_AND_DESCENDANTS — the named group AND every subgroup beneath it.
 *   - DESCENDANTS_ONLY     — ONLY the subgroups beneath the named group, NOT the
 *                            group itself (e.g. a regional lead who administers
 *                            the branches but is not operational at the region
 *                            node).
 *   - GROUPS               — a hand-picked set of specific groups (carried in the
 *                            companion `grant_scope_groups` rows); scope_group_id
 *                            is not used for coverage in this mode.
 *
 * Org-wide is still represented by a NULL scope_group_id with mode SELF (the
 * historical shape), which {@see GroupScopeResolver::grantCovers()} treats as
 * "covers everything".
 */
final class ScopeMode
{
    public const SELF = 'self';
    public const SELF_AND_DESCENDANTS = 'self_and_descendants';
    public const DESCENDANTS_ONLY = 'descendants_only';
    public const GROUPS = 'groups';

    public const ALL = [
        self::SELF,
        self::SELF_AND_DESCENDANTS,
        self::DESCENDANTS_ONLY,
        self::GROUPS,
    ];

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }

    /**
     * Normalize any input to a valid mode, defaulting to SELF. Also maps the
     * legacy include_descendants boolean when a mode is not supplied.
     */
    public static function normalize(mixed $mode, mixed $includeDescendantsLegacy = null): string
    {
        if (is_string($mode) && self::isValid($mode)) {
            return $mode;
        }
        if ($mode === null && $includeDescendantsLegacy !== null) {
            return ! empty($includeDescendantsLegacy)
                ? self::SELF_AND_DESCENDANTS
                : self::SELF;
        }

        return self::SELF;
    }

    /** Whether this mode includes the named group itself. */
    public static function includesSelf(string $mode): bool
    {
        return $mode === self::SELF || $mode === self::SELF_AND_DESCENDANTS;
    }

    /** Whether this mode includes the descendants of the named group. */
    public static function includesDescendants(string $mode): bool
    {
        return $mode === self::SELF_AND_DESCENDANTS || $mode === self::DESCENDANTS_ONLY;
    }
}
