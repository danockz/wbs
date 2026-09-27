<?php

// Framework + service stubs so the responder and the four filters load without a
// booted app (the pattern used by Filters/tests/webcsrf_issue_test.php).
namespace CodeIgniter\Filters {
    interface FilterInterface
    {
    }
}
namespace CodeIgniter\HTTP {
    interface RequestInterface
    {
    }
    interface ResponseInterface
    {
    }
}
namespace WBS\Identity\Config {
    /** Stub: the filters only touch webAuth()->verifyCsrf/issueCsrf, sessions()->active, tokens()->verifyAccessToken. */
    class Services
    {
        public static function webAuth(): object
        {
            return new class {
                public function verifyCsrf(?string $cookie, ?string $field): bool
                {
                    return ($GLOBALS['__csrfOk'] ?? false) === true;
                }

                public function issueCsrf(): string
                {
                    return 'MINTED';
                }
            };
        }

        public static function sessions(): object
        {
            return new class {
                public function active(string $id): ?array
                {
                    return ($GLOBALS['__sessionActive'] ?? false) === true
                        ? ['user_id' => 'u-1', 'organization_id' => 'org-1', 'mfa_level' => 'password']
                        : null;
                }

                public function touch(string $id): void
                {
                }
            };
        }

        public static function tokens(): object
        {
            return new class {
                public function verifyAccessToken(string $t): object
                {
                    return new class {
                        public bool $ok = false;
                        /** @var array<string,mixed> */
                        public array $data = [];
                    };
                }
            };
        }
    }
}
namespace WBS\AccessControl\Config {
    /** Stub PDP: the verdict is scripted per assertion. */
    class Services
    {
        public static function authorization(): object
        {
            return new class {
                public function decide($req): object
                {
                    $allowed = ($GLOBALS['__pdpAllowed'] ?? true) === true;

                    return new class($allowed) {
                        public function __construct(private bool $allowed)
                        {
                        }

                        public string $reason = 'rbac.no_grant';

                        public function isPermitted(): bool
                        {
                            return $this->allowed;
                        }
                    };
                }

                public function isAllowed($req): bool
                {
                    return ($GLOBALS['__pdpAllowed'] ?? true) === true;
                }
            };
        }
    }
}
namespace WBS\Shared\Config {
    /** Stub limiter: always over quota, with a scripted retry window. */
    class Services
    {
        public static function rateLimiter(): object
        {
            return new class {
                public function check(string $policy, array $dims): object
                {
                    return new class {
                        public bool $allowed = false;
                        public int $retryAfter = 42;
                    };
                }
            };
        }

        public static function response(): object
        {
            return \FakeResponse::make();
        }
    }
}

namespace {

use WBS\Shared\Http\ProblemResponder;
use WBS\Shared\Http\RequestNegotiator;

/**
 * HTTP problem representation below the controller layer — browsers get a page,
 * API clients get the same problem+json they always got.
 *
 * The bug this pins: a member whose session expired while browsing was shown
 * `{"type":"about:blank","title":"UNAUTHENTICATED","status":401,"detail":
 * "identity.unauthenticated"}` because every request filter answered ALL clients
 * with JSON — filters run before any controller, so BaseController's "never dump
 * raw JSON in a browser" safety net never applied. Covers:
 *
 *   1. negotiation, including the Fetch-metadata refinement that keeps a script
 *      call from being handed an HTML page;
 *   2. the JSON envelope is byte-for-byte the old one (status, title, detail,
 *      Retry-After) — the wire contract did not move;
 *   3. the browser representation: localized heading + explanation in all six
 *      locales, RTL for Arabic, an action that fits the failure, no-store/nosniff
 *      /Vary headers, and no secret or policy internals;
 *   4. return targets are same-site guarded, so an error page can't be turned
 *      into an open redirect through the query string or a cross-site Referer;
 *   5. each of the four filters (auth, authorize, webcsrf, ratelimit) actually
 *      routes its denial through the responder — runtime, not just by grep.
 *
 *   php app/Modules/Shared/Http/tests/problem_responder_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
function slurp(string $p): string
{
    return (string) @file_get_contents($p);
}

// ── fakes ────────────────────────────────────────────────────────────────────
final class FakeUri
{
    public function __construct(
        private string $path = '/notifications/credentials',
        private string $query = '',
        private string $host = 'public.test',
    ) {
    }

    public function getPath(): string
    {
        return ltrim($this->path, '/');
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getTotalSegments(): int
    {
        return count(array_filter(explode('/', $this->getPath()), static fn ($s) => $s !== ''));
    }

    public function getSegment(int $n): string
    {
        $parts = array_values(array_filter(explode('/', $this->getPath()), static fn ($s) => $s !== ''));

        return (string) ($parts[$n - 1] ?? '');
    }
}

#[AllowDynamicProperties]
final class FakeRequest implements \CodeIgniter\HTTP\RequestInterface
{
    /** @var array<string,string> */
    private array $h;
    /** @var array<string,string> */
    private array $cookies;
    /** @var array<string,string> */
    private array $get;
    private FakeUri $uri;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     * @param array<string,string> $get
     */
    public function __construct(array $headers = [], array $cookies = [], array $get = [], ?FakeUri $uri = null)
    {
        $this->h       = array_change_key_case($headers, CASE_LOWER);
        $this->cookies = $cookies;
        $this->get     = $get;
        $this->uri     = $uri ?? new FakeUri();
    }

