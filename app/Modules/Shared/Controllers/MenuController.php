<?php

declare(strict_types=1);

namespace WBS\Shared\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Navigation\MenuBundle;
use WBS\Shared\Support\Result;

/**
 * Universal dynamic menu endpoints (SRS: navigation; docs/DYNAMIC-MENU-*.md).
 *
 *   GET /me/menu            -> the current user's menu for the active scope.
 *                              Honours If-None-Match: an unchanged menu returns
 *                              304 with NO body (Tier 0 -- zero render work).
 *                              ?bundle=1 returns the client render bundle (word +
 *                              masks) so the browser can render/scope-switch locally.
 *   GET /me/menu/authority  -> the tenant role->word table + catalog. The client
 *                              derives ANY user's word itself (derive-don't-store).
 *
 * Behind `auth`. Menu drives DISPLAY only; route authorize: filters remain the
 * security boundary, so a stale/tampered menu can never grant access.
 */
final class MenuController extends BaseController
{
    /** GET /me/menu (+ ?bundle=1) */
    public function index(): ResponseInterface
    {
        $orgId   = $this->orgId();
        $userId  = $this->currentUserId();
        $scopeId = $this->activeScope();
        $locale  = $this->activeLocale();

        if ($orgId === '' || $userId === '') {
            return $this->respondJson(Result::denied('menu.unauthenticated', 'UNAUTHENTICATED'));
        }

        $svc      = SharedServices::menu($orgId); // wired with this org's RoleWordTable
        $provider = SharedServices::menuWordProvider();

        // Versions: subject grant version + role-table version (both stateless).
        $grantVersion = $this->grantVersionInt($provider->grantVersion($orgId, $userId));

        // Fold the active locale into the ETag: the rendered category + item labels
        // are localized, so a language switch MUST invalidate even when grants,
        // scope and catalog are unchanged.
        $etag = $svc->etag($grantVersion, $scopeId, $locale);

        // -- Tier 0: If-None-Match -> 304, no body, no render. --
        $inm = $this->request->getHeaderLine('If-None-Match');
        if ($inm !== '' && $this->etagMatches($inm, $etag)) {
            return $this->menuResponse($etag, $userId, $orgId, $locale)->setStatusCode(304)->setBody('');
        }

        $catalogVersion = SharedServices::menuCatalog()->catalogVersion();
        $roleTableVer   = $svc->roleTableVersion();
        $signer         = SharedServices::menuWordSigner();

        // -- ZERO-BURDEN FAST PATH: trust a client-held SIGNED word. --
        // If the client echoes a valid, fresh, context-matching signed word we skip
        // the DB role reads AND word derivation entirely -- pure in-memory render.
        $word = null;
        $signedIn = $this->clientSignedWord();
        if ($signedIn !== null) {
            $word = $signer->verify(
                $signedIn,
                $userId,
                $orgId,
                $scopeId,
                $grantVersion,
                $roleTableVer,
                $catalogVersion,
            );
        }

        // -- Tier 1/2 fallback: derive the word from the subject's roles. --
        if ($word === null) {
            $roleCodes = $provider->roleCodesForSubject($orgId, $userId, $scopeId);
            $word      = $svc->deriveWord($roleCodes) ?? 0;
        }

        // Mint a fresh signed word to hand back (client caches + echoes it next time).
        $signed = $signer->mint(
            $word,
            $userId,
            $orgId,
            $scopeId,
            $grantVersion,
            $roleTableVer,
            $catalogVersion,
        );

        if ($this->request->getGet('bundle') !== null) {
            $bundle = MenuBundle::assemble(
                SharedServices::menuCatalog(),
                $word,
                $grantVersion,
                $scopeId,
                $roleTableVer,
            );
            $bundle['signedWord'] = $signed;

            return $this->menuResponse($etag, $userId, $orgId, $locale)
                ->setHeader('X-Menu-Word', $signed)
                ->setJSON($bundle);
        }

        $tree               = $svc->render($word);
        $tree['scope']      = $scopeId;
        $tree['version']    = $grantVersion;
        $tree['etag']       = $etag;
        $tree['signedWord'] = $signed;

        return $this->menuResponse($etag, $userId, $orgId, $locale)
            ->setHeader('X-Menu-Word', $signed)
            ->setJSON($tree);
    }

