<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * The process-wide, immutable set of all MenuItems. Built ONCE (boot / first use)
 * and shared by every request -- the "stable structure" half that is identical for
 * all users. Only the per-user capability WORD varies; this never does.
 *
 * Modules contribute items via provider callables. For the prototype the providers
 * are wired here; in production they are discovered the same way services are.
 */
final class MenuCatalog
{
    /** @var list<MenuItem>|null lazily built, then frozen for the process. */
    private static ?array $items = null;

    /** @var list<MenuItem> items in stable (category order, then item order) order. */
    private array $ordered;

    /** @var array<int,int> parallel mask array: ordered[i]->requiredMask (cache-friendly). */
    private array $masks;

    /** Deterministic fingerprint of the STRUCTURE (see catalogVersion()). */
    private string $version;

    /** @param list<MenuItem> $items */
    public function __construct(array $items)
    {
        // Stable sort: category display order, then per-item order, then id.
        $catRank = array_flip(MenuCategory::order());
        usort($items, static function (MenuItem $a, MenuItem $b) use ($catRank): int {
            return [$catRank[$a->category] ?? 99, $a->order, $a->id]
                <=> [$catRank[$b->category] ?? 99, $b->order, $b->id];
        });

        $this->ordered = $items;
        $this->masks   = array_map(static fn (MenuItem $i): int => $i->requiredMask, $items);

        // Structure fingerprint: everything that changes what the menu LOOKS LIKE
        // (ids, labels, routes, order, category, requirement mask). Deterministic
        // across nodes running the same catalog, DIFFERENT the instant any item is
        // added/removed/relabelled/reordered/re-permissioned. This is what makes a
        // structure update invalidate ETags fleet-wide during a rolling deploy.
        $fp = '';
        foreach ($items as $it) {
            $fp .= $it->id . '|' . $it->category . '|' . $it->label . '|'
                . $it->route . '|' . $it->order . '|' . $it->requiredMask . "\n";
        }
        $this->version = substr(hash('xxh128', $fp), 0, 12);
    }

    /**
     * Short, stable fingerprint of the menu STRUCTURE. Composed into the ETag and
     * the word cache key so a deploy that changes the catalog invalidates cached
     * menus everywhere, even mid-rollout when nodes differ.
     */
    public function catalogVersion(): string
    {
        return $this->version;
    }

    /** @return list<MenuItem> */
    public function items(): array
    {
        return $this->ordered;
    }

    /** @return array<int,int> parallel requirement masks (index-aligned with items()). */
    public function masks(): array
    {
        return $this->masks;
    }

    /**
     * The shared, boot-frozen catalog. Providers are the module-contributed item
     * factories. Swap this for service-discovery in production.
     */
    public static function shared(): self
    {
        if (self::$items === null) {
            $all = [];
            foreach (self::providers() as $provider) {
                foreach ($provider() as $item) {
                    $all[] = $item;
                }
            }
            self::$items = $all;
        }

        return new self(self::$items);
    }

    /** @return list<callable():list<MenuItem>> */
    private static function providers(): array
    {
        return [
            [CoreMenuProvider::class, 'items'],
        ];
    }
}
