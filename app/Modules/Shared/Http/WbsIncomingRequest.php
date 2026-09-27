<?php

declare(strict_types=1);

namespace WBS\Shared\Http;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Application request object.
 *
 * Extends the framework IncomingRequest solely to DECLARE the server-side,
 * request-scoped context that AuthFilter attaches to each request. Declaring
 * them here (rather than assigning undeclared/dynamic properties on the stock
 * IncomingRequest) avoids the PHP 8.2+ "Creation of dynamic property is
 * deprecated" warning that otherwise fires on every authenticated request.
 *
 * These are intentionally NOT request headers: headers are client-supplied and
 * spoofable, whereas the authenticated principal must only ever be set by
 * AuthFilter on the server. BaseController::orgId()/currentUserId() rely on the
 * request attribute taking precedence over any body/query input so a caller
 * cannot impersonate another organization or user.
 *
 * Registered via Config\Services::incomingrequest(). `instanceof IncomingRequest`
 * checks elsewhere in the framework continue to hold because this is a subclass.
 */
final class WbsIncomingRequest extends IncomingRequest
{
    /** Authenticated user id (set by AuthFilter), or null when unauthenticated. */
    public ?string $wbsUserId = null;

    /** Authenticated organization id (set by AuthFilter). */
    public ?string $wbsOrgId = null;

    /** MFA assurance level for this request: e.g. 'none' | 'token' | session level. */
    public ?string $wbsMfaLevel = null;

    /** Granted API scopes when authenticated via an access token. */
    public mixed $wbsScopes = null;

    /** Access-token id when authenticated via a wbsat_ token. */
    public ?string $wbsTokenId = null;

    /** Server-side session reference when authenticated via a session. */
    public ?string $wbsSessionId = null;

    /** Negotiated locale for this request (set by LocaleFilter); layout/views read it. */
    public ?string $wbsLocale = null;

    /** Text direction for the negotiated locale: 'ltr' | 'rtl' (set by LocaleFilter). */
    public ?string $wbsLocaleDir = null;

    /**
     * Signals the response step should (re)write the locale cookie — set by
     * LocaleFilter when the visitor switched language or the stored cookie
     * disagrees with the resolved locale.
     */
    public mixed $wbsLocaleWrite = null;

    /** Per-request web CSRF token minted by WebCsrfIssueFilter for form embedding. */
    public ?string $wbsCsrf = null;
}
