# Hierarchical Group Dashboard

The hierarchical group dashboard provides every logged-in user with a personalized overview of their groups and related activities. It auto-discovers widgets from all modules and renders them based on the user's permissions and group scope.

## Features

- **Auto-discovery**: Widgets from all modules are automatically discovered and registered
- **Permission-gated**: Widgets are hidden when user lacks required permissions (fail-closed)
- **Config-gated**: Widgets respect module configuration capabilities
- **Scope-aware**: Widgets render based on user's group membership and hierarchy
- **Caching**: Multi-level caching for performance optimization
- **Mobile-first**: Responsive design optimized for mobile devices

## Architecture

### Core Components

```
┌─────────────────────────────────────────────────────────────┐
│                        GroupDashboardController                  │
│  - Builds dashboard scope (org, user, group, configs)           │
│  - Resolves widget-specific scope data                         │
│  - Renders widgets via GroupDashboardService                   │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                      GroupDashboardService                       │
│  - Collects widgets from all registered providers               │
│  - Filters by permissions and config                           │
│  - Renders visible widgets with caching support                │
│  - Auto-discovers providers from module namespaces             │
└─────────────────────────────────────────────────────────────┘
                              │
              ┌───────────────────────┬───────────────────────┐
              ▼                       ▼                       ▼
   ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
   │ WidgetProvider   │    │ WidgetProvider   │    │ WidgetProvider   │
   │ (Groups)         │    │ (Gamification)   │    │ (Events)         │
   └────────┬────────┘    └────────┬────────┘    └────────┬────────┘
             │                      │                      │
             ▼                      ▼                      ▼
   ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
   │ Widget[]         │    │ Widget[]         │    │ Widget[]         │
   │ - membership_    │    │ - my_standing    │    │ - my_upcoming   │
   │   summary        │    │ - my_points      │    │ - group_upcoming│
   │ - hierarchy_nav  │    │ - my_badges      │    │ - my_registr.   │
   │ - birthdays      │    │ - ...            │    │ - ...           │
   └─────────────────┘    └─────────────────┘    └─────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        WidgetCache                              │
│  - Caches rendered widget HTML                                 │
│  - Multi-level TTL based on cache type                         │
│  - Per-user, per-group, per-scope cache keys                   │
└─────────────────────────────────────────────────────────────┘
```

### Widget Lifecycle

1. **Registration**: Module defines `GroupDashboardWidgetProvider` with `widgets()` method
2. **Discovery**: `WidgetProviderDiscovery` scans namespaces for providers
3. **Collection**: `GroupDashboardService` collects all widgets
4. **Filtering**: Widgets filtered by user permissions and config capabilities
5. **Scope Resolution**: Service resolves scope data for each visible widget
6. **Rendering**: Widget renderer generates HTML (with optional caching)
7. **Display**: Controller passes rendered HTML to view

## Adding a New Widget

### Step 1: Create the Renderer

Create a class that implements `WidgetRenderer`:

```php
<?php

namespace WBS\YourModule\Services;

use WBS\Groups\Support\WidgetRenderer;

final class YourWidgetRenderer implements WidgetRenderer
{
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        // Fetch data and return HTML
        return '<div class="widget">...</div>';
    }
}
```

### Step 2: Register the Widget

Create a provider class:

```php
<?php

namespace WBS\YourModule\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

final class YourModuleDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'YourModule',
                key: 'your_widget',
                labelKey: 'YourModule.dashboard.yourWidget',
                permission: null,           // Optional: required permission bit
                capability: null,          // Optional: required config capability
                scope: 'membership',       // self, membership, membership_desc, descendants, ancestors, ancestor_only
                rendererClass: YourWidgetRenderer::class,
                order: 10,                // Display order within section
                section: 'your_section',   // Group widgets into sections
                cacheType: GroupDashboardWidget::CACHE_LIST, // static, summary, list, realtime, live
            ),
        ];
    }
}
```

### Step 3: Add Language Strings

Add to `app/Modules/YourModule/Language/en/YourModule.php`:

```php
return [
    'dashboard' => [
        'yourWidget' => 'Your Widget Title',
    ],
];
```

Repeat for all locales (ar, es, fr, pt, zh).

### Step 4: Done!

The widget will be automatically discovered and registered. No changes needed to the Groups module.

## Cache Types

Choose the appropriate cache type based on data volatility:

