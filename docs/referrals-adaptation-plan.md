# Referrals Adaptation Plan — porting `urlmanager.md` + `clicktracker.md` into `WBS\Referrals`

**Status:** IMPLEMENTED — G1, G2, G3, G4, G5 built in the recommended order (migration `000027`). Two items deferred as TODOs (§9).
**Sources:** `/home/user/uploads/urlmanager.md` (`App\Libraries\UrlManager` v2.1.0, ~60 helpers) and `/home/user/uploads/clicktracker.md` (`App\Libraries\ClickTracker`).
**Target:** `app/Modules/Referrals/` (ReferralService, SponsorshipService, ReferralController, migration `000009_CreateReferrals`).

---

## 1. Executive summary

The two uploaded specs describe a **cloaked short-URL + click-tracking + fraud/analytics** subsystem written for a *different* application (`App\Libraries` + `SwissArmyKnifeModel`, integer auto-increment IDs, tables `url_tokens` / `url_clicks` / `url_analytics`). The WBS platform already has an equivalent subsystem — the `WBS\Referrals` module — built on the platform's own conventions and with **stronger privacy invariants**.

Therefore the correct move is **capability adaptation, not code transplant.** We keep the WBS module's structure, DI, return types, and privacy posture, and selectively add the *behaviours* the specs have that WBS currently lacks. We deliberately do **not** copy the spec's raw-PII storage.

**Bottom line:**
- ~60% of the specs is already implemented in WBS (opaque codes, resolve, hashed click capture, consent-gated prospects, idempotent attribution, sponsor chain).
- ~15% conflicts with WBS privacy invariants and must be **rejected or downgraded to hash-only** (raw IP / ISP / full UA / raw geo per click).
- ~25% is a genuine capability gap worth porting (analytics aggregates, fraud verdict + review helpers, chain endpoint, typed redirect/UTM).

---

## 2. Convention reconciliation (spec → WBS)

| Concern | Spec (`urlmanager`/`clicktracker`) | WBS `Referrals` | Adaptation rule |
|---|---|---|---|
| Base class | `App\Libraries\*` + `SwissArmyKnifeModel` (`selectData`, `safeInsert`, `selectDataIn`, `safeUpdate`) | Plain final service, `BaseConnection` + `Clock` injected, CI query builder | Use `$this->db->table(...)`; never introduce SwissArmyKnifeModel. |
| IDs | `INT AUTO_INCREMENT` | `CHAR(36)` UUIDv7 (`Uuid::v7()`) | All new rows use UUIDv7. No integer IDs. |
| Tables | `url_tokens`, `url_clicks`, `url_analytics` | `referral_links`, `referral_clicks`, `referral_attributions`, `prospects`, `sponsorships` | Reuse existing tables; extend via new migration only where a column is genuinely missing. |
| Return type | scalars / arrays / bare ints | `WBS\Shared\Support\Result` (`ok/created/fail/notFound`) | Every new public method returns `Result`. |
| Time | `date()` / `strtotime()` | `Clock` DI (`nowUtcString`, `nowUtcMicro`) | Inject/reuse `Clock`; no direct `date()` in services. |
| IP | **stored raw** (`ip_address`) + ISP lookups | **HMAC-SHA256 hash only** (`ip_hash`), salt from `REFERRAL_IP_SALT` | Never store raw IP. Velocity/fraud keys off `ip_hash`. |
| User agent | **stored full** (`user_agent` up to 64 KB) | **sha256 hash** (`ua_hash`) | Keep hash. Bot heuristics must run on the raw UA *in-request only*, then discard — persist just a verdict flag, not the string. |
| Device fingerprint | dedicated `DeviceFingerprinter`, stored raw | none | Optional: store a `device_hash` (sha256 of a normalized signal bundle). No raw fingerprint components persisted. |
| Geo per click | raw `location_data` JSON (country/city/ISP) via IP or coords | none | Do **not** store per-click geo. If ever needed, resolve at read time via `GeoResolverService`, or store only a coarse country code — decision deferred. |
| Sponsor chain | `users.sponsor_id` self-join | `sponsorships` table + `SponsorshipService::upline()` | Chain traversal delegates to `SponsorshipService`; returns **member IDs only** (no names/emails). |
| Fraud | separate `FraudDetector` class, delegated from `trackClick` | none | New `FraudService` in the module, injected into `ReferralService`; returns a verdict value object, not booleans scattered inline. |
| Encryption payload | `UrlManager` encrypts sponsor/resource IDs into the token | opaque random code + DB lookup | Keep the opaque-code + DB-lookup model (no reversible payload in the URL). Simpler and leak-proof; reject the encrypted-payload approach. |

