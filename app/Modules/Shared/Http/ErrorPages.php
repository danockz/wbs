<?php

declare(strict_types=1);

namespace WBS\Shared\Http;

/**
 * Localized bodies for the framework's own error views.
 *
 * `ProblemResponder` covers requests refused by a FILTER; this covers the ones
 * CodeIgniter renders itself — a route that does not exist (404), a malformed
 * request (400) and an uncaught exception in production (500). Those used to be
 * the stock framework templates: hardcoded `lang="en"`, light theme, Helvetica,
 * and `lang('Errors.pageNotFound')` from the framework's own English bundle — so
 * a French or Arabic member who followed a dead link got an unbranded English
 * page that matched nothing else in the product.
 *
 * Each method returns the SAME self-contained page `ProblemResponder` renders for
 * a browser (`WBS\Shared\Views\error_page`): locale-aware and RTL-correct, dark
 * house style from the shared tokens, six locales, no JS, no external assets. The
 * framework keeps ownership of the status code and headers — these only supply a
 * body — so `Config\Exceptions`, the debug toolbar and the CLI paths are
 * untouched. The development-only stack-trace template (`error_exception.php`)
 * stays stock on purpose: it is for engineers, not members.
 */
final class ErrorPages
{
    /** 404 — no route, or a controller threw PageNotFoundException. */
    public static function notFound(string $message = ''): string
    {
        return self::render(404, 'NOT_FOUND', 'http.not_found', [
            'heading'     => (string) lang('App.err.notFound'),
            'explanation' => (string) lang('App.err.notFoundBody'),
            'reference'   => self::reference($message),
            'accent'      => '#299cdb',
        ]);
    }

    /** 400 — a request the router could not parse. */
    public static function badRequest(string $message = ''): string
    {
        return self::render(400, 'BAD_REQUEST', 'http.bad_request', [
            'heading'     => (string) lang('App.err.badRequest'),
            'explanation' => (string) lang('App.err.badRequestBody'),
            'reference'   => self::reference($message),
            'accent'      => '#f7b84b',
        ]);
    }

    /** 500 (and any other uncaught status) — production template, no internals. */
    public static function serverError(int $status = 500): string
    {
        return self::render($status, $status === 503 ? 'SERVICE_UNAVAILABLE' : 'SERVER_ERROR', 'http.server_error', [
            'heading'     => (string) lang('App.err.serverError'),
            'explanation' => (string) lang('App.err.serverErrorBody'),
            'accent'      => '#f06548',
        ]);
    }

    /**
     * @param array<string,mixed> $page
     */
    private static function render(int $status, string $title, string $detail, array $page): string
    {
        // Signed in? Then the useful way out is the dashboard; otherwise the
        // sign-in page. The cookie is the same signal AuthFilter reads, and it is
        // only used to pick between two same-site targets.
        $signedIn = self::signedIn();

        $data = $page + [
            'status'         => $status,
            'title'          => $title,
            'detail'         => $detail,
            'actionLabel'    => (string) lang($signedIn ? 'App.err.dashboardAction' : 'App.err.signInShort'),
            'actionHref'     => $signedIn ? '/me' : '/login',
            'secondaryLabel' => $signedIn ? null : (string) lang('App.err.homeAction'),
            'secondaryHref'  => $signedIn ? null : '/',
            'accent'         => '#f06548',
        ];

        return view(ProblemResponder::VIEW, $data);
    }

    private static function signedIn(): bool
    {
        try {
            $request = service('request');
            if ($request !== null && method_exists($request, 'getCookie')) {
                return (string) $request->getCookie('wbs_session') !== '';
            }
        } catch (\Throwable) {
            // No request context (CLI, early boot) — fall through to the anonymous copy.
        }

        return (string) ($_COOKIE['wbs_session'] ?? '') !== '';
    }

    /**
     * The framework's own message, when it has one, as the muted reference line —
     * never as the headline (it is engineer-facing English and can name a route).
     */
    private static function reference(string $message): ?string
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        return mb_strlen($message) > 160 ? mb_substr($message, 0, 157) . '…' : $message;
    }
}
