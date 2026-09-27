# Hierarchical Group Dashboard - Build Progress

## Overview

We are building a **dynamic hierarchical group dashboard** that pulls widgets from every module and displays them for each logged-in user, respecting their scope(s). The dashboard is:

- **Server-rendered** for fast loading and minimal server resource burden
- **Mobile-first** responsive design
- **Fail-closed** on permissions and feature configs (widgets hidden when user lacks permission or feature is OFF)
- **Hierarchy-aware** with configurable scope per widget

## What's Been Built So Far

### Core Infrastructure (Complete)

1. **Widget System** (`app/Modules/Groups/Support/`)
   - `GroupDashboardWidget.php` - Widget value object with visibility logic
   - `GroupDashboardScope.php` - Scope value object (single group vs rollup)
   - `GroupDashboardWidgetProvider.php` - Interface for modules to register widgets
   - `WidgetRenderer.php` - Interface for widget rendering

2. **Dashboard Service** (`app/Modules/Groups/Services/GroupDashboardService.php`)
   - Collects widgets from all registered providers
   - Filters by user permissions and effective configs
   - Resolves widget scopes (self, membership, descendants, ancestors, ancestor_only)
   - Sorts widgets by section and order
   - Renders widget HTML

3. **Controller** (`app/Modules/Groups/Controllers/GroupDashboardController.php`)
   - Route: `GET /me/groups`
   - Auth filter only (widgets are fail-closed)
   - Group switcher support
   - Builds and renders dashboard

4. **View** (`app/Modules/Groups/Views/group_dashboard.php`)
   - Mobile-first responsive design
   - Group switcher
   - Section-based widget organization
   - Placeholder rendering for widgets

5. **Groups Module Widgets** (`app/Modules/Groups/Services/`)
   - `GroupsDashboardWidgetProvider.php` - Widget provider for Groups module
   - `GroupMembershipWidgetRenderer.php` - Shows user's groups
   - `GroupHierarchyWidgetRenderer.php` - Shows group hierarchy navigation
   - `GroupBirthdaysWidgetRenderer.php` - Shows birthdays (gated by groups.birthdays config)

6. **Integration**
   - Route added to `app/Config/Routes.php`
   - Menu item added to `app/Modules/Shared/Navigation/CoreMenuProvider.php`
   - Language strings added to all locales
   - Service registered in `app/Modules/Groups/Config/Services.php`

7. **Tests** (`app/Modules/Groups/Services/tests/group_dashboard_service_test.php`)
   - 14 passing tests for widget creation, visibility, scoping, and provider

## Widget Discovery Pattern

Each module that wants to expose widgets on the dashboard:

1. Creates a class implementing `GroupDashboardWidgetProvider`
2. Returns a list of `GroupDashboardWidget` instances
3. Each widget specifies:
   - `module` - Module name
   - `key` - Unique widget key
   - `labelKey` - Language key for the title
   - `permission` - Optional permission bit (null = no permission required)
   - `capability` - Optional group config capability (null = no config gate)
   - `scope` - Data scope: 'self', 'membership', 'membership_desc', 'descendants', 'ancestors', 'ancestor_only'
   - `rendererClass` - Class that implements `WidgetRenderer`
   - `order` - Display order within section
   - `section` - Section to group under (e.g., 'people', 'events', 'giving')

4. Registers the provider with the dashboard service (auto-discovered or manual)

## Current Widgets

| Module | Widget | Permission | Capability | Scope | Section |
|--------|--------|-----------|-----------|-------|---------|
| Groups | membership_summary | none | none | membership | people |
| Groups | hierarchy_nav | none | none | membership | people |
| Groups | birthdays | none | groups.birthdays | ancestor_only | people |

## Next Steps

### Phase 1: Complete Groups Module Widgets
- [ ] Implement actual rendering logic in widget renderers
- [ ] Add more Groups widgets (recent activity, members list, etc.)

### Phase 2: Add Widgets from Other Modules
Each module should add its own widget provider:

- **Events**
  - Upcoming events widget (scope: membership_desc)
  - My registrations widget (scope: self)
  - Committee tasks widget (scope: membership)

- **Contributions**
  - My giving summary (scope: self)
  - Group giving progress (scope: membership_desc)
  - Commitment status (scope: self)

- **Courses**
  - My courses (scope: self)
  - Group learning progress (scope: membership_desc)

- **Gamification**
  - My points/rank (scope: self)
  - Group leaderboard (scope: membership_desc)

- **Journey**
  - My journey stage (scope: self)
  - Pipeline status (scope: membership_desc)

- **Community**
  - Group feed (scope: membership)
  - Recent posts (scope: membership_desc)

- **Announcements**
  - My announcements (scope: self)
  - Group announcements (scope: membership_desc)

- **Notifications**
  - My notifications (scope: self)
  - Campaign status (scope: membership_desc, permission: notification.send)

- **AccessControl**
  - My access requests (scope: self)
  - Pending approvals (scope: membership_desc, permission: access.request.approve)

- **Reporting**
  - Group funnel metrics (scope: membership_desc, permission: report.view)

- **Referrals**
  - My referrals (scope: self)
  - Group outreach (scope: membership_desc)

- **Meetings**
  - My meetings (scope: self)
  - Group schedule (scope: membership_desc)

