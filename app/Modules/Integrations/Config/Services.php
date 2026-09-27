<?php

declare(strict_types=1);

namespace WBS\Integrations\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Integrations\Canonical\ProfileValidator;
use WBS\Integrations\Providers\FacebookProvider;
use WBS\Integrations\Providers\FallbackMatrix;
use WBS\Integrations\Providers\GoogleMeetProvider;
use WBS\Integrations\Providers\MeetingProvider;
use WBS\Integrations\Providers\MeetingProviderRegistry;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Integrations\Providers\ProviderRegistry;
use WBS\Integrations\Providers\RtmpProvider;
use WBS\Integrations\Providers\StreamProvider;
use WBS\Integrations\Providers\TwitchProvider;
use WBS\Integrations\Providers\YouTubeProvider;
use WBS\Integrations\Providers\ZoomProvider;
use WBS\Integrations\Sdk\ContractTestSuite;
use WBS\Integrations\Sdk\CustomAdapter;
use WBS\Integrations\Sdk\CustomAdapterRegistry;
use WBS\Integrations\Sdk\Examples\ExampleRestNotificationAdapter;
use WBS\Integrations\Sdk\ManifestSigner;
use WBS\Integrations\Services\CatalogService;
use WBS\Integrations\Services\ConnectionService;
use WBS\Integrations\Services\CredentialVault;
use WBS\Integrations\Services\CustomAdapterService;
use WBS\Integrations\Services\FallbackPlanService;
use WBS\Integrations\Services\ProfileService;
use WBS\Integrations\Services\ProviderReliabilityService;
use WBS\Integrations\Services\StreamingOAuthService;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Security\SecretBox;

