# WBS Platform — Add-on: Bespoke sponsorship module views (2026-09-10)

**Scope:** two browser-facing module views that were missing, closing the
"browser never sees raw JSON" gap for the sponsorship/leadership feature. No
schema changes, no new dependencies, no new routes. Builds on
`wbs-fixes-2026-10-14.zip`; overlay onto that tree with `unzip -o`.

Follows the chained-leadership seeder shipped earlier today
(`wbs-fixes-2026-09-10-chained-seeder.zip`): that populated the leadership spine;
these give the sponsorship surfaces a proper UI.

---

## The gap

The Referrals module already had a bespoke view for the **downline** birds-eye
(`contacts_index.php`, hot/warm/cold triage). But the **upline / leadership**
surfaces were JSON-only:

- `GET /referrals/chain/{memberId}` (`ReferralController::chain`) — the
  sponsorship spine — returned raw JSON to browsers.
- The sponsor-reassignment maker–checker workflow
  (`SponsorReassignmentController`, FR-MEM-002) — submit / pending / approve /
  reject / cancel — was JSON-only, with no page to drive it.

## What's added

### 1 — Sponsorship chain view (`Referrals/Views/chain.php`)

A read-only view of the upline spine: the member at the top, then each sponsor
nearest-first up to the **Top of chain** (national/root leader), mirroring
`SponsorshipService::upline()`. Badges call out the **Nearest sponsor**, the
**Top of chain**, and each intermediate **Level**. Empty state when the member is
already at the top (sponsor-less). The service is PII-free by design, so member
IDs render verbatim (escaped) — no names/emails.

Wired via `ReferralController::chain()` — browsers get the view, API clients
(Accept: application/json / XHR / Bearer) still get the exact same JSON.

### 2 — Sponsor-reassignment dashboard (`Referrals/Views/reassign_index.php`)

The maker–checker workspace: a list of requests awaiting the current user as
checker (member / current sponsor / proposed sponsor / reason / status /
eligibility / downline-impact), plus the maker form to open a new request.
Pending rows show **Approve / Reject / Cancel** actions; non-pending rows hide
them (the PDP still enforces `sponsor.reassign.approve` and blocks self-approval
server-side — a note states this on the page).

Wired via `SponsorReassignmentController::pending()` — browsers get the view with
a freshly minted double-submit **CSRF** token + matching `wbs_csrf` cookie
(HttpOnly, SameSite=Lax, Secure over HTTPS), so the action forms pass the
`webcsrf` guard. The mutation endpoints (`submit`/`approve`/`reject`/`cancel`)
now **post-redirect-get** back to the dashboard for browsers while returning JSON
to API clients — a refresh never re-submits, and no raw JSON is shown.

## Conventions honoured

- **Self-contained pages** (own `<html>`) that `include _locale.php` for a
  locale-aware `<html lang dir>` — **RTL for Arabic** — exactly like
  `contacts_index.php` / the Groups public pages.
- **Fully localized** via `lang('Referrals.chain.*' / 'Referrals.reassign.*')`
  with **English fallback**; `{0}` counts interpolated in PHP via the `$li()`
  helper and singular/plural chosen in PHP (no ICU runtime dependency).
- **Fixed vocabularies** (request status, eligibility) localized with a
  **raw-value fallback** so an unknown value never breaks the page; free-form
  data (ids, reasons) stays verbatim and escaped.
- **Inline styles only** — renders in the sandboxed in-app preview too.

---

## Files

**New views**
- `app/Modules/Referrals/Views/chain.php`
- `app/Modules/Referrals/Views/reassign_index.php`

**Controllers (view wiring; JSON behaviour for API unchanged)**
- `app/Modules/Referrals/Controllers/ReferralController.php`
- `app/Modules/Referrals/Controllers/SponsorReassignmentController.php`

**Localization (`chain.*` + `reassign.*` groups added, all 6 locales, parity-clean)**
- `app/Modules/Referrals/Language/{en,fr,es,pt,zh,ar}/Referrals.php`

**Test**
- `app/Modules/Referrals/Views/tests/referrals_i18n_test.php` — extended to cover
  both new views: key completeness + parity, self-contained locale wiring, no
  bare English in the templates, and render-smoke in fr (LTR) + ar (RTL) —
  translated headings/labels/buttons, `{0}` interpolation, singular/plural,
  status/eligibility fallback, nearest-first ordering, CSRF tokens present in all
  action forms, correct POST action URLs, non-pending hiding actions, and
  verbatim/escaped ids & reasons.

## Verify

```
php app/Modules/Referrals/Views/tests/referrals_i18n_test.php   # 90 passed, 0 failed
php tests/run-standalone.php                                     # 33 files, 1463 assertions, 0 failed
```
