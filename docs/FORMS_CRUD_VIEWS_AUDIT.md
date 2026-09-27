# Platform Audit — Forms, Fields, CRUD, Views

**Scope:** synchronous security, functionality, and data capture across every server-rendered view/form, plus a performance verdict on the attached **Velzon** admin template vs. the current architecture.
**Date:** 2026-09-12
**Method:** static inventory of `app/Modules/**/Views`, `app/Views`, `app/Config/Routes.php`, the CSRF/CSP/cookie config, and the shared page presenter — cross-checked against the live response headers you captured (`image-1.png`).

---

## 0. Executive verdict (read this first)

1. **Security posture is strong and consistent.** Of 94 view files carrying `<form>` (187 forms, 618 inputs), **only 2 have no `_csrf` field — both correct by design** (one anonymous rate-limited public form, one is a controller doc-comment false positive). The live headers confirm HttpOnly/Secure/SameSite=Lax cookies, `nosniff`, `X-Frame-Options: SAMEORIGIN`, correlation-id, and PRG redirects.
2. **The fastest page load is the one you already have.** The current views are **self-contained, zero-external-asset, inline-critical-CSS, no-JS-required** pages. That is *the* best-case pattern an algorithms/perf specialist would choose. **Adopting Velzon as-is would make pages slower and break the CSP.**
3. **Recommendation:** **Keep the presenter architecture. Do NOT adopt the Velzon runtime.** Harvest Velzon's *visual design* (spacing, color tokens, component look) into the presenter's inline token set. This gives you the template's polish with none of its 23-asset, jQuery-bound, CSP-violating cost.
4. **Real gaps to fix (small):** 5 data-capture forms lack HTML5 validation attributes; a few `<input type=text>` fields that are semantically email/number/date; no maxlength on some free-text notes. All are one-line hardening edits. Details in §4.

---

## 1. Inventory (what exists)

| Metric | Count |
|---|---|
| View files | 253 |
| Controller files | 73 |
| Views containing `<form>` | 94 |
| Total `<form>` tags | 187 |
| `<input>`/`<select>`/`<textarea>` tags | 618 |
| Views with a `_csrf` field | 136 |
| POST routes | 265 |
| POST routes guarded by `webcsrf` | 168 |
| PUT / PATCH / DELETE routes | 0 / 10 / 6 |
| Endpoints now on the shared presenter | 35 specs / 19 controllers |

**Read:** ~63% of POST routes are `webcsrf`-guarded (browser, cookie-auth, state-changing). The remaining ~37% are **token/JSON APIs** whose caller authenticates with a header credential, not the session cookie — CSRF does not apply to them (that is the correct, deliberate split enforced by `WebCsrfFilter::before`, which no-ops for non-cookie callers).

---

## 2. Security audit (synchronous)

### 2.1 CSRF — PASS (with 2 explained exceptions)
- **Mechanism:** `WebCsrfFilter` double-submit. A browser POST passes only when the `wbs_csrf` cookie equals the submitted `_csrf` field (`hash_equals`). The token is bound to the `wbs_session` cookie.
- **Field name is `_csrf` everywhere** (not the framework default `csrf_test_name`, not `webcsrf`). Every generated form uses `name="_csrf"`.
- **Forms with a `<form>` but no `_csrf` — 2, both correct:**
  - `Groups/Views/public_join.php` — **anonymous public** self-join form; the route is protected by `ratelimit:auth.register`, and there is no session to bind a token to. CSRF-exempt by design; abuse is bounded by rate-limiting + server-side validation.
  - `Shared/Controllers/LocaleController.php` — **false positive**; the "`<form`" match is inside a doc-comment, not markup. The actual locale form (in views) carries `_csrf`.
- **Presenter guarantee:** the shared presenter emits `name="_csrf"` for *every* write form and every detail/row action, so new pages inherit CSRF automatically. Verified by `page_presenter_test.php`.

