<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * The client/edge handoff for the "best tier": everything needed to render the
 * menu WITHOUT the app server, plus a single version token to revalidate cheaply.
 *
 * Two shapes:
 *
 *  1. render bundle  (assemble) -- the user's derived word + the item masks +
 *     structure. A client renders locally by AND-folding, and a SCOPE SWITCH is a
 *     pure client-side re-mask (no round trip) as long as the new scope's word is
 *     known. Revalidation = compare `version`; if unchanged the server returns 304
 *     (a CDN can absorb that entirely).
 *
 *  2. authority bundle (authorityBundle) -- the tiny role->word table + catalog.
 *     Broadcast once per tenant; the client derives ANY user's word itself from the
 *     user's role list. This removes per-user server state completely: adding a user
 *     costs nothing, and the app tier does no work between grant/role changes.
 *
 * Security note: the bundle only drives DISPLAY. The word is signed/versioned by the
 * server; a tampered word can only mis-show links, never bypass a route's authorize:
 * filter. Never put per-instance authorization in here (see the discipline note in
 * docs/DYNAMIC-MENU-FLOOR.md) -- keep the bundle to coarse capability only.
 */
final class MenuBundle
{
    /**
     * Per-user render bundle. The client renders by testing
     * (word & item.mask) === item.mask for each item.
     *
     * @return array<string,mixed>
     */
    public static function assemble(
        MenuCatalog $catalog,
        int $capabilityWord,
        int $grantVersion,
        ?string $scopeGroupId,
        string $roleTableVersion,
    ): array {
        $items = [];
        foreach ($catalog->items() as $it) {
            $items[] = [
                'id'       => $it->id,
                'category' => $it->category,
                'label'    => $it->displayLabel(), // localized (App.menuItems.<id>), English fallback
                'route'    => $it->route,
                'icon'     => $it->icon,
                'order'    => $it->order,
                'mask'     => $it->requiredMask,   // client folds this against `word`
            ];
        }

        return [
            'word'       => $capabilityWord,       // 8 bytes; the whole authority
            'scope'      => $scopeGroupId,
            'categories' => self::categoryLabels(),
            'items'      => $items,
            'version'    => self::version($grantVersion, $scopeGroupId, $catalog->catalogVersion(), $roleTableVersion),
        ];
    }

    /**
     * Tenant-wide authority bundle: role->word table + catalog. The client derives a
     * user's word by OR-ing the words of the user's roles, then renders. No per-user
     * server state at all.
     *
     * @return array<string,mixed>
     */
    public static function authorityBundle(MenuCatalog $catalog, RoleWordTable $roles): array
    {
        $items = [];
        foreach ($catalog->items() as $it) {
            $items[] = [
                'id' => $it->id, 'category' => $it->category, 'label' => $it->displayLabel(),
                'route' => $it->route, 'icon' => $it->icon, 'order' => $it->order,
                'mask' => $it->requiredMask,
            ];
        }

        return [
            'roleWords'  => $roles->toArray(),     // role code => word (the ~48-byte table)
            'categories' => self::categoryLabels(),
            'items'      => $items,
            // Locale term keeps the SHARED (tenant-wide, edge-cached) authority bundle
            // from cross-serving a French tree to an English client and vice-versa:
            // its category + item labels are localized, so the locale is part of its
            // identity. The version doubles as the ETag body in the controller.
            'version'    => 'a' . $roles->version() . '.' . $catalog->catalogVersion() . '.' . self::localeTerm(),
        ];
    }

    /**
     * The single revalidation token / ETag body. Encodes every dimension that can
     * change what the user sees: grant version, scope, catalog structure, and the
     * role->word table version.
     */
    public static function version(
        int $grantVersion,
        ?string $scopeGroupId,
        string $catalogVersion,
        string $roleTableVersion,
        ?string $locale = null,
    ): string {
        return 'b' . $grantVersion
            . '.' . ($scopeGroupId ?? 'org')
            . '.' . $catalogVersion
            . '.' . $roleTableVersion
            // Labels are localized at build time, so a locale change must revalidate
            // even when grants/catalog/roles are unchanged.
            . '.' . self::localeTerm($locale);
    }

    /** @return list<array{key:string,label:string}> */
    private static function categoryLabels(): array
    {
        $out = [];
        foreach (MenuCategory::order() as $key) {
            $out[] = ['key' => $key, 'label' => MenuCategory::label($key)];
        }

        return $out;
    }

    /**
     * Active-request locale for cache/version identity. Defaults to the CI4 request
     * locale when available, else 'en' (CLI/tests). Mirrors MenuService::localeTerm
     * so the bundle version and the /me/menu ETag move together on a locale switch.
     */
    private static function localeTerm(?string $locale = null): string
    {
        if ($locale === null && function_exists('service')) {
            $locale = service('request')->getLocale();
        }

        return $locale !== null && $locale !== '' ? $locale : 'en';
    }
}
