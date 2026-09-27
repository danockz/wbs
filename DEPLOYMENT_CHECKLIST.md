# Dashboard Deployment Checklist

## Pre-Deployment

### Code Review
- [ ] Verify all 12 commits are merged to main branch
- [ ] Check that no sensitive data is in the codebase
- [ ] Review `DASHBOARD.md` for any environment-specific configs
- [ ] Verify all 55 tests pass in staging

### Environment Preparation
- [ ] Ensure PHP 8.1+ is installed
- [ ] Verify CodeIgniter 4 dependencies are installed
- [ ] Check that cache directory is writable (`writable/cache/`)
- [ ] Verify database migrations are up to date
- [ ] Ensure all 6 locale directories exist

### Configuration
- [ ] Review `app/Config/Dashboard.php` settings
- [ ] Set `$enabled = true` (default: true)
- [ ] Set `$cacheEnabled = true` (default: true)
- [ ] Configure `$cacheTtls` as needed (defaults are good for most cases)
- [ ] Set `$sectionOrder` to match your organization's preferences
- [ ] Set `$showDebugBadges = false` for production (default: false)

### Cache Setup
- [ ] Verify cache driver is configured in `app/Config/Cache.php`
- [ ] Recommended: File cache for development, Redis for production
- [ ] Test cache operations: `service('cache')->save()` / `get()` / `delete()`
- [ ] Set appropriate cache directory permissions

### Database
- [ ] No new tables needed (reuse existing infrastructure)
- [ ] Verify `groups`, `group_members`, `users` tables exist
- [ ] Check that all required permission bits exist
- [ ] Verify config capabilities are set up in admin panel

## Deployment

### Step 1: Code Deployment
```bash
# Pull latest code
git pull origin main

# Run migrations (if any)
php spark migrate

# Clear cached files
rm -rf writable/cache/*
```

### Step 2: Verify Dependencies
```bash
# Check PHP version
php -v

# Verify Composer dependencies
composer validate

# Check directory permissions
ls -la writable/
```

### Step 3: Configuration Check
```bash
# Verify dashboard config is loaded
php spark config:check Dashboard

# Test cache
php -r "\$cache = service('cache'); \$cache->save('test', 'value', 60); echo \$cache->get('test');"
```

### Step 4: Route Verification
```bash
# Test the route exists
php spark routes | grep /me/groups

# Expected output:
# GET /me/groups -> WBS\Groups\Controllers\GroupDashboardController::index
```

## Post-Deployment

### Smoke Tests
- [ ] Access `/me/groups` as authenticated user
- [ ] Verify dashboard loads without errors
- [ ] Check that widgets appear (should see 30-47 widgets depending on permissions)
- [ ] Test group switcher (if multiple groups)
- [ ] Verify responsive design on mobile viewport

### Functional Tests
- [ ] User with no permissions sees only open widgets (31 widgets)
- [ ] User with `report.view` sees analytics widgets (5 additional)
- [ ] User with `identity.manage` sees member widgets (2 additional)
- [ ] Group leader sees descendant group widgets
- [ ] Cache is working (second load should be faster)

### Performance Tests
- [ ] First load time < 1 second
- [ ] Subsequent loads < 200ms (cached)
- [ ] Memory usage < 50MB per request
- [ ] No database query errors

### Security Tests
- [ ] Anonymous users cannot access `/me/groups` (redirects to login)
- [ ] Users only see widgets for their scope
- [ ] Permission-gated widgets are hidden for unauthorized users
- [ ] Config-gated widgets are hidden when feature is disabled
- [ ] No sensitive data exposed in widget output

## Monitoring Setup

### Log Monitoring
- [ ] Check `writable/logs/` for errors after deployment
- [ ] Set up log rotation for dashboard-related logs
- [ ] Monitor for permission denied errors

### Performance Monitoring
```bash
# Simple performance check (run multiple times)
curl -s -w "\nTime: %{time_total}s\n" -o /dev/null http://yoursite.test/me/groups
```

