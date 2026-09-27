<?php

declare(strict_types=1);

namespace WBS\Shared\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Per-request correlation id (SRS FR-ARC observability).
 *
 * Mints (or reuses an inbound) correlation id and:
 *   - attaches it to the request as `wbsCorrelationId` so services/controllers
 *     and audit rows can stamp it, tying together every log line for one request;
 *   - echoes it back as `X-Correlation-Id` so a support ticket can name the
 *     exact request it is about.
 *
 * An inbound `X-Correlation-Id` (from an upstream gateway / another service) is
 * honoured when it looks sane, otherwise a fresh 16-hex-char id is generated.
 * Registered as a global `before`/`after` filter alias `correlationid`.
 */
final class CorrelationIdFilter implements FilterInterface
{
    private const HEADER = 'X-Correlation-Id';

    public function before(RequestInterface $request, $arguments = null)
    {
        $inbound = trim($request->getHeaderLine(self::HEADER));
        $id      = $this->isSane($inbound) ? $inbound : bin2hex(random_bytes(8));

        // Store on the request as a real header (not a dynamic property, which is
        // deprecated on PHP 8.2+). Services/controllers/audit rows can read it with
        // $request->getHeaderLine('X-Correlation-Id').
        $request->setHeader(self::HEADER, $id);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $id = trim($request->getHeaderLine(self::HEADER));
        if ($id !== '') {
            $response->setHeader(self::HEADER, $id);
        }

        return $response;
    }

    /** Accept a bounded, token-safe inbound id; reject anything else (no header injection). */
    private function isSane(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;
    }
}