---

## 3. What is ALREADY covered (no work)

| Spec capability | WBS equivalent |
|---|---|
| `generateUrl()` cloaked token | `ReferralService::createLink()` (opaque 12-char code, unique-loop) |
| `processUrl()` resolve token | `ReferralService::resolve()` |
| `trackClick()` persist click | `ReferralService::recordClick()` (hashed IP/UA, consent) |
| lead capture | `ReferralService::captureProspect()` (consent-gated, email hashed) |
| conversion credit | `ReferralService::attributeConversion()` (idempotent UNIQUE) |
| conversion tally | `ReferralService::conversionCount()` |
| `getReferralChain()` upward walk | `SponsorshipService::upline()` (+ `wouldCycle` guard) |
| rate limiting | route filter `ratelimit:referral.click` on `r/(:segment)` |

---

## 4. What must be REJECTED / downgraded (privacy conflicts)

These spec behaviours regress WBS's GDPR posture and must **not** be ported verbatim:

1. **`persistClick()` storing `ip_address`, `user_agent` (64 KB), `referrer`, `session_id`, `location_data`.** WBS stores `ip_hash`, `ua_hash`, `consent` only. Any new signal must be hashed or bucketed before persistence.
2. **`resolveLocationData()` writing raw country/city/ISP per click.** Rejected — no per-click geo storage. (Spec itself concedes it degrades to `Unknown` without coordinates.)
3. **`getReferralChain()` returning `full_name` + `email` per level.** Downgrade to member-ID-only; PII stays out of the analytics surface.
4. **Encrypted reversible token payload (`UrlManager`).** Rejected in favour of the existing opaque-code + DB-lookup design.
5. **`markSuspicious` writing free-text reason.** Keep, but clamp length (`VARCHAR(255)`) and treat reason as an operator note, not user input echoed back.

---

## 5. Capability GAPS worth porting (each adapted to WBS)

Grouped so they can be approved individually. Each item lists the new surface and the privacy-safe adaptation.

### G1 — Click analytics aggregates
- **New:** `ReferralService::getLinkAnalytics(string $code, string $period='30 days'): Result` and `getReferrerAnalytics(string $referrerId, string $period): Result`.
- Computed from `referral_clicks`: `total_clicks`, `unique_visitors` (distinct `ip_hash`), `unique_devices` (distinct `device_hash` if G4 added, else omit), `suspicious_clicks` (if G2 added), `clicks_by_hour` (0–23 from `created_at`), `top_campaigns` (from `referral_links.campaign`).
- **No raw PII** in output — counts and buckets only. `top_referrers` (spec) is dropped because we don't store raw referrer URLs.
- No schema change needed for the base version.

### G2 — Fraud / suspicious detection
- **New:** `FraudService` (`app/Modules/Referrals/Services/FraudService.php`) with `assess(array $ctx): FraudVerdict` — heuristics: click velocity per `ip_hash` in a sliding window, self-click (`ip_hash`/referrer match), obvious bot UA patterns (checked on raw UA in-request, only the verdict persisted), missing-consent weighting.
- Wire into `recordClick()`: compute verdict, persist `is_suspicious` + `suspicious_reason`.
- **New review helpers:** `markSuspicious(string $clickId, string $reason): Result`, `clearSuspicious(string $clickId): Result`.
- **Schema:** migration **`000027`** adds `is_suspicious TINYINT(1) NOT NULL DEFAULT 0` and `suspicious_reason VARCHAR(255) NULL` to `referral_clicks` (+ index on `(link_id, is_suspicious)`).
- Bind `FraudService` in `Referrals/Config/Services.php`; inject into `ReferralService`.

### G3 — Referral-chain endpoint
- **New:** `ReferralController::chain(string $memberId)` → `SponsorshipService::upline()`, returning `[{level, member_id}]` (IDs only).
- Route: `GET referrals/chain/(:segment)` (auth-gated, not public).
- No schema change.