    public function getHeaderLine(string $n): string
    {
        return (string) ($this->h[strtolower($n)] ?? '');
    }

    public function getCookie(string $n): ?string
    {
        return $this->cookies[$n] ?? null;
    }

    public function getGet($k = null)
    {
        return $k === null ? $this->get : ($this->get[$k] ?? null);
    }

    public function getPost($k = null)
    {
        return $k === null ? [] : null;
    }

    public function getMethod(): string
    {
        return (string) ($this->h[':method'] ?? 'GET');
    }

    public function getIPAddress(): string
    {
        return '203.0.113.9';
    }

    public function getUri()
    {
        return $this->uri;
    }
}

final class FakeResponse implements \CodeIgniter\HTTP\ResponseInterface
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    public string $body = '';
    public bool $jsonSet = false;

    public static function make(): self
    {
        return new self();
    }

    public function setStatusCode(int $code, string $reason = ''): self
    {
        $this->status = $code;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function setHeader(string $name, $value): self
    {
        $this->headers[strtolower($name)] = (string) $value;

        return $this;
    }

    public function getHeaderLine(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function setJson($body = null, bool $raw = false): self
    {
        $this->jsonSet                    = true;
        $this->body                       = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->headers['content-type']    = 'application/json';

        return $this;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** Decoded JSON body, or null when it isn't JSON. */
    public function json(): ?array
    {
        $d = json_decode($this->body, true);

        return is_array($d) ? $d : null;
    }
}

/** Locale-aware lang() over the REAL app/Language/<loc>/App.php catalogues. */
function lang(string $key, array $args = [])
{
    static $cache = [];
    $loc = $GLOBALS['__loc'] ?? 'en';
    if (! isset($cache[$loc])) {
        $cache[$loc] = require $GLOBALS['__root'] . '/app/Language/' . $loc . '/App.php';
    }
    $node = $cache[$loc];
    foreach (explode('.', $key) as $seg) {
        if ($seg === 'App') {
            continue;
        }
        if (! is_array($node) || ! array_key_exists($seg, $node)) {
            return $key;
        }
        $node = $node[$seg];
    }
    if (! is_string($node)) {
        return $key;
    }
    foreach ($args as $i => $v) {
        $node = str_replace('{' . $i . '}', (string) $v, $node);
    }

    return $node;
}

function esc($s, $c = 'html')
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

function service($name = null)
{
    if ($name === 'response') {
        return FakeResponse::make();
    }
    if ($name === 'request') {
        return new class {
            public function getLocale()
            {
                return $GLOBALS['__loc'] ?? 'en';
            }
        };
    }

    return null;
}

function config($c)
{
    return new class {
        /** @var list<string> */
        public array $rtl = ['ar', 'he', 'fa', 'ur'];
    };
}

function session()
{
    return new class {
        public function get(string $k)
        {
            return null;
        }
    };
}

/** Resolve a namespaced module view to its file and render it (no framework). */
function view(string $name, array $data = []): string
{
    $rel  = str_replace(['WBS\\Shared\\Views\\', '\\'], ['', '/'], $name);
    $file = $GLOBALS['__root'] . '/app/Modules/Shared/Views/' . $rel . '.php';
    if (! is_file($file)) {
        return 'MISSING VIEW ' . $name;
    }
    extract($data);
    ob_start();
    include $file;

    return (string) ob_get_clean();
}

$GLOBALS['__root'] = $root;
$GLOBALS['__loc']  = 'en';

require_once $root . '/app/Modules/AccessControl/Policy/AccessRequest.php';
require_once $root . '/app/Modules/Shared/Http/RequestNegotiator.php';
require_once $root . '/app/Modules/Shared/Http/ProblemResponder.php';
require_once $root . '/app/Modules/Identity/Filters/AuthFilter.php';
require_once $root . '/app/Modules/AccessControl/Filters/AuthorizeFilter.php';
require_once $root . '/app/Modules/Identity/Filters/WebCsrfFilter.php';
require_once $root . '/app/Modules/Shared/Filters/RateLimitFilter.php';

$browser = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Host' => 'public.test'];
$api = ['Accept' => 'application/json', 'Host' => 'public.test'];

// ── 1. Negotiation ──────────────────────────────────────────────────────────
echo "negotiation: who gets a page and who gets JSON\n";
$cases = [
    'explicit ?format=json wins over a browser Accept' => [['Accept' => 'text/html'], ['format' => 'json'], true],
    'explicit ?format=html wins over an API Accept'    => [['Accept' => 'application/json'], ['format' => 'html'], false],
    'XHR is JSON'                                      => [['X-Requested-With' => 'XMLHttpRequest'], [], true],
    'Accept: application/json is JSON'                 => [['Accept' => 'application/json'], [], true],
    'a browser Accept (html + */*) is HTML'            => [['Accept' => 'text/html,*/*;q=0.8'], [], false],
    'json AND html in one Accept prefers html'         => [['Accept' => 'application/json,text/html'], [], false],
    'an Accept naming neither falls through'           => [['Accept' => 'application/xml'], [], false],
    'no Accept at all is HTML (historical default)'    => [[], [], false],
    'a fetch with no Accept is JSON (Sec-Fetch-Dest)'  => [['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors'], [], true],
    'a navigation with no Accept is HTML'              => [['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate'], [], false],
    'Sec-Fetch-Mode: navigate alone is HTML'           => [['Sec-Fetch-Mode' => 'navigate'], [], false],
    'a form POST navigation is HTML'                   => [['Sec-Fetch-Dest' => 'document', 'Sec-Fetch-Mode' => 'navigate', 'Accept' => 'text/html'], [], false],
    'Accept beats Fetch metadata when it names json'   => [['Accept' => 'application/json', 'Sec-Fetch-Dest' => 'document'], [], true],
];
foreach ($cases as $label => [$headers, $get, $wantJson]) {
    $n = new RequestNegotiator(new FakeRequest($headers, [], $get));
    chk($label, $n->wantsJson() === $wantJson, var_export($n->wantsJson(), true));
    chk("$label — representation() agrees", $n->representation() === ($wantJson ? 'json' : 'html'));
}

// ── 2. The JSON envelope did not move ───────────────────────────────────────
echo "problem+json contract (API clients)\n";
$expect = [
    'unauthenticated' => [401, 'UNAUTHENTICATED', 'identity.unauthenticated'],
    'forbidden'       => [403, 'ACCESS_DENIED', 'rbac.no_grant'],
    'csrfFailed'      => [403, 'CSRF_FAILED', 'identity.csrf_failed'],
    'tooManyRequests' => [429, 'TOO_MANY_REQUESTS', 'rate.limited'],
];
$call = [
    'unauthenticated' => static fn (ProblemResponder $r): FakeResponse => $r->unauthenticated(true),
    'forbidden'       => static fn (ProblemResponder $r): FakeResponse => $r->forbidden('rbac.no_grant'),
    'csrfFailed'      => static fn (ProblemResponder $r): FakeResponse => $r->csrfFailed(),
    'tooManyRequests' => static fn (ProblemResponder $r): FakeResponse => $r->tooManyRequests(42),
];
foreach ($expect as $method => [$status, $title, $detail]) {
    /** @var FakeResponse $res */
    $res = $call[$method](new ProblemResponder(new FakeRequest($api)));
    $j = $res->json();
    chk("$method: status $status", $res->getStatusCode() === $status, (string) $res->getStatusCode());
    chk("$method: JSON body, not HTML", $j !== null && $res->jsonSet);
    chk("$method: exactly the four problem keys", is_array($j) && array_diff(['type', 'title', 'status', 'detail'], array_keys($j)) === []
        && array_diff(array_keys($j), ['type', 'title', 'status', 'detail']) === [], json_encode(array_keys((array) $j)));
    chk("$method: type/title/status/detail unchanged",
        ($j['type'] ?? '') === 'about:blank' && ($j['title'] ?? '') === $title
        && ($j['status'] ?? 0) === $status && ($j['detail'] ?? '') === $detail, json_encode($j));
    chk("$method: content-type is JSON", str_contains($res->getHeaderLine('Content-Type'), 'application/json'));
    chk("$method: no HTML leaked into the body", ! str_contains($res->body, '<html'));
}
$rq  = new FakeRequest($api);
$res = (new ProblemResponder($rq))->tooManyRequests(42);
chk('429 keeps Retry-After for API clients', $res->getHeaderLine('Retry-After') === '42', $res->getHeaderLine('Retry-After'));

// ── 3. The browser representation ───────────────────────────────────────────
echo "browser representation\n";
$res = (new ProblemResponder(new FakeRequest($browser)))->unauthenticated(true);
chk('401 is HTML for a navigating browser', str_contains($res->body, '<!DOCTYPE html>') && ! $res->jsonSet);
chk('401 status + content type', $res->getStatusCode() === 401
    && str_contains($res->getHeaderLine('Content-Type'), 'text/html'));
chk('an expired session is never cached', $res->getHeaderLine('Cache-Control') === 'no-store'
    && $res->getHeaderLine('X-Content-Type-Options') === 'nosniff');
chk('one URL, two representations → Vary', str_contains($res->getHeaderLine('Vary'), 'Accept'));
chk('it says the session expired', str_contains($res->body, 'Your session has expired'));
chk('it explains that nothing was lost', str_contains($res->body, 'Nothing was lost'));
chk('it offers sign-in with a return to the page they were on',
    str_contains($res->body, 'href="/login?return=%2Fnotifications%2Fcredentials"'));
chk('the query string survives the return target',
    str_contains((new ProblemResponder(new FakeRequest($browser, [], [], new FakeUri('/events/9', 'tab=guests'))))->unauthenticated(true)->body,
        'return=%2Fevents%2F9%3Ftab%3Dguests'));
chk('no raw problem+json is dumped in the page', ! str_contains($res->body, '"about:blank"'));
chk('no JS at all', ! str_contains(strtolower($res->body), '<script') && ! str_contains($res->body, 'javascript:'));
chk('no external assets (CSP-safe)', ! preg_match('#(src|href)\s*=\s*["\']https?://#i', $res->body));
chk('the machine title + status are still visible for support', str_contains($res->body, '401 UNAUTHENTICATED'));
chk('and it tells them what to do if it recurs', str_contains($res->body, 'quote the reference above'));

// no credential at all → "sign in to continue", not "expired"
$res = (new ProblemResponder(new FakeRequest($browser)))->unauthenticated(false);
chk('no credential reads as "sign in to continue"', str_contains($res->body, 'Sign in to continue')
    && ! str_contains($res->body, 'Your session has expired'));

// auto-detection when the caller can't say
$res = (new ProblemResponder(new FakeRequest($browser, ['wbs_session' => 'deadbeef'])))->unauthenticated();
chk('a dead session cookie is detected as an expiry', str_contains($res->body, 'Your session has expired'));
$res = (new ProblemResponder(new FakeRequest($browser)))->unauthenticated();
chk('no cookie at all is detected as "not signed in"', str_contains($res->body, 'Sign in to continue'));
$res = (new ProblemResponder(new FakeRequest($browser + ['Authorization' => 'Bearer wbsat_x'])))->unauthenticated();
chk('a rejected bearer token counts as a presented credential', str_contains($res->body, 'Your session has expired'));

// 403
$res = (new ProblemResponder(new FakeRequest($browser)))->forbidden('rbac.no_grant');
chk('403 says access is missing, not "error"', str_contains($res->body, 'You do not have access to this page'));
chk('403 tells them who can fix it', str_contains($res->body, 'group leader or an administrator'));
chk('403 shows the PDP reason as a reference only', str_contains($res->body, 'rbac.no_grant'));
chk('403 offers the dashboard as the way out', str_contains($res->body, 'href="/me"'));
chk('403 does not leak policy internals', ! str_contains($res->body, 'mfa_level') && ! str_contains($res->body, 'subjectId'));

// CSRF
$res = (new ProblemResponder(new FakeRequest($browser + ['Referer' => 'https://public.test/notifications/credentials']))->csrfFailed());
chk('a stale form is explained as such', str_contains($res->body, 'That submission was blocked'));
chk('and offers a way back to the SAME-SITE referer', str_contains($res->body, 'href="/notifications/credentials"'));
chk('the back link is not a javascript: history hack', ! str_contains($res->body, 'history.back'));
$res = (new ProblemResponder(new FakeRequest($browser + ['Referer' => 'https://evil.example/phish'])))->csrfFailed();
chk('a cross-site Referer is ignored', ! str_contains($res->body, 'evil.example'));
chk('and the dashboard remains as the way out', str_contains($res->body, 'href="/me"'));

// 429
$res = (new ProblemResponder(new FakeRequest($browser)))->tooManyRequests(42);
chk('429 tells a human how long to wait', str_contains($res->body, 'Please wait 42 seconds'));
chk('429 keeps Retry-After for browsers too', $res->getHeaderLine('Retry-After') === '42');
chk('429 shows the retry window as a reference', str_contains($res->body, 'Retry-After: 42'));
chk('a zero retry window is clamped to something readable',
    str_contains((new ProblemResponder(new FakeRequest($browser)))->tooManyRequests(0)->body, 'Please wait 1 seconds'));

// ── 4. Open-redirect guards ─────────────────────────────────────────────────
echo "return targets are same-site guarded\n";
$targets = [
    'protocol-relative' => '//evil.example/x',
    'backslash variant' => '/\\evil.example/x',
    'absolute url'      => 'https://evil.example/x',
    'control character' => "/me\r\nSet-Cookie: x=1",
    'the login page'    => '/login',
    'a login sub-path'  => '/login/again',
];
foreach ($targets as $label => $path) {
    // Once from the raw request target (what a browser actually sends) and once
    // rebuilt from the URI object, which has already normalized the slashes away.
    foreach (['REQUEST_URI' => $path, 'uri object' => null] as $how => $rawTarget) {
        if ($rawTarget === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $rawTarget;
        }
        $body = (new ProblemResponder(new FakeRequest($browser, [], [], new FakeUri($path))))->unauthenticated(true)->body;
        chk("$label refused via $how (falls back to /me)", str_contains($body, 'return=%2Fme'),
            preg_match('#href="/login\?return=([^"]*)"#', $body, $m) === 1 ? $m[1] : 'no action link');
        chk("$label never appears in the page via $how",
            ! str_contains($body, 'evil.example') && ! str_contains($body, 'Set-Cookie'));
    }
}
unset($_SERVER['REQUEST_URI']);

// A clean raw target is reflected verbatim, query string and all.
$_SERVER['REQUEST_URI'] = '/contributions/giving?tab=pledges';
$body = (new ProblemResponder(new FakeRequest($browser)))->unauthenticated(true)->body;
chk('a clean REQUEST_URI is reflected verbatim', str_contains($body, 'return=%2Fcontributions%2Fgiving%3Ftab%3Dpledges'));
$_SERVER['REQUEST_URI'] = '//evil.example/x';
chk('a protocol-relative REQUEST_URI is refused', str_contains(
    (new ProblemResponder(new FakeRequest($browser)))->unauthenticated(true)->body, 'return=%2Fme'));
unset($_SERVER['REQUEST_URI']);
$long = '/me?' . str_repeat('a', 2100);
chk('an overlong target is refused', str_contains(
    (new ProblemResponder(new FakeRequest($browser, [], [], new FakeUri($long))))->unauthenticated(true)->body,
    'return=%2Fme'));

// ── 5. All six locales ──────────────────────────────────────────────────────
echo "localization\n";
$want = [
    'en' => ['Your session has expired', 'ltr'],
    'fr' => ['Votre session a expiré', 'ltr'],
    'es' => ['Su sesión ha caducado', 'ltr'],
    'pt' => ['A sua sessão expirou', 'ltr'],
    'zh' => ['你的会话已过期', 'ltr'],
    'ar' => ['انتهت صلاحية جلستك', 'rtl'],
];
foreach ($want as $loc => [$heading, $dir]) {
    $GLOBALS['__loc'] = $loc;
    $body = (new ProblemResponder(new FakeRequest($browser)))->unauthenticated(true)->body;
    chk("$loc heading translated", str_contains($body, $heading));
    chk("$loc lang/dir correct", str_contains($body, 'lang="' . $loc . '"') && str_contains($body, 'dir="' . $dir . '"'));
    chk("$loc action translated", ! str_contains($body, 'App.err.'));
    $body429 = (new ProblemResponder(new FakeRequest($browser)))->tooManyRequests(7)->body;
    chk("$loc rate-limit copy keeps the {0} placeholder substituted", str_contains($body429, '7')
        && ! str_contains($body429, '{0}'));
    $bodyJson = (new ProblemResponder(new FakeRequest($api)))->unauthenticated(true);
    chk("$loc JSON detail stays an untranslated key", ($bodyJson->json()['detail'] ?? '') === 'identity.unauthenticated');
}
$GLOBALS['__loc'] = 'en';

// ── 6. The filters themselves, at runtime ───────────────────────────────────
echo "filters route their denials through the responder\n";
$GLOBALS['__sessionActive'] = false;
$auth = new \WBS\Identity\Filters\AuthFilter();

$res = $auth->before(new FakeRequest($browser, ['wbs_session' => 'expired-cookie']));
chk('AuthFilter: an expired browser session gets a page', $res instanceof FakeResponse
    && $res->getStatusCode() === 401 && str_contains($res->body, '<!DOCTYPE html>')
    && str_contains($res->body, 'Your session has expired'));
chk('AuthFilter: and a sign-in action', str_contains($res->body, 'href="/login?return='));
$res = $auth->before(new FakeRequest($api, ['wbs_session' => 'expired-cookie']));
chk('AuthFilter: an API caller still gets problem+json', $res instanceof FakeResponse
    && ($res->json()['title'] ?? '') === 'UNAUTHENTICATED' && ($res->json()['status'] ?? 0) === 401);
$res = $auth->before(new FakeRequest($browser));
chk('AuthFilter: no credential at all reads as "sign in to continue"', str_contains($res->body, 'Sign in to continue'));
$GLOBALS['__sessionActive'] = true;
$res = $auth->before(new FakeRequest($browser, ['wbs_session' => 'live-cookie']));
chk('AuthFilter: a live session still passes through', $res instanceof FakeRequest);
$GLOBALS['__sessionActive'] = false;

$GLOBALS['__pdpAllowed'] = false;
$authz = new \WBS\AccessControl\Filters\AuthorizeFilter();
$rq    = new FakeRequest($browser + [':method' => 'GET']);
$rq->wbsUserId = 'u-1';
$rq->wbsOrgId  = 'org-1';
$res = $authz->before($rq, ['contribution.manage']);
chk('AuthorizeFilter: a refused browser gets a page', $res instanceof FakeResponse
    && $res->getStatusCode() === 403 && str_contains($res->body, 'You do not have access to this page'));
$res = $authz->before(new FakeRequest($api), ['contribution.manage']);
chk('AuthorizeFilter: no principal → 401 for an API caller', $res instanceof FakeResponse
    && ($res->json()['title'] ?? '') === 'UNAUTHENTICATED' && ($res->json()['detail'] ?? '') === 'access.unauthenticated');
$rqAnon = new FakeRequest($browser);
$res    = $authz->before($rqAnon, ['contribution.manage']);
chk('AuthorizeFilter: no principal → a sign-in page for a browser', str_contains($res->body, 'Sign in to continue'));
$GLOBALS['__pdpAllowed'] = true;
$rq2 = new FakeRequest($browser);
$rq2->wbsUserId = 'u-1';
$rq2->wbsOrgId  = 'org-1';
chk('AuthorizeFilter: an allowed subject still passes through', $authz->before($rq2, ['contribution.manage']) instanceof FakeRequest);

$GLOBALS['__csrfOk'] = false;
$csrf = new \WBS\Identity\Filters\WebCsrfFilter();
$rq   = new FakeRequest($browser + [':method' => 'POST', 'Referer' => 'https://public.test/notifications/credentials'], ['wbs_csrf' => 'c1']);
$res  = $csrf->before($rq);
chk('WebCsrfFilter: a blocked browser POST gets a page', $res instanceof FakeResponse
    && $res->getStatusCode() === 403 && str_contains($res->body, 'That submission was blocked'));
chk('WebCsrfFilter: with a way back to the form', str_contains($res->body, 'href="/notifications/credentials"'));
$res = $csrf->before(new FakeRequest($api + [':method' => 'POST'], ['wbs_csrf' => 'c1']));
chk('WebCsrfFilter: an API caller still gets CSRF_FAILED json',
    ($res->json()['title'] ?? '') === 'CSRF_FAILED' && ($res->json()['status'] ?? 0) === 403);

$rl  = new \WBS\Shared\Filters\RateLimitFilter();
$res = $rl->before(new FakeRequest($browser + [':method' => 'POST']), ['auth.login']);
chk('RateLimitFilter: a browser gets a localized page', $res instanceof FakeResponse
    && $res->getStatusCode() === 429 && str_contains($res->body, 'Too many requests'));
chk('RateLimitFilter: the old inline English HTML is gone', ! str_contains($res->body, 'Too many attempts')
    && ! str_contains($res->body, 'javascript:history.back()'));
$res = $rl->before(new FakeRequest($api + [':method' => 'POST']), ['auth.login']);
chk('RateLimitFilter: an API caller still gets TOO_MANY_REQUESTS json + Retry-After',
    ($res->json()['title'] ?? '') === 'TOO_MANY_REQUESTS' && $res->getHeaderLine('Retry-After') === '42');
chk('RateLimitFilter: no policy name → no response (pass-through)', $rl->before(new FakeRequest($browser), null) === null);

// ── 7. Source-level guards ──────────────────────────────────────────────────
echo "source contract\n";
$files = [
    'AuthFilter'       => $root . '/app/Modules/Identity/Filters/AuthFilter.php',
    'AuthorizeFilter'  => $root . '/app/Modules/AccessControl/Filters/AuthorizeFilter.php',
    'WebCsrfFilter'    => $root . '/app/Modules/Identity/Filters/WebCsrfFilter.php',
    'RateLimitFilter'  => $root . '/app/Modules/Shared/Filters/RateLimitFilter.php',
];
foreach ($files as $name => $file) {
    $src = slurp($file);
    chk("$name imports ProblemResponder", str_contains($src, 'use WBS\Shared\Http\ProblemResponder;'));
    chk("$name no longer answers every client with setJSON", ! str_contains($src, '->setJSON(['));
    chk("$name no longer hardcodes the problem envelope", ! str_contains($src, "'about:blank'"));
}
chk('the responder is the only place the envelope is built below controllers',
    substr_count(slurp($root . '/app/Modules/Shared/Http/ProblemResponder.php'), "'about:blank'") === 1);
chk('the view resolves to a real file', is_file($root . '/app/Modules/Shared/Views/error_page.php'));
chk('and the responder points at the namespaced module form',
    str_contains(slurp($root . '/app/Modules/Shared/Http/ProblemResponder.php'), "VIEW = 'WBS\\Shared\\Views\\error_page'"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
