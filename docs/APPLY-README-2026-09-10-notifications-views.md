# WBS Platform — Bespoke views: Notifications module (2026-09-10)

**Scope:** the sixth of the seven zero-view modules. Adds **one read-only route**
(a campaigns index) so the module has a browsable landing page; no schema changes,
no new dependencies. Overlay onto the working tree with `unzip -o`.

## What's added

Notifications was a pure-mutation module (create/submit/approve) with no browser
landing page. It now has a **broadcast campaigns** dashboard: the organization's
message campaigns with channel, status in the approval lifecycle, priority, and
frozen audience count.

- New read endpoint `GET /notifications/campaigns` →
  `CampaignController::index()` (browsers get the bespoke view; API clients get
  JSON), backed by a new read-only `CampaignService::list()`.
- Registered in the dynamic menu as **Communications → Broadcast campaigns**
  (`comms.campaigns`), so the coverage guard stays satisfied and the item is
  localized in all six locales.
- `public/openapi.json` regenerated to include the new route (378 paths / 436
  operations); the freshness guard passes.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new
  `Notifications/Views/_locale.php` for a locale-aware `<html lang dir>` —
  **RTL for Arabic**.
- **Localized** via `lang('Notifications.*')` with **English fallback**; the `{0}`
  count is interpolated in PHP via `$li()` with PHP singular/plural.
- `status` and `priority` are fixed vocabularies localized with a **raw-value
  fallback**; name/template/channel/audience are server data shown verbatim
  (channel humanized in-view).
- **Inline styles only** — renders in the sandboxed in-app preview too.

## Files

**New**
- `app/Modules/Notifications/Views/campaigns.php`
- `app/Modules/Notifications/Views/_locale.php`
- `app/Modules/Notifications/Views/tests/notifications_campaigns_view_test.php`
- `app/Modules/Notifications/Language/{en,fr,es,pt,zh,ar}/Notifications.php`

**Changed**
- `app/Modules/Notifications/Controllers/CampaignController.php` (new `index`; JSON unchanged for mutations)
- `app/Modules/Notifications/Services/CampaignService.php` (new read-only `list`)
- `app/Config/Routes.php` (new `GET notifications/campaigns`)
- `app/Modules/Shared/Navigation/CoreMenuProvider.php` (new `comms.campaigns` MenuItem)
- `app/Language/{en,fr,es,pt,zh,ar}/App.php` (menu label `comms_campaigns`)
- `public/openapi.json` (regenerated)

## Verify

```
php app/Modules/Notifications/Views/tests/notifications_campaigns_view_test.php   # 34 passed, 0 failed
php tests/run-standalone.php                                                       # 39 files, 1721 assertions, 0 failed
```