### G4 — Typed redirect + UTM/AB resolution
- **New:** `ReferralService::resolveRedirectUrl(array $link): string` — `match` on a new `link_type` (member/event/giving/course/streaming) mapping to platform routes, mirroring the spec's `resolveRedirectUrl`. UTM params passed through from the `land()` request to the destination query string.
- **Schema:** migration `000027` (same as G2) adds `link_type VARCHAR(20) NOT NULL DEFAULT 'member'` and `resource_id CHAR(36) NULL` to `referral_links`.
- Controller `land()` returns the resolved target (or issues a redirect) instead of the bare `landing` field.
- AB-testing (spec's variant split) is **optional/stretch** — needs a variants table; recommend deferring.

### G5 — Device fingerprint (optional, privacy-safe)
- Store `device_hash CHAR(64) NULL` on `referral_clicks` = sha256 of a normalized client-signal bundle (screen res + timezone + UA class), never the raw components. Enables `unique_devices` in G1 and a stronger velocity signal in G2.
- **Schema:** column in migration `000027`.

---

## 6. Proposed migration `000027` (only if G2/G4/G5 approved)

`2026-09-01-000027_ExtendReferralsTracking.php` — additive, reversible:

```
ALTER TABLE referral_clicks
  ADD COLUMN is_suspicious     TINYINT(1)  NOT NULL DEFAULT 0,   -- G2
  ADD COLUMN suspicious_reason VARCHAR(255) NULL,                -- G2
  ADD COLUMN device_hash       CHAR(64)     NULL,                -- G5
  ADD KEY rc_susp_idx (link_id, is_suspicious);

ALTER TABLE referral_links
  ADD COLUMN link_type   VARCHAR(20) NOT NULL DEFAULT 'member',  -- G4
  ADD COLUMN resource_id CHAR(36)    NULL;                       -- G4
```
`down()` drops the columns/index. Next free number after this is `000028`.

---

## 7. Recommended sequencing

1. **G1 (analytics)** — highest value, zero schema risk. Do first.
2. **G2 (fraud + review)** — needs `000027`; the biggest behavioural add.
3. **G4 (typed redirect/UTM)** — needs `000027`; improves the public link UX.
4. **G3 (chain endpoint)** — trivial, delegates to existing service.
5. **G5 (device hash)** — only if G1/G2 want device uniqueness.
6. **AB testing** — defer (separate variants table, low priority).

Each step: implement service method → bind in `Config/Services.php` → controller action + route → update `REBUILD_STATUS.md` / `RUNBOOK.md`. `php -l` unavailable this session (no toolchain) → validate via brace/paren balance; CI lint on next run.

---

## 10. Sponsor reassignment — maker-checker (FR-MEM-002, shipped 2026-09-08)

Sponsor re-parenting is no longer a bare `SponsorshipService::assign()`; it is a
reviewed request. Operator notes:

- **Migration `000052`** creates `sponsor_reassignments` (request) +
  `sponsor_reassignment_reviews` (append-only decision trail). Idempotent.
- **Permission** `sponsor.reassign.approve` was already seeded and already in the
  PDP SoD self-approval block — the workflow now uses it.
- **Flow:** `POST referrals/sponsor-reassignments` (maker; `auth`) → checker lists
  `GET .../pending` and acts via `POST .../{id}/approve|reject` (both
  `auth` + `authorize:sponsor.reassign.approve`); maker/staff may `POST .../{id}/cancel`.
- **Approve** enforces checker ≠ maker (`SOD_SELF_APPROVAL`), re-checks eligibility
  (self / cycle) because the graph can shift while pending, delegates the actual
  re-parent to `assign()` (**history preserved** — old edge closed, new opened),
  then recalculates **only affected, non-finalized** metrics
  (`on_read_live_recompute`: live `upline_depth`/`direct_recruits` for the member +
  downline). **Never** rewrites attributions / ledger / certificates /
  closed-season snapshots / audit log.
- **Evidence:** every request carries reason + before/after upline paths + a
  descendant-impact assessment; every decision writes an immutable hash-chained
  audit entry (`referral.sponsor.reassign_{requested,approved,rejected,cancelled}`).
- **Tests:** `tests/unit/SponsorReassignmentEligibilityTest.php` locks the pure
  eligibility/cycle guard and projected-upline computation.

## 9. Deferred TODOs (noted for later)

- **[TODO] AB testing / variant split** — the spec's variant-split redirect is deferred. Needs a `referral_link_variants` table (variant code, weight, destination) + weighted selection in `resolveRedirectUrl()`, plus per-variant analytics rollup. Low priority; revisit after core adoption.
- **[TODO] Per-click geo** — currently NOT stored, preserving the WBS no-per-click-geo stance. For later consideration: either (a) resolve coarse country at read time via `GeoResolverService` from supplied coordinates, or (b) store only a bucketed country code (never city/ISP/coords) behind an explicit consent flag. Would light up `geographic_distribution` in `aggregateClicks()`. Requires a privacy/DPIA review before building.

## 8. Open decisions for the user

- **Which G-items to build, and in what order** (default: G1 → G2 → G4 → G3).
- **AB testing:** in or out? (recommend out for now.)
- **`land()` behaviour:** keep returning JSON landing target, or switch to an actual HTTP redirect for the typed-redirect feature (G4)?
- **Per-click geo:** confirm we keep the WBS stance of *no* per-click geo storage (recommended).
