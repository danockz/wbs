<?php

declare(strict_types=1);

namespace WBS\Shared\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use Redis;
use WBS\Shared\Messaging\IdempotencyStore;
use WBS\Shared\Navigation\BadgeContext;
use WBS\Shared\Navigation\MenuBadgeProvider;
use WBS\Shared\Navigation\MenuCatalog;
use WBS\Shared\Navigation\MenuService;
use WBS\Shared\Navigation\MenuWordProvider;
use WBS\Shared\Messaging\OutboxRelay;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Messaging\QueueService;
use WBS\Shared\RateLimiting\PolicyRegistry;
use WBS\Shared\RateLimiting\RateLimiter;
use WBS\Shared\Security\EnvelopeKeyProvider;
use WBS\Shared\Security\EnvKeyProvider;
use WBS\Shared\Security\KeyProvider;
use WBS\Shared\Security\LocalKekUnwrapper;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\RedisFactory;
use WBS\Shared\Sweep\MySqlSweepLock;
use WBS\Shared\Sweep\SweepRegistry;
use WBS\Shared\Sweep\SweepRunner;
use WBS\Shared\I18n\TranslationRegistry;
use WBS\Shared\I18n\Providers\FileCatalogProvider;
use WBS\Shared\I18n\Providers\NotificationTemplateProvider;
use WBS\Shared\I18n\Providers\JsonColumnProvider;

/**
 * Shared cross-cutting service bindings (SRS §6.3). Auto-discovered.
 *
 * IMPORTANT: reference the rate-policy config by FQCN
 * `config(RateLimitPolicies::class)` everywhere — never the short-name string,
 * which triggers a Factories file re-include fatal under some test orderings.
 */
class Services extends BaseService
{
    public static function clock(bool $getShared = true): Clock
    {
        if ($getShared) {
            return static::getSharedInstance('clock');
        }

        return new Clock();
    }

    /**
     * Hierarchical group-scope resolver over `group_closure`. Shared by every
     * group-scoped surface and the PDP so inheritance is computed identically.
     */
    public static function groupScope(bool $getShared = true): GroupScopeResolver
    {
        if ($getShared) {
            return static::getSharedInstance('groupScope');
        }

        return new GroupScopeResolver(Database::connect());
    }

    /**
     * The boot-frozen, process-shared menu catalog (structure only). Identical for
     * every request/user; the per-user word varies, this never does.
     */
    public static function menuCatalog(bool $getShared = true): MenuCatalog
    {
        if ($getShared) {
            return static::getSharedInstance('menuCatalog');
        }

        return MenuCatalog::shared();
    }

    /** DB-backed bridge: role->word table, subject roles, stateless grant version. */
    public static function menuWordProvider(bool $getShared = true): MenuWordProvider
    {
        if ($getShared) {
            return static::getSharedInstance('menuWordProvider');
        }

        return new MenuWordProvider(Database::connect());
    }

    /**
     * The MenuService for a tenant, wired with that org's role->word table so word
     * DERIVATION needs no per-user storage. Not shared (org-specific); the heavy bits
     * it depends on (catalog, provider) ARE shared.
     */
    public static function menu(?string $organizationId = null): MenuService
    {
        $catalog  = self::menuCatalog();
        $provider = self::menuWordProvider();
        $table    = $organizationId !== null && $organizationId !== ''
            ? $provider->roleWordTable($organizationId)
            : null;

        // Capability provider is the derive path; a legacy per-user cold read is not
        // needed once the role table is present, so pass a no-op fallback.
        return new MenuService($catalog, static fn (): int => 0, null, null, $table);
    }

    /**
     * Signer for the client-held capability word (zero-server-burden menu option).
     * Reuses the platform KeyProvider seam so rotation/KMS switch needs no change.
     */
    public static function menuWordSigner(bool $getShared = true): \WBS\Shared\Navigation\SignedWord
    {
        if ($getShared) {
            return static::getSharedInstance('menuWordSigner');
        }

        return new \WBS\Shared\Navigation\SignedWord(self::keyProvider());
    }

