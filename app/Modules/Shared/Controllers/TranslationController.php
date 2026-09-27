<?php

declare(strict_types=1);

namespace WBS\Shared\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Locale as LocaleConfig;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\I18n\LocaleResolver;

/**
 * Frontend translation bundle (Phase 1 language-awareness).
 *
 *   GET /i18n/{locale}.json[?ns=Prefix,Prefix2]
 *
 * Serves the MERGED, English-backed catalog for one locale — bundled file
 * strings plus every DB-backed source (notification_templates, Geo reference
 * translations) — as a single JSON object the browser can translate against.
 * Optionally narrows to one or more dotted key prefixes (?ns=) so a page pulls
 * only the namespaces it needs.
 *
 * ── Zero-server-burden design (the task's hard constraint) ────────────────────
 *  - The catalog itself is built by TranslationRegistry, which caches the merged
 *    result cross-request under a version-stamped key; between data changes this
 *    endpoint does NO DB work and NO rebuild.
 *  - The response is PUBLIC and edge-cacheable: it contains no per-user data, so
 *    a CDN serves one copy per (locale, ns, version) to every visitor.
 *  - A strong ETag over the payload turns repeat visits into 304s with no body
 *    and no serialization — If-None-Match short-circuits before we JSON-encode.
 *  - The locale is clamped to the supported allowlist, so hostile/unknown values
 *    can never trigger arbitrary work or leak keys.
 */
final class TranslationController extends BaseController
{
    public function bundle(string $locale = 'en'): ResponseInterface
    {
        $cfg      = config(LocaleConfig::class);
        $resolver = new LocaleResolver($cfg->supported, $cfg->default, $cfg->countryLocale);

        // Clamp to a supported locale (unknown -> app default, never arbitrary).
        $locale = $resolver->canonicalize($locale) ?? $resolver->default();

        $registry = SharedServices::translations();
        $catalog  = $registry->catalog($locale);

        // Optional namespace narrowing: ?ns=Identity,Events (dotted prefixes).
        $nsParam = (string) ($this->request->getGet('ns') ?? '');
        $etagNs  = 'all';
        if ($nsParam !== '') {
            $prefixes = array_values(array_filter(array_map(
                static fn ($s) => preg_replace('/[^A-Za-z0-9_.]/', '', trim($s)),
                explode(',', $nsParam),
            )));
            if ($prefixes !== []) {
                $catalog = array_filter(
                    $catalog,
                    static function (string $k) use ($prefixes): bool {
                        foreach ($prefixes as $p) {
                            if ($p !== '' && ($k === $p || str_starts_with($k, $p . '.'))) {
                                return true;
                            }
                        }

                        return false;
                    },
                    ARRAY_FILTER_USE_KEY,
                );
                sort($prefixes);
                $etagNs = implode('+', $prefixes);
            }
        }

        // Strong ETag over locale + ns + a stable hash of the (sorted) payload.
        ksort($catalog);
        $etag = '"i18n.' . $locale . '.' . $etagNs . '.' . substr(hash('sha256', (string) json_encode($catalog)), 0, 16) . '"';

        // If-None-Match -> 304 before any serialization work.
        $inm = trim($this->request->getHeaderLine('If-None-Match'));
        if ($inm !== '' && $this->etagMatches($inm, $etag)) {
            return $this->bundleHeaders($etag, $locale)->setStatusCode(304)->setBody('');
        }

        return $this->bundleHeaders($etag, $locale)
            ->setStatusCode(200)
            ->setJSON([
                'locale'  => $locale,
                'strings' => (object) $catalog,
            ]);
    }

    /**
     * Public, shared-cacheable headers. The bundle is identical for every visitor
     * on a given (locale, ns, version), so a CDN can hold one copy and serve all
     * users from the edge; the version-bearing ETag guarantees freshness.
     */
    private function bundleHeaders(string $etag, string $locale): ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('ETag', $etag)
            ->setHeader('Vary', 'Accept-Encoding')
            ->setHeader('Cache-Control', 'public, max-age=300, s-maxage=86400, stale-while-revalidate=86400')
            ->setHeader('Surrogate-Key', 'i18n i18n-' . $locale);
    }

    /** RFC-compliant-enough If-None-Match check (comma lists, weak validators, *). */
    private function etagMatches(string $header, string $etag): bool
    {
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = preg_replace('/^W\//', '', trim($candidate)) ?? trim($candidate);
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
