# Integration decisions — the standalone dated decisions of the integration lifecycle

**Status:** ✅ Implemented 2026-09-22 · **default OFF** (hierarchical group capability `referrals.integration_decisions`).
**Schema:** `app/Modules/Referrals/Database/Migrations/2026-09-22-000091_ExtendProspectDecisions.php` — EXTENDS `prospect_decisions` (no new table; additive, idempotent).
**Support (pure):** `Referrals/Support/{IntegrationDecision,IntegrationConfig}.php`
**Service:** `Referrals/Services/IntegrationService.php`
**Ports/adapters:** `Referrals/Services/{IntegrationDecisionsPort,IntegrationDecisionsAdapter}.php` (Courses → decisions), `Journey/Services/IntegrationGatePort.php` + `Referrals/Services/IntegrationGateAdapter.php` (Journey gate).
**Controller / pages:** `Referrals/Controllers/IntegrationController.php` → `Referrals/Views/{integration,decisions_queue}.php`
**Tests:** `integration_decision_support_test.php` (39/0), `integration_service_test.php` (55/0), `Journey/Services/tests/integration_gate_test.php` (11/0), `integration_views_test.php` (41/0).

---

## 1. The requirement (SRS onboarding, FR-REF-3)

The integration lifecycle requires **four major user decisions, each with a date, EACH STANDING ALONE**:

1. **Salvation** — the decision for Christ (`salvation`).
2. **Water baptism** — `water_baptism`; its own group, its own date.
3. **Holy Spirit baptism** — `holy_spirit_baptism`; its own group, its own date (the two baptisms are **never bundled** — one never satisfies the other, and the old `baptism_mode` both/any knob is gone).
4. **Foundation course** — enrolling in the foundation/membership course (`foundation_course`).

> **Amendment (2026-09-23):** the baptisms were previously one combined `baptism` group. Per direct instruction they are now **separated so each stands alone**. A legacy config `required_groups: […, baptism, …]` is expanded by `IntegrationConfig::normalize()` to **both** standalone baptisms, so nobody silently loses a requirement.

They may **all happen in one day or on different dates**, at an **invitation**, an **event**, or **staff/member-assisted registration**. A decision is a dated, sourced, confirmable statement about a *person* — never just a free-text row about a contact.

## 2. What "required" means

A member/contact is **derived as `integrated`** only once all four standalone decisions carry a confirmed date. Until then the **Journey gate** refuses to advance them into a gated stage — default **In Foundation / Growth** and **Established** (the two stages that only make sense for an integrated person). Nothing else is blocked: capture forms, belonging, and course enrolment all proceed normally; the gate is advisory to everything except the stage move.

Everything is **config-gated and default OFF**: with no config row, there is no gate, no self-declaration capture, no derived row, and every transition behaves exactly as before.

## 3. Config (`referrals.integration_decisions`, `IntegrationConfig`)

Resolved through `EffectiveConfigResolver` (mode `ancestor_default_child_override`), parsed fail-closed:

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | OFF is off — must be explicitly `true` |
| `required_groups` | `[salvation, water_baptism, holy_spirit_baptism, foundation_course]` | each code standalone; unknown codes dropped; empty ⇒ the default; legacy `baptism` expands to both baptisms |
| `foundation_course_categories` | `[foundation, membership]` | `courses.category` values that count, case-insensitive |
| `allow_self_declaration` | `true` | may a person state their own decision |
| `self_declaration_requires_confirmation` | `true` | self-declarations are born `pending` until a mentor/sponsor confirms |
| `derive_from_enrolment` | `true` | a foundation-category enrolment auto-writes the decision |
| `derive_from_completion` | `false` | opt-in: completion also satisfies it |
| `gate_journey_advance` | `true` | the Journey gate is active (when `enabled`) |
| `gate_stages` | `[in_foundation, established]` | stages whose entry requires integration |

`AdminConfigSeeder` ships one DISABLED row for the demo org, so the shape is documented without switching the feature on.

## 4. Schema (`prospect_decisions`, extended)

The existing append-only table gains, all idempotently (`resetDataCache()` + `fieldExists()`/`getIndexData()`):

* `user_id` (NULL) — a decision may attach to a **user** directly (member self-service, derived rows where the member has no contact). `prospect_id` becomes NULLABLE; the service enforces **exactly one subject** (prospect XOR user).
* `status` — `pending` | `confirmed` | `rejected` (default `confirmed`, so every pre-existing row reads as confirmed).
* `source` — `assisted` | `self` | `landing` | `event_guest` | `derived_course` | `derived_completion` | `system`.
* `source_ref` — idempotency anchor for DERIVED rows (`enr:<id>`, `cmp:<enrollment_id>`).
* `decided_by` / `decided_at` — who confirmed/rejected a pending row, when.
* Two UNIQUE keys — `pd_prospect_uq (prospect_id, decision_type, source_ref)` and `pd_user_uq (user_id, decision_type, source_ref)` — make derived rows idempotent while leaving hand-recorded rows (NULL `source_ref`) as append-only history, exactly as before.

## 5. Who may record — and who may confirm