    /**
     * GET /me/menu/badges -> the lazy count for every badge placeholder in the
     * current user's menu (docs/DYNAMIC-MENU-DESIGN.md §6/§8.5). Deliberately
     * SEPARATE from GET /me/menu: badge counts are volatile + expensive, so they
     * are quarantined off the cached/304-able menu tree and fetched after paint
     * with their own short TTL. Returns { badges: { <providerId>: <count> }, scope }
     * (only non-zero, permitted counts appear).
     *
     * "My events" and other group-related badges are HIERARCHICAL-GROUP aware: the
     * active scope is resolved to its self+descendants subtree ONCE here and handed
     * to every resolver via BadgeContext, so counts reflect the member's current
     * scope, not a flat org-wide total. No scope = org-wide.
     */
    public function badges(): ResponseInterface
    {
        $orgId  = $this->orgId();
        $userId = $this->currentUserId();
        if ($orgId === '' || $userId === '') {
            return $this->respondJson(Result::denied('menu.unauthenticated', 'UNAUTHENTICATED'));
        }

        $scopeId = $this->activeScope();

        // Resolve the active scope to a concrete hierarchical subtree ONCE (self +
        // descendants). null scope => org-wide (null group set); a scope with no
        // descendants is just [self]. This is the single group-tree read for the
        // whole badges response — resolvers receive the resolved ids, never the tree.
        $scopeGroupIds = null;
        if ($scopeId !== null && $scopeId !== '') {
            $resolver      = SharedServices::groupScope();
            $scopeGroupIds = array_values(array_unique([$scopeId, ...$resolver->descendants($scopeId)]));
        }

        $ctx    = new \WBS\Shared\Navigation\BadgeContext($orgId, $userId, $scopeId, $scopeGroupIds);
        $counts = SharedServices::menuBadges()->all($ctx);

        // Volatile data: short private TTL, revalidatable, never shared at the edge.
        return $this->cacheSafe()
            ->setStatusCode(200)
            ->setHeader('Cache-Control', 'private, max-age=30, must-revalidate')
            ->setHeader('Vary', 'Cookie, Authorization, Accept')
            ->setJSON(['badges' => $counts, 'scope' => $scopeId]);
    }

    /** GET /me/menu/authority -> tenant-wide role->word table + catalog. */
    public function authority(): ResponseInterface
    {
        $orgId = $this->orgId();
        if ($orgId === '') {
            return $this->respondJson(Result::denied('menu.unauthenticated', 'UNAUTHENTICATED'));
        }

        $catalog = SharedServices::menuCatalog();
        $table   = SharedServices::menuWordProvider()->roleWordTable($orgId);
        $bundle  = MenuBundle::authorityBundle($catalog, $table);
        // bundle['version'] already folds in the active locale, so the ETag differs
        // per language even though grants/catalog are identical.
        $etag    = '"auth.' . $bundle['version'] . '"';
        $locale  = $this->activeLocale();

        $inm = $this->request->getHeaderLine('If-None-Match');
        if ($inm !== '' && $this->etagMatches($inm, $etag)) {
            return $this->authorityResponse($etag, $orgId, $locale)->setStatusCode(304)->setBody('');
        }

        // The authority bundle is TENANT-WIDE and non-secret (it contains no per-user
        // data), so it may be cached at a shared edge keyed by org + LOCALE + version.
        // That is what removes origin traffic for navigation: every user in the tenant
        // who shares a language is served the same table from the CDN until a
        // role-permission edit (version) or a language switch (locale) changes it.
        return $this->authorityResponse($etag, $orgId, $locale)->setJSON($bundle);
    }

    // -- response shaping (edge/CDN cache directives) ------------------------

