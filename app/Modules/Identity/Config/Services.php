<?php

declare(strict_types=1);

namespace WBS\Identity\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Identity\Security\BreachChecker;
use WBS\Identity\Security\LocalListBreachChecker;
use WBS\Identity\Security\NullBreachChecker;
use WBS\Identity\Security\PasswordHasher;
use WBS\Identity\Security\PhoneNormalizer;
use WBS\Identity\Security\PwnedPasswordsBreachChecker;
use WBS\Identity\Security\RecoveryCodeService;
use WBS\Identity\Security\RiskEngine;
use WBS\Identity\Security\StepUpPolicy;
use WBS\Identity\Security\TotpService;
use WBS\Identity\Services\AccountLifecycleService;
use WBS\Identity\Services\AccountService;
use WBS\Identity\Services\JourneySignalAdapter;
use WBS\Identity\Services\LifecycleConfigAdapter;
use WBS\Identity\Services\VerifyReminderAdapter;
use WBS\Identity\Services\CredentialSetupService;
use WBS\Identity\Services\AuthenticationService;
use WBS\Identity\Services\IdentityPolicyService;
use WBS\Identity\Services\MfaService;
use WBS\Identity\Services\SessionService;
use WBS\Identity\Services\SocialAuthService;
use WBS\Identity\Services\UserPreferenceService;
use WBS\Identity\Services\TokenService;
use WBS\Identity\Services\WebAuthService;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Security\SecretBox;
use WBS\Audit\Config\Services as AuditServices;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Referrals\Config\Services as ReferralServices;

