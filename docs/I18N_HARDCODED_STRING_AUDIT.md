# i18n audit — hardcoded strings reaching the UI (2026-09-16)

Triggered by the member dashboard showing English milestone labels ("1-year
member", "Attended 1 event", …) inside an otherwise-French page.

## Fixed now

- **Member-dashboard milestone labels** (`MemberDashboardService` built the label
  text as hardcoded English closures; the view printed it verbatim). Now the
  view localizes from the milestone's `key` + `tier` via
  `Reporting.member.ms.*` (with `_one`/`_other` plural variants) across all six
  locales, keeping the English `label` as the guaranteed fallback. The service
  still emits the English label so JSON API responses and any un-translated
  locale degrade gracefully.

## Fixed later (2026-09-21) — error pages

Found while fixing "browsers are shown raw JSON": every user-facing failure page
is now localized in all six locales.

- **The four request filters** (`auth` 401, `authorize` 403/401, `webcsrf` 403,
  `ratelimit` 429) answered ALL clients with `application/problem+json`, so a
  member whose session expired mid-browsing was shown
  `{"type":"about:blank","title":"UNAUTHENTICATED",…}`. They now negotiate
  (`ProblemResponder`): API clients keep the identical envelope, browsers get
  `Shared/Views/error_page` with copy from `App.err.*`.
- **`RateLimitFilter`'s inline HTML** was English-only, off-theme (light card,
  system font) and used a `javascript:history.back()` link that CSP blocks. It is
  gone; the shared page renders the wait in the member's own language.
- **The stock CodeIgniter error templates** (`error_404.php`, `error_400.php`,
  `production.php`) were hardcoded `lang="en"`, light-themed and read
  `lang('Errors.pageNotFound')` from the framework's own English bundle. They now
  delegate to `ErrorPages` and render the same shared page (`App.err.notFound*`,
  `badRequest*`, `serverError*`). `error_exception.php` (development trace) is
  deliberately left stock — it is engineer-facing and never served in production.

## Reviewed and intentionally left as-is (NOT UI copy)

These hardcoded English strings are **data or diagnostics**, not localized
interface chrome, so they are correct to leave verbatim:

| Location | String kind | Why verbatim |
| --- | --- | --- |
| `ContributionService`, `ManualContributionService` | ledger `memo` | immutable audit/ledger record, not shown as UI chrome |
| `OrderRefundService` | refund `reason` | stored audit reason |
| `CampaignService` | point-ledger `explanation` | audit trail |
| `JourneySignalService` | proposal `reason` | audit trail |
| `GeoResolverService` | validation `detail` | JSON-API error detail (field-level; UI maps codes) |
| `MeetingService`, `StreamService`, `StreamRelayService` | operator `note`/`summary` | operational diagnostics for API/console, not member copy |

## Known gap — DB-seeded catalog content is English-only (NOT fixed here)

The gamification catalog tables — `badges.name`, `achievement_definitions.name`
/`description`, `rank_definitions.name` — are seeded English-only and have **no
`translations` JSON column**, even though the platform already has a DB-backed
translation provider (`Shared/I18n/Providers/JsonColumnProvider`) built for
exactly this shape ("`<prefix>.<id>` => translations[locale]`, falling back to
`name`"). Today these render English in a non-English UI once a member actually
earns a badge/achievement/rank (the sampled dashboard had 0, so it is not yet
visible).

Closing this properly is a separate, larger change:
1. add a nullable `translations JSON` column to `badges`,
   `achievement_definitions`, `rank_definitions` (guarded ADD COLUMN migration,
   with `resetDataCache()` per the migration-cache fix);
2. register a `JsonColumnProvider` for each in the translation registry;
3. have the seeders populate `translations` for the shipped catalog rows;
4. localize reads through the registry with English fallback.

Deferred pending a decision on whether admin-authored catalog rows should be
translatable in-app (which also implies an editor UI), tracked here so it is not
lost.