### 2.2 Content-Security-Policy — PASS, and it is the deciding constraint
- Config: `script-src 'self'` (no `unsafe-inline`, no CDN), `style-src 'self' 'unsafe-inline'`, `img-src 'self'`, `form-action 'self'`, `frame-ancestors` locked, `CSPEnabled=true` in the real environment.
- **Consequence:** inline `<script>`, inline `on*=` handlers, and third-party CDN scripts are **blocked by the browser**. Current views comply because they ship **no runtime JS** and only inline `<style>`.
- The single canonical menu is the one deliberate exception: a same-origin `/assets/js/menu.js` under `script-src 'self'`, CSS-only toggle, no inline JS.

### 2.3 Transport / cookies / headers — PASS (confirmed live in `image-1.png`)
`Set-Cookie: wbs_session … Secure; HttpOnly; SameSite=Lax`, expired `wbs_csrf` rotated on login, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `X-Download-Options: noopen`, `Referrer-Policy: same-origin`, `X-Correlation-Id`, and a **303 PRG redirect** after POST login. `forceGlobalSecureRequests` + `CSPEnabled` on in the real host; `baseURL=https://public.test/`.

### 2.4 Output encoding — PASS
Every dynamic value in the presenter and audited views goes through `esc(…)` / `esc(…, 'attr')`. The presenter's `cell()` renderer escapes all data; only fixed, developer-authored SVG icons are emitted raw (safe).

### 2.5 Authorization — PASS (out of scope here, noted)
Visibility/authority is enforced by route `authorize:` filters + PDP, not by the view. Views are display-only. Presenter pages do not leak actions the route would deny (the button posting to a denied route simply gets a server-side denial + flash).

---

## 3. CRUD & functionality audit

### 3.1 CRUD coverage pattern
The platform splits cleanly into:
- **Bespoke management consoles** (ticketing, media-review, venues form, campaigns admin, gamification config, ABAC/rules, break-glass) — full C/R/U/D with dedicated views. **Healthy.**
- **Presenter pages** (35) — Read + targeted Write. Reads render as tables/detail cards; writes are added **only where a same-resource POST is `webcsrf`-guarded** (correct — see §5).

### 3.2 HTTP-verb reality for forms
- HTML forms can only `GET`/`POST`. The 10 `PATCH` + 6 `DELETE` routes are **JSON-API-only** and must *not* get HTML forms; browser "delete/disable" actions correctly use dedicated `POST …/delete` / `…/disable` routes. **Consistent.**

### 3.3 Presenter functional completeness
- List, detail (key/value card), facts strip, empty states, count (singular/plural), row→detail links, create-forms, and detail-level lifecycle actions — all localized (6 locales), all parity-checked. **Complete for the read+targeted-write mandate.**

---

## 4. Data-capture audit (findings + fixes)

**Server-side validation exists** (services validate every field and return typed `Result` failures). The gaps below are **client-side/UX hardening** — they reduce round-trips and improve capture quality, which *helps* perceived performance.

### 4.1 Data-capture forms missing HTML5 validation — 5 (fix recommended)
| View | Field(s) | Recommended attributes |
|---|---|---|
| `AccessControl/Views/break_glass_pending.php` | `notes` | `maxlength="500"` |
| `AccessControl/Views/break_glass_show.php` | `notes`, reason | `maxlength`, `required` where mandatory |
| `AccessControl/Views/access_requests_pending.php` | decision note | `maxlength` |
| `Integrations/Views/custom_adapters.php` | adapter fields | `required`, `maxlength`, `pattern` for class name |
| `Reporting/Views/exports.php` | selects | fine (fixed vocab) — low priority |

### 4.2 Semantic input types (quick wins)
Several `<input type="text">` fields capture emails / numbers / dates. Switching to `type="email|number|date"` + `inputmode` gives free client validation and better mobile keyboards with **zero JS**. (24 forms have *inputs*; only **5** are genuine data-capture forms lacking any validation — the other 19 are action/confirmation forms of hidden+submit only, which correctly need none.)