/**
 * Identity service bindings (SRS §6.3, FR-ID-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function passwordHasher(bool $getShared = true): PasswordHasher
    {
        if ($getShared) {
            return static::getSharedInstance('passwordHasher');
        }

        return new PasswordHasher();
    }

    public static function totp(bool $getShared = true): TotpService
    {
        if ($getShared) {
            return static::getSharedInstance('totp');
        }

        return new TotpService();
    }

    public static function recoveryCodes(bool $getShared = true): RecoveryCodeService
    {
        if ($getShared) {
            return static::getSharedInstance('recoveryCodes');
        }

        $key = (string) (getenv('mfa.recoveryHmacKey') ?: (getenv('encryption.key') ?: 'wbs-dev-recovery'));

        return new RecoveryCodeService(Database::connect(), SharedServices::clock(), $key);
    }

    public static function riskEngine(bool $getShared = true): RiskEngine
    {
        if ($getShared) {
            return static::getSharedInstance('riskEngine');
        }

        return new RiskEngine();
    }

    public static function stepUpPolicy(bool $getShared = true): StepUpPolicy
    {
        if ($getShared) {
            return static::getSharedInstance('stepUpPolicy');
        }

        return new StepUpPolicy();
    }

    public static function phoneNormalizer(bool $getShared = true): PhoneNormalizer
    {
        if ($getShared) {
            return static::getSharedInstance('phoneNormalizer');
        }

        return new PhoneNormalizer();
    }

    public static function identityPolicies(bool $getShared = true): IdentityPolicyService
    {
        if ($getShared) {
            return static::getSharedInstance('identityPolicies');
        }

        return new IdentityPolicyService(Database::connect(), SharedServices::clock());
    }

    /**
     * Breached-password checker (FR-ID-003). Switchable via `identity.breachCheck`:
     *   - "local" (default) — offline embedded common/breached-password corpus.
     *   - "pwned"           — HaveIBeenPwned k-anonymity range API (opt-in,
     *                          fails open to the local corpus on network error).
     *   - "off"             — explicit no-op (NullBreachChecker).
     * Extra org/context banned words come from `identity.breachExtraWords` (a
     * comma-separated list, e.g. the org/product name).
     */
    public static function breachChecker(bool $getShared = true): BreachChecker
    {
        if ($getShared) {
            return static::getSharedInstance('breachChecker');
        }

        $mode  = strtolower((string) (getenv('identity.breachCheck') ?: 'local'));
        $extra = array_filter(array_map('trim', explode(',', (string) (getenv('identity.breachExtraWords') ?: ''))));

        $local = new LocalListBreachChecker(array_values($extra));

        return match ($mode) {
            'off'   => new NullBreachChecker(),
            'pwned' => new PwnedPasswordsBreachChecker(fallback: $local),
            default => $local,
        };
    }

    public static function accounts(bool $getShared = true): AccountService
    {
        if ($getShared) {
            return static::getSharedInstance('accounts');
        }

        return new AccountService(
            Database::connect(),
            SharedServices::clock(),
            self::passwordHasher(false),
            self::phoneNormalizer(false),
            self::identityPolicies(false),
            self::breachChecker(false),
            ReferralServices::sponsorResolver(),
            ReferralServices::sponsorships(),
            // M4: a new account opens its membership journey through the platform's
            // OWN rule engine (entry rule) — no forked openJourney() call.
            new JourneySignalAdapter(JourneyServices::journeySignals()),
        );
    }

    public static function preferences(bool $getShared = true): UserPreferenceService
    {
        if ($getShared) {
            return static::getSharedInstance('preferences');
        }

        return new UserPreferenceService(
            Database::connect(),
            SharedServices::clock(),
        );
    }

    public static function credentialSetup(bool $getShared = true): CredentialSetupService
    {
        if ($getShared) {
            return static::getSharedInstance('credentialSetup');
        }

        $ttl = (int) (getenv('invite.ttl') ?: 604800);
        $ttl = $ttl > 0 ? $ttl : 604800;

        return new CredentialSetupService(
            Database::connect(),
            SharedServices::clock(),
            self::accounts(false),
            self::sessions(false),
            $ttl,
        );
    }

    public static function accountLifecycle(bool $getShared = true): AccountLifecycleService
    {
        if ($getShared) {
            return static::getSharedInstance('accountLifecycle');
        }

        return new AccountLifecycleService(
            Database::connect(),
            SharedServices::clock(),
            self::sessions(false),
            self::tokens(false),
            AuditServices::auditLogger(),
            AccessControlServices::authorization(),
            SharedServices::outbox(false),
            // M6 verify-expiry: hierarchical config gate (default OFF) + gated
            // verify-reminder notifications. Reuses the platform's one config
            // store and NotificationService — no parallel machinery.
            new LifecycleConfigAdapter(AdminServices::effectiveConfig(), Database::connect()),
            new VerifyReminderAdapter(NotificationServices::notifications()),
        );
    }

    public static function sessions(bool $getShared = true): SessionService
    {
        if ($getShared) {
            return static::getSharedInstance('sessions');
        }

        $salt = (string) (getenv('session.hashSalt') ?: (getenv('encryption.key') ?: 'wbs-session-salt'));

        // Session lifetime is configurable via env; sane secure defaults apply
        // (absolute 14d, idle 8h) when unset. Values are clamped positive.
        $absolute = (int) (getenv('session.absoluteTtl') ?: 1209600);
        $idle     = (int) (getenv('session.idleTtl') ?: 28800);
        $absolute = $absolute > 0 ? $absolute : 1209600;
        $idle     = $idle > 0 ? $idle : 28800;

        return new SessionService(Database::connect(), SharedServices::clock(), $salt, $absolute, $idle);
    }

    public static function authentication(bool $getShared = true): AuthenticationService
    {
        if ($getShared) {
            return static::getSharedInstance('authentication');
        }

        $salt = (string) (getenv('auth.hashSalt') ?: (getenv('encryption.key') ?: 'wbs-auth-salt'));

        return new AuthenticationService(
            Database::connect(),
            SharedServices::clock(),
            self::passwordHasher(false),
            self::riskEngine(false),
            self::stepUpPolicy(false),
            self::sessions(false),
            $salt,
        );
    }

    public static function mfa(bool $getShared = true): MfaService
    {
        if ($getShared) {
            return static::getSharedInstance('mfa');
        }

        return new MfaService(
            Database::connect(),
            SharedServices::clock(),
            self::totp(false),
            self::recoveryCodes(false),
            new SecretBox(SharedServices::keyProvider()),
        );
    }

    public static function webAuth(bool $getShared = true): WebAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('webAuth');
        }

        return new WebAuthService(
            new SecretBox(SharedServices::keyProvider()),
            SharedServices::clock(),
        );
    }

    public static function socialAuth(bool $getShared = true): SocialAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('socialAuth');
        }

        return new SocialAuthService(Database::connect(), SharedServices::clock(), self::accounts(false));
    }

    public static function tokens(bool $getShared = true): TokenService
    {
        if ($getShared) {
            return static::getSharedInstance('tokens');
        }

        return new TokenService(Database::connect(), SharedServices::clock());
    }
}
