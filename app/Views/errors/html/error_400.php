<?php

declare(strict_types=1);

/**
 * 400 — a request the router could not parse.
 *
 * Same treatment as error_404.php: the shared, localized problem page instead of
 * the stock framework template. See WBS\Shared\Http\ErrorPages.
 *
 * @var string|null $message framework-supplied detail, when there is one
 */

echo \WBS\Shared\Http\ErrorPages::badRequest((string) ($message ?? ''));