### 4.3 Presenter already models this
The presenter's field spec supports `type` (`text|number|email|textarea|select|checkbox`), `required`, and `full`. Extending §4.1/§4.2 is declarative — add `maxlength`/`pattern`/`min`/`max` to the field spec and render them. (Small presenter enhancement, one place, all pages benefit.)

---

## 5. The Velzon template — performance & fit analysis

You asked for "whatever facilitates faster page loading (best case for an expert algorithm designer)." Here is the honest engineering comparison.

### 5.1 What Velzon costs at runtime
| Dimension | Velzon (as attached) | Current architecture |
|---|---|---|
| CSS files over the wire | ~11 `<link>` (bootstrap, icons, app.min, custom, select2, multi.js, autocomplete, nouislider, pickr ×3) | **0** (inline critical CSS, ~4 KB) |
| JS files over the wire | ~16 `<script>` incl. **jQuery, DataTables, select2, cleave, pickr, nouislider, feather, simplebar, waves** | **0–1** (only the same-origin menu.js) |
| Third-party origins | `cdn.jsdelivr.net`, `code.jquery.com`, `cdn.datatables.net` | **0** |
| `app.min.css` weight | **~500 KB** (unminified source shown; ships large even minified) | ~4 KB inline per page |
| jQuery dependency | **Yes** (4 refs) | **None** (vanilla, CSP-safe) |
| Render-blocking requests | 20+ before first paint | **0** (single HTML doc) |
| Runtime JS init | `document.writeln` injects more scripts; data-attr plugin boot | **None** |

### 5.2 Why Velzon-as-runtime is the *slower* choice here
- **Request waterfall:** 20+ blocking CSS/JS fetches (many cross-origin ⇒ extra DNS+TLS) vs. **one** HTML document that paints immediately. For an algorithm designer, this is the dominant term: page load is bounded by the **critical-path request count and bytes**, and Velzon multiplies both.
- **CSP incompatibility (hard blocker):** Velzon relies on inline handlers, `document.writeln` script injection, and CDN scripts. Under `script-src 'self'` these are **blocked** — the template would be visually broken unless you weaken the CSP (a security regression) and add CDN origins.
- **jQuery + plugin tax:** parse/compile/execute of jQuery + DataTables + select2 on every page is CPU on the main thread the current pages simply never spend.
- **Cache reality:** the "shared bundle caches after first visit" argument is weak here — first paint still pays the tax, HTTP/2 multiplexing doesn't erase 500 KB of CSS parse, and self-contained pages are already trivially edge-cacheable (ETag/304) *whole*.

### 5.3 The best-case design (what to actually do)
**Keep the self-contained, inline-critical-CSS, zero-runtime-JS presenter.** It is already the textbook fast-path:
- 1 request, 1 document, immediate paint, no CSP compromise, works with JS disabled, RTL-correct, edge-cacheable.
- **Harvest Velzon's *look*, not its *load*:** lift its color tokens, spacing scale, border-radii, and component styling into a small shared **inline design-token block** used by the presenter + layout. You get Velzon's visual quality at ~4 KB, no jQuery, no CDN, no CSP change.
- If a specific page ever needs a rich widget (e.g., a big sortable table), add **one** progressively-enhanced, same-origin, dependency-free script for *that* page only — never a global 20-asset bundle.

**Optional micro-optimizations (all cheap, all keep the fast path):**
1. Extract the presenter's inline `<style>` into a single same-origin `presenter.css` served with a far-future cache + hash, **only if** measurement shows the ~4 KB inline repeat matters (it usually doesn't; inline wins first paint).
2. Emit `Link: rel=preload` only if you introduce any external asset (you currently have none — nothing to preload).
3. Keep `Cache-Control: no-store` for authenticated data pages (correct today); add ETag/304 for the menu tree (already designed).

---

## 6. Prioritized action list

