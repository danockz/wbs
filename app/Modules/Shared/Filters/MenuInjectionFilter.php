<?php

declare(strict_types=1);

namespace WBS\Shared\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Universal dynamic-menu injector (SRS: navigation; docs/DYNAMIC-MENU-*.md).
 *
 * WHY A FILTER: the menu must appear on EVERY authenticated page. Pages that
 * extend layouts/app.php already carry the menu mount; but several authenticated
 * pages are self-contained HTML documents (Identity/me, sessions, Referrals
 * contacts) and would otherwise have no menu — and hand-editing each page is
 * fragile (the next new page would miss it). This `after` filter injects the
 * mount + renderer once into any authenticated HTML response that does not
 * already contain it, so coverage is automatic and future-proof.
 *
 * RESOURCE COST: negligible and origin-light. It only touches 2xx text/html
 * responses, does a couple of substring checks, and splices a small static
 * fragment before </body>. The menu DATA is still fetched client/edge-side
 * (GET /me/menu, 304-revalidated) — nothing is derived here.
 *
 * SECURITY: display only. The injected menu is chrome; every route keeps its
 * authorize: filter, so an injected link the user cannot use is denied by the PDP.
 */
final class MenuInjectionFilter implements FilterInterface
{
    /** No-op: injection happens after the response is built. */
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // 1) Only authenticated browser pages. The web session cookie is the signal;
        //    API/token callers (no cookie, JSON responses) are skipped.
        if ((string) $request->getCookie('wbs_session') === '') {
            return $response;
        }

        // 2) Only successful HTML responses.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }
        $ctype = strtolower($response->getHeaderLine('Content-Type'));
        if ($ctype !== '' && ! str_contains($ctype, 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        // 3) Must be a full HTML document, and must not already carry the menu
        //    (pages that extend layouts/app.php already include the mount).
        $close = strripos($body, '</body>');
        if ($close === false || str_contains($body, 'id="wbs-menu"')) {
            return $response;
        }

        // 4) Splice the self-contained fragment in just before </body>.
        $fragment = $this->fragment();
        $response->setBody(substr($body, 0, $close) . $fragment . substr($body, $close));

        return $response;
    }

    /**
     * The universal menu fragment for pages NOT built on the app layout. Delegates
     * to the single source of truth (MenuFragment) so self-contained pages get the
     * IDENTICAL launcher + drawer, in the same location, as layout pages.
     */
    private function fragment(): string
    {
        return \WBS\Shared\Navigation\MenuFragment::html();
    }
}
