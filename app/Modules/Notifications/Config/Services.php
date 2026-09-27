<?php

declare(strict_types=1);

namespace WBS\Notifications\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Notifications\Services\CampaignAudienceResolver;
use WBS\Notifications\Services\CampaignService;
use WBS\Notifications\Services\NotificationCredentialResolver;
use WBS\Notifications\Services\NotificationService;
use WBS\Notifications\Services\NotificationTemplateService;
use WBS\Notifications\Services\ResolvedCredential;
use WBS\Notifications\Services\RetentionPolicyGate;
use WBS\Notifications\Services\TemplateRenderer;
use WBS\Notifications\Transport\ChannelTransport;
use WBS\Notifications\Transport\EmailTransport;
use WBS\Notifications\Transport\InAppTransport;
use WBS\Notifications\Transport\MNotifySmsTransport;
use WBS\Notifications\Transport\NaloSmsTransport;
use WBS\Notifications\Transport\SmsProviderChain;
use WBS\Notifications\Transport\NotificationDispatcher;
use WBS\Notifications\Transport\TransportRegistry;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Notifications service bindings (SRS FR-NOT-*). Auto-discovered.
 */
class Services extends BaseService
{
    /** Module-local shared instance (avoids the global 'campaigns' key collision). */
    private static ?CampaignService $sharedCampaigns = null;

    private static ?NotificationTemplateService $sharedTemplates = null;

    /**
     * Notification template CRUD + send-time resolution.
     * Module-local cache: CI4 getSharedInstance('notificationTemplates') returns
     * null when AppServices has not discovered this factory (GET /notifications/templates).
     */
    public static function notificationTemplates(bool $getShared = true): NotificationTemplateService
    {
        if ($getShared) {
            return self::$sharedTemplates ??= self::notificationTemplates(false);
        }

        return new NotificationTemplateService(
            Database::connect(),
            SharedServices::clock(),
            self::templateRenderer(false),
        );
    }

