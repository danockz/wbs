<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * One navigable destination in the universal menu. Pure, immutable metadata --
 * declared by modules, discovered once at boot, frozen into the catalog. Carries
 * its own visibility requirement as a PRE-COMPUTED bitmask so the render hot path
 * is a single AND + compare (no string hashing, no map lookups per request).
 *
 * See docs/DYNAMIC-MENU-DESIGN.md (structure) and
 * docs/DYNAMIC-MENU-ALGORITHMIC.md (why the mask lives here).
 */
final class MenuItem
{
    /** Pre-computed requirement mask: (word & requiredMask) == requiredMask => visible. */
    public readonly int $requiredMask;

    /**
     * @param string       $id          stable unique id (e.g. 'events.create')
     * @param string       $category    top-level bucket key (see MenuCategory)
     * @param string       $label       i18n key / display label
     * @param string       $route       canonical URL or named route ('' = header/non-link)
     * @param list<string> $permissions permission codes ALL required (AND). Empty = always-visible.
     * @param string       $scopeCheck  'exact' | 'any' -- mirrors the route's authorize: filter
     * @param string|null  $icon        presentation only
     * @param int          $order       sort within category
     * @param string|null  $parentId    for nested items
     * @param string|null  $badge       lazy count-provider id (fetched off the hot path)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $label,
        public readonly string $route = '',
        public readonly array $permissions = [],
        public readonly string $scopeCheck = 'exact',
        public readonly ?string $icon = null,
        public readonly int $order = 100,
        public readonly ?string $parentId = null,
        public readonly ?string $badge = null,
    ) {
        // Freeze the requirement mask at construction (boot), never per request.
        // No permissions => 0 mask => (word & 0) == 0 always true => always visible.
        $this->requiredMask = $permissions === [] ? 0 : PermissionBits::maskAll($permissions);
    }

    /**
     * Hot-path visibility test against a capability word. One AND + one compare.
     * Theta(1), no allocation, branch-predictable.
     */
    public function visibleTo(int $capabilityWord): bool
    {
        return ($capabilityWord & $this->requiredMask) === $this->requiredMask;
    }

    /**
     * Localized display label. Resolves lang('App.menuItems.<id>') — with the id's
     * dots collapsed to underscores so it addresses a FLAT key (not a nested path)
     * — falling back to the hardcoded English $label when the framework is absent
     * (CLI/tests) or the key is missing, so a raw key can never render. Mirrors
     * MenuCategory::label().
     *
     * Resolution happens only when the tree/bundle is actually (re)built — i.e. on
     * a cache miss / non-304 path, which is already the rare tier — so this adds no
     * cost to the common 304 hot path. Category labels already resolve the same way.
     */
    public function displayLabel(): string
    {
        if (function_exists('lang')) {
            $key        = 'App.menuItems.' . str_replace('.', '_', $this->id);
            $translated = lang($key);
            if (is_string($translated) && $translated !== '' && $translated !== $key) {
                return $translated;
            }
        }

        return $this->label;
    }

    /** @return array<string,mixed> serialization for JSON clients (no mask leaked). */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'label'    => $this->displayLabel(),
            'route'    => $this->route,
            'icon'     => $this->icon,
            'order'    => $this->order,
            'parentId' => $this->parentId,
            'badge'    => $this->badge,
        ];
    }
}
