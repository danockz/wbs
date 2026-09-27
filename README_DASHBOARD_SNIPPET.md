# Dashboard Quick Reference

## What is it?

A hierarchical group dashboard that provides every logged-in user with a personalized overview of their groups and related activities across all modules.

## Quick Start

### Access
- **URL**: `/me/groups`
- **Requires**: Authentication
- **Shows**: 30-47 widgets depending on user permissions

### Features
- ✅ 47 widgets from 14 modules
- ✅ Auto-discovery of new widgets
- ✅ Multi-level caching (static, summary, list, realtime, live)
- ✅ Permission & config gating
- ✅ Hierarchical scope awareness
- ✅ Mobile-first responsive design
- ✅ 6 locales supported (en, ar, es, fr, pt, zh)

## Configuration

Edit `app/Config/Dashboard.php`:
```php
// Enable/disable
$enabled = true;

// Caching
$cacheEnabled = true;
$cacheTtls = [
    'static' => 3600,   // 1 hour
    'summary' => 300,   // 5 minutes
    'list' => 180,      // 3 minutes
    'realtime' => 60,   // 1 minute
    'live' => 0,        // No cache
];
```

## Adding a Widget

1. Create renderer:
```php
class MyWidgetRenderer implements \WBS\Groups\Support\WidgetRenderer
{
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        return '<div>My Widget HTML</div>';
    }
}
```

2. Create provider:
```php
class MyModuleDashboardWidgetProvider implements \WBS\Groups\Support\GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new \WBS\Groups\Support\GroupDashboardWidget(
                module: 'MyModule',
                key: 'my_widget',
                labelKey: 'MyModule.dashboard.myWidget',
                scope: 'membership',
                rendererClass: MyWidgetRenderer::class,
                cacheType: \WBS\Groups\Support\GroupDashboardWidget::CACHE_LIST,
            ),
        ];
    }
}
```

3. Add language strings to all 6 locales

4. **Done!** Auto-discovery will find it.

## Documentation

- 📖 **Full Guide**: `DASHBOARD.md`
- 📊 **Implementation Details**: `DASHBOARD_IMPLEMENTATION.md`
- 🎯 **Summary**: `DASHBOARD_SUMMARY.md`
- ✅ **Deployment**: `DEPLOYMENT_CHECKLIST.md`
- 🔧 **Maintenance**: `MAINTENANCE_GUIDE.md`

## Tests

```bash
# Run all dashboard tests
php app/Modules/Groups/Services/tests/group_dashboard_full_test.php
php app/Modules/Groups/Support/tests/widget_provider_discovery_test.php
php app/Modules/Groups/Support/tests/widget_cache_test.php
php app/Modules/Groups/Support/tests/widget_cache_invalidator_test.php
php app/Modules/Groups/Services/tests/group_dashboard_complete_test.php
```

**Total: 55+ tests**

## Support

- **Route**: `/me/groups`
- **Controller**: `WBS\Groups\Controllers\GroupDashboardController`
- **Service**: `WBS\Groups\Services\GroupDashboardService`
- **Config**: `app/Config/Dashboard.php`