* **Assisted** (staff/member on a contact's behalf — `POST me/contacts/{id}/decision`, unchanged): born `confirmed`; the recorder vouches. This path now **validates the catalog** (the old free-text gap) and **refuses future dates**.
* **Self-declaration** (public invite landing, event guest form, member self-service `my/integration`): born `pending` (when confirmation is required) and only counts once confirmed.
* **Derived** (foundation-category course enrolment/completion): born `confirmed`, source-stamped, idempotent — nobody double-enters a fact the platform already knows.

**Maker-checker:** a pending row is confirmed/rejected only by the owning **mentor** (contact-attached: `prospects.owner_user_id`) or the member's **sponsor** (`SponsorshipService::activeSponsor`). Anyone else is refused. There is **no TTL** — a declaration stays pending until a human decides (the same rule as the transfer queue and the committee queue). A rejected declaration is kept as history; it never counts.

## 6. Derivation (Courses → decisions)

`EnrollmentService` gained an optional `?IntegrationDecisionsPort` seam (`IntegrationDecisionsAdapter` wired through DI). On `enroll()` — and, when opted in, on `recordCompletion()` — the adapter asks `IntegrationService` to write a `foundation_course` row **if and only if** the config for the *course's* group is enabled and the course `category` is in `foundation_course_categories`. The write is best-effort and idempotent; a decision row can never fail a learner's enrolment. No course group ⇒ no row (fail closed).

## 7. The Journey gate

`JourneyService::transition()` is the single choke point every advance funnels through (auto-apply `adjust` rules, proposal approval, manual moves). It gained an optional `?IntegrationGatePort`; when wired and the move targets a gated stage, a non-integrated member is refused with `INTEGRATION_REQUIRED` and the outstanding groups — **before** any write. The gate re-checks the *current* world at decision time (a member may have been integrated while a proposal sat in the queue), and an optional gate error never bricks the journey.

## 8. Surfaces

* `GET/POST my/integration` — member self-service: the four standalone decisions as tiles (salvation, water baptism, Holy Spirit baptism, foundation course) with their recorded dates, the integrated verdict, the "state a decision" form, and the member's own declarations with their pending/confirmed/rejected status. Menu item `overview.integration` (**unmasked** — a personal workspace like My contacts; the service bounds what each member can do).
* `GET me/integration-decisions` — the mentor's confirmation queue (in `MenuCoverage::EXCLUSIONS` as a filtered action surface linked from the integration page).
* `POST me/integration-decisions/confirm|reject/{id}` — the maker-checker decisions.
* All routes are `auth` + `webcsrf` only; **no new permission bit** (the budget stays frozen at 41) — the authoritative decision is the service's config gate + ownership/sponsor check.

Both pages are self-contained, CSP-clean, JavaScript-free, and RTL-correct in six locales.

## 9. What deliberately does not exist

* **No new permission bits**, no new tables, no fork of Courses/Journey/Membership.
* **No TTL** on the confirmation queue.
* **No enforced ordering** between the four decisions, no future dates allowed (but historic/backdated dates are — a decision may predate the capture).
* **No bundled groups** — each decision (including each baptism) is satisfied only by its own dated, confirmed row.
* **No gate** on anything except the two configured journey stages — belonging, enrolment, attendance and follow-ups are untouched.

## 10. Testing

| File | Proves |
| --- | --- |
| `Referrals/Support/tests/integration_decision_support_test.php` (39/0) | the catalog (four standalone groups, six types, NO bundled `baptism` group, `baptism_mode` gone, legacy `baptism` expands to both), the 1:1 group↔type mapping (each baptism stands alone), the verdict (neither baptism covers the other, history-only types satisfy nothing, configurable required decisions), and the fail-closed config parser |
| `Referrals/Services/tests/integration_service_test.php` (55/0) | off-means-off, self-declaration (contact + user), validation (unknown type, missing/future date), confirm/reject authority (owner/sponsor only, no double-decide, audited), the checklist (pending/rejected never count, byType dates, user+contact union), the gate, derivation (idempotent, category-filtered, no-group fail-closed), the mentor queue, and the assisted path's new catalog + future-date guards |
| `Journey/Services/tests/integration_gate_test.php` (11/0) | the gate is consulted BEFORE any write with the target stage + context group, a non-null verdict refuses with the blocked payload, null verdict / no gate leave transitions unchanged |
| `Referrals/Views/tests/integration_views_test.php` (40/0) | six-locale render (RTL for Arabic, no raw-key leaks, no JS), `_csrf` on every form, correct endpoints, integrated/disabled/empty states, i18n parity, and the source-level wiring (routes/controller/DI/migration/seeder) |

## 11. Go-live notes

1. `php spark migrate` — migration `000091` is idempotent and column-guarded.
2. Nothing changes until a leader enables `referrals.integration_decisions` for their group (`enabled: true`, plus the categories and gate stages they want). Default OFF is the shipped state.
3. `public/openapi.json` was regenerated (588 operations / 508 paths).
4. The `foundation_course` decision is now a valid assisted type too — the contact-book "Record decision" select offers it (shared catalog with the self-service page).
