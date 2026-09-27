# SMS Delivery — Provider Chain: mNotify (primary) → Nalo Solutions (fallback)

**Status:** ✅ Implemented 2026-09-20 (mNotify), ✅ 2026-09-20 (Nalo + chain) — Nalo's endpoint and field names **confirmed against the account's API sheet 2026-09-20**; the transport ships as-is.
**Classes:**
- `app/Modules/Notifications/Transport/SmsProviderChain.php` — what the `sms` channel resolves to
- `app/Modules/Notifications/Transport/MNotifySmsTransport.php` — primary provider
- `app/Modules/Notifications/Transport/NaloSmsTransport.php` — fallback provider

**Channel:** `sms` (registered in `app/Modules/Notifications/Config/Services.php::transportRegistry()`)
**Catalog entries:** `mnotify_sms_v1`, `nalo_sms_v1` (`AdapterCatalogSeeder`)
**Tests:** `mnotify_sms_transport_test.php` (44/0), `nalo_sms_chain_test.php` (115/0)
**SRS:** FR-NOT-4 (§5.12), §7 External Interfaces

---

## 1. Why this existed as a gap

The notification pipeline was already channel-agnostic up to the transport seam: `NotificationService::send()` records a delivery and stages a `notification.dispatch` job; `NotificationDispatcher` resolves the transport for the job's channel and calls `deliver()`. `email` and `inapp` had real transports — **`sms` had none**, so every SMS job threw `No transport for channel "sms"` and retried until it dead-lettered. Adding SMS is exactly one factory in the registry: the dispatcher and the service did not change. That seam is also what makes the **second** provider (§7) and the **failover chain** (§8) cheap: the chain is itself a `ChannelTransport`, so nothing above it changed either.

---

## 2. The provider contract

**Send** (mNotify "Quick Bulk SMS"):

```
POST https://api.mnotify.com/api/sms/quick?key=<API_KEY>
Content-Type: application/json

{
  "recipient":     ["0241234567", "0201234567"],   // ARRAY — one call is one campaign
  "sender":        "WBS",                          // registered alphanumeric sender ID
  "message":       "Service starts at 9am.",
  "is_schedule":   false,
  "schedule_date": ""                              // "Y-m-d H:i:s" when scheduled
  // "sms_type": "otp"                             // OPT-IN — costs 0.035/campaign extra
}
```

**Success response** — note `summary._id` is the **campaign id**, the handle for delivery reports:

```json
{"status":"success","code":"2000","message":"messages sent successfully",
 "summary":{"_id":"A59CCB70-662D-45EF-9976-1EFAD249793D","type":"API QUICK SMS","total_sent":2}}
```

**Delivery report** (polled — mNotify exposes no inbound SMS webhook):

```
GET https://api.mnotify.com/api/status/{campaignId}?key=<API_KEY>
→ {"status":"success","report":{"_id":60711577,"recipient":"233241234567","status":"DELIVERED", …}}
```

Exposed as `MNotifySmsTransport::deliveryReport($campaignId)` for a future reconciliation sweep that moves a `sent` delivery to `delivered`/`failed` without re-sending.

---

## 3. Provider quirks the adapter handles

| Quirk | Handling |
|---|---|
| **API key goes in the query string**, not a header | Sent as `query: {key: …}` through `ProviderHttp`. Keep it out of client-visible URLs and logs. |
| **HTTP 200 can still be an error** (bad key, no credit, bad recipient) | A 2xx is *not* sufficient: only `status:"success"` or `code:"2000"` is accepted. Everything else is classified from the body. |
| `recipient` is an array | The message's own recipient **plus** any `meta['recipients']` (array or `;`/`,`-separated string) go out as **one campaign** — one call, one charge — de-duplicated after normalization. |
| `sms_type:"otp"` costs extra and must not be sent for ordinary blasts | **Opt-in only**: explicit `meta['sms_type'] = 'otp'` (or `meta['otp']`), or a category containing `otp` / `mfa` / `2fa` / `verification` / `verify`. An explicit non-otp `sms_type` overrides the category. **Message text is never inspected.** |
| Ghana number formats vary (`0241234567`, `+233241234567`, `233 24 123 4567`, `00233…`) | `normalizePhone()` drops separators and a leading `+`/`00`, and folds `<cc> + 9 digits` into the local `0…` form using `SMS_DEFAULT_COUNTRY_CODE`. Foreign numbers pass through as digits. Fewer than 7 or more than 15 digits ⇒ invalid. |
| Scheduling | `meta['schedule_date']` or `meta['send_at']` ⇒ `is_schedule: true` + `Y-m-d H:i:s`. A past or unparsable value is **ignored** (sends now) rather than scheduling something that can never fire. |

