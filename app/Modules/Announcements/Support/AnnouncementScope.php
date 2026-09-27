<?php

declare(strict_types=1);

namespace WBS\Announcements\Support;

/**
 * Hierarchy mode chosen PER announcement (not a global default). Distinct from
 * grant ScopeMode: announcements also look UP (`ancestors`) and can target a
 * group KIND org-wide (`kind` is a target kind, not a mode).
 */
final class AnnouncementScope
{
    public const SELF = 'self';
    public const SELF_AND_DESCENDANTS = 'self_and_descendants';
    public const DESCENDANTS_ONLY = 'descendants_only';
    public const ANCESTORS = 'ancestors';

    public const ALL = [
        self::SELF,
        self::SELF_AND_DESCENDANTS,
        self::DESCENDANTS_ONLY,
        self::ANCESTORS,
    ];

    public static function isValid(string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }

    public static function normalize(mixed $mode): string
    {
        $m = is_string($mode) ? $mode : '';

        return self::isValid($m) ? $m : self::SELF;
    }
}