    /**
     * Per-user menu response headers. Private (per-subject) but revalidatable, so a
     * browser/edge serves 304s without hitting origin between version changes. The
     * surrogate key lets a CDN purge a subject's menu on a targeted grant change.
     */
    private function menuResponse(string $etag, ?string $subjectId = null, ?string $orgId = null, ?string $locale = null): ResponseInterface
    {
        $r = $this->cacheSafe()
            ->setStatusCode(200)
            ->setHeader('ETag', $etag)
            // Cookie/Accept-Language are in Vary because the active locale (which
            // drives the localized item + category labels) is carried by the
            // wbs_locale cookie and, as a fallback, negotiated from Accept-Language.
            // The locale is also folded into $etag, so a language switch revalidates.
            ->setHeader('Vary', 'Cookie, Authorization, X-Menu-Word, Accept, Accept-Language')
            ->setHeader('Cache-Control', 'private, max-age=0, must-revalidate');

        if ($locale !== null && $locale !== '') {
            $r->setHeader('Content-Language', $locale);
        }

        if ($subjectId !== null && $orgId !== null) {
            // Surrogate keys let a CDN purge exactly this subject (or the whole org)
            // on a grant change, instead of waiting for TTL.
            $r->setHeader('Surrogate-Key', 'menu-user-' . $subjectId . ' menu-org-' . $orgId);
        }

        return $r;
    }

    /**
     * Authority-bundle response headers. SHARED (tenant-wide, non-secret) so a CDN
     * can cache one copy per org and serve every user from the edge. `s-maxage`
     * lets shared caches hold it; the version in the ETag/key guarantees freshness.
     */
    private function authorityResponse(string $etag, string $orgId, string $locale = 'en'): ResponseInterface
    {
        return $this->cacheSafe()
            ->setStatusCode(200)
            ->setHeader('ETag', $etag)
            // Cookie is in Vary because the active locale is carried by the
            // wbs_locale cookie: a shared cache MUST NOT serve a French authority
            // bundle to an English visitor. Content-Language advertises the payload
            // language; the locale is also in the ETag/Surrogate-Key for good measure.
            ->setHeader('Vary', 'Accept, Accept-Language, Cookie')
            ->setHeader('Content-Language', $locale)
            // public: identical for all users in the org sharing this language; safe
            // to share at the edge, keyed by org + locale + version.
            ->setHeader('Cache-Control', 'public, max-age=60, s-maxage=86400, stale-while-revalidate=86400')
            ->setHeader('Surrogate-Key', 'menu-authority org-' . $orgId . ' menu-authority-' . $locale . '-org-' . $orgId);
    }

    /** The client-echoed signed word, from header (preferred) or ?word=. */
    private function clientSignedWord(): ?string
    {
        $h = $this->request->getHeaderLine('X-Menu-Word');
        if ($h !== '') {
            return trim($h);
        }
        $q = $this->request->getGet('word');

        return is_string($q) && $q !== '' ? $q : null;
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Active UI locale, session-free and DB-free (same discipline as activeScope):
     * prefer the CI4 request locale the GLOBAL LocaleFilter already resolved, else
     * the wbs_locale cookie / ?lang= switch, else the app default 'en'. Used only
     * to key/advertise the response language for edge caches — never to gate access.
     */
    private function activeLocale(): string
    {
        if (method_exists($this->request, 'getLocale')) {
            $l = $this->request->getLocale();
            if (is_string($l) && $l !== '') {
                return $l;
            }
        }
        $q = $this->request->getGet('lang');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        $cookie = $this->request->getCookie('wbs_locale');

        return is_string($cookie) && $cookie !== '' ? $cookie : 'en';
    }

    /**
     * Active scope from ?scope= or a plain cookie; null = org view.
     *
     * DELIBERATELY session-free. Reading CI4's session here would force
     * session_start() (a DB read under DatabaseHandler) on every /me/menu hit,
     * defeating the whole zero-server-burden / edge-cacheable design and coupling
     * the response to per-user session storage. The active scope is a non-secret
     * UI preference, so a lightweight cookie is the correct carrier.
     */
    private function activeScope(): ?string
    {
        $q = $this->request->getGet('scope');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        $cookie = $this->request->getCookie('wbs_menu_scope');

        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }

    /** Map the stateless 12-hex grant version to a stable int for the ETag/keys. */
    private function grantVersionInt(string $hex): int
    {
        // Lower 31 bits keep it a safe positive int across platforms.
        return (int) (hexdec(substr($hex, 0, 8)) & 0x7FFFFFFF);
    }

    /** RFC-compliant-enough If-None-Match check (supports comma lists and *). */
    private function etagMatches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            // Tolerate weak validators (W/"...").
            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
