<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

/**
 * Builds the per-user, per-scope menu from a single capability WORD.
 *
 * The three-tier cost cascade (docs/DYNAMIC-MENU-ALGORITHMIC.md):
 *
 *   Tier 0  If-None-Match == current version  -> 304, zero work (handled in the
 *           controller via etagFor(); this class does not even run).
 *   Tier 1  version fresh, word cached         -> 1 KV get -> render() (AND-fold).
 *   Tier 2  word absent                         -> capabilityProvider() computes it
 *                                                  ONCE from grants, cache, render.
 *
 * This service owns ONLY the fast part (Tier 1/2 rendering + versioning). Producing
 * the capability word from grants is injected (capabilityProvider) so the heavy PDP
 * read stays in AccessControl and is exercised only on a cache miss / after a write.
 */
final class MenuService
{
    /**
     * @param MenuCatalog                     $catalog boot-frozen shared structure
     * @param (callable(string,string,?string):int) $capabilityProvider (org, subject, scope) => uint64 word.
     *                                                Called ONLY on a cold miss (Tier 2).
     * @param (callable(string):?int)|null    $cacheGet   version/word cache read (Tier 1). null = no cache.
     * @param (callable(string,int):void)|null $cacheSet  cache write.
     */
    public function __construct(
        private readonly MenuCatalog $catalog,
        private $capabilityProvider,
        private $cacheGet = null,
        private $cacheSet = null,
        private readonly ?RoleWordTable $roleWords = null,
    ) {
    }

    /**
     * DERIVE-DON'T-STORE path (best tier). Given a user's role codes, compute the
     * capability word by OR-ing the role->word table -- no DB, no per-user cache.
     * Falls back to null if no RoleWordTable was wired.
     *
     * @param list<string> $roleCodes
     */
    public function deriveWord(array $roleCodes): ?int
    {
        return $this->roleWords?->deriveWord($roleCodes);
    }

    /** Version stamp of the wired role->word table ('0' if none), for bundles/ETags. */
    public function roleTableVersion(): string
    {
        return $this->roleWords?->version() ?? '0';
    }

    /**
     * Best-tier build: render straight from a user's role codes. Zero per-user
     * server state -- the word is derived, the tree is rendered, nothing is stored.
     *
     * @param list<string> $roleCodes
     * @return array<string,mixed>
     */
    public function buildFromRoles(array $roleCodes, int $grantVersion, ?string $scopeGroupId): array
    {
        $word = $this->deriveWord($roleCodes) ?? 0;
        $tree = $this->render($word);
        $tree['scope']   = $scopeGroupId;
        $tree['version'] = $grantVersion;
        $tree['etag']    = $this->etag($grantVersion, $scopeGroupId);

        return $tree;
    }

    /**
     * The HOT PATH render: fold the shared catalog against one capability word.
     * A tight AND + compare per item over parallel arrays -- no hashing, no map
     * lookups, no per-item allocation. Theta(items) machine instructions.
     *
     * @return array<string,mixed> { categories: [ {key,label,items:[...] } ], count }
     */
    public function render(int $capabilityWord): array
    {
        $items = $this->catalog->items();
        $masks = $this->catalog->masks();
        $n     = count($items);

        // Bucket visible items by category in one pass.
        $byCat = [];
        $count = 0;
        for ($i = 0; $i < $n; $i++) {
            // The entire access decision, per item: one AND + one compare.
            if (($capabilityWord & $masks[$i]) === $masks[$i]) {
                $it                       = $items[$i];
                $byCat[$it->category][]   = $it->toArray();
                $count++;
            }
        }

        // Emit categories in fixed display order, pruning empties.
        $categories = [];
        foreach (MenuCategory::order() as $key) {
            if (! empty($byCat[$key])) {
                $categories[] = [
                    'key'   => $key,
                    'label' => MenuCategory::label($key),
                    'items' => $byCat[$key],
                ];
            }
        }

        return ['categories' => $categories, 'count' => $count];
    }

