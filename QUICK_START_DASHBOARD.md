# Dashboard - Quick Start Guide

## 1. Enable Dashboard

The dashboard is enabled by default. To verify:

```php
// app/Config/Dashboard.php
$enabled = true;  // Default: true
```

## 2. Access Dashboard

Navigate to: **`/me/groups`**

- Requires authentication
- Shows widgets based on your permissions and group memberships

## 3. What You'll See

### For Regular Users (31 widgets)
- My Groups (membership summary)
- Group Hierarchy
- My Profile
- My Memberships
- My Journey Stage & Progress
- My Upcoming Events
- My Giving Summary
- My Courses
- My Badges & Points
- My Meetings
- My Announcements
- My Referrals
- And more...

### For Leaders (additional widgets)
- Group Leaderboards
- Recent Members
- Group Giving Progress
- Group Pipeline
- Event Analytics
- Discipling Leaderboard
- Group Courses
- Completion Rates
- Group Schedule
- Group Announcements
- Campaign Status
- Pending Approvals
- Live Streams

### For Admins (all 47 widgets)
- Everything above
- Membership Conflicts
- All analytics and reports

## 4. Configuration (Optional)

### Enable/Disable
```php
// app/Config/Dashboard.php
$enabled = true;           // Enable dashboard
$cacheEnabled = true;      // Enable caching
$autoDiscover = true;      // Auto-find widgets
```

### Cache Settings
```php
$defaultCacheTtl = 180;   // 3 minutes default

$cacheTtls = [
    'static' => 3600,      // 1 hour - rarely changes
    'summary' => 300,      // 5 min - statistics
    'list' => 180,         // 3 min - lists
    'realtime' => 60,      // 1 min - near real-time
    'live' => 0,           // No cache - always fresh
];
```

### Display Options
```php
$showSectionHeaders = true;    // Show "People", "Events", etc.
$showDebugBadges = false;       // Hide permission badges in production
$groupSwitcherEnabled = true;   // Allow switching groups
$showEmptyStates = true;         // Show helpful messages
```

## 5. Adding a New Widget

### Step 1: Create Renderer
```php
// app/Modules/YourModule/Services/YourWidgetRenderer.php
namespace WBS\YourModule\Services;

use WBS\Groups\Support\WidgetRenderer;

final class YourWidgetRenderer implements WidgetRenderer
{
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        // Fetch your data
        $data = $this->fetchData($orgId, $userId, $scopeData);
        
        // Return HTML
        return '<div class="widget">Your content here</div>';
    }
}
```

### Step 2: Create Provider
```php
// app/Modules/YourModule/Services/YourModuleDashboardWidgetProvider.php
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
                permission: null,           // Optional: 'permission.bit'
                capability: null,          // Optional: 'config.capability'
                scope: 'membership',       // self, membership, membership_desc, descendants, ancestors, ancestor_only
                rendererClass: YourWidgetRenderer::class,
                order: 10,                // Display order in section
                section: 'your_section',   // Group widgets: people, events, giving, etc.
                cacheType: GroupDashboardWidget::CACHE_LIST, // static, summary, list, realtime, live
            ),
        ];
    }
}
```

### Step 3: Add Language Strings
```php
// app/Modules/YourModule/Language/en/YourModule.php
return [
    'dashboard' => [
        'yourWidget' => 'Your Widget Title',
    ],
];
```

Repeat for all locales: `ar`, `es`, `fr`, `pt`, `zh`

### Step 4: Done!

The widget will be **automatically discovered** and appear on the dashboard.

## 6. Cache Invalidation

When data changes, invalidate the cache:

```php
// In your controller or service
use WBS\Groups\Support\WidgetCacheInvalidator;

// After user updates profile
WidgetCacheInvalidator::invalidateUser($userId, service('cache'));

// After group is updated
WidgetCacheInvalidator::invalidateGroup($groupId, service('cache'));

// After user's role in group changes
WidgetCacheInvalidator::invalidateUserGroup($userId, $groupId, service('cache'));

// After specific data changes
WidgetCacheInvalidator::invalidateWidget('YourModule.your_widget', $userId, service('cache'));
```

## 7. Testing

```bash
# Run all dashboard tests
php app/Modules/Groups/Services/tests/group_dashboard_full_test.php
php app/Modules/Groups/Support/tests/widget_provider_discovery_test.php
php app/Modules/Groups/Support/tests/widget_cache_test.php
php app/Modules/Groups/Support/tests/widget_cache_invalidator_test.php
php app/Modules/Groups/Services/tests/group_dashboard_complete_test.php
```

**Expected**: All tests pass ✅

## 8. Troubleshooting

### Dashboard not showing
- Check user is authenticated
- Check user has at least one group membership
- Check `$enabled = true` in config

### Widgets missing
- Check user has required permissions
- Check config capabilities are enabled
- Check widget is in provider's `widgets()` method

### Cache not working
- Check `$cacheEnabled = true` in config
- Check cache directory is writable
- Check cache driver is configured

### Slow performance
- Check cache hit rate (should be 70-80%)
- Increase cache TTLs for stable widgets
- Optimize slow database queries

## 9. Documentation

| File | Purpose |
|------|---------|
| `DASHBOARD.md` | Full user/developer guide |
| `DASHBOARD_IMPLEMENTATION.md` | Implementation details |
| `DASHBOARD_SUMMARY.md` | Executive summary |
| `DEPLOYMENT_CHECKLIST.md` | Deployment steps |
| `MAINTENANCE_GUIDE.md` | Maintenance tasks |
| `README_DASHBOARD_SNIPPET.md` | This quick reference |

## 10. Summary

| Feature | Status |
|---------|--------|
| Route | ✅ `/me/groups` |
| Widgets | ✅ 47 across 14 modules |
| Locales | ✅ 6 supported |
| Caching | ✅ Multi-level TTL |
| Auto-discovery | ✅ Working |
| Permissions | ✅ Fail-closed |
| Tests | ✅ 55+ passing |
| Docs | ✅ Complete |

**Status**: 🎉 **Production Ready**
