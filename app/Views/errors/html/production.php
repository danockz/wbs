<?php

declare(strict_types=1);

/**
 * Production body for an UNCAUGHT exception (any status that is not 404/400).
 *
 * Deliberately says nothing about the exception: no class, no file, no line, no
 * message, no stack. The exception itself is already logged by the framework
 * handler with its correlation id; what a member needs is a localized page that
 * says it was our fault and offers a way out. Rendered from the same shared
 * problem page as every other browser-facing failure — see
 * WBS\Shared\Http\ErrorPages. Development keeps the stock `error_exception.php`
 * trace template (engineer-facing, never served in production).
 *
 * @var int|null $statusCode when the handler passes it
 */

echo \WBS\Shared\Http\ErrorPages::serverError((int) ($statusCode ?? 500));