    /**
     * Resolve the capability word for (org, subject, scope): Tier 1 cache read, else
     * Tier 2 compute-once-and-store. Returns the word; render() consumes it.
     */
    public function capabilityWord(string $orgId, string $subjectId, ?string $scopeGroupId, int $version): int
    {
        $key = $this->wordKey($orgId, $subjectId, $scopeGroupId, $version);

        if ($this->cacheGet !== null) {
            $cached = ($this->cacheGet)($key);
            if ($cached !== null) {
                return $cached;               // Tier 1: warm word.
            }
        }

        // Tier 2: cold. Pay the grant read exactly once. Mask to the KNOWN bits so
        // a corrupt/over-wide word can never satisfy the UNSATISFIABLE sentinel or
        // grant a retired/reserved bit (defense in depth -- fail closed).
        $word = ((int) ($this->capabilityProvider)($orgId, $subjectId, $scopeGroupId))
            & PermissionBits::allKnownMask();

        if ($this->cacheSet !== null) {
            ($this->cacheSet)($key, $word);
        }

        return $word;
    }

    /**
     * Full build: word (Tier 1/2) -> render. The controller should try Tier 0
     * (ETag) BEFORE calling this.
     *
     * @return array<string,mixed>
     */
    public function buildFor(string $orgId, string $subjectId, ?string $scopeGroupId, int $version): array
    {
        $word = $this->capabilityWord($orgId, $subjectId, $scopeGroupId, $version);
        $tree = $this->render($word);
        $tree['scope']   = $scopeGroupId;
        $tree['version'] = $version;
        $tree['etag']    = $this->etag($version, $scopeGroupId);

        return $tree;
    }

    /**
     * The ETag for THIS service instance (instance method so it can fold in the live
     * catalog fingerprint). Tier 0 (304) compares the client's If-None-Match against
     * this. It changes when ANY of the three update dimensions move:
     *   - $version          : the subject's grant/config version (authority changed)
     *   - $scopeGroupId     : the active scope (user switched scope)
     *   - catalogVersion()  : the menu STRUCTURE (deploy changed the catalog)
     * The last term is what makes structure updates invalidate fleet-wide, even
     * mid-rollout when different nodes serve different catalogs.
     */
    public function etag(int $version, ?string $scopeGroupId, ?string $locale = null): string
    {
        return '"m' . $version . '.' . ($scopeGroupId ?? 'org')
            . '.' . $this->catalog->catalogVersion()
            . '.' . ($this->roleWords?->version() ?? '0')
            . '.' . self::localeTerm($locale) . '"';
    }

    /**
     * Pure helper for callers that already hold the catalog fingerprint (e.g. a CDN
     * edge rule) and want to build the ETag without a service instance.
     */
    public static function etagFor(int $version, ?string $scopeGroupId, string $catalogVersion, ?string $locale = null): string
    {
        return '"m' . $version . '.' . ($scopeGroupId ?? 'org') . '.' . $catalogVersion
            . '.' . self::localeTerm($locale) . '"';
    }

    /**
     * Locale dimension for the ETag. Category labels are localized at render time,
     * so a French menu MUST NOT share a cache entry with an English one. Defaults
     * to the active request locale when not passed explicitly.
     */
    private static function localeTerm(?string $locale): string
    {
        if ($locale === null && function_exists('service')) {
            $locale = service('request')->getLocale();
        }

        return $locale !== null && $locale !== '' ? $locale : 'en';
    }

    private function wordKey(string $orgId, string $subjectId, ?string $scopeGroupId, int $version): string
    {
        // Structure fingerprint is included so a catalog change also re-keys the
        // cached word (a new item may need a bit the old word never carried).
        return 'menuword:' . $orgId . ':' . $subjectId . ':' . ($scopeGroupId ?? 'org')
            . ':v' . $version . ':' . $this->catalog->catalogVersion()
            . ':' . ($this->roleWords?->version() ?? '0');
    }
}
