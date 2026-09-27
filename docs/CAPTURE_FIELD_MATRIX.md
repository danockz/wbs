# Capture-Path Field Matrix (onboarding field-sync)

**Decision (2026-09-24):** every path that writes a PERSON (prospect or user)
must write the same canonical field set — with one documented graded subset
(public cloaked-link landing) — `users` profile columns are justified in the
schema (migration `000092` COLUMN COMMENTs, mirrored below) and surfaced
read-only on `/me/profile`, and no browser flow may render JSON or raw
machine-key errors (central humanizer on every flash + data-page fallback).

**Follow-on decisions (2026-09-24, same round):**
1. integration decisions = **optional inputs on ALL capture paths**, gated by the
   hierarchical `referrals.integration_decisions` config extended with `capture_inputs`
   (see §E);
2. **temperature is automated** on contact creation (manual selector removed from the
   create form; follow-up triage still adjusts; bulk strips it; sweep decays);
3. the group location directory ships **full-world** reference states/cities (see §F).

Companion artifacts:

- Schema justification: `app/Modules/Identity/Database/Migrations/2026-09-24-000092_JustifyUserProfileColumns.php`
- Humanizer: `app/Modules/Shared/Support/Messages.php` (+ `BaseController::errText()`)
- Locks: `onboarding_field_sync_test`, `error_flash_humanization_test`, `referral_invite_funnel_test`, `profile_editor_test`, `catalog_parity_test`

---

## A. Prospect paths (`prospects` table)

Canonical insert set (from `ContactBookService::createContact`, the canonical
writer) + why each field differs (or does not) per path:

| Field | Member address book (`source=member`) | Staff bulk (`source=staff_bulk`) | Event guest (`source=event_invite_link`) | Cloaked landing (`source=link`) |
|---|---|---|---|---|
| `owner_user_id` | session user | session user | link sponsor (`created_by` of the invite link) | link's `referrer_id` |
| `created_by` | session user | session user | link sponsor | link's `referrer_id` |
| `assigned_group_id` | `placementFor(mentor)` | `placementFor(mentor)` + scope-bound | sponsor placement (`group_attribution` hint) | `placementFor(referrer)` — no choice |
| `source` | `member` | `staff_bulk` | `event_invite_link` | `link` (explicit; was implicit DB default `member`) |
| `full_name` / `display_name` | required (both cols) | required (both cols) | required (both cols) | required (both cols); **service gate** `contact.name_required` |
| `phone` | optional, as entered | optional (per row) | optional | **optional NEW** (graded subset: accepted in clear) |
| `email` (plaintext) | optional | optional | optional | **NEVER written** — graded subset (privacy + funnel test lock) |
| `email_hash` | when email present | when email present | when present | SHA-256 when present, else NULL |
| `consent` | checkbox `name="consent"` (**NEW** on the form; required in browser) | per row (0 when absent) | pass-through from guest form input (**NEW**) | hard gate `referral.consent_required` → stored `1` |
| `notes` | optional (staff annotation) | optional | none (self-service) → NULL | NULL |
| GPS + 2-flag consent | consent-gated | consent-gated | not collected → 0/NULL | not collected → 0/NULL (explicit) |
| `journey_stage` | form (default `prospect`) | form/row (default `prospect`) | DB default `prospect` | explicit `prospect` |
| `temperature` | **automated** — selector removed (2026-09-24): self-selecting → `warm` else `cold` | stripped when posted (automated) | `warm` (self-selecting) | `warm` (self-selecting — parity) |
| invite context / `link_id` | `invite_context_*` when invited | same | `event` + event id | `link_id` (its context) |
| `state` | `captured` | `captured` | `captured` (+ inactivity transfer) | `captured` |
| timestamps | `created_at`/`updated_at` | same | same | same (updated_at was missing → **fixed**) |

**Vocabulary:** `journey_stage` uses the JourneyStageSeeder canonical 8 —
`prospect, first_timer, new_believer, in_foundation, established, worker,
leader, sender`. `ContactBookController::STAGES` now matches exactly (the old
`foundation`/`member` values were never written by any seeder).

---

## B. User paths (`users` table)

Canonical writer = `AccountService::register`. All writers now write:

| Field | Self-register | Public group join (`findOrCreateUser`) | Contact→user (`ensureContactUser`) |
|---|---|---|---|
| `email` / `email_verified` | input / 0 | input / 0 | contact email or `{uuid}@contacts.invalid` / 0 |
| phone trio (`phone`, `phone_input`, `phone_region`) | normalized E.164 (policy region), invalid → 422 | **optional** — same normalizer/policy; invalid → 422 `group.join_invalid_phone` | **NEW** — contact's number, best-effort normalize (raw kept in `phone_input` even when E.164 fails) |
| `display_name` | input | input | contact full name |
| `locale` / `timezone` | input ?? **org `default_locale`/`timezone`** ?? en/UTC | **org defaults** ?? en/UTC | **org defaults** ?? en/UTC (was hardcoded `en`/`Africa/Accra`) |
| `status` | `pending_verification` | `pending_verification` | `pending_verification` |
| `status_reason` + `status_changed_at` | `registration` + t | **NEW** `group_join` + t | **NEW** `contact_promotion` + t |
| `account_state_transitions` row | yes | **NEW** yes | **NEW** yes |
| `mfa_enabled`, `updated_at` | explicit | **NEW** explicit | explicit |
| consents, DOB/is_minor/country | collected (policy) | NULL (not collected) | NULL (not collected) |
| password | optional (policy-checked) | NULL until set-password | NULL |

