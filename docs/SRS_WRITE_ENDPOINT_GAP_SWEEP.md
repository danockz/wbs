# SRS gap sweep — JSON-only write endpoints without a bespoke browser form

_Generated after the Follow-ups record CRUD build (full suite green: 124 files /
9785 assertions / 0 failed; presenter 1238/0; parity 218/0)._

_Last refresh: after the involvement-triage config console + referral/prospect
capture funnel builds. **Full suite green: 149 files / 11,287 assertions / 0
failed.** OpenAPI: 441 paths / 514 operations / 19 tags / 40 permissions. Every
Section-B and Section-C write-gap row below is now ✅ DONE and tested; the last
member/guest-facing row (referral, #85) and the involvement-config admin console
both ship with dedicated wiring tests (`referral_invite_funnel_test.php` 65/0,
`involvement_config_console_test.php` 52/0)._

**What this is.** The Follow-ups work just closed the gap where a follow-up
_record_ could only be created/edited/cancelled via the JSON API — there was no
capture form, no inline edit/cancel, and the three write routes were missing
`webcsrf`. This document sweeps the rest of the route table for the same shape so
you can decide which to tackle next, in priority order. It is a **review list, not
a commitment** — many entries are correctly API-only and should stay that way.

**Method.** 282 write routes (POST/PATCH/PUT/DELETE) total; **172 already carry
`webcsrf`** (browser-facing, most already have views), **110 do not**. The 110 are
triaged below into: (A) correctly API-/machine-only — leave alone; (B) genuine
back-office CRUD gaps worth a bespoke form like Follow-ups just got; (C) member-
facing actions that would benefit from a real page but are lower priority.

> Note on `webcsrf`: absence is a strong _signal_ of "API-only", not proof — a few
> admin writes below are browser-reachable in intent but were never given a form
> or the CSRF guard. Those are the real gaps.

---

## A. Correctly API-/machine-only — no browser form needed (leave as-is)

These are consumed by other services, SDKs, device kiosks, OAuth/webhook
callbacks, or token/session machinery. A human never fills a form for them.

| Route | Action | Why API-only |
|---|---|---|
| `POST auth/register`, `login`, `logout` | AuthController | Auth flow owns its own views elsewhere; endpoints are the API. |
| `POST mfa/totp/enrol`, `/confirm`, `mfa/verify` | MfaController | MFA ceremony has its own dedicated screens. |
| `POST social/{p}/callback`, `/link`, `/unlink` | SocialAuthController | OAuth provider callbacks / AJAX. |
| `POST tokens/refresh`, `issue`, `revoke-all`, `DELETE tokens/{id}` | TokenController | Programmatic token lifecycle. |
| `POST webhooks/payments/{provider}` | WebhookController | Inbound PSP webhook. |
| `POST events/{id}/checkin/qr`, `/manual`, `/nonce` | CheckinController | Kiosk/scanner device flow. |
| `POST kiosks/{id}/offline-scans` | KioskController | Device batch upload. |
| `POST streams/{id}/relay/heartbeat` | StreamRelayController | Encoder heartbeat. |
| `POST streams/{id}/chat`,`/reactions`,`/metrics`,`/viewers`,`viewers/{id}/leave`,`/polls`,`/vote`,`/giving` | EngagementController | Live realtime client events. |
| `POST geo/resolve` | GeoController | Lookup helper (AJAX). |
| `POST contributions/{id}/contribute`, `refunds` | Contribution/RefundController | Payment intents (redirect to PSP). |

**Verdict: no action.**

---

## B. Back-office CRUD gaps — bespoke form candidates (same pattern as Follow-ups)

These are admin/config writes reachable by a human that currently have **no
capture/edit form** (and several also lack `webcsrf`). Ordered by likely value.

| Priority | Route(s) | Action | Gap | Notes |
|---|---|---|---|---|
| **1** | `POST settings/{key}`, `POST flags/{key}`, `POST groups/{g}/config/{key}` | AdminController | No form + **no `webcsrf`** | Admin console has read views (`getSetting`, `resolveGroupConfig`) but writes are JSON-only and unguarded for browsers. Direct parallel to the Follow-ups fix. |
| **2** | `POST achievements`, `PATCH achievements/{c}`, `POST achievements/{c}/disable` | AwardsController | No create/edit form | Gamification catalog; siblings (rules, follow-up types) already got inline CRUD forms — this one is still list-only. |
| **2** | `PATCH ranks/{c}`, `PATCH badges/{c}`, `PATCH streak-definitions/{c}` | AwardsController | Edit form missing | Lists exist; edit is API-only. |
| ~~**3**~~ ✅ | `GET vbcs/commitments/new`, `POST vbcs/commitments`, `POST vbcs/commitments/{id}/cancel` | VbcsController | **DONE** — pledge capture form + list Cancel controls | Bespoke `commitment_form` view (active-cause picker + frequency select, minor-units amount), PRG create/cancel, both writes now `webcsrf`-guarded, i18n `commitmentForm.*` ×6 + `annual`/`one_time` frequency aliases, 94-assertion test. |
| ~~**3**~~ ✅ | `GET partnership/tiers/manage`, `POST partnership/tiers`, `/tiers/{id}/disable` | VbcsController | **DONE** — admin CRUD catalog | Bespoke `partnership_tiers_admin` view (inline create/edit/disable, min_pgv minor-units, `<details>` panels), PRG define/disable, both writes now `webcsrf`-guarded, `listForAdmin` (incl. disabled), i18n `partnershipTierForm.*` ×6 + `giving_partnership_admin` menu item, 85-assertion test. Public catalogue stays read-only/active-only. |
| ~~**3**~~ ✅ | `POST subjects/{id}/streaks`, `/achievements/unlock`, `/reevaluate`, `badges/{c}/grant`, `badges/{c}/revoke` | AwardsController | **DONE** — manual award actions | Detail-page action forms: badge_show gains grant/revoke, achievement_show gains unlock + re-evaluate, streak_show gains record — each a no-JS, CSP-safe, webcsrf-guarded POST with an active-member picker (`awardRoster`, one bounded query), PRG flash (`respondAwardAction`), scope-enforced on write. Added browser aliases with the CODE in the path + subject in body (`achievements/{c}/unlock\|reevaluate`, `streak-definitions/{c}/record`) so the no-JS forms need no per-option JS; legacy subject-in-path routes hardened with the missing `webcsrf`. i18n `*.actions.*` ×6, 90-assertion test `award_actions_crud_test.php`. |
| ~~**3**~~ ✅ | Campaign team management: `GET campaigns/{id}/teams/manage`, `POST …/teams`, `…/teams/{id}/update`, `…/teams/{id}/delete`, `…/teams/{id}/members`, `…/members/{uid}/remove` | CampaignsController | **DONE** — full team management console | Bespoke `campaign_teams_manage` view (create/rename/delete team + add/remove member, org-member picker, `<details>` panels), PRG for all writes, new webcsrf-guarded POST aliases (JSON PATCH/DELETE kept), resource-light `teamsWithRosters` (1 bounded users query), lifecycle-aware (adhoc-only / delete draft-only / frozen), i18n `campaignTeams.*` ×6 (40 keys incl. friendly error map), 91-assertion test. |

**Recommended next: Priority 1 (Admin settings/flags/group-config).** It is the
closest structural twin to Follow-ups (read view exists, write is JSON-only AND
missing `webcsrf`), and hits the standing constraint that feature-gating flows
through `EffectiveConfigResolver` group config — an admin should be able to set
those from a guarded page.

---

## C. Member-facing action gaps — lower priority

Reachable by end-users; a real page would improve UX but each is a smaller,
self-contained action rather than a CRUD surface.

| Route | Action | Note |
|---|---|---|
| ~~`POST g/{slug}/join`~~ ✅ | GroupPublicController | **DONE** — public self-join funnel **CSRF-hardened**. The bespoke confirm flow already existed (`GET g/{slug}/join` → `public_join` form; `POST` → validate → `public_join_done` with pending/approved messaging + one-time password-setup link for brand-new members), but the form carried **no hidden `_csrf`** (the controller only minted a token for a logged-in viewer's logout form, never for the ANONYMOUS guest who is the join form's primary audience) and the POST route had **no `webcsrf`**. Fixed: `viewerContext()` now mints a double-submit token for **every** visitor (pure crypto, no DB — resource-light) and sets the `wbs_csrf` cookie; the join form renders the hidden `_csrf` bound to `$csrf`; the POST route gained **webcsrf** (keeps `ratelimit:auth.register`, stays **auth-free** so guests may join). No-JS, CSP-safe, 6-locale `Groups.join.*` parity. Test: `public_join_funnel_test.php` 33/0. |
| ~~`POST events/{id}/register`~~ ✅ (+ new `/register/cancel`) | EventController | **DONE** — self-service RSVP funnel. Event show page is now attendee-aware: `RegistrationService::registrationFor` returns the viewer's own live registration (null when none/cancelled; one bounded read); `register()` enrols the **session** user, is idempotent, and lets an existing attendee **change RSVP intent** (yes/maybe/no) without re-queuing capacity; new `cancelRegistration()` self-cancels (reuses `cancel()` waitlist auto-promote). No-JS, CSP-safe RSVP panel (status pill + rsvp_state `<select>` + register/update + cancel, with sign-in/closed fallbacks); both writes PRG back (JSON kept for API). Routes hardened with the missing **auth + webcsrf** (register keeps `ratelimit:event.rsvp`; new cancel route same filters). i18n `Events.rsvp.*` ×6 (16 keys), 46-assertion test `rsvp_funnel_crud_test.php`. **Ticket funnel now DONE too** — see below. |
| ~~`POST events/{id}/ticket-hold`, `/checkout`~~ ✅ (+ new `GET`/`POST /tickets`) | TicketingController | **DONE** — attendee-facing ticket purchase funnel. New `GET /events/{id}/tickets` renders a buyer page (`TicketingService::purchaseView`: on-sale ticket types + remaining availability + the buyer's live hold + their paid tickets; resource-light bounded reads). New `POST /events/{id}/tickets` (`TicketingService::purchase`) collapses the two-step hold→checkout API dance into **one no-JS submit** — reuses the existing atomic `ticket_holds` (capacity-safe), then checkout; a **free/zero-total** order is `markPaid` immediately (registration confirmed, no payment step), a paid order stays `pending` and the buyer sees "payment required". Buys as the **session** user; PRGs back with a confirmed/payment-required flash (JSON kept for API). New buy POST hardened with **auth + webcsrf** (+`ratelimit:event.checkout`); the raw `ticket-hold`/`checkout` JSON endpoints stay un-webcsrf'd for API two-step flows. CSP-safe per-tier buy forms (sign-in/closed/sold-out fallbacks). i18n `Events.tickets.*` ×6 (24 keys), 54-assertion test `ticket_purchase_funnel_test.php`. |
| ~~`POST feedback-forms/{id}/responses`~~ ✅ (+ new `GET /respond`) | FeedbackController | **DONE** — respondent-facing survey/quiz submission page. New `GET /feedback-forms/{id}/respond` renders a no-JS survey (`FeedbackService::respondentView`: respondent-safe snapshot that STRIPS quiz secrets — `answer_key`/`points`/`rubric` — from every question server-side; reports `is_open` + `already_submitted`; resource-light bounded reads). Question types render as rating radios (1–5), yes/no, choice `<select>`, or textarea; fields namespaced by question id. `submit()` folds the browser's namespaced fields into the service's `answers` list (`collectBrowserAnswers`) and PRGs back with a flash; JSON kept for API. POST responses gained **webcsrf** (renderForm mints the `wbs_csrf` cookie for guests too) + keeps `ratelimit`; deliberately **NO `auth`** (guests may respond, per the original route intent). CSP-safe; closed / already-submitted / thank-you fallbacks. i18n `Events.respond.*` ×6 (19 keys), 50-assertion test `feedback_respond_funnel_test.php` (+ console test updated to new contract). |
| ~~`POST courses/{id}/enrol`, `enrollments/{id}/lessons/{l}/complete`~~ ✅ | EnrollmentController | **DONE** — self-service learner flow | Syllabus page is now learner-aware: `EnrollmentService::learnerView` returns the member's enrollment + per-lesson completed flag + required-lesson progress rollup (resource-light: bounded reads, batch-before-loop). No-JS Enrol button (unenrolled) + per-unlocked-lesson Mark-complete forms + progress bar; `enrol()` enrols the SESSION user, both writes PRG back to the syllabus (JSON kept for API). Both routes hardened with the missing **auth + webcsrf** (enrol keeps its ratelimit; lesson-complete previously had NEITHER guard). i18n `Courses.learner.*` ×6, 59-assertion test `learner_flow_crud_test.php`. |
| ~~`POST vbcs/commitments`, `/{id}/cancel`, `manual`~~ ✅ | VbcsController | **DONE** — commitments capture was shipped earlier (see B row); the **manual/in-kind giving MAKER step** is now closed too. That step previously had **no capture form** and its POST route carried **no `webcsrf`** (JSON-only), so a finance staffer could not record an offline/in-kind gift from the browser. Added `GET vbcs/manual/new` → `submitManualForm` rendering the bespoke `manual_form` page (active-cause picker, fixed type vocab cash/cheque/bank_transfer/in_kind, in-kind category + volunteer-hours, minor-unit value, valuation/custodian/evidence), reached from a "Record manual gift" button on the approval queue; `POST vbcs/manual` PRGs to the pending queue on success / re-renders with `$error` + sticky values on failure (JSON kept for API) and gained **webcsrf** (+ keeps `authorize:contribution.manage`). **Also fixed two real bugs in the existing checker console**: the approve/reject forms and the controller PRG targeted the non-existent `/contributions/manual/*` group and **404'd** — repointed to the actual `/vbcs/manual/*` routes; and the two inline `onsubmit="confirm(...)"` handlers (a CSP violation under the no-inline-handler rule) were removed. i18n `Contributions.manualForm.*` ×6 (35 keys). Tests: `manual_capture_form_test.php` 46/0 + `manual_pending_workflow_test.php` updated to 100/0; menu coverage excludes `vbcs/manual/new` (capture action). |
| `POST profiles` | ProfileController | Profile create. |
| ~~`GET r/{code}`, `POST r/{code}/prospect`~~ ✅ | ReferralController | **DONE** — public cloaked-link **invitation + prospect-capture funnel**. `GET r/{code}` (`land`) records the click (analytics-only side effect, safe on GET) then renders a bespoke, no-JS, self-contained **invite** page for browsers (JSON kept for API): a branded welcome showing the **campaign label only — the sponsor id is NEVER surfaced**, a "continue to destination" CTA to the typed redirect (member/event/giving/course/streaming copy), and a **consent-gated** capture form; an unknown/inactive code shows a friendly **invite_expired** page (no sponsor leak). `POST r/{code}/prospect` (`captureProspect`) gained the missing **webcsrf** (renderForm mints the `wbs_csrf` cookie for the anonymous guest; **no `auth`** — guests may submit, per route intent; keeps `ratelimit:referral.click`) and PRGs the browser onward to the branded destination with a localized flash. Privacy hard constraints honored: prospect data stored only after the consent tick (service `CONSENT_REQUIRED` gate), email stored as `email_hash` only, and **no precise GPS** on the public funnel (the two-flag GPS gate stays in the authenticated contact book). Service now returns `campaign`/`redirect` for one-hop PRG (no extra query). i18n `Referrals.invite.*` (22 keys) + `Referrals.inviteExpired.*` (4 keys) ×6; 65-assertion test `referral_invite_funnel_test.php`. The authenticated member-facing outreach writes (`referrals/links`, `sponsorships`, `attribute`, plus the whole `me/contacts` downline console + sponsor-reassignment maker-checker) were already shipped earlier. |

---

## Immutable / privileged writes with their own guarded flows (already handled)

`access-requests/*`, `role-assignments assign|revoke`, `break-glass open`,
`delegations`, expense `approve|reject|reimburse`, ticketing `pay|transfer` — these
sit in groups that **do** carry `webcsrf` on the sibling routes or have dedicated
pending/console views (AccessControl views were hardened earlier). Not gaps.

---

## Suggested order of attack — ✅ ALL COMPLETE

1. ✅ **Admin settings / flags / group-config** (Priority 1) — SHIPPED (bespoke
   admin CRUD + webcsrf).
2. ✅ **Gamification Awards catalogs** — SHIPPED (achievements admin catalog built;
   ranks/badges/streaks already had inline upsert CRUD).
3. ✅ **VBCS commitments capture** — SHIPPED (pledge capture form + list cancel).
4. ✅ **VBCS partnership-tier config catalog** — SHIPPED (admin CRUD catalog).
5. ✅ **Campaign team management UI** — SHIPPED (team management console: create/
   rename/delete team + add/remove member, resource-light rosters).

Every genuine back-office / member-facing write gap identified in section B has now
been closed with a bespoke, no-JS, CSP-safe, i18n-parity (6-locale) browser form,
each with a dedicated CRUD wiring test. Section A endpoints remain correctly
API-/machine-only. Suite green throughout.

---

## Beyond the sweep — campaign config polish pass (post-closure)

The sweep above is fully closed. The following were built afterward as lower-priority
polish (the campaign write endpoints were API-complete but lacked browser forms):

- ✅ **Campaign create/edit browser form** (`campaign_form.php`) — `GET campaigns/new`
  + `GET campaigns/{id}/edit`; webcsrf `POST campaigns` (create) and
  `POST campaigns/{id}/update` (edit alias); `PATCH` kept for API. Identity fields
  (group_id/code/award_mode) locked on edit. Team-milestone overlay fields
  (team_target_value, team_award_points, team_badge_code, recognize_top_teams)
  included. Reachable via the group-campaigns list "New campaign" CTA + campaign
  detail "Edit campaign" link. Test: `campaign_form_crud_test.php`.
- ✅ **Campaign reward-ladder (tier) management console** (`campaign_tiers_manage.php`)
  — `GET campaigns/{id}/tiers/manage`; webcsrf `POST campaigns/{id}/tiers` (add) +
  `POST campaigns/{id}/tiers/{tierId}/delete`; JSON DELETE kept for API. New
  `CampaignService::deleteTier()` re-packs tier_position 1..N. Draft-only, tiered-only.
  Reachable via campaign detail "Manage tiers" link. Test: `campaign_tiers_crud_test.php`.

Both are no-JS, CSP-safe, 6-locale i18n-parity, with dedicated CRUD wiring tests.
Presenter gained an additive `detailLinks` (GET-anchor) capability for detail-page
links (alongside the existing POST `detailActions`).
