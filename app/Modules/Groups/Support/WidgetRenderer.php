<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Interface for widget renderers.
 *
 * Each widget that appears on the group dashboard must have a renderer class
 * that implements this interface. The renderer receives the resolved scope
 * and options, and returns the HTML to display.
 */
interface WidgetRenderer
{
    /**
     * Render the widget HTML.
     *
     * @param string $orgId    Organization ID
     * @param string $userId   Authenticated user ID
     * @param array<string,mixed> $scopeData Resolved scope data
     * @param array<string,mixed> $options Widget-specific options
     *
     * @return string HTML to display
     */
    public function render(string $orgId, string $userId, array $scopeData, array $options): string;
}