    public static function templateRenderer(bool $getShared = true): TemplateRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('templateRenderer');
        }

        return new TemplateRenderer();
    }

    public static function retentionGate(bool $getShared = true): RetentionPolicyGate
    {
        if ($getShared) {
            return static::getSharedInstance('retentionGate');
        }

        return new RetentionPolicyGate(Database::connect(), SharedServices::clock());
    }

    public static function notifications(bool $getShared = true): NotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('notifications');
        }

        return new NotificationService(
            Database::connect(),
            SharedServices::clock(),
            self::retentionGate(false),
            self::templateRenderer(false),
            SharedServices::outbox(false),
            IntegrationServices::providerReliability(),
            // Stamps each delivery with the body it belongs to, so the transport
            // can send on THAT body's credentials (FR-INT-007).
            SharedServices::groupScope(),
        );
    }

    /**
     * Per-group notification credentials: which body's provider account a message
     * sends on, resolved most-specific-wins over the group hierarchy (own
     * connection, else an ancestor's/org-wide connection that GRANTED this group
     * access, scope-aware). Secrets never leave the vault boundary.
     */
    public static function notificationCredentials(bool $getShared = true): NotificationCredentialResolver
    {
        if ($getShared) {
            return static::getSharedInstance('notificationCredentials');
        }

        return new NotificationCredentialResolver(
            Database::connect(),
            IntegrationServices::credentialVault(),
            SharedServices::groupScope(),
            SharedServices::clock(),
        );
    }

    public static function campaigns(bool $getShared = true): CampaignService
    {
        // NOTE: uses a module-local shared cache rather than
        // getSharedInstance('campaigns'): the framework's shared registry is
        // keyed by a global string, and WBS\Gamification also exposes a
        // campaigns() service, so a shared 'campaigns' key collides across
        // modules and can return the wrong CampaignService type.
        if ($getShared) {
            return static::$sharedCampaigns ??= self::campaigns(false);
        }

        return new CampaignService(
            Database::connect(),
            SharedServices::clock(),
            // N2 fan-out: resolve audience from the directory + send through the
            // gated NotificationService (per-recipient opt-out/quiet-hours honoured).
            new CampaignAudienceResolver(Database::connect()),
            self::notifications(false),
        );
    }

    /**
     * Registry of real outbound channel transports (FR-NOT-*, FR-INT-012).
     *
     * Each channel is a lazy factory so its client/credentials are built only
     * when a job for that channel runs. Email is the reference adapter (real
     * HTTP via ProviderHttp); in-app needs no external provider. Adding push is
     * one more factory here — the dispatcher and service do not change.
     *
     * SMS is a CHAIN of two aggregators over the same ProviderHttp — mNotify
     * primary, Nalo Solutions fallback (env `SMS_PROVIDER_ORDER` reorders them).
     * The chain is itself a ChannelTransport, so the dispatcher, the queue and
     * the circuit breaker are unchanged; it fails over on a provider FAULT only,
     * never on a permanent per-message rejection (which would just send the same
     * undeliverable message somewhere else).
     */
    public static function transportRegistry(bool $getShared = true): TransportRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('transportRegistry');
        }

        return new TransportRegistry([
            'email' => static fn (): ChannelTransport => new EmailTransport(
                new ProviderHttp('email'),
                (string) (getenv('EMAIL_API_BASE_URL') ?: ''),
                (string) (getenv('EMAIL_API_KEY') ?: ''),
                (string) (getenv('EMAIL_FROM_ADDRESS') ?: ''),
                (string) (getenv('EMAIL_FROM_NAME') ?: ''),
            ),
            'sms' => static fn (): ChannelTransport => new SmsProviderChain(
                [
                    // mNotify/BMS Quick Bulk SMS (Ghana-first aggregator). Key
                    // rides in the query string; a 200 with a non-success body is
                    // an error, so the transport classifies the envelope rather
                    // than the status line. Numbers go in LOCAL form (0XXXXXXXXX).
                    //
                    // Credentials come from the SENDING GROUP's connection when a
                    // resolver is wired ($cred non-null): its own key, its own
                    // sender ID, its own base URL. Env is the org-wide default for
                    // installs that have not adopted per-group credentials, and a
                    // per-group credential NEVER falls back to env for a secret —
                    // the chain skips the hop instead (fail-closed).
                    'mnotify' => static function (?ResolvedCredential $cred = null, array $secrets = []): ChannelTransport {
                        $base = (string) ($cred?->setting('api_base_url') ?? '');
                        if ($base === '') {
                            $base = (string) (getenv('SMS_API_BASE_URL') ?: 'https://api.mnotify.com');
                        }

                        $apiKey = (string) ($secrets['api_key'] ?? '');
                        if ($apiKey === '' && $cred === null) {
                            $apiKey = (string) (getenv('MNOTIFY_API_KEY') ?: getenv('SMS_API_KEY') ?: '');
                        }

                        $sender = (string) ($cred?->senderId ?? '');
                        if ($sender === '') {
                            $sender = (string) (getenv('SMS_SENDER_ID') ?: '');
                        }

                        $cc = (string) ($cred?->setting('country_code') ?? '');
                        if ($cc === '') {
                            $cc = (string) (getenv('SMS_DEFAULT_COUNTRY_CODE') ?: '233');
                        }

                        return new MNotifySmsTransport(new ProviderHttp('mnotify'), $base, $apiKey, $sender, $cc);
                    },
                    // Nalo Solutions reseller SMS — the FALLBACK. Form-encoded
                    // body, reseller username/password (or a single auth key),
                    // comma-separated recipients in INTERNATIONAL form
                    // (233XXXXXXXXX). Base + path are per-connection settings
                    // (env-overridable) because reseller endpoints differ between
                    // account types.
                    'nalo' => static function (?ResolvedCredential $cred = null, array $secrets = []): ChannelTransport {
                        $base = (string) ($cred?->setting('api_base_url') ?? '');
                        if ($base === '') {
                            $base = (string) (getenv('NALO_SMS_API_BASE_URL') ?: 'https://api.nalosolutions.com');
                        }
                        $path = (string) ($cred?->setting('api_path') ?? '');
                        if ($path === '') {
                            $path = (string) (getenv('NALO_SMS_API_PATH') ?: '/smsbackend/clientapi/ResellerAPI/send_sms/');
                        }

                        $username = (string) ($secrets['username'] ?? '');
                        $password = (string) ($secrets['password'] ?? '');
                        $authKey  = (string) ($secrets['auth_key'] ?? '');
                        if ($cred === null) {
                            if ($username === '') {
                                $username = (string) (getenv('NALO_SMS_USERNAME') ?: '');
                            }
                            if ($password === '') {
                                $password = (string) (getenv('NALO_SMS_PASSWORD') ?: '');
                            }
                            if ($authKey === '') {
                                $authKey = (string) (getenv('NALO_SMS_AUTH_KEY') ?: '');
                            }
                        }

                        $sender = (string) ($cred?->senderId ?? '');
                        if ($sender === '') {
                            $sender = (string) (getenv('NALO_SMS_SENDER_ID') ?: getenv('SMS_SENDER_ID') ?: '');
                        }

                        $cc = (string) ($cred?->setting('country_code') ?? '');
                        if ($cc === '') {
                            $cc = (string) (getenv('SMS_DEFAULT_COUNTRY_CODE') ?: '233');
                        }

                        return new NaloSmsTransport(
                            new ProviderHttp('nalo'), $base, $path, $username, $password, $authKey, $sender, $cc,
                        );
                    },
                ],
                SmsProviderChain::parseOrder(getenv('SMS_PROVIDER_ORDER') ?: 'mnotify,nalo'),
                // Per-group credentials: a body sends on the account it provided
                // or was granted, and on nobody else's.
                self::notificationCredentials(),
            ),
            'inapp' => static fn (): ChannelTransport => new InAppTransport(),
        ]);
    }

    /**
     * Coordinator that performs the real delivery for a `notification.dispatch`
     * job and maps the transport outcome onto the delivery lifecycle + breaker.
     */
    public static function notificationDispatcher(bool $getShared = true): NotificationDispatcher
    {
        if ($getShared) {
            return static::getSharedInstance('notificationDispatcher');
        }

        return new NotificationDispatcher(
            Database::connect(),
            self::transportRegistry(false),
            self::notifications(false),
        );
    }
}
