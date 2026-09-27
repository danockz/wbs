<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Top-level menu buckets. Keys are stable ids; labels are org-relabelable later.
 * Order here defines display order. Empty categories are pruned per-user.
 */
final class MenuCategory
{
    public const OVERVIEW       = 'overview';
    public const PEOPLE         = 'people';
    public const GROUPS         = 'groups';
    public const EVENTS         = 'events';
    public const LEARNING       = 'learning';
    public const GIVING         = 'giving';
    public const COMMUNICATIONS = 'communications';
    public const STREAMING      = 'streaming';
    public const REPORTS        = 'reports';
    public const ACCESS         = 'access';
    public const ADMIN          = 'admin';

    /** @var array<string,string> key => default label, in display order. */
    public const LABELS = [
        self::OVERVIEW       => 'Overview',
        self::PEOPLE         => 'People',
        self::GROUPS         => 'Groups',
        self::EVENTS         => 'Events',
        self::LEARNING       => 'Learning',
        self::GIVING         => 'Giving',
        self::COMMUNICATIONS => 'Communications',
        self::STREAMING      => 'Streaming',
        self::REPORTS        => 'Reports',
        self::ACCESS         => 'Access & Security',
        self::ADMIN          => 'Administration',
    ];

    /** @return list<string> category keys in display order. */
    public static function order(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * Localized display label for a category key. Resolves lang('App.menu.<key>')
     * when the framework is available, falling back to the hardcoded English so
     * this is safe in CLI/tests and can never render a raw key. An org relabel
     * (future) would layer on top of this.
     */
    public static function label(string $key): string
    {
        $fallback = self::LABELS[$key] ?? $key;

        if (function_exists('lang')) {
            $translated = lang('App.menu.' . $key);
            // CI4 returns the key string unchanged when a translation is missing.
            if (is_string($translated) && $translated !== '' && $translated !== 'App.menu.' . $key) {
                return $translated;
            }
        }

        return $fallback;
    }
}
