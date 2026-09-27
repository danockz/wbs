# Dashboard Maintenance Guide

## Overview

This guide covers ongoing maintenance tasks for the hierarchical group dashboard.

## Daily Maintenance

### Log Review
```bash
# Check for dashboard-related errors
grep -i "dashboard\|widget" writable/logs/log-*.php

# Check for permission errors
grep -i "permission\|denied" writable/logs/log-*.php | grep dashboard
```

### Cache Health Check
```bash
# Count dashboard cache entries
php -r "\$cache = service('cache'); \$keys = \$cache->getKeys(); \$dashboardKeys = array_filter(\$keys, fn(\$k) => str_starts_with(\$k, 'dashboard_widget_')); echo count(\$dashboardKeys) . ' dashboard cache entries\n';"
```

## Weekly Maintenance

### Performance Monitoring
```bash
# Average dashboard load time (sample 10 requests)
for i in {1..10}; do curl -s -w "%{time_total}\n" -o /dev/null http://yoursite.test/me/groups; done | awk '{sum+=$1; count++} END {print "Avg: " sum/count "s"}'
```

### Cache Hit Rate
```bash
# This requires cache driver with hit/miss tracking
# For file cache, check if cache files are being reused
```

## Monthly Maintenance

### Widget Usage Review
```bash
# Check which widgets are most/least used
# (Requires analytics implementation)
```

### Cache TTL Review
```php
// Review cache TTLs in app/Config/Dashboard.php
// Adjust based on:
// - Data volatility
// - User feedback
// - Performance metrics
```

## Quarterly Maintenance

### Widget Audit
1. Review all 47 widgets for relevance
2. Remove deprecated widgets
3. Add new widgets for new features
4. Update widget cache types if data volatility changes

### Performance Optimization
1. Review slowest widgets
2. Optimize database queries
3. Consider increasing cache TTLs for stable widgets
4. Consider adding more granular cache invalidation

## As-Needed Maintenance

### Adding a New Widget

See `DASHBOARD.md` for complete instructions.

**Quick checklist:**
- [ ] Create renderer class implementing `WidgetRenderer`
- [ ] Create provider class implementing `GroupDashboardWidgetProvider`
- [ ] Add language strings for all 6 locales
- [ ] Set appropriate cache type
- [ ] Set appropriate scope
- [ ] Set permission/capability if needed
- [ ] Test widget visibility
- [ ] Verify caching works

### Updating a Widget

1. Update renderer class
2. Update provider if widget definition changes
3. Update language strings if label changes
4. Invalidate cache for affected users:
   ```php
   $cache = service('cache');
   WidgetCacheInvalidator::invalidateModule('YourModule', $userId, $cache);
   ```

### Removing a Widget

1. Remove renderer class
2. Remove from provider's `widgets()` method
3. Remove language strings
4. Cache will automatically expire

### Changing Cache TTLs

Edit `app/Config/Dashboard.php`:
```php
$cacheTtls = [
    'static' => 3600,   // 1 hour
    'summary' => 300,   // 5 minutes
    'list' => 180,      // 3 minutes
    'realtime' => 60,   // 1 minute
    'live' => 0,        // No caching
];
```

Then invalidate all cache:
```php
$cache = service('cache');
WidgetCacheInvalidator::invalidateAll($cache);
```

## Troubleshooting

### Issue: Dashboard is slow

**Symptoms:** Load times > 1 second

**Diagnosis:**
1. Check cache hit rate (should be 70-80%)
2. Identify slow widgets
3. Check database query times

**Solutions:**
1. Increase cache TTLs for stable widgets
2. Optimize slow database queries
3. Add more granular caching
4. Consider lazy loading for heavy widgets

### Issue: Widgets not appearing

**Symptoms:** Users report missing widgets

**Diagnosis:**
1. Check user has required permissions
2. Check config capabilities are enabled
3. Check user is in appropriate scope
4. Check widget is registered in provider

**Solutions:**
1. Grant missing permissions
2. Enable required config capabilities
3. Verify user's group memberships
4. Check auto-discovery is working

### Issue: Cache not invalidating

**Symptoms:** Users see stale data after updates

**Diagnosis:**
1. Check cache invalidation hooks are registered
2. Check cache invalidation is being called
3. Verify cache keys match

**Solutions:**
1. Ensure hooks are registered in `Groups/Config/Hooks.php`
2. Add manual cache invalidation calls
3. Verify cache key generation

### Issue: Cache growing too large

**Symptoms:** Cache directory is very large

**Diagnosis:**
1. Count cache entries
2. Check cache TTLs
3. Check if cache cleanup is running

**Solutions:**
1. Reduce cache TTLs
2. Add cache size limits
3. Implement cache cleanup cron job

## Cache Management

### Manual Cache Clearing

```php
// Clear all dashboard cache
$cache = service('cache');
WidgetCacheInvalidator::invalidateAll($cache);

// Clear cache for specific user
WidgetCacheInvalidator::invalidateUser($userId, $cache);

// Clear cache for specific group
WidgetCacheInvalidator::invalidateGroup($groupId, $cache);

// Clear cache for specific widget
WidgetCacheInvalidator::invalidateWidget('Module.widget_key', $userId, $cache);
```

### Automated Cache Clearing

Set up a cron job to clear old cache:
```bash
# Clear dashboard cache daily at 3 AM
0 3 * * * php /path/to/project/public/index.php cache:clear-dashboard
```

Create a custom command:
```php
// In app/Commands/ClearDashboardCache.php
public function run(array $params)
{
    $cache = service('cache');
    $count = WidgetCacheInvalidator::invalidateAll($cache);
    $this->io->success("Cleared $count dashboard cache entries");
}
```

## Monitoring

### Recommended Monitoring

| Metric | Frequency | Threshold |
|--------|-----------|-----------|
| Dashboard load time | Daily | < 1s |
| Cache hit rate | Daily | > 70% |
| Widget count | Weekly | 47 |
| Permission errors | Daily | 0 |
| Cache size | Weekly | < 100MB |

### Alerting

Set up alerts for:
- Dashboard load time > 2s
- Cache hit rate < 50%
- Permission errors > 0
- Cache size > 500MB

## Backup

### What to Backup

1. Dashboard configuration (`app/Config/Dashboard.php`)
2. Widget provider classes
3. Widget renderer classes
4. Language files
5. Custom cache configuration

### Backup Frequency

- Configuration: Weekly
- Code: With regular code backups
- Cache: Not needed (can be regenerated)

## Recovery

### From Backup

1. Restore dashboard configuration
2. Restore widget provider classes
3. Restore widget renderer classes
4. Restore language files
5. Clear cache to force regeneration

### From Scratch

If dashboard files are lost:
1. Recreate from this documentation
2. Use `DASHBOARD_IMPLEMENTATION.md` as reference
3. Check git history for previous versions

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-09-27 | Initial implementation |

## Resources

- **Documentation**: `DASHBOARD.md`
- **Implementation**: `DASHBOARD_IMPLEMENTATION.md`
- **Summary**: `DASHBOARD_SUMMARY.md`
- **Deployment**: `DEPLOYMENT_CHECKLIST.md`
- **Tests**: `app/Modules/Groups/Services/tests/` and `app/Modules/Groups/Support/tests/`

## Contacts

For maintenance questions:
- Primary: [Your contact]
- Secondary: [Backup contact]

For dashboard-specific issues:
- Check documentation files first
- Review test files for usage examples
- Check git history for changes