### Cache Monitoring
- [ ] Monitor cache hit/miss ratio
- [ ] Check cache directory size growth
- [ ] Set up cache expiration monitoring

## Rollback Plan

### Quick Rollback
```bash
# Revert to previous commit
git revert --no-commit HEAD~11..HEAD
git commit -m "Revert dashboard implementation"
git push origin main
```

### Partial Rollback Options
1. **Disable dashboard**: Set `$enabled = false` in `Config/Dashboard.php`
2. **Disable caching**: Set `$cacheEnabled = false` in `Config/Dashboard.php`
3. **Disable auto-discovery**: Set `$autoDiscover = false` in `Config/Dashboard.php`

### Rollback Verification
- [ ] Dashboard route returns 404 or redirects
- [ ] No errors in logs
- [ ] Other routes still work

## Maintenance Tasks

### Regular Maintenance
- [ ] Monthly: Review dashboard performance metrics
- [ ] Quarterly: Review widget usage analytics
- [ ] As needed: Add new widgets for new features
- [ ] As needed: Update cache TTLs based on usage patterns

### Cache Maintenance
```bash
# Clear all dashboard cache
php spark cache:clear

# Or programmatically
\$cache = service('cache');
\$keys = \$cache->getKeys();
foreach (\$keys as \$key) {
    if (str_starts_with(\$key, 'dashboard_widget_')) {
        \$cache->delete(\$key);
    }
}
```

### Adding New Widgets
1. Create renderer class
2. Create provider class
3. Add language strings
4. Deploy - auto-discovery will find it

## Troubleshooting

### Common Issues

**Issue: Dashboard shows no widgets**
- Check: User is authenticated
- Check: User has at least one group membership
- Check: `$enabled = true` in config
- Check: `$autoDiscover = true` in config
- Check: Cache is not blocking (try clearing cache)

**Issue: Widgets not appearing for users with permissions**
- Check: Permission bits are set for the user
- Check: Permission bit names match widget definitions
- Check: Effective permissions include the required bits

**Issue: Cache not working**
- Check: `$cacheEnabled = true` in config
- Check: Cache driver is configured
- Check: Cache directory is writable
- Check: Cache TTL > 0

**Issue: Slow performance**
- Check: Cache hit ratio (should be 70-80%)
- Check: Database query times
- Check: Widget render times
- Check: Consider increasing cache TTLs

**Issue: Missing locale strings**
- Check: All 6 locale files exist
- Check: Language keys match widget definitions
- Check: Fallback locale is configured

### Debug Mode

Enable debug mode for troubleshooting:

```php
// In app/Config/Dashboard.php
$showDebugBadges = true;
```

This will show:
- Permission badges on gated widgets
- Config capability badges
- Scope indicators
- Cache status

## Success Criteria

### Minimum Viable Deployment
- [ ] Dashboard loads without errors
- [ ] At least 30 widgets visible for admin user
- [ ] Load time < 2 seconds
- [ ] No permission errors

### Optimal Deployment
- [ ] All 47 widgets visible for users with all permissions
- [ ] Load time < 1 second (first load)
- [ ] Load time < 200ms (cached)
- [ ] Cache hit rate > 70%
- [ ] All 6 locales working

## Contacts

For deployment issues:
- Primary: [Your contact]
- Secondary: [Backup contact]

For dashboard-specific questions:
- Documentation: `DASHBOARD.md`
- Implementation: `DASHBOARD_IMPLEMENTATION.md`
- Summary: `DASHBOARD_SUMMARY.md`

## Checklist Completion

- [ ] All pre-deployment tasks completed
- [ ] All deployment steps executed
- [ ] All post-deployment tests passed
- [ ] Monitoring in place
- [ ] Rollback plan documented
- [ ] Maintenance tasks scheduled

**Status**: Ready for deployment ✅
