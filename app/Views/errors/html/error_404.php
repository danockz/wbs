<?php

declare(strict_types=1);

/**
 * 404 — a route that does not exist, or a controller that threw
 * PageNotFoundException.
 *
 * Was the stock CodeIgniter template: hardcoded lang="en", light theme, and
 * `lang('Errors.pageNotFound')` from the framework's English bundle, so a member
 * on any other locale got an unbranded English page. It now renders the SAME
 * self-contained, localized problem page the request filters use
 * (WBS\Shared\Views\error_page) through WBS\Shared\Http\ErrorPages, which owns the
 * copy and the action choice. The framework still owns the status code, headers
 * and logging — this file only supplies a body.
 *
 * @var string|null $message framework-supplied detail, when there is one
 */

echo \WBS\Shared\Http\ErrorPages::notFound((string) ($message ?? ''));