| # | Action | Effort | Impact | Status |
|---|---|---|---|---|
| 1 | Add `maxlength`/`required`/semantic `type` to the **5** data-capture forms in §4.1 | XS | Better capture, fewer round-trips | ✅ DONE |
| 2 | Extend presenter field spec with `maxlength/pattern/min/max/inputmode`; render them | S | All future pages hardened in one place | ✅ DONE |
| 3 | Create a shared **inline design-token block** distilled from Velzon (colors/spacing/radius/typography) and apply to presenter + layout | S–M | Velzon's polish, none of its load cost | ✅ DONE |
| 4 | Do **not** wire Velzon's JS/CSS runtime or CDNs; keep CSP `script-src 'self'` | — | Preserves security + speed | ✅ HELD |
| 5 | (Optional) per-page progressive-enhancement script, same-origin, only where a rich widget is truly needed | M | Rich UX without global tax | ✅ DONE |

### 6.1 Implementation record (2026-09-12)

**(a) The 5 data-capture forms hardened** — all `php -l` clean:
- `AccessControl/Views/break_glass_pending.php` — `notes` → `maxlength="1000"` (matches service `substr(…,0,1000)`).
- `AccessControl/Views/break_glass_show.php` — both `notes` → `maxlength="1000"`.
- `AccessControl/Views/access_requests_pending.php` — `note` → `maxlength="500"`.
- `Integrations/Views/custom_adapters.php` — `impl_class` select → `required`.
- `Reporting/Views/exports.php` — `report_key` + `format` selects → `required`.

**(b) Single design-token source, platform-wide:**
- Token partial relocated to **`app/Modules/Shared/Views/_tokens.php`** (was presenter-local).
- Included by **both** `app/Views/layouts/app.php` **and** the shared presenter — one source of truth.
- Base layout core styles refactored onto `var(--wbs-*)` (surfaces, borders, radius, muted/faint text, warning, pill).
- Presenter field spec renders `maxlength/minlength/pattern/min/max/step/inputmode/placeholder/autocomplete` when declared; applied to the ticket-type create form (3-letter currency `pattern`, numeric price `min=0`, 500-char description).

**Verification:** presenter test **1166/0** (adds token single-source + validation-attr assertions); **full suite 122 files / 9566 passed / 0 failed.** Zero external assets / zero `<script>` in presenter output preserved; CSP unchanged.

**(5) Progressive table enhancement — the "rich UX without global tax" pattern:**
- New asset **`public/assets/js/table-enhance.js`** (~5 KB, IIFE, `'use strict'`, no jQuery/DataTables/CDN/eval). Adds instant client-side **column sort** (text/num/date, keyboard-accessible) + a **live filter box** (appears only when a table has ≥ `data-filter-min` rows).
- **Opt-in per page** via PageSpecs `'enhance' => true` (enabled on `gam_campaign_leaderboard`, `gam_campaign_team_standings`, `gam_group_campaigns`). Non-enhanced pages emit neither the wrapper nor the `<script>`.
- **No-JS-safe:** the server table is complete and usable on its own; the script only upgrades it. Sort keys come from `data-sort-value` (raw value), so sorting is locale-/format-independent.
- **CSP-safe:** loaded as a same-origin `<script src … defer>` under `script-src 'self'` — no inline handlers. New i18n key `Pages.common.filter` (+ `sortBy`) added ×6 locales.
- **Global tax = zero:** pages that don't opt in ship exactly as before (0 JS).

**Note on the in-app preview:** the preview iframe is `sandbox="allow-scripts"` with no network, so it cannot fetch the external `/assets/js/table-enhance.js`. `docs/preview_table_enhance_demo.html` is a **demo build with the identical script inlined** so the sort/filter is clickable in-app; the real page keeps the external `src=` for CSP + caching.

**Verification (this step):** presenter test **1182/0** (adds enhance-markup, no-enhance-omission, and asset-hygiene assertions); catalog parity **218/0**; **full suite 122 files / 9582 passed / 0 failed.** Node `--check` confirms the JS parses.

