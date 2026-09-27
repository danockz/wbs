<?php

// Framework + service stubs so the filter class loads without a booted app.
// (Mirrors the approach in Navigation/tests/menu_inject_test.php.)
namespace CodeIgniter\Filters { interface FilterInterface {} }
namespace CodeIgniter\HTTP {
    interface RequestInterface {}
    interface ResponseInterface {}
}
namespace WBS\Identity\Config {
    /** Stub: the filter only calls IdentityServices::webAuth()->issueCsrf(). */
    class Services
    {
        public static function webAuth(): object
        {
            return new class {
                public int $n = 0;
                public function issueCsrf(): string { return 'MINTED_' . (++$this->n); }
            };
        }
    }
}

namespace {

/**
 * WebCsrfIssueFilter + language-switcher wiring test (fixes the always-403
 * language switch on shared-layout pages).
 *
 * Covers, framework-free:
 *   1. Filters.php registers the issuer alias and lists it in global before+after.
 *   2. layouts/app.php emits the CORRECT double-submit field (name="_csrf" bound to
 *      the token) and no longer uses the broken native csrf_token/hash convention.
 *   3. The filter's after() sets a wbs_csrf HttpOnly cookie on a 2xx HTML response
 *      carrying a token, SKIPS when a controller already set its own wbs_csrf
 *      cookie, and SKIPS non-HTML / non-2xx responses.
 *   4. before() exposes an existing $request->wbsCsrf unchanged (idempotent) and
 *      leaves unsafe methods / API callers alone.
 *
 *   php app/Modules/Shared/Filters/tests/webcsrf_issue_test.php
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

echo "filter registration\n";
$filters = slurp($root . '/app/Config/Filters.php');
chk('WebCsrfIssueFilter imported', str_contains($filters, 'use WBS\Shared\Filters\WebCsrfIssueFilter;'));
chk('webcsrfissue alias registered', str_contains($filters, "'webcsrfissue'") && str_contains($filters, 'WebCsrfIssueFilter::class'));
chk('webcsrfissue in global before', (bool) preg_match('/before.\s*=>\s*\[[^\]]*\'webcsrfissue\'/s', $filters));
chk('webcsrfissue in global after', (bool) preg_match('/after.\s*=>\s*\[[^\]]*\'webcsrfissue\'/s', $filters));

echo "layout switcher field convention\n";
$layout = slurp($root . '/app/Views/layouts/app.php')
    . slurp($root . '/app/Modules/Shared/Views/_shell_open.php');
chk('switcher emits name="_csrf"', str_contains($layout, 'name="_csrf"'));
chk('switcher binds field to CSRF token', str_contains($layout, 'name="_csrf"') && str_contains($layout, '$wbsLangCsrf'));
chk('dropped broken native csrf_token field', ! str_contains($layout, "'csrf_token'"));
chk('dropped broken hash/value indexing', ! str_contains($layout, "\$langCsrf['hash']") && ! str_contains($layout, "\$langCsrf['value']"));
chk('reads $request->wbsCsrf fallback', str_contains($layout, '->wbsCsrf'));
chk('still posts to /prefs/locale', str_contains($layout, 'action="/prefs/locale"'));

echo "filter runtime behavior (fakes)\n";
require_once $root . '/app/Modules/Shared/Filters/WebCsrfIssueFilter.php';

// Minimal request/response fakes exposing only the methods the filter touches.
$makeRequest = static function (string $method = 'GET', array $headers = []): \CodeIgniter\HTTP\RequestInterface {
    return new class($method, $headers) implements \CodeIgniter\HTTP\RequestInterface {
        public $wbsCsrf; // dynamic attribute, like the real request
        private array $h;
        public function __construct(private string $method, array $headers)
        {
            $this->h = array_change_key_case($headers, CASE_LOWER);
        }
        public function getMethod(): string { return $this->method; }
        public function getHeaderLine(string $n): string { return (string) ($this->h[strtolower($n)] ?? ''); }
        public function isSecure(): bool { return false; }
    };
};
$makeResponse = static function (int $status = 200, string $ctype = 'text/html'): \CodeIgniter\HTTP\ResponseInterface {
    return new class($status, $ctype) implements \CodeIgniter\HTTP\ResponseInterface {
        public array $cookies = [];
        public function __construct(private int $status, private string $ctype) {}
        public function getStatusCode(): int { return $this->status; }
        public function getHeaderLine(string $n): string { return strtolower($n) === 'content-type' ? $this->ctype : ''; }
        public function hasCookie(string $n): bool { return isset($this->cookies[$n]); }
        public function setCookie(array $c): void { $this->cookies[$c['name']] = $c; }
    };
};

$filter = new \WBS\Shared\Filters\WebCsrfIssueFilter();

// before(): a safe GET with no token gets one minted (via the Services stub).
$rqFresh = $makeRequest('GET');
$filter->before($rqFresh);
chk('before() mints a token on a fresh GET', is_string($rqFresh->wbsCsrf ?? null) && str_starts_with((string) $rqFresh->wbsCsrf, 'MINTED_'));

// before(): pre-seeded token is preserved (idempotent, no re-mint).
$rq = $makeRequest('GET');
$rq->wbsCsrf = 'PRE_TOKEN';
$filter->before($rq);
chk('before() keeps existing token', $rq->wbsCsrf === 'PRE_TOKEN');

// before(): unsafe method is left untouched (no token needed; issued nowhere).
$rqPost = $makeRequest('POST');
$filter->before($rqPost);
chk('before() ignores POST (no token seeded)', ! isset($rqPost->wbsCsrf) || $rqPost->wbsCsrf === null);

// before(): API caller (Bearer) is exempt.
$rqApi = $makeRequest('GET', ['Authorization' => 'Bearer abc']);
$filter->before($rqApi);
chk('before() exempts Bearer API caller', ! isset($rqApi->wbsCsrf) || $rqApi->wbsCsrf === null);

// after(): 2xx HTML + token => sets wbs_csrf HttpOnly cookie == token.
$rq2 = $makeRequest('GET');
$rq2->wbsCsrf = 'TOK_A';
$resp = $makeResponse(200, 'text/html; charset=UTF-8');
$filter->after($rq2, $resp);
chk('after() sets wbs_csrf cookie', isset($resp->cookies['wbs_csrf']));
chk('cookie value equals request token', ($resp->cookies['wbs_csrf']['value'] ?? null) === 'TOK_A');
chk('cookie is HttpOnly', (bool) ($resp->cookies['wbs_csrf']['httponly'] ?? false));
chk('cookie SameSite=Lax', ($resp->cookies['wbs_csrf']['samesite'] ?? null) === 'Lax');

// after(): controller already set its OWN cookie => issuer must not clobber it.
$rq3 = $makeRequest('GET');
$rq3->wbsCsrf = 'ISSUER_TOK';
$resp3 = $makeResponse(200, 'text/html');
$resp3->setCookie(['name' => 'wbs_csrf', 'value' => 'CONTROLLER_TOK']);
$filter->after($rq3, $resp3);
chk('after() does not overwrite controller cookie', ($resp3->cookies['wbs_csrf']['value'] ?? null) === 'CONTROLLER_TOK');

// after(): non-HTML response is skipped.
$rq4 = $makeRequest('GET');
$rq4->wbsCsrf = 'TOK_B';
$respJson = $makeResponse(200, 'application/json');
$filter->after($rq4, $respJson);
chk('after() skips non-HTML response', ! isset($respJson->cookies['wbs_csrf']));

// after(): non-2xx response is skipped.
$rq5 = $makeRequest('GET');
$rq5->wbsCsrf = 'TOK_C';
$resp404 = $makeResponse(404, 'text/html');
$filter->after($rq5, $resp404);
chk('after() skips non-2xx response', ! isset($resp404->cookies['wbs_csrf']));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

} // namespace