    /**
     * Lazy MENU BADGE resolver registry (docs/DYNAMIC-MENU-DESIGN.md §6/§8.5).
     * Kept OFF the cached menu tree: counts are fetched by GET /me/menu/badges with
     * their own short TTL. Each resolver is ONE bounded aggregate over the same
     * data its module already owns, gated at source (returns null when it does not
     * apply / is denied), so a badge can never leak a count.
     *
     * Module-backed resolvers are wired here because Shared already bridges to
     * module Config\Services (e.g. JobRouter); the registry class itself stays pure
     * for testing.
     */
    public static function menuBadges(bool $getShared = true): MenuBadgeProvider
    {
        if ($getShared) {
            return static::getSharedInstance('menuBadges');
        }

        $provider = new MenuBadgeProvider();

        // Events → "My events (N)": upcoming events the member is registered for,
        // scoped to the active hierarchical group subtree carried on the context.
        $provider->register('events.mine_upcoming', static function (BadgeContext $ctx): ?int {
            if ($ctx->userId === '' || $ctx->organizationId === '') {
                return null;
            }

            return \WBS\Events\Config\Services::eventRegistrations()
                ->upcomingCount($ctx->organizationId, $ctx->userId, $ctx->scopeGroupIds);
        });

        return $provider;
    }

    public static function redis(bool $getShared = true): ?Redis
    {
        if ($getShared) {
            return static::getSharedInstance('redis');
        }

        $host = (string) (getenv('redis.host') ?: '127.0.0.1');
        $port = (int) (getenv('redis.port') ?: 6379);
        $pass = getenv('redis.password') ?: null;

        return RedisFactory::connect($host, $port, 0.5, $pass !== false ? $pass : null);
    }

    /**
     * The platform key provider used to build {@see SecretBox} instances.
     *
     * Single seam for Tier 1 key management (docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md):
     * the default {@see EnvKeyProvider} is behavior-identical to the historical
     * env-key wiring. To switch to AWS/GCP/Azure KMS or HashiCorp Vault, return
     * a different KeyProvider here (optionally selected by a KEY_PROVIDER env
     * var) — no call site changes required.
     */
    public static function keyProvider(bool $getShared = true): KeyProvider
    {
        if ($getShared) {
            return static::getSharedInstance('keyProvider');
        }

        // Single switch for Tier 1 key management. `KEY_PROVIDER` selects the
        // custody model; every option satisfies the same KeyProvider seam, so no
        // call site (SecretBox and friends) ever changes.
        //
        //   env       → EnvKeyProvider  (Option A, default, behavior-identical)
        //   envelope  → EnvelopeKeyProvider over a KekUnwrapper (Options B/C/D):
        //               a managed KEK unwraps per-epoch DEKs. The unwrapper is
        //               chosen by KEY_KEK (aws-kms | gcp-kms | azure-kv | vault |
        //               local). Cloud unwrappers are same-shaped drop-ins; the
        //               `local` unwrapper makes the envelope path fully testable
        //               offline. See docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md.
        $provider = strtolower(trim((string) (getenv('KEY_PROVIDER') ?: 'env')));

        return match ($provider) {
            'envelope', 'kms', 'vault' => self::envelopeKeyProvider(),
            default                    => EnvKeyProvider::fromEnv(),
        };
    }

    /**
     * Build the Tier 1 {@see EnvelopeKeyProvider} from environment configuration.
     *
     * Expected env (all injected by the platform's secret delivery, never
     * committed):
     *   KEY_KEK               unwrapper backend (default "local")
     *   KEY_ACTIVE_ID         active keyId for new writes (default "k1")
     *   KEY_WRAPPED_DEKS      "k1=<wrapped>,k2=<wrapped>" — keyId=wrappedDEK map
     *   KEK_MATERIAL          KEK bytes for the `local` unwrapper only
     *
     * Cloud backends (aws-kms/gcp-kms/azure-kv/vault) are wired here as they are
     * adopted; until then selecting one fails loudly rather than silently
     * downgrading custody.
     */
    private static function envelopeKeyProvider(): KeyProvider
    {
        $activeId = (string) (getenv('KEY_ACTIVE_ID') ?: 'k1');
        $wrapped  = self::parseWrappedDeks((string) (getenv('KEY_WRAPPED_DEKS') ?: ''));
        if ($wrapped === []) {
            throw new \RuntimeException(
                'KEY_PROVIDER=envelope requires KEY_WRAPPED_DEKS ("keyId=wrappedDEK,...").',
            );
        }

        $backend   = strtolower(trim((string) (getenv('KEY_KEK') ?: 'local')));
        $unwrapper = match ($backend) {
            'local' => new LocalKekUnwrapper((string) (getenv('KEK_MATERIAL') ?: '')),
            default => throw new \RuntimeException(sprintf(
                'KEY_KEK="%s" is not wired yet. Implement a KekUnwrapper for it '
                . '(see docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md) and add it here.',
                $backend,
            )),
        };

        return new EnvelopeKeyProvider($unwrapper, $wrapped, $activeId);
    }