**(5b) Enhancement rollout + CSV export / copy — extending the same layer:**
- **Rollout:** `enhance` now enabled on **23 list pages** (20 substantive multi-row lists added: MFA factors, tokens, memberships, journey ×4, geo places, venues ×2, ticket types, media, stream acks, follow-ups ×3, badges, campaign tiers/teams, integration fallback ×2). Deliberately **skipped** the 1–2-column stats/aggregate/allowlist tables (`*_stats`, `integration_adapter_allowlist`, `referral_*_analytics`) where sort/filter add nothing.
- **CSV export + copy-to-clipboard** added to `table-enhance.js` (now ~9.7 KB): both operate **purely on the in-page table** — no network, no new document — so they stay within CSP (`connect-src 'self'` untouched). Export uses a `Blob`/`URL.createObjectURL` download with a UTF-8 BOM; copy uses `navigator.clipboard` with an `execCommand` fallback.
- **Respects the active filter** (exports only visible rows) and **excludes the row-actions column** via `data-no-export` on its `<th>`. Export filename = the page id.
- New i18n keys `Pages.common.{exportCsv,copy,copied}` (+ earlier `filter`,`sortBy`) — all ×6 locales, parity green.

**Verification (5b):** presenter test **1219/0** (adds export/copy toolbar, `data-no-export`, and enhance-key parity assertions; repointed the "plain page" fixtures to a genuinely non-enhanced page); catalog parity **218/0**; **full suite 122 files / 9619 passed / 0 failed.** Node `--check` clean.

**(5c) Functional JS test + column-visibility & density toggles:**
- **Functional test (behaviour, not markup):** `table_enhance_functional.mjs` executes the REAL `table-enhance.js` against a ~120-line built-in DOM shim (no jsdom / no npm), asserting sort (num/text/keyboard), aria-sort state, live filter, CSV export (visible-rows-only, actions column excluded, raw sort-value data), copy-to-clipboard, single-row safety, and **idempotency**. Wrapped by `table_enhance_functional_test.php`, which runs `node` and folds the tally into the suite — and **skips gracefully (passes) if node is absent**, so the suite keeps its zero-dependency contract. **27 JS assertions.**
- **Idempotency hardening:** `enhance()` now marks its container `data-enhanced="1"` and no-ops on re-entry (found by the functional test — a real double-toolbar bug had the init hook fired twice).
- **Column visibility:** a "Columns ▾" popover with one checkbox per data column (actions column excluded) shows/hides whole columns.
- **Density toggle:** a "Compact" button flips `table.tbl-compact` (tighter padding/font) with `aria-pressed` state.
- Both are opt-in via the same wrapper (`data-columns`, `data-density`), localized (`Pages.common.{columns,density}` ×6), CSP-safe (pure DOM, no network), no-JS-safe (server table complete without them). Asset now ~12.5 KB.

**Verification (5c):** presenter test **1236/0**; functional JS **27/0**; catalog parity **218/0**; **full suite 123 files / 9640 passed / 0 failed.** Node `--check` clean.

---

## 7. What "synchronous security & data capture" looks like after this

- Every browser write: `_csrf` double-submit + server `Result` validation + PRG redirect + localized flash. (Already true; §4 tightens the client edge.)
- Every page: one document, no external assets, CSP-clean, RTL-aware, localized, JS-optional — the best-case load path.
- Velzon's **design language** adopted via inline tokens; Velzon's **runtime** declined on measured performance and CSP grounds.

*Appendix A — evidence commands and raw counts are reproducible from `app/Config/Routes.php`, `app/Config/ContentSecurityPolicy.php`, `WebCsrfFilter`, and the view tree; live headers per `uploads/image-1.png`.*

### 6.2 Deferred (agreed to do later)
- **localStorage persistence** of each user's column-visibility / density / sort choice, keyed by page id (no server burden).
- **Client-side pagination** for very large tables (still no-JS-safe).
- **Roll `enhance` onto remaining eligible list pages** in other modules once their views are reviewed.

## 7. Module CRUD/forms walkthrough — entity-reference pickers

**Goal:** replace raw entity-reference **free-text ID inputs** with **pickers backed by the relevant index table** (name → id), with a graceful bounded free-text fallback when no list is available and preservation of a previously-set (possibly archived) id. Server-side validation is unchanged; these improve data capture + correctness.