- **Streaming**
  - Live streams (scope: membership_desc)

### Phase 3: Auto-Discovery
- [ ] Implement auto-discovery of widget providers from all modules
- [ ] Convention: Each module's Config/Services.php registers its provider
- [ ] Or: Scan for classes implementing GroupDashboardWidgetProvider

### Phase 4: Polish
- [ ] Finalize view styling (Tailwind v4)
- [ ] Add loading states
- [ ] Add empty states
- [ ] Performance optimization (caching, lazy loading)
- [ ] Accessibility improvements

## Scope Definitions

| Scope | Description |
|-------|-------------|
| `self` | Only the user's own data |
| `membership` | Groups the user is a member of |
| `membership_desc` | Membership groups + their descendants |
| `descendants` | Only descendants of selected group (or user's groups) |
| `ancestors` | Ancestors of selected group (or user's groups) |
| `ancestor_only` | Only ancestor groups (not including selected group) |

## Configuration Gating

Widgets can be gated by group config capabilities:
- If a widget specifies a `capability`, it only shows when that capability is enabled for the current group
- Example: `groups.birthdays` capability gates the birthdays widget
- Fail-closed: if config is OFF or missing, widget is hidden

## Permission Gating

Widgets can require permission bits:
- If a widget specifies a `permission`, it only shows when the user has that permission
- Fail-closed: if user lacks permission, widget is hidden
- Example: `report.view` gates analytics widgets

## Routing

- **Dashboard**: `GET /me/groups`
- **Menu**: Overview → Group Dashboard

## Files Created/Modified

### New Files
- `app/Modules/Groups/Support/GroupDashboardWidget.php`
- `app/Modules/Groups/Support/GroupDashboardScope.php`
- `app/Modules/Groups/Support/GroupDashboardWidgetProvider.php`
- `app/Modules/Groups/Support/WidgetRenderer.php`
- `app/Modules/Groups/Services/GroupDashboardService.php`
- `app/Modules/Groups/Controllers/GroupDashboardController.php`
- `app/Modules/Groups/Views/group_dashboard.php`
- `app/Modules/Groups/Services/GroupsDashboardWidgetProvider.php`
- `app/Modules/Groups/Services/GroupMembershipWidgetRenderer.php`
- `app/Modules/Groups/Services/GroupHierarchyWidgetRenderer.php`
- `app/Modules/Groups/Services/GroupBirthdaysWidgetRenderer.php`
- `app/Modules/Groups/Services/tests/group_dashboard_service_test.php`

### Modified Files
- `app/Config/Routes.php` - Added `/me/groups` route
- `app/Modules/Groups/Config/Services.php` - Registered dashboard service
- `app/Modules/Shared/Navigation/CoreMenuProvider.php` - Added menu item
- `app/Modules/Groups/Language/{ar,en,es,fr,pt,zh}/Groups.php` - Added dashboard strings

## Testing

Run the unit tests:
```bash
php app/Modules/Groups/Services/tests/group_dashboard_service_test.php
```

Expected: 14 passed, 0 failed

## Usage Example

To add a widget from another module (e.g., Events):

1. Create a widget provider:
```php
// app/Modules/Events/Services/EventsDashboardWidgetProvider.php
namespace WBS\Events\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

final class EventsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Events',
                key: 'upcoming_events',
                labelKey: 'Events.dashboard.upcoming',
                permission: null,
                capability: null,
                scope: 'membership_desc',
                rendererClass: UpcomingEventsWidgetRenderer::class,
                order: 10,
                section: 'events',
            ),
        ];
    }
}
```

2. Create a renderer:
```php
// app/Modules/Events/Services/UpcomingEventsWidgetRenderer.php
namespace WBS\Events\Services;

use WBS\Groups\Support\WidgetRenderer;

final class UpcomingEventsWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        // Get upcoming events for the resolved scope
        $groupIds = $scopeData['group_ids'] ?? [];
        
        // Query and render...
        return '<ul>...</ul>';
    }
}
```

3. Register the provider in Events module's Services.php:
```php
// In app/Modules/Events/Config/Services.php
public static function dashboardWidgets(bool $getShared = true): EventsDashboardWidgetProvider
{
    if ($getShared) {
        return static::getSharedInstance('dashboardWidgets');
    }
    return new EventsDashboardWidgetProvider();
}
```

4. Or auto-register in Groups module's dashboard service

## Architecture Decisions

1. **Server-side rendering**: All widgets are rendered server-side for SEO, performance, and to respect fail-closed security
2. **Mobile-first**: Design starts with mobile and scales up
3. **Fail-closed**: Widgets are hidden when permissions/config don't allow
4. **Scope per widget**: Each widget defines its own data scope
5. **Section organization**: Widgets grouped into logical sections
6. **Ordering**: Widgets have explicit order within sections

## Performance Considerations

- Dashboard service collects all widgets but only renders visible ones
- Each widget's data fetching happens during render (lazy)
- Scope resolution is cached per request
- For users with many groups, consider:
  - Limiting the number of groups in scope
  - Paginating widgets
  - Caching dashboard HTML per user/group

## Security

- Route is auth-only (`auth` filter)
- Widgets are fail-closed on permissions
- Widgets are fail-closed on config capabilities
- No user-supplied IDs are trusted for data access
- All data is scoped to the authenticated user's permissions