| Type | TTL | Use Case |
|------|-----|----------|
| `CACHE_STATIC` | 1 hour | Rarely changing data (e.g., hierarchy navigation) |
| `CACHE_SUMMARY` | 5 min | Summary statistics (e.g., points, giving totals) |
| `CACHE_LIST` | 3 min | Lists of items (e.g., my courses, my badges) |
| `CACHE_REALTIME` | 1 min | Near real-time data (e.g., upcoming events, announcements) |
| `CACHE_LIVE` | No cache | Always fresh (e.g., notifications, live streams) |

## Configuration

### Enabling/Disabling Caching

Caching is enabled by default. To disable:

```php
// In your controller or service
$service = Services::groupDashboard(
    getShared: false,
    cacheDriver: null,
    enableCaching: false,
);
```

### Custom Cache TTL

```php
$service = Services::groupDashboard(
    getShared: false,
    enableCaching: true,
    cacheTtl: 300, // 5 minutes
);
```

### Custom Cache Driver

```php
$customCache = new \CodeIgniter\Cache\FileHandler();
$service = Services::groupDashboard(
    getShared: false,
    cacheDriver: $customCache,
);
```

## Scopes

Widgets can target different data scopes:

| Scope | Description |
|-------|-------------|
| `self` | Current user only |
| `membership` | User's direct group memberships |
| `membership_desc` | User's groups + descendant groups |
| `descendants` | Descendant groups only |
| `ancestors` | Ancestor groups in hierarchy |
| `ancestor_only` | Ancestor groups excluding user's direct groups |

## Permissions and Capabilities

### Permission Gating

Widgets can require a permission bit:

```php
new GroupDashboardWidget(
    // ...
    permission: 'event.create',
    // Widget only visible if user has event.create permission
),
```

### Capability Gating

Widgets can require a config capability to be enabled:

```php
new GroupDashboardWidget(
    // ...
    capability: 'groups.birthdays',
    // Widget only visible if groups.birthdays is enabled in config
),
```

## Current Widgets

### 14 Modules, 47 Widgets

| Module | Widgets | Section |
|--------|---------|---------|
| Groups | 3 | people |
| Gamification | 6 | gamification |
| Identity | 4 | people |
| Journey | 5 | journey |
| Events | 6 | events |
| Contributions | 5 | giving |
| Courses | 4 | learning |
| Community | 2 | community |
| Announcements | 2 | comms |
| Notifications | 2 | comms |
| AccessControl | 2 | access |
| Reporting | 1 | reports |
| Referrals | 2 | people |
| Meetings | 2 | events |
| Streaming | 1 | streaming |

### Cache Type Distribution

- **STATIC**: 1 widget (hierarchy_nav)
- **SUMMARY**: 13 widgets (statistics, totals)
- **LIST**: 15 widgets (lists of items)
- **REALTIME**: 14 widgets (near real-time data)
- **LIVE**: 4 widgets (always fresh)

## Route

- **URL**: `/me/groups`
- **Controller**: `WBS\Groups\Controllers\GroupDashboardController`
- **Method**: `index()`

## Testing

### Unit Tests

```bash
# Run full dashboard integration test
php app/Modules/Groups/Services/tests/group_dashboard_full_test.php

# Run widget provider discovery tests
php app/Modules/Groups/Support/tests/widget_provider_discovery_test.php

# Run cache tests
php app/Modules/Groups/Support/tests/widget_cache_test.php
```

### Test Coverage

- ✅ 23 integration tests (providers, widgets, sections, permissions, visibility)
- ✅ 7 discovery tests (namespace scanning, provider registration)
- ✅ 10 cache tests (set, get, delete, clear, key generation)

## Best Practices

1. **Fail-closed**: Always hide widgets when permissions/config are insufficient
2. **Mobile-first**: Design widgets for small screens first
3. **Minimal queries**: Fetch only necessary data in renderers
4. **Appropriate caching**: Use cache types based on data volatility
5. **Clear naming**: Use descriptive widget keys and label keys

## Performance Tips

1. **Use caching**: Most widgets benefit from caching
2. **Choose right TTL**: Balance freshness with performance
3. **Minimize DB queries**: Batch queries where possible
4. **Lazy load**: Consider lazy loading for heavy widgets
5. **Monitor**: Track widget render times in production

## Future Enhancements

- [ ] Cache invalidation on data changes
- [ ] User preferences for widget visibility/order
- [ ] Widget-specific refresh intervals
- [ ] Dashboard layout customization
- [ ] Export dashboard as PDF
- [ ] Real-time updates via WebSockets