**Diagnostic:** `/tmp/formscan.php` (repeatable) flags `name="*_id"` free-text inputs, `<select>` with no `<option>`, and forms with data inputs but no validation. Findings by module drive this walkthrough.

### Reference-field backlog (from the scan)
| Module | View | Field(s) | Index table / source |
|---|---|---|---|
| Journey | member.php | `discipler_id` | group roster (`group_members`+`users`) — **DONE** |
| Streaming | giving_config.php | `cause_id` | `causes` (active) — **DONE** |
| Streaming | overlays.php | `user_id` | org roster — **DONE** |
| Events | checkin.php | `user_id`, `staff_id` | org roster — **DONE** |
| Events | logistics_plan.php | `user_id` | org roster — **DONE** |
| Groups | create.php | `parent_id` | groups tree (`listForOrg`) — **DONE** |
| Groups | crosscut_for_node.php | `crosscut_group_id` | groups — **DONE** |
| Groups | memberships_manage.php | `user_id` | users/roster — **DONE** |
| Groups | lifecycle_history.php | `survivor_id` | groups — **DONE** |
| AccessControl | delegations_received.php | `delegate_id`, `scope_group_id` | users, groups — **DONE** |
| AccessControl | rule_form.php | `scope_group_id` | groups — **DONE** |
| Referrals | contacts_index.php | `target_id` | events + courses — **DONE** |
| Referrals | reassign_index.php | `member_id`, `new_sponsor_id`, `approver_id` | org roster — **DONE** |
| Meetings | schedule.php | `user_id` | org roster — **DONE** |