---

## 4. Outcome mapping (drives retry + circuit breaker)

`NotificationDispatcher` maps `accepted → sent` (ack, breaker success), `rejected → terminal failure` (ack, **no retry**, breaker untouched), `failed → nack/retry` (breaker fault).

| Condition | Outcome | Why |
|---|---|---|
| `status:"success"` / `code:"2000"` | **accepted** (+ campaign id) | — |
| Invalid/missing recipient, empty body | **rejected** | Retrying can never help |
| Body error mentioning an invalid recipient/sender/number, or an empty/too-long message | **rejected** | Permanent per-message |
| Body error: invalid API key, **insufficient credit** | **failed** | Fix the account/top up, the queue replays; the breaker trips so we stop hammering |
| No API key configured | **failed**, **no HTTP call** | A misconfigured environment retries loudly — never a silent "sent" |
| HTTP 401 / 402 / 403 | **failed** | Credential/balance state, not a per-message fault |
| HTTP 408 / 429 / 5xx / transport error | **failed** | Transient |
| Other HTTP 4xx | **rejected** | Malformed request; a retry repeats the same bug |
| Unrecognisable envelope (no `status`/`code`) | **failed** | Don't claim success on an unknown shape |

---

## 5. Configuration

App-level env is the **platform bootstrap**: it configures a provider endpoint and one sender identity for an install that has not provisioned per-group accounts, and it backs org-wide connections. A body's own secret and sender id live in the `CredentialVault` / `integration_connections` instead — see §10:

```dotenv
MNOTIFY_API_KEY = REPLACE_ME            # or SMS_API_KEY (MNOTIFY_API_KEY wins)
SMS_API_BASE_URL = https://api.mnotify.com
SMS_SENDER_ID = REPLACE_ME              # must be registered + APPROVED with mNotify first
SMS_DEFAULT_COUNTRY_CODE = 233          # folds +233XXXXXXXXX → 0XXXXXXXXX
```

Documented in `.env.example` alongside the email transport block.

**Operator checklist**

1. Register the alphanumeric **sender ID** with mNotify and wait for approval — an unapproved sender is a per-message rejection, not a transient fault.
2. Keep wallet credit above zero: `Insufficient credit` is retryable, so messages queue rather than fail permanently, but nothing delivers until you top up.
3. Only tag a notification category as OTP-bearing when it genuinely carries a one-time code — the `sms_type:"otp"` charge applies per campaign.
4. HTTP goes through the shared `ProviderHttp` (5 s connect / 15 s timeout, provider error body surfaced) — never a bare `curl`.

---

## 6. Testing without a network

`ProviderHttp::client()` is `protected`, and the standalone test declares a **recording fake** of `WBS\Integrations\Providers\ProviderHttp` (plus a matching `ProviderException`) in its own namespace block *before* requiring the transport — the same technique the suite uses for `CodeIgniter\Database\BaseConnection`. The fake records method/URL/options and returns a canned body or throws, so all 44 assertions (request shape, normalization, OTP opt-in, the 200-with-error trap, the full outcome matrix, scheduling, bulk de-duplication, report lookup) run offline and deterministically.

---

## 7. The second provider: Nalo Solutions (fallback)

`NaloSmsTransport` implements the same seam, so nothing above the transport changed — but almost everything about the wire format differs from mNotify's, which is exactly why each adapter normalizes independently instead of sharing helpers.

**Send** (reseller API, **form-encoded**, not JSON):

```
POST https://api.nalosolutions.com/smsbackend/clientapi/ResellerAPI/send_sms/
Content-Type: application/x-www-form-urlencoded

username=…&password=…            # or: auth_key=…  (single-key accounts)
&message=Service starts at 9am.
&sender_id=WBS                   # pre-approved in the Nalo portal, ≤ 11 chars (truncated defensively)
&recipients=233244000111,233201234567   # COMMA-SEPARATED, INTERNATIONAL form
# &is_schedule=1&schedule_date=2026-09-21 09:00:00   # opt-in, only for a future send
```

**Success response** — the envelope varies between account types, so acceptance is read **permissively**:

```json
{"status":"success","message":"…","message_id":"NALO-77"}
```