/**
 * Integrations / UPAF service bindings (SRS FR-INT-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function adapterCatalog(bool $getShared = true): CatalogService
    {
        if ($getShared) {
            return static::getSharedInstance('adapterCatalog');
        }

        return new CatalogService(Database::connect());
    }

    /** Code-owned per-feature fallback matrix (SRS FR-INT-012). */
    public static function fallbackMatrix(bool $getShared = true): FallbackMatrix
    {
        if ($getShared) {
            return static::getSharedInstance('fallbackMatrix');
        }

        return new FallbackMatrix();
    }

    /** Documented fallback coverage plans per adapter (SRS FR-INT-012). */
    public static function fallbackPlans(bool $getShared = true): FallbackPlanService
    {
        if ($getShared) {
            return static::getSharedInstance('fallbackPlans');
        }

        return new FallbackPlanService(self::adapterCatalog(false), self::fallbackMatrix(false));
    }

    /** Provider circuit-breaking + quota accounting (SRS FR-INT-012). */
    public static function providerReliability(bool $getShared = true): ProviderReliabilityService
    {
        if ($getShared) {
            return static::getSharedInstance('providerReliability');
        }

        return new ProviderReliabilityService(Database::connect(), SharedServices::clock());
    }

    public static function profileValidator(bool $getShared = true): ProfileValidator
    {
        if ($getShared) {
            return static::getSharedInstance('profileValidator');
        }

        $hosts = array_filter(array_map('trim', explode(',', (string) (getenv('INTEGRATION_APPROVED_HOSTS') ?: ''))));

        return new ProfileValidator(array_values($hosts));
    }

    // ---- Custom-adapter SDK (SRS FR-INT-013) -------------------------------

    /**
     * Allowlist of shippable custom-adapter classes. Custom adapters are
     * REVIEWED CODE, not runtime-injected: only classes bound here can be
     * registered, contract-tested or activated. Ship the class + add it here in
     * the same reviewed change. Keyed by FQCN, built lazily.
     */
    public static function customAdapterRegistry(bool $getShared = true): CustomAdapterRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('customAdapterRegistry');
        }

        return new CustomAdapterRegistry([
            // Reference adapter shipped with the SDK (also the docs' worked example).
            ExampleRestNotificationAdapter::class => static fn (): CustomAdapter => new ExampleRestNotificationAdapter(),
        ]);
    }

    /** SDK contract-test conformance harness (SRS FR-INT-013). */
    public static function contractTestSuite(bool $getShared = true): ContractTestSuite
    {
        if ($getShared) {
            return static::getSharedInstance('contractTestSuite');
        }

        return new ContractTestSuite();
    }

    /** Versioned manifest signer — reuses the switchable platform KeyProvider. */
    public static function manifestSigner(bool $getShared = true): ManifestSigner
    {
        if ($getShared) {
            return static::getSharedInstance('manifestSigner');
        }

        return new ManifestSigner(SharedServices::keyProvider());
    }

    /** Custom-adapter registration/certification/publication (SRS FR-INT-013). */
    public static function customAdapters(bool $getShared = true): CustomAdapterService
    {
        if ($getShared) {
            return static::getSharedInstance('customAdapters');
        }

        return new CustomAdapterService(
            Database::connect(),
            SharedServices::clock(),
            self::customAdapterRegistry(false),
            self::contractTestSuite(false),
            self::manifestSigner(false),
        );
    }

    public static function connectorProfiles(bool $getShared = true): ProfileService
    {
        if ($getShared) {
            return static::getSharedInstance('connectorProfiles');
        }

        return new ProfileService(Database::connect(), SharedServices::clock(), self::profileValidator(false));
    }

    public static function credentialVault(bool $getShared = true): CredentialVault
    {
        if ($getShared) {
            return static::getSharedInstance('credentialVault');
        }

        return new CredentialVault(
            Database::connect(),
            new SecretBox(SharedServices::keyProvider()),
            SharedServices::clock(),
        );
    }

    public static function connections(bool $getShared = true): ConnectionService
    {
        if ($getShared) {
            return static::getSharedInstance('connections');
        }

        return new ConnectionService(
            Database::connect(),
            SharedServices::clock(),
            self::credentialVault(false),
            self::providerReliability(false),
            AuditServices::auditLogger(),
            SharedServices::outbox(false),
            // A body may share its credentials down its OWN subtree only.
            SharedServices::groupScope(),
        );
    }

    /** Streaming-provider OAuth consent flow (S7 streaming adaptation). */
    public static function streamingOAuth(bool $getShared = true): StreamingOAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('streamingOAuth');
        }

        return new StreamingOAuthService(
            self::credentialVault(false),
            new ProviderHttp('oauth'),
        );
    }

    /**
     * Registry of live-streaming provider adapters (S1 streaming adaptation).
     * Adapters are built lazily; app-level provider credentials come from env
     * (never per-group, never hardcoded).
     */
    public static function streamProviders(bool $getShared = true): ProviderRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('streamProviders');
        }

        $vault = self::credentialVault(false);

        return new ProviderRegistry([
            'rtmp' => static fn (): StreamProvider => new RtmpProvider($vault),
            'youtube' => static fn (): StreamProvider => new YouTubeProvider(
                $vault,
                new ProviderHttp('youtube'),
                (string) (getenv('YOUTUBE_CLIENT_ID') ?: ''),
                (string) (getenv('YOUTUBE_CLIENT_SECRET') ?: ''),
            ),
            'twitch' => static fn (): StreamProvider => new TwitchProvider(
                $vault,
                new ProviderHttp('twitch'),
                (string) (getenv('TWITCH_CLIENT_ID') ?: ''),
                (string) (getenv('TWITCH_CLIENT_SECRET') ?: ''),
            ),
            'facebook' => static fn (): StreamProvider => new FacebookProvider(
                $vault,
                new ProviderHttp('facebook'),
            ),
        ]);
    }

    /**
     * Registry of meeting-platform adapters (S2 streaming adaptation). Zoom uses
     * Server-to-Server OAuth (no per-group consent); Google Meet reuses the
     * Google OAuth refresh token.
     */
    public static function meetingProviders(bool $getShared = true): MeetingProviderRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('meetingProviders');
        }

        $vault = self::credentialVault(false);

        return new MeetingProviderRegistry([
            'zoom' => static fn (): MeetingProvider => new ZoomProvider(
                $vault,
                new ProviderHttp('zoom'),
            ),
            'meet' => static fn (): MeetingProvider => new GoogleMeetProvider(
                $vault,
                new ProviderHttp('meet'),
                (string) (getenv('GOOGLEMEET_CLIENT_ID') ?: getenv('YOUTUBE_CLIENT_ID') ?: ''),
                (string) (getenv('GOOGLEMEET_CLIENT_SECRET') ?: getenv('YOUTUBE_CLIENT_SECRET') ?: ''),
            ),
        ]);
    }
}
