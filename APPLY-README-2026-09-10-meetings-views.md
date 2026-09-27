# WBS Platform — Bespoke views: Meetings module (2026-09-10)

**Scope:** the seventh and final zero-view module. Adds **one read-only route**
(a meeting schedule index) so the module has a browsable landing page; no schema
changes, no new dependencies. Overlay onto the working tree with `unzip -o`.

## What's added

Meetings was a pure-mutation module (create / grant access / transition) with no
browser landing page. It now has a **meeting schedule** dashboard: the
organization's meetings and webinars with provider, time window, status, access
policy, and hosted join link.

- New read endpoint `GET /meetings` → `MeetingController::index()` (browsers get
  the bespoke view; API clients get JSON), backed by a new read-only
  `MeetingService::list()`. Gated `authorize:meeting.manage,any` (the permission
  in any scope), matching the item's `scopeCheck: 'any'`.
- Registered in the dynamic menu as **Events → Meeting schedule**
  (`events.meetings`), so the coverage + antidrift guards stay satisfied and the
  item is localized in all six locales.
- `public/openapi.json` regenerated to include the new route (378 paths / 437
  operations); the freshness guard passes.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new `Meetings/Views/_locale.php`
  for a locale-aware `<html lang dir>` — **RTL for Arabic**.
- **Localized** via `lang('Meetings.*')` with **English fallback**; the `{0}` count
  is interpolated in PHP via `$li()` with PHP singular/plural.
- `status`, `provider`, `mode` and `access` are fixed vocabularies localized with
  a **raw-value fallback**; title / times / join links are server data shown
  verbatim (times formatted defensively, `— UTC`).
- **Inline styles only** — renders in the sandboxed in-app preview too.

## Files

**New**
- `app/Modules/Meetings/Views/schedule.php`
- `app/Modules/Meetings/Views/_locale.php`
- `app/Modules/Meetings/Views/tests/meetings_schedule_view_test.php`
- `app/Modules/Meetings/Language/{en,fr,es,pt,zh,ar}/Meetings.php`

**Changed**
- `app/Modules/Meetings/Controllers/MeetingController.php` (new `index`; mutations unchanged)
- `app/Modules/Meetings/Services/MeetingService.php` (new read-only `list`)
- `app/Config/Routes.php` (new `GET meetings`)
- `app/Modules/Shared/Navigation/CoreMenuProvider.php` (new `events.meetings` MenuItem)
- `app/Language/{en,fr,es,pt,zh,ar}/App.php` (menu label `events_meetings`)
- `public/openapi.json` (regenerated)

## Verify

```
php app/Modules/Meetings/Views/tests/meetings_schedule_view_test.php   # 39 passed, 0 failed
php tests/run-standalone.php                                            # 40 files, 1774 assertions, 0 failed
```