### Completed
1. **Journey / member.php — `discipler_id`.** Controller now loads the member group's active roster (`GroupServices::memberships()->listForGroup(org, group, 'active')`, name+user_id) only when a group context exists; view renders a `<select>` (name→user_id) with a localized "defaults to you" option, and a bounded free-text fallback (`maxlength=64`) when the roster is empty. New key `Journey.admin.member.disciplerNone` ×6. Tests added to `journey_member_workflow_test.php` (roster select, option value=user_id, name shown, none option, empty fallback).
2. **Streaming / giving_config.php — `cause_id`.** Controller loads active `causes` (`ContributionServices::causes()->list(org, {status:active, limit:200})`); view renders a `<select>` (name→id) with the current value preselected, a localized "select a cause" option, **preservation of a stale/archived cause_id** as a selected option, and a bounded free-text fallback when no causes exist. New key `Streaming.givingConfig.causeNone` ×6. Tests added to `overlays_giving_console_test.php`.
3. **Groups / create.php — `parent_id`.** Controller (`createForm` + error re-render) loads `GroupServices::groups()->listForOrg(org)`; view renders a `<select>` (name→id) with **hierarchy indentation by `depth`** and a type-label suffix, a "top level" none option, current preselection, stale preservation, and a bounded text fallback. New key `Groups.createForm.parentNone` ×6. Tests added to `create_form_view_test.php`.
4. **AccessControl / rule_form.php — `scope_group_id`.** All four `renderForm` paths (create/edit form + create/update error) now pass `groups` (`listForOrg`); view renders an indented org-groups `<select>` with an "org-wide (no group)" none option, preselection, stale preservation, and bounded text fallback. New key `AccessControl.ruleForm.scopeGroupNone` ×6. Tests added to `accesscontrol_rule_crud_test.php`.
5. **AccessControl / delegations_received.php — `delegate_id` + `scope_group_id`.** `DelegationController::received` now passes `delegates` (`IdentityServices::accounts()->listMembers(org,'active')`) and `groups` (`listForOrg`); the inline re-delegate form renders a **person picker** (roster, excludes the subject themselves) and an indented **groups picker**, each with a none option and a bounded text fallback. New keys `AccessControl.delegationsRecvView.{fDelegateNone,fScopeGroupNone}` ×6. Tests added to `accesscontrol_delegations_received_view_test.php`.
6. **Groups / crosscut_for_node.php — `crosscut_group_id`.** `GroupCrosscutController::forNode` passes `groups` (`listForOrg`); the link form renders an indented groups `<select>` that **excludes the node itself and already-linked cross-cut ids**, with a none option and text fallback. New key `Groups.crosscutNode.linkNone` ×6. Tests added to `crosscut_console_test.php`.
7. **Groups / memberships_manage.php — `user_id`.** `GroupMembershipController::listForGroup` passes `roster` (`IdentityServices::accounts()->listMembers(org,'active')`); the add-member form renders a person `<select>` that **excludes users already on this roster**, with a none option and text fallback. New key `Groups.roster.fUserNone` ×6. Tests added to `roster_console_test.php`.
8. **Groups / lifecycle_history.php — `survivor_id`.** `GroupLifecycleController::history` passes `groups` (`listForOrg`); the merge form renders an indented groups `<select>` that **excludes the group being merged away**, with a none option and text fallback. New key `Groups.lifecycle.survivorNone` ×6. Tests added to `lifecycle_console_test.php`.
9. **Events / checkin.php — `user_id` + `staff_id`.** `CheckinController::checkinForm`/`checkinDispatch` (both render paths) pass `roster` (`IdentityServices::accounts()->listMembers(org,'active')`); a shared in-view `$personSelect` helper renders both fields as roster-backed person `<select>`s (attendee required, recorder optional) with current-value preselection, **stale/off-list id preserved as a selected option**, none option, and bounded text fallback. New keys `Events.checkin.{userNone,staffNone}` ×6. Tests added to `checkin_form_view_test.php`.
10. **Events / logistics_plan.php — `user_id` (assign-staff).** `LogisticsController::planConsole` passes `roster`; the assign-staff form renders a person `<select>` that **excludes users already on this event's staff roster**, with a none option and text fallback. New key `Events.logistics.userNone` ×6. Tests added to `logistics_console_test.php`.
11. **Referrals / reassign_index.php — `member_id` + `new_sponsor_id` + `approver_id`.** `SponsorReassignmentController::pending` passes `roster` (`IdentityServices::accounts()->listMembers(org,'active')`); a shared in-view `$personSelect` helper renders all three fields as roster-backed person `<select>`s (member/new-sponsor required, approver optional) with a none option and bounded text fallback. New key `Referrals.reassign.personNone` ×6. Tests added to `referrals_i18n_test.php`.
12. **Referrals / contacts_index.php — `target_id` (attend/register).** `ContactBookController::index` passes `events` (`Events…listForOrg`) + `courses` (`Courses…listForOrg`); the register form renders a **type-labelled `<optgroup>` picker** (Events / Courses → id) with a none option, falling back to bounded text when both lists are empty. New key `Referrals.eventCourseIdNone` ×6. Tests added to `referrals_i18n_test.php`.
13. **Meetings / schedule.php — `user_id` (grant access).** `MeetingController::index` passes `roster` (`IdentityServices::accounts()->listMembers(org,'active')`); the per-meeting grant form renders a person `<select>` with a none option and a bounded text fallback. New key `Meetings.admin.userIdNone` ×6. Tests added to `meetings_schedule_view_test.php`.
14. **Streaming / overlays.php — `user_id` (invite co-host).** `OverlayController::console` passes `roster`; the invite-co-host form renders a person `<select>` that **excludes users already invited/joined as co-hosts**, with a none option and bounded text fallback. New key `Streaming.overlaysConsole.userIdNone` ×6. Tests added to `overlays_giving_console_test.php`.

### Walkthrough COMPLETE ✅
All entity-reference free-text `_id` inputs found by the scan are now index-table pickers (with graceful bounded free-text fallbacks). **14 forms across 8 modules; ~18 entity-reference fields converted.** Every fix reused an existing index/roster service (no new tables or queries), applied contextual candidate exclusions where meaningful (self, already-linked, already-member/-assigned/-cohost), preserved stale/off-list ids as selected options, added a localized "none" key ×6 locales, and shipped regression assertions.

**Final verification:** catalog parity **218/0**; **full suite 123 files / 9726 passed / 0 failed.**