    /**
     * Parse a "k1=blob,k2=blob" env string into a keyId => wrappedDEK map.
     *
     * @return array<string,string>
     */
    private static function parseWrappedDeks(string $raw): array
    {
        $map = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$id, $blob] = explode('=', $pair, 2);
            $id          = trim($id);
            $blob        = trim($blob);
            if ($id !== '' && $blob !== '') {
                $map[$id] = $blob;
            }
        }

        return $map;
    }

    public static function ratePolicyRegistry(bool $getShared = true): PolicyRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('ratePolicyRegistry');
        }

        return new PolicyRegistry(config(RateLimitPolicies::class));
    }

    public static function rateLimiter(bool $getShared = true): RateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimiter');
        }

        $salt = (string) (getenv('ratelimit.keySalt') ?: (getenv('encryption.key') ?: 'wbs-dev-salt'));

        return new RateLimiter(self::ratePolicyRegistry(false), self::redis(false), $salt);
    }

    public static function outbox(bool $getShared = true): OutboxService
    {
        if ($getShared) {
            return static::getSharedInstance('outbox');
        }

        return new OutboxService(Database::connect(), self::clock(false));
    }

    public static function queue(bool $getShared = true): QueueService
    {
        if ($getShared) {
            return static::getSharedInstance('queue');
        }

        return new QueueService(Database::connect(), self::clock(false));
    }

    public static function outboxRelay(bool $getShared = true): OutboxRelay
    {
        if ($getShared) {
            return static::getSharedInstance('outboxRelay');
        }

        return new OutboxRelay(Database::connect(), self::queue(false), self::clock(false));
    }

    public static function idempotencyStore(bool $getShared = true): IdempotencyStore
    {
        if ($getShared) {
            return static::getSharedInstance('idempotencyStore');
        }

        return new IdempotencyStore(Database::connect(), self::clock(false));
    }

    /**
     * The catalogue of scheduled sweeps (Theme C — unified sweep runner).
     *
     * Each lifecycle-bearing module contributes its idempotent sweep here so the
     * single `sweep:run` command / SweepRunner can drive them all with one
     * retry/locking/observability story instead of ~18 bespoke cron entries.
     * Reuses each module's EXISTING idempotent operation (no forked sweep logic).
     */
    public static function sweepRegistry(bool $getShared = true): SweepRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('sweepRegistry');
        }

        return new SweepRegistry([
            new \WBS\AccessControl\Sweep\AccessExpirySweep(),
            new \WBS\Contributions\Sweep\CommitmentDueSweep(),
            new \WBS\Events\Sweep\EventCloseDueSweep(),
            new \WBS\Events\Sweep\EventRemindersDueSweep(),
            new \WBS\Events\Sweep\ExpireHoldsSweep(),
            new \WBS\Events\Sweep\CommitteeAuthorityExpirySweep(),
            new \WBS\Identity\Sweep\SessionPruneSweep(),
            new \WBS\Journey\Sweep\InvolvementRecomputeSweep(Database::connect()),
            new \WBS\Meetings\Sweep\EvidenceReconcileSweep(),
            new \WBS\Notifications\Sweep\ReleaseDeferredSweep(),
            new \WBS\Notifications\Sweep\BuildDigestsSweep(),
            new \WBS\Notifications\Sweep\RunQueuedCampaignsSweep(),
            new \WBS\Gamification\Sweep\ReclaimRolloversSweep(),
            new \WBS\Contributions\Sweep\ReconcileLedgerSweep(),
            new \WBS\Referrals\Sweep\FollowUpDecaySweep(),
            new \WBS\Integrations\Sweep\PruneRetiredCredentialsSweep(),
            new \WBS\Journey\Sweep\MarkDormantSweep(),
            new \WBS\Journey\Sweep\ProposalAgingSweep(),
            new \WBS\Identity\Sweep\VerifyExpirySweep(),
            new \WBS\Streaming\Sweep\RelayHealthSweep(),
            new \WBS\Community\Sweep\RetentionPurgeSweep(),
            new \WBS\Gamification\Sweep\HeldReviewAgingSweep(),
        ]);
    }

    /**
     * The unified sweep runner: drives every registered sweep with a per-sweep
     * advisory lock (no double-processing), per-sweep error isolation, and a
     * structured heartbeat line so a stuck runner is observable.
     */
    public static function sweepRunner(bool $getShared = true): SweepRunner
    {
        if ($getShared) {
            return static::getSharedInstance('sweepRunner');
        }

        return new SweepRunner(
            self::sweepRegistry(false),
            new MySqlSweepLock(Database::connect()),
            self::clock(false),
        );
    }

    /**
     * Unified translation read layer — merges bundled file catalogs with every
     * DB-backed source (notification_templates, Geo reference `translations`)
     * into one English-backed catalog per locale, for the frontend.
     *
     * RESOURCE DISCIPLINE (the task's hard constraint):
     *  - The merged catalog is cached cross-request under a key that embeds each
     *    provider's O(1) version stamp, so after the first build a request is a
     *    single cache GET with NO DB work until data actually changes.
     *  - Those version stamps are themselves short-TTL cached (see versionStamp),
     *    so forming the cache key costs at most one tiny COALESCE(MAX(...)) query
     *    every few minutes — never a per-request scan, never per-string queries.
     *  - DB providers take injected loaders that run at most ONCE PER LOCALE per
     *    rebuild; the hot path never touches them.
     */
    public static function translations(bool $getShared = true): TranslationRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('translations');
        }

        $default = 'en';
        try {
            $default = (string) (config(\Config\Locale::class)->default ?? 'en') ?: 'en';
        } catch (\Throwable) {
            // Config not booted (CLI/tests) — keep 'en'.
        }

        $cache = function_exists('cache') ? cache() : null;

        // Bundled file catalogs (app + every module Language/ tree).
        $providers = [new FileCatalogProvider()];

        // DB-backed sources. Loaders + version stamps are wired here (the only
        // place that knows the schema); the providers stay framework-free.
        try {
            $db = Database::connect();

            // notification_templates: one bounded query per locale (active rows,
            // highest version per key/channel), O(1) version via MAX(updated_at).
            $providers[] = new NotificationTemplateProvider(
                static function (string $locale) use ($db): array {
                    return $db->query(
                        'SELECT t.key_name, t.channel, t.subject, t.body
                           FROM notification_templates t
                           JOIN (
                                SELECT key_name, channel, MAX(version) AS v
                                  FROM notification_templates
                                 WHERE locale = ? AND status = ?
                                   AND group_id IS NULL
                              GROUP BY key_name, channel
                           ) mx ON mx.key_name = t.key_name
                               AND mx.channel  = t.channel
                               AND mx.v        = t.version
                          WHERE t.locale = ? AND t.status = ?
                            AND t.group_id IS NULL',
                        [$locale, 'active', $locale, 'active'],
                    )->getResultArray();
                },
                static fn (): string => self::versionStamp('notification_templates', 'updated_at', $cache),
            );

            // Geo reference tables (world-countries-style translations JSON). One
            // provider per table; all merge into the same catalog. down-only,
            // read-only reference data → a single load per table per rebuild.
            foreach ([
                'geo.countries'   => 'countries',
                'geo.regions'     => 'regions',
                'geo.subregions'  => 'subregions',
                'geo.states'      => 'states',
            ] as $prefix => $table) {
                $providers[] = new JsonColumnProvider(
                    $prefix,
                    static function () use ($db, $table): array {
                        // Only reference tables that actually exist in this build.
                        if (! $db->tableExists($table)) {
                            return [];
                        }

                        return $db->table($table)->select('id, name, translations')->get()->getResultArray();
                    },
                    static fn (): string => self::versionStamp($table, 'updated_at', $cache),
                    $prefix,
                );
            }
        } catch (\Throwable) {
            // No DB (CLI/tests/offline) — file catalogs alone still serve fully.
        }

        $get = $cache !== null ? static fn (string $k) => $cache->get(self::cacheSafe($k)) : null;
        $set = $cache !== null ? static function (string $k, array $v, int $ttl) use ($cache): void {
            $cache->save(self::cacheSafe($k), $v, $ttl);
        } : null;

        return new TranslationRegistry($providers, $default, $get, $set, 3600);
    }

    /**
     * O(1)-ish change stamp for a DB table, itself short-TTL cached so the merged
     * catalog's cache key can be formed without a per-request query. Falls back
     * to a fixed token when the table/column is unavailable.
     */
    private static function versionStamp(string $table, string $column, $cache): string
    {
        $key = 'wbs.i18n.ver.' . $table;
        if ($cache !== null) {
            $hit = $cache->get($key);
            if (is_string($hit)) {
                return $hit;
            }
        }

        $stamp = '0';
        try {
            $db = Database::connect();
            if ($db->tableExists($table)) {
                $row   = $db->query("SELECT COALESCE(MAX({$column}), 0) AS v, COUNT(*) AS c FROM {$table}")->getRowArray();
                $stamp = (string) ($row['v'] ?? '0') . '.' . (string) ($row['c'] ?? '0');
            }
        } catch (\Throwable) {
            $stamp = '0';
        }

        if ($cache !== null) {
            $cache->save($key, $stamp, 300); // re-check at most every 5 minutes
        }

        return $stamp;
    }

    /** Make a registry cache key safe for CI4's cache handlers. */
    private static function cacheSafe(string $key): string
    {
        return 'wbs_i18n_' . substr(hash('sha256', $key), 0, 40);
    }
}
