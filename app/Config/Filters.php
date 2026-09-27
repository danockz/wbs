<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;
use WBS\Shared\Filters\CorrelationIdFilter;
use WBS\Shared\Filters\LocaleFilter;
use WBS\Shared\Filters\MenuInjectionFilter;
use WBS\Shared\Filters\RateLimitFilter;
use WBS\Shared\Filters\WebCsrfIssueFilter;

class Filters extends BaseConfig
{
    /**
     * Configures aliases for Filter classes to make reading things nicer.
     *
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,

        // Reusable rate-limiting service (SRS FR-RL-001). Use as
        // ['filter' => 'ratelimit:policy.name'] on any route.
        'ratelimit'     => RateLimitFilter::class,
        // Per-request correlation id (in + echoed back). Applied globally.
        'correlationid' => CorrelationIdFilter::class,
        // Universal dynamic-menu injector: adds the menu mount + renderer to any
        // authenticated HTML page that does not already carry it (self-contained
        // pages that don't extend layouts/app.php). Applied globally as `after`.
        'menuinject'    => MenuInjectionFilter::class,
        // Resolves + applies the request locale (manual switch, cookie, org,
        // network/geo header, Accept-Language). Global BEFORE; DB-free hot path.
        'locale'        => LocaleFilter::class,
        // Universal double-submit CSRF token ISSUER for server-rendered browser
        // pages: mints $request->wbsCsrf on safe navigations and sets the
        // wbs_csrf cookie on HTML responses so shared-layout forms (the language
        // switcher) satisfy the webcsrf check. Applied globally; DB-free hot path.
        'webcsrfissue'  => WebCsrfIssueFilter::class,

        // Platform-owned authn/authz (SRS FR-ID / FR-ACL). NO Shield.
        // ['filter' => 'auth'] resolves the session; ['filter' => 'authorize:perm.code']
        // runs the default-deny PDP (must run after 'auth').
        'auth'          => \WBS\Identity\Filters\AuthFilter::class,
        'authorize'     => \WBS\AccessControl\Filters\AuthorizeFilter::class,
        // Double-submit CSRF guard for cookie-authenticated BROWSER POSTs only
        // (header/token API callers are exempt). SRS FR-ID web session flow.
        'webcsrf'       => \WBS\Identity\Filters\WebCsrfFilter::class,
    ];

    /**
     * List of special required filters.
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => ['forcehttps', 'pagecache'],
        'after'  => ['pagecache', 'performance', 'toolbar'],
    ];

    /**
     * List of filter aliases that are always applied before and after every request.
     *
     * @var array<string, array<string, array<string, string>>>|array<string, list<string>>
     */
    public array $globals = [
        'before' => [
            'invalidchars',
            'correlationid',
            'locale',
            'webcsrfissue',
        ],
        'after' => [
            'secureheaders',
            'correlationid',
            'locale',
            'webcsrfissue',
            'menuinject',
        ],
    ];

    /**
     * List of filter aliases that works on a particular HTTP method.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any before or after URI patterns.
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}