| Quirk | Handling |
|---|---|
| **Numbers go in international form** — `233XXXXXXXXX`, no `+`, no trunk `0` (the *opposite* of mNotify's local `0XXXXXXXXX`) | `NaloSmsTransport::normalizePhone()` lifts a trunk-prefixed local number into `cc` + national digits by **prefix rule, not fixed length** (Ghana is 0+9, Nigeria 0+10). A trunk-form number with no country code to lift it with ⇒ **invalid** (fail closed) rather than handing the provider a leading `0` it will misroute. |
| **HTTP 200 can still be an error**, and the envelope differs per account | A success-ish `status` (`success`/`ok`/`sent`/`accepted`/`1`/`true`/`200`), **or** a message/campaign id with no error text ⇒ accepted. An explicit error ⇒ rejected (permanent patterns) or failed. An **unrecognisable** body ⇒ `failed` — never a claimed "sent". |
| `recipients` is a comma-separated string | The message's own recipient **plus** any `meta['recipients']` (array or `;`/`,`-separated string) go out as **one call** — one charge — de-duplicated after normalization. |
| Sender IDs are approved separately from mNotify's, and are ≤ 11 characters | Own env (`NALO_SMS_SENDER_ID`, falling back to `SMS_SENDER_ID`) and truncated rather than rejected. |
| Reseller endpoints differ between account types | This account's contract is **confirmed** (endpoint + field names below), so the transport ships as-is — but base URL **and** path stay constructor-injected from env (`NALO_SMS_API_BASE_URL`, `NALO_SMS_API_PATH`) and every outbound **field name** lives in one private `payload()` method, so a second or future account whose API differs is a config change or a one-method edit, never a new transport. |
| No documented inbound webhook or per-message status endpoint | The catalog entry declares only `sendNotification` + `healthCheck` (FR-INT-011: never advertise an op the provider cannot fulfil), and the adapter implements **no** `deliveryReport()` — polling an endpoint nobody documented would be a guess. |

Outcome mapping mirrors mNotify's table exactly (bad number/sender/empty body ⇒ `rejected`; 5xx/408/429/401/402/403/unconfigured ⇒ `failed`; other 4xx ⇒ `rejected`), so the dispatcher, the queue and the breaker need no provider-specific branches.

```dotenv
NALO_SMS_USERNAME = REPLACE_ME
NALO_SMS_PASSWORD = REPLACE_ME
NALO_SMS_AUTH_KEY =                 # single-key accounts (sent as auth_key)
NALO_SMS_SENDER_ID = REPLACE_ME     # approved in the Nalo portal, ≤ 11 chars
NALO_SMS_API_BASE_URL = https://api.nalosolutions.com
NALO_SMS_API_PATH = /smsbackend/clientapi/ResellerAPI/send_sms/
```

---

## 8. The chain: when the fallback actually fires

`SmsProviderChain` **is** a `ChannelTransport`, so the dispatcher still resolves one transport per channel and sees one `TransportResult` per attempt. It walks `SMS_PROVIDER_ORDER` (default `mnotify,nalo`):

| Provider outcome | Chain behaviour | Why |
|---|---|---|
| **accepted** | **stop**, return it | The message is with a provider; trying another would send it twice and charge twice. |
| **rejected** | **stop**, return the rejection | A rejection is a *permanent, per-message* verdict (bad number, unapproved sender id, empty body). The next provider would refuse the same message — failing over would only hide a data problem and burn credit. The dispatcher marks the delivery failed without re-queueing. |
| **failed** | **try the next provider** | A failure is a *provider fault* (5xx, timeout, rate limit, credentials, no credit) — precisely the case where another aggregator can still deliver. |
| every provider failed | `failed`, reason names **both hops** (`… [chain: mnotify: HTTP 503 | nalo: HTTP 502]`) | One delivery row explains the whole chain; the queue retries with backoff and the breaker sees a fault. |
| provider not configured | **skipped**, not attempted | `isConfigured()` (mNotify: an API key; Nalo: username+password or an auth key). An environment with only mNotify keys never burns a hop on Nalo, and the skip is not reported as an outage. |
| **no group** on the delivery *(per-group credentials wired)* | **rejected**, permanent | The chain cannot know whose account to bill. A message with no owning body is refused rather than sent on the platform's key. |
| **no usable credential** for the group *(per-group credentials wired)* | **rejected**, permanent, reason names the group | Fail closed: a body sends on an account it provided **or was granted** — never on another body's, never on env. |
| a hop whose credential has **no secret stored** | that hop is **skipped**, the next is tried | An account approved without a secret is not a credential. The skip is visible in `planFor()` as `usable=false`. |
| **nothing** configured *(env path only)* | `failed`: `no sms provider configured (…)` | Loud, retryable — a delivery is never marked "sent" by default. |

Order handling is deliberately forgiving: an unknown name in `SMS_PROVIDER_ORDER` is **ignored**, and a registered provider the list omits is **demoted to the end rather than dropped**, so a typo cannot silently disable the fallback.

---

## 9. Adding a third provider

Implement `ChannelTransport` (`channel()` + `deliver()`, plus `isConfigured()` so the chain can skip it), add a lazy factory to the chain's provider map in `transportRegistry()`, add its name to `SMS_PROVIDER_ORDER` and its env block to `.env.example`, and give it an `AdapterCatalogSeeder` row declaring only the capabilities it really has. Nothing else changes: the dispatcher, the delivery lifecycle, the breaker and the queue all key off the channel string.

Per-group provider *switching* (one body on mNotify, another on Nalo) **shipped** — see §10. A new provider needs one more `ADAPTER_SLOTS` entry and an adapter row; nothing else in the resolution model changes.

---

## 10. Per-group credentials and subtree sharing

Each hierarchical body provides **its own** provider account and decides how far down its subtree that account may be used. Nothing silently falls back to the platform's env key: the rule is **fail closed**.

### 10.1 Where the data lives (no new tables)

| Piece | Home | Notes |
|---|---|---|
| the account | `integration_connections` | `group_id` = the owning body (`NULL` = org-wide), `adapter_code` = `mnotify_sms_v1` / `nalo_sms_v1`, `sender_identity` = the approved sender id, `settings` = non-secret wire config (e.g. `api_base_url`) |
| the secret | `connection_credentials` through `CredentialVault` | encrypted, **write-only**, versioned — storing again retires the previous version. AAD `connection:{id}:{slot}`. Slots: mNotify `api_key`; Nalo `username`, `password`, `auth_key` |
| subtree sharing | `capability_grants` (+ `grant_scope_groups`) | `capability = sms.send`, `scope_mode` ∈ `ScopeMode` (`self`, `self_and_descendants`, `descendants_only`, `groups`), `include_crosscut` opt-in, `starts_at` / `expires_at` window |
| who may configure | PDP `provider.configure` | leader-scoped through `GroupScopeResolver`, so a cell leader reads and writes only their own subtree |

### 10.2 Resolution — one rule, one code path

`NotificationCredentialResolver` (a `GroupCredentialSource`) answers *"which accounts may group G send on, for channel C?"*:

1. G's **own** active connections (specificity 0), unioned with
2. active connections named by an **active, in-window grant** whose scope covers G (`GroupScopeResolver::grantCoversScoped`) — an ancestor merely *owning* an account is not enough; the share must be explicit,
3. **one credential per provider**, nearest wins (own → … → org-wide `PHP_INT_MAX` → off-chain `1000`), capability `{channel}.send` exact or `.*`,
4. secrets only through a `withSecrets()` callback — never in a payload, a log or a view,
5. `groupId === null` ⇒ nothing resolves. Fail closed.

`SmsProviderChain` walks that list in order (mNotify first, Nalo as the fallback). `planFor()` exposes the same answer **without** secrets, which is exactly what the screen renders as "your effective chain". The env path — a chain constructed with no credential source — is untouched, so an install that never provisions per-group accounts behaves as before.

### 10.3 Grant containment (bounded twice)

The controller refuses a grantee outside the actor's own `provider.configure` scope, and `ConnectionService::grantCapability` independently refuses a grantee outside the account **owner's** subtree (`GRANT_OUT_OF_SCOPE`, 403). An org-wide account may be shared anywhere; a body's account can only ever reach downward.

### 10.4 The screen

`/notifications/credentials` — `GroupCredentialController`, menu item `comms.credentials`, permission `provider.configure`, localized in all six locales:

1. **Effective sending chain** for the selected group: provider, whose account it is (*own* / *shared with us*), the share that reaches it, sender id, and ready vs. no-secret. An empty plan renders the fail-closed warning, not a shrug.
2. **Accounts in your scope**: a write-only secret-slot form (storing rotates the slot), the shares already made with their reach and window plus a revoke, and the share form (grantee, capability, `scope_mode`, hand-picked groups, cross-cut opt-in). Sharing is offered only for an `active` account.
3. **Add an account**: group, SMS adapter (validated against the active catalogue, version pinned from it), display name, sender id, and optionally this body's preferred provider order — the per-connection `settings.provider_order` the chain already honours, settable only at creation because there is no settings-edit path.

Provider testing, approval and activation stay where they already are — `/integrations/connections`, linked from the page rather than duplicated. Every write goes through `ConnectionService`, so there is one writer and no forked credential logic. Secrets are never echoed: the page shows only *which slots exist*.

### 10.5 Tests

| File | Assertions | Covers |
|---|---|---|
| `Notifications/Services/tests/notification_credentials_test.php` | 80 | resolution, specificity, windows, cross-cut isolation, containment, fail-closed |
| `Notifications/Transport/tests/nalo_sms_chain_test.php` | 141 | both providers, credential-aware ordering, refusals, `planFor()` |
| `Notifications/Views/tests/group_credentials_view_test.php` | 116 | CSRF, route filters, scope gates, slot allowlist, menu, i18n parity, rendered copy |

