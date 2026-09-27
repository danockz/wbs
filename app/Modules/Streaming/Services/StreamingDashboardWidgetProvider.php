<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Streaming module widget provider for the hierarchical group dashboard.
 */
final class StreamingDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Streaming',
                key: 'live_streams',
                labelKey: 'Streaming.dashboard.liveStreams',
                permission: 'stream.moderate',
                capability: null,
                scope: 'membership_desc',
                rendererClass: LiveStreamsWidgetRenderer::class,
                order: 10,
                section: 'streaming',
            ),
        ];
    }
}