Justification for every `users` column (the schema COMMENTs from 000092):

| Class | Columns | Meaning |
|---|---|---|
| **SELF-SERVICE** | `display_name`, `locale`, `timezone`, `profile_photo_*` | editable on `/me/profile` / `/me/photo` |
| **VERIFICATION** | `email`, `email_verified`, `phone`, `phone_verified`, `phone_input`, `phone_region` | sign-in / verified identity — change = admin or re-verification flow; shown **read-only** on `/me/profile` with `Identity.profile.identityReadonlyNote` |
| **REGISTRATION** | `date_of_birth`, `is_minor`, `country_code` | captured once for age/jurisdiction policy; read-only on profile |
| **SECURITY** | `password_hash`, `mfa_enabled` | dedicated security flows only |
| **ADMIN** | `status`, `status_reason`, `status_changed_at`, `status_changed_by`, `merged_into_id`, `anonymized_at` | lifecycle; evidence in `account_state_transitions` |
| **SYSTEM** | `id`, `organization_id`, `last_login_at`, `created_at`, `updated_at`, `verify_reminded_at`, `verify_reminder_count` | platform bookkeeping |

---

## C. Error presentation contract (JSON-in-views sweep)

1. **Set-time humanization:** every `->with('error', …)` flash routes through
   `BaseController::errText()` → `Messages::humanize()`:
   existing catalog key → `lang()` translation; dotted machine key with no
   catalog → last segment prettified (`contact.name_required` → "Name
   required"); UPPER_SNAKE code → prettified; already-human copy passthrough.
2. **Family catalogs shipped ×6 locales** (en/fr/es/pt/zh/ar) for the
   browser-facing onboarding families: `contact`, `identity`, `group`,
   `journey`, `integration`, `event`, `referral`, `sponsorship`, `token`.
   Families not yet cataloged (gamification, acl, stream, …) still never render
   raw — the prettify fallback covers them.
3. **Silent PRG fixed:** contact-book create/followUp/decision/attend/bulk and
   integration declare/decide now flash failures instead of redirecting
   silently; the address book renders the flash banner.
4. **Data-page fallback** humanizes `detail`/`title` before render; API/JSON
   responses keep the raw key (machine contract unchanged).
5. `respondRegistration`, `profilePrg`, Campaigns `$friendly`, AdminController
   `lang()`-wraps and the public-join error list all go through the same
   helper.

---

## D. Public landing — the one graded subset

Kept minimal BY DECISION (hashed email + optional phone), never full parity:

- **present:** `display_name` (required), optional `phone`, SHA-256
  `email_hash`, hard `consent`, `source=link`, owner/created_by = referrer,
  mentor placement, stage/temperature/timestamps.
- **absent by design:** plaintext `email` (privacy — test-locked), GPS,
  notes, temperature default, invite-context columns (`link_id` plays that
  role).

---

## E. Decision inputs (onboarding, 2026-09-24)

Integration decisions are **optional inputs on every capture path**, offered only when the
hierarchical group config says so:

- **Config:** existing capability `referrals.integration_decisions` (default OFF) extended
  with a `capture_inputs` list (catalog types). Resolved through `EffectiveConfigResolver`
  on the **placement group** (member/staff add → prospect placement; event-guest → placement;
  landing → referrer placement or org default). Feature enabled **and** type listed → the
  input is rendered and accepted; otherwise nothing shows and a posted value is rejected
  (authenticated → 403 `integration.capture_input_disabled`; public paths → skipped
  silently, the capture itself is never blocked). Empty list = no inputs anywhere.
- **Does not gate** `/my/integration` self-declaration (available whenever the feature is on).
- **Paths & flavor:**

  | path | source | status on save | gate failure |
  |---|---|---|---|
  | member add-contact | `assisted` | **confirmed immediately** (`recorded_by` = actor) | 403 before insert |
  | staff bulk (scope-bound) | via createContact, same as above | confirmed | 403 before insert |
  | event-guest register | `event_guest` | **pending** until owner confirms | silent skip (capture wins) |
  | cloaked landing | `landing` | **pending** until owner confirms | silent skip (capture wins) |

- **Fields:** `decision_type` / `decision_date` / `decision_note` (note optional); bad or
  future dates rejected exactly like the existing decision form.
- **Views:** the form block renders only when the resolved list is non-empty
  (`$decisionInputs` from `ContactBookController::index` and `ReferralController::land`).
- **Locks:** `capture_decision_inputs_test` (source), `integration_service_test` §10 (behavior).

---

## F. Location directory (group locations, 2026-09-24)

Reference data for the Country → State/Region → City tree ships in `GeoReferenceSeeder`
(**full world**): `STATES` + `cities` for **every** one of the 18 reference countries —
387 states/regions, 408 cities, unique `state_code` per country, cities upserted on
country+state+name, orphans skipped, lat/lng NULL (venues remain the geo source of truth).
Group creation selects existing geo entities only (no free text, no demo venues).
Locks: `geo_reference_full_world_test` (integrity + Ghana 16 regions + run() wiring).

