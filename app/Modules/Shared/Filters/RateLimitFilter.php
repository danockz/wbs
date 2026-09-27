<?php

declare(strict_types=1);

namespace WBS\Shared\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\ProblemResponder;

/**
 * Route filter that applies a named rate policy (SRS FR-RL-001).
 *
 * Usage in routes: ['filter' => 'ratelimit:auth.login']. The policy name is the
 * argument. Blocks return 429 with a generic body and Retry-After — never
 * disclosing which key dimension tripped (FR-RL-006). The body is negotiated per
 * caller (problem+json for API clients, a localized page for browsers). Provider (webhook) and
 * user dimensions are pulled from the request/session.
 */
final class RateLimitFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $policyName = $arguments[0] ?? '';
        if ($policyName === '') {
            return null;
        }

        $limiter = SharedServices::rateLimiter();

        // The "provider" dimension only exists on 3-segment routes such as
        // /webhooks/payments/{provider}. CI4's URI::getSegment() THROWS
        // HTTPException when the segment is out of range (the `?? ''` default is
        // never reached), so short routes like POST /login would 500 here. Guard
        // on the segment count and default to '' when there is no 3rd segment.
        $uri      = $request->getUri();
        $provider = $uri->getTotalSegments() >= 3 ? (string) $uri->getSegment(3) : '';

        $dimensions = [
            'route'    => $policyName,
            'ip'       => (string) $request->getIPAddress(),
            'user'     => (string) (session()->get('user_id') ?? ''),
            'group'    => (string) (session()->get('group_id') ?? ''),
            'provider' => $provider,
        ];

        $decision = $limiter->check($policyName, $dimensions);
        if ($decision->allowed) {
            return null;
        }

        // Content-negotiated by ProblemResponder, the same presenter the auth,
        // authorize and csrf filters use: an API/XHR caller gets the problem+json
        // envelope, a browser gets a localized page saying how long to wait.
        // Retry-After is set for BOTH representations. (This used to build its own
        // inline English-only HTML with a `javascript:history.back()` link, which
        // CSP blocks and no other failure family did.)
        return (new ProblemResponder($request))->tooManyRequests((int) $decision->retryAfter);
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
