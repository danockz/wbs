<?php

declare(strict_types=1);

/**
 * Behaviour test for MenuInjectionFilter::after() — the universal injector that
 * puts the dynamic menu on authenticated pages that don't extend layouts/app.php.
 *
 * Uses tiny local fakes for Request/Response so no framework runtime is needed.
 *
 *   php app/Modules/Shared/Navigation/tests/menu_inject_test.php
 */

// -- Minimal CI4-compatible fakes -------------------------------------------------
namespace CodeIgniter\Filters { interface FilterInterface {} }
namespace CodeIgniter\HTTP {
    interface RequestInterface {}
    interface ResponseInterface {}
}

namespace {

use WBS\Shared\Filters\MenuInjectionFilter;

require __DIR__ . '/../MenuFragment.php';
require __DIR__ . '/../../Filters/MenuInjectionFilter.php';

final class FakeReq implements \CodeIgniter\HTTP\RequestInterface
{
    /** @param array<string,string> $cookies */
    public function __construct(private array $cookies = []) {}
    public function getCookie(string $name): ?string { return $this->cookies[$name] ?? null; }
}

final class FakeRes implements \CodeIgniter\HTTP\ResponseInterface
{
    public function __construct(
        private int $status = 200,
        private string $ctype = 'text/html; charset=UTF-8',
        private string $body = '',
    ) {}
    public function getStatusCode(): int { return $this->status; }
    public function getHeaderLine(string $n): string { return strtolower($n) === 'content-type' ? $this->ctype : ''; }
    public function getBody(): string { return $this->body; }
    public function setBody($b): static { $this->body = (string) $b; return $this; }
}

$pass = 0; $fail = 0;
function chk(string $label, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

$f    = new MenuInjectionFilter();
$page = '<!DOCTYPE html><html><head><title>t</title></head><body><h1>Hi</h1></body></html>';

echo "menu injection filter\n";

// 1) Authenticated HTML page WITHOUT a menu -> injected.
$res = new FakeRes(200, 'text/html', $page);
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
$out = $res->getBody();
chk('injects the mount into an authenticated HTML page', str_contains($out, 'id="wbs-menu"'));
chk('injects the same-origin renderer', str_contains($out, '/assets/js/menu.js'));
chk('mount points at /me/menu', str_contains($out, 'data-endpoint="/me/menu"'));
chk('fragment is placed before </body>', strpos($out, 'id="wbs-menu"') < strripos($out, '</body>'));
chk('original page content preserved', str_contains($out, '<h1>Hi</h1>'));

// 2) No session cookie (API/anonymous) -> untouched.
$res = new FakeRes(200, 'text/html', $page);
$f->after(new FakeReq([]), $res);
chk('no session cookie -> not injected', ! str_contains($res->getBody(), 'id="wbs-menu"'));

// 3) JSON response -> untouched.
$res = new FakeRes(200, 'application/json', '{"ok":true}');
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
chk('JSON response -> not injected', ! str_contains($res->getBody(), 'id="wbs-menu"'));

// 4) Non-2xx (e.g. 302/401) -> untouched.
$res = new FakeRes(302, 'text/html', $page);
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
chk('redirect/error status -> not injected', ! str_contains($res->getBody(), 'id="wbs-menu"'));

// 5) Page that ALREADY has the menu (extends layouts/app.php) -> not doubled.
$withMenu = '<!DOCTYPE html><html><body><nav id="wbs-menu"></nav></body></html>';
$res = new FakeRes(200, 'text/html', $withMenu);
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
chk('layout page not double-injected', substr_count($res->getBody(), 'id="wbs-menu"') === 1);

// 6) Fragment (non-document, no </body>) -> untouched.
$res = new FakeRes(200, 'text/html', '<div>partial</div>');
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
chk('non-document body -> not injected', ! str_contains($res->getBody(), 'id="wbs-menu"'));

// 7) CSP: injected block has no inline JS body (only same-origin src).
$res = new FakeRes(200, 'text/html', $page);
$f->after(new FakeReq(['wbs_session' => 'abc']), $res);
$scripts = preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $res->getBody(), $m);
chk('injected <script> has no inline body (CSP script-src \'self\')',
    $scripts === 1 && trim($m[1][0]) === '');
chk('menu toggle is CSS-only (no inline onclick)', ! str_contains($res->getBody(), 'onclick'));

// -- Single location: injected menu uses the SAME canonical drawer as the layout. --
chk('injected menu uses the single drawer location (wbs-menu--drawer)',
    str_contains($res->getBody(), 'wbs-menu--drawer'));
chk('injected fragment matches MenuFragment source of truth',
    str_contains($res->getBody(), \WBS\Shared\Navigation\MenuFragment::html()));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);

}
