<?php

declare(strict_types=1);

namespace WBS\Shared\Http;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Decides the response representation for a request (SRS FR-ARC-001/002).
 *
 * The platform exposes ONE canonical endpoint per resource — there is no
 * duplicate /api tree. Whether a caller gets JSON or HTML is negotiated here
 * from the Accept header, an explicit ?format=json override, an
 * X-Requested-With / XHR hint, or — when a client sends no usable Accept — the
 * Fetch metadata browsers attach (Sec-Fetch-Dest / Sec-Fetch-Mode). This keeps
 * web and API behavior identical, and it is what stops a script call from being
 * handed an HTML page (or a human navigation being handed raw JSON).
 */
final class RequestNegotiator
{
    /** Representations this platform serves for one canonical URL. */
    public const JSON = 'json';
    public const HTML = 'html';

    public function __construct(private readonly RequestInterface $request)
    {
    }

    public function wantsJson(): bool
    {
        return $this->representation() === self::JSON;
    }

    /**
     * The representation this caller should get. Precedence:
     *
     *  1. an explicit `?format=json|html` override (a human or a test forcing one);
     *  2. `X-Requested-With: XMLHttpRequest` — a script call;
     *  3. `Accept` when it names JSON and not HTML (JSON), or names HTML (HTML);
     *  4. Fetch metadata: `Sec-Fetch-Dest: document` / `Sec-Fetch-Mode: navigate`
     *     is a person looking at the viewport, anything else (`empty`, `cors`,
     *     `script`, `image`, …) is a subresource or a script call;
     *  5. no signal at all — a browser page, which is the historical default (an
     *     empty Accept was never treated as JSON).
     */
    public function representation(): string
    {
        $format = $this->request->getGet('format');
        if (is_string($format)) {
            $format = strtolower($format);
            if ($format === 'json') {
                return self::JSON;
            }
            if ($format === 'html') {
                return self::HTML;
            }
        }

        if (strtolower((string) $this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest') {
            return self::JSON;
        }

        $accept = strtolower((string) $this->request->getHeaderLine('Accept'));
        if ($accept !== '') {
            // Prefer JSON when it is explicitly acceptable and HTML is not more so.
            $wantsJson = str_contains($accept, 'application/json');
            $wantsHtml = str_contains($accept, 'text/html');
            if ($wantsJson && ! $wantsHtml) {
                return self::JSON;
            }
            if ($wantsHtml) {
                return self::HTML;
            }
            // An Accept that names neither (application/xml, text/plain, */*):
            // fall through to Fetch metadata instead of assuming a page.
        }

        $dest = strtolower((string) $this->request->getHeaderLine('Sec-Fetch-Dest'));
        $mode = strtolower((string) $this->request->getHeaderLine('Sec-Fetch-Mode'));
        if ($dest !== '' || $mode !== '') {
            return $dest === 'document' || $mode === 'navigate' ? self::HTML : self::JSON;
        }

        return self::HTML;
    }
}
