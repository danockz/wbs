<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Resolver for LAZY MENU BADGE counts (docs/DYNAMIC-MENU-DESIGN.md §6 / §8.5).
 *
 * Badge counts ("My events (3)") are expensive and volatile, so they are kept
 * OFF the cached menu tree: the menu ships only the *labels* + each item's static
 * `badge` provider-id, and the browser fetches the numbers separately from
 * `GET /me/menu/badges` after paint, with its own short TTL. This class is the
 * server side of that endpoint — a small registry mapping a provider-id to a
 * count closure.
 *
 * Design rules honoured:
 *   - RESOURCE-LIGHT: each resolver is expected to be ONE bounded aggregate
 *     query; nothing here runs on the menu hot path.
 *   - GATED AT SOURCE: a resolver returns null when it does not apply (feature
 *     off, no scope, denied) so a badge can never leak a count the user could not
 *     otherwise see. Only non-null, non-zero counts are emitted.
 *   - DEPENDENCY-LIGHT: the registry itself is pure (a map of closures), so it is
 *     unit-testable without a framework; the concrete module-backed resolvers are
 *     injected by Shared\Config\Services (which already bridges to module services).
 */
final class MenuBadgeProvider
{
    /** @var array<string,callable(BadgeContext):?int> */
    private array $resolvers;

    /** @param array<string,callable(BadgeContext):?int> $resolvers id => resolver */
    public function __construct(array $resolvers = [])
    {
        $this->resolvers = $resolvers;
    }

    /** Register (or override) a single provider-id resolver. */
    public function register(string $id, callable $resolver): void
    {
        $this->resolvers[$id] = $resolver;
    }

    /** True when a provider-id is known. */
    public function has(string $id): bool
    {
        return isset($this->resolvers[$id]);
    }

    /** @return list<string> the known provider ids (sorted, for stable output/tests). */
    public function ids(): array
    {
        $ids = array_keys($this->resolvers);
        sort($ids);

        return $ids;
    }

    /**
     * Resolve a single badge count, or null when it does not apply / is denied /
     * is zero. Zero is normalized to null so the UI shows no pill for an empty
     * badge (a "(0)" is noise). A resolver that throws is swallowed to null: a
     * badge must never break the menu.
     */
    public function count(string $id, BadgeContext $ctx): ?int
    {
        $resolver = $this->resolvers[$id] ?? null;
        if ($resolver === null) {
            return null;
        }
        try {
            $n = $resolver($ctx);
        } catch (\Throwable) {
            return null;
        }
        if ($n === null || $n <= 0) {
            return null;
        }

        return (int) $n;
    }

    /**
     * Resolve EVERY known badge for a context, returning only non-null counts.
     * Used by GET /me/menu/badges to answer the whole placeholder set at once.
     *
     * @return array<string,int> id => count (omits null/zero)
     */
    public function all(BadgeContext $ctx): array
    {
        $out = [];
        foreach ($this->ids() as $id) {
            $n = $this->count($id, $ctx);
            if ($n !== null) {
                $out[$id] = $n;
            }
        }

        return $out;
    }
}
