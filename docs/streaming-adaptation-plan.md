# Streaming Adaptation Plan — porting `streamingservice.md` into `WBS\Streaming` (+ Meetings, Integrations)

**Status:** IMPLEMENTED — S1–S7 built in the recommended order (migrations 000028/000029). AB testing, live provider analytics fan-in, and delegated-auth Teams/GoToWebinar remain deferred TODOs (§8).
**Source:** `/home/user/uploads/streamingservice.md` (`App\Libraries\StreamingService`, 1544 lines, ~55 methods).
**Targets:** `app/Modules/Streaming/`, `app/Modules/Meetings/`, `app/Modules/Integrations/`.

---

## 1. Executive summary

`streamingservice.md` is a **multi-platform live-streaming integration library** for a different app (`App\Libraries` + `SwissArmyKnifeModel`, integer IDs, tables `streaming_sessions`/`stream_analytics`/`streaming_credentials`/`stream_viewers`/`stream_chat`/`stream_polls`/`stream_donations`/`stream_reactions`). It does three quite different jobs bundled into one class:

1. **Provider integration** — real server-to-server API calls + token refresh for YouTube Live, Twitch, Facebook Live, RTMP, and meeting platforms (Zoom, Google Meet, GoToWebinar, MS Teams).
2. **Stream lifecycle** — initialize / start / end a stream, dispatched per platform.
3. **Engagement + analytics** — chat, polls, donations, reactions, viewer tracking, real-time metrics, per-session analytics rollup.

The WBS platform **already implements jobs 2 and 3** across the existing `Streaming` and `Meetings` modules, on its own conventions and with stronger privacy/secret-handling. What WBS is genuinely *missing* is **job 1 — the actual outbound provider integration** (right now `stream_destinations` just references an integration connection; nothing calls YouTube/Twitch/etc.).

**So the correct move is the same as the referrals adaptation:** keep WBS structure + conventions, do **not** transplant the `App\Libraries` class, and selectively port the one big capability WBS lacks — provider integration — routed through the existing `Integrations` module (credential vault, connections, capability grants) rather than a new `streaming_credentials` table.

**Rough split:**
- ~45% already implemented in WBS (lifecycle, chat, polls, metrics, access policy).
- ~20% conflicts with WBS invariants and must be rejected/downgraded (raw PII viewer rows, plaintext-ish credential table, God-class shape, integer IDs, `SwissArmyKnifeModel`).
- ~35% genuine gap worth porting (provider adapters + token refresh + meeting-link creation + donations tied to Contributions).

---

## 2. Convention reconciliation (spec → WBS)

| Concern | Spec | WBS | Adaptation rule |
|---|---|---|---|
| Base class | one `App\Libraries\StreamingService` God-class (55 methods) + `SwissArmyKnifeModel` | small final services per concern, `BaseConnection`+`Clock`(+`AuthorizationService`) DI | Split by concern; no God-class, no SwissArmyKnifeModel. |
| IDs | `INT AUTO_INCREMENT`, `session_id` string | `CHAR(36)` UUIDv7 | UUIDv7 everywhere. |
| Tables | `streaming_sessions`, `stream_*`, `streaming_credentials` | `streams`, `stream_destinations`, `stream_chat_messages`, `stream_polls`/`stream_poll_votes`, `stream_metric_samples`, `stream_archives`, `stream_cohosts`, `stream_overlays`; Meetings: `meetings`/`meeting_participants`/`meeting_attendance_evidence` | Reuse existing tables; add only what's missing (viewer sessions, reactions, donations link). |
| Credentials | dedicated `streaming_credentials` table, per-group, `crypto->encrypt` base64 blobs | `Integrations` module: `integration_connections` + `connection_credentials` via `CredentialVault` (`put`/`useSecret`, never returns raw secret) | Route ALL provider tokens through `CredentialVault`. Delete the `streaming_credentials` concept. |
| OAuth | library owns token refresh; controller does consent (per spec scope note) | `Integrations/ConnectionService` owns connection lifecycle + approval | Token refresh becomes a `ProviderClient` using `CredentialVault::useSecret`; consent flow = a new controller per spec's own note. |
| Secrets in memory | returns decrypted access/refresh tokens from `getCredentials()` | `CredentialVault::useSecret(callable)` — secret never leaves the closure; `#[SensitiveParameter]` | Never return a decrypted token; use the callback pattern. |
| HTTP | `httpRequest()` inline `CURLRequest` | no provider HTTP layer yet | New `ProviderHttp` helper wrapping CI `CURLRequest`, timeouts + honest error surfacing (keep spec's "surface platform's own error message" idea). |
| Viewer tracking | stores raw `ip_address`, `user_agent`, `country`, `city`, `referrer` per viewer | WBS stores hashed IP/UA elsewhere (Referrals), no raw PII | Store `ip_hash`/`ua_hash` + coarse `device_type` only; NO raw IP/UA/city/referrer. |
| Donations | `stream_donations` with `status='completed'` set blindly | `Contributions` module owns money (Stripe + MoMo, VBCS) | Stream "giving" must create a `Contributions` intent, NOT a standalone completed row. Reject the blind-complete. |
| Time | `date()` / `strtotime()` | `Clock` DI | Inject `Clock`. |
| Return type | mixed (ids, arrays, throws `Exception`) | `Result` envelope | Public methods return `Result`; no raw throws across the boundary. |
| Config | `getenv` per platform | platform config + `Integrations` catalog | App-level provider config via catalog/adapter rows, not scattered `getenv`. |

---

## 3. What is ALREADY covered (no work)

| Spec capability | WBS equivalent |
|---|---|
| create/init/start/end stream lifecycle | `StreamService::create/goLive/end/transition` + `stream_destinations` |
| access to a restricted stream | `StreamService::canView` (independent access policy) |
| `sendChatMessage` | `StreamEngagementService::postChat` (+ slow-mode, moderation, bans) |
| `createPoll` / poll lifecycle | `StreamEngagementService::launchPoll/vote/closePoll` (+ `stream_poll_votes`) |
| per-session metric capture | `StreamEngagementService::recordMetric` + `stream_metric_samples` |
| organizer dashboard rollup | `StreamService::organizerDashboard` |
| archive/VOD link | `StreamService::linkArchive` + `stream_archives` |
| meeting-as-stream (Zoom/Meet/Teams/GoToWebinar) | `Meetings` module: `MeetingService::create/grantAccess/verifyJoinToken/recordAttendanceEvidence/transition` |
| overlays / co-hosts | `OverlayService` + `stream_overlays`/`stream_cohosts` |

---

## 4. What must be REJECTED / downgraded (conflicts)

1. **`streaming_credentials` table + `getCredentials()` returning decrypted tokens.** Rejected. Provider secrets live in `connection_credentials` via `CredentialVault`; tokens are used inside `useSecret()` closures and never returned.
2. **Raw PII viewer rows** (`ip_address`, `user_agent`, `country`, `city`, `referrer`). Downgrade to `ip_hash`/`ua_hash` + coarse `device_type`; drop city/referrer (or bucket country only behind consent — deferred, see TODO).
3. **`processGiving()` writing `status='completed'` directly.** Rejected — money flows through `Contributions` (Stripe/MoMo intents, VBCS). Stream giving = create intent + reconcile on webhook.
4. **God-class shape.** Split into `ProviderClient` interface + per-provider adapters, kept out of `StreamService` (which stays lifecycle/access only).
5. **`createPoll` `created_by => 1` TODO and blind `Exception` throws.** Replaced by real actor + `Result` returns.
6. **Integer IDs / `SwissArmyKnifeModel` / `rawExecute` counter bumps.** Replaced by UUIDv7 + query builder; analytics counters via `stream_metric_samples` aggregation, not denormalized `UPDATE ... +1`.

---

## 5. Capability GAPS worth porting (each adapted to WBS)

### S1 — Provider integration layer (the core gap)
- **New:** `app/Modules/Integrations/Providers/` — a `StreamProvider` interface (`createBroadcast`, `startBroadcast`, `endBroadcast`, `fetchAnalytics`) + adapters `YouTubeProvider`, `TwitchProvider`, `FacebookProvider`, `RtmpProvider`.
- Each adapter uses a new `ProviderHttp` (CURLRequest wrapper: timeouts, ret/backoff, honest error surfacing) and `CredentialVault::useSecret()` for tokens. Token refresh lives in the adapter.
- `StreamService::goLive()` gains an optional dispatch to the destination's provider adapter (resolved from `stream_destinations.provider` + its `integration_connection`).
- **No `streaming_credentials`** — all secrets via `connection_credentials`.

### S2 — Meeting-link creation for meeting platforms
- **New:** `MeetingProvider` adapters (`ZoomProvider`, `GoogleMeetProvider`, `GoToWebinarProvider`, `MsTeamsProvider`) creating the real meeting and returning `{meeting_ref, join_url}`.
- Wired into `MeetingService::create()` (currently stores meeting rows but doesn't call the platform). Join URL/passwords stored via existing meeting columns; secrets never in the row.

### S3 — Viewer session tracking (privacy-safe)
- **New:** `stream_viewers` table via migration (UUIDv7, `stream_id`, `viewer_id` nullable, `ip_hash`, `ua_hash`, `device_type`, `joined_at`, `left_at`) + `StreamEngagementService::trackViewer/endViewer` and concurrent-count.
- No raw IP/UA/city/referrer. Feeds `recordMetric('viewers', ...)`.

### S4 — Reactions
- **New:** `stream_reactions` table + `StreamEngagementService::addReaction` (UUIDv7, rate-limited). Counts via metric samples, not a denormalized column.

### S5 — Stream giving via Contributions
- **New:** `StreamEngagementService::recordGiving()` that creates a **Contributions intent** (Stripe/MoMo) tagged with `stream_id`, returning the intent — NOT a completed donation. Completion arrives on the Contributions webhook. Reuses the existing money rails; no new payment code.

### S6 — Real-time metrics endpoint
- **New:** `StreamService::realTimeMetrics(streamId)` — current viewers, chat/reactions in last N minutes, top countries (only if S3 stores coarse country), engagement score — computed from existing tables. Mostly a read-side aggregation over what S3/S4 record.

### S7 — OAuth consent controller (per spec's own scope note)
- **New:** `StreamingOAuthController` (Integrations) handling the browser-redirect half: authorize URL per provider + callback with session-bound `state`, exchanging the code and storing tokens via `CredentialVault`. This is the piece the library explicitly said it does NOT do.

---

## 6. Proposed migrations (only for approved items)

- `000028_CreateStreamViewers` (S3): `stream_viewers` (hashed identifiers, coarse device only).
- `000029_CreateStreamReactions` (S4): `stream_reactions`.
- (S1/S2/S5/S7 need **no** new tables — they reuse `integration_connections`/`connection_credentials`, `stream_destinations`, `meetings`, and `Contributions`.)

Next free migration number is currently **000028**.

---

## 7. Recommended sequencing

1. **S1 — provider integration layer** (the actual gap; highest value). Interface + YouTube + RTMP first, Twitch/Facebook next.
2. **S7 — OAuth consent controller** (needed to obtain the tokens S1 consumes).
3. **S2 — meeting-link creation** (Zoom/Meet server-to-server first — no per-group consent).
4. **S3 — viewer tracking** (privacy-safe) → migration 000028.
5. **S4 — reactions** → migration 000029.
6. **S6 — real-time metrics** (read-side over S3/S4).
7. **S5 — stream giving via Contributions** (touches money; do carefully, last).

Each step: service/adapter → bind in module `Config/Services.php` → controller action + route → docs (`REBUILD_STATUS.md`/`RUNBOOK.md`). `php -l` unavailable this session → validate via brace/paren balance; CI lint on next run.

---

## 8. Deferred TODOs (noted for later)

- **[TODO] Per-viewer geo (country) storage** — kept OUT for now (no raw IP/city). For later: coarse country only, resolved via `GeoResolverService` or a consented signal, behind a DPIA. Would light up S6 `top_countries`.
- **[TODO] Live provider analytics fan-in** — pulling YouTube/Twitch/Facebook native viewer numbers into `stream_metric_samples` on a schedule (queue worker), with graceful degradation when a provider is down (spec's fallback idea).
- **[TODO] GoToWebinar / MS Teams delegated-auth** variants — only if meetings must be created strictly "as" a signed-in organizer (vs app-only Graph / S2S).

---

## 9. Open decisions for the user

- **Which S-items to build, and order** (default: S1 → S7 → S2 → S3 → S4 → S6 → S5).
- **Provider priority** for S1/S2 — which platforms first? (suggest YouTube + RTMP + Zoom to start.)
- **Live outbound HTTP in this environment:** the sandbox has **no network + no PHP toolchain**, so provider adapters can be written and structurally validated but **cannot be run/tested here** — real API calls verify only in CI/staging. Confirm that's acceptable.
- **Stream giving (S5):** confirm it must go through `Contributions` intents (recommended) rather than a standalone donations table.
