# BaseController reference — adaptation notes

**Source:** `/home/user/uploads/basecontroller.md` — external reference `App\Controllers\BaseController` (887 lines). Reviewed for genuine gaps vs. our `WBS\Shared\Http\BaseController`.

## Verdict
The reference is a **god-controller** (a `SERVICE_MAP` `__get()` magic bridge injecting ~25 libraries into every controller, `SwissArmyKnifeModel`, eager per-request user DB load in `initController`, MAC enforcement via an in-controller trait, `exit;` inside a rate-limit helper). All of that conflicts with our filters + DI + Result-envelope architecture and was **rejected** — same discipline as the awardlib / givingslibrary adaptations.

Three genuine, small wins were adapted. Two optional items were deferred.

## Adapted (implemented)

1. **Cache-safety headers on negotiated responses.** One canonical URL serves both JSON and HTML, but `respondJson()`/`respondHtml()` set no cache-partitioning headers — a shared cache keyed on URL alone could serve the wrong representation. Added `BaseController::cacheSafe()` setting `Vary: Accept, X-Requested-With, Authorization` + `X-Content-Type-Options: nosniff`, applied on both response paths.

2. **Per-request correlation id.** New global filter `WBS\Shared\Filters\CorrelationIdFilter` (alias `correlationid`, registered in `Config/Filters` globals before+after). Honours a sane inbound `X-Correlation-Id` (bounded, token-charset — no header injection) or mints a fresh 16-hex id; attaches it to the request as `wbsCorrelationId` and echoes it back on the response. Kept OUT of the controller (unlike the reference) so CLI/queue paths and every controller share one mechanism.

3. **DRY request-context helpers.** The `wbsOrgId ?? body ?? getenv` org-resolution chain was copy-pasted ~35× and user-id resolution ~30× across 26 controllers (3 also had private `orgId()`/`currentUserId()` copies). Centralised in `BaseController`:
   - `orgId(): string`
   - `currentUserId(?string $fallbackKey = null, string $default = ''): string`
   - `actorId(?string $fallbackKey = null): ?string` (nullable optional-actor form)
   - `mfaLevel(): ?string`, `clientIp(): ?string`, `userAgent(): ?string`

   **Security improvement:** the helpers make the authenticated request attribute (`wbsOrgId`/`wbsUserId`, set by AuthFilter) ALWAYS win over a body/query param, so a caller can no longer spoof another org by posting `organization_id`. All 26 controllers migrated; the 3 private duplicates deleted.

## Deferred (optional, not built)
- **UTF-8 JSON scrubbing** (`cleanForJson` with an `mb_check_encoding` fast path) before encoding. Low value here — our data is utf8mb4; revisit only if invalid-encoding bugs appear.
- **In-action `checkRateLimit()` helper** for tighter-than-route limits (e.g. OTP resend). We already have a reusable `RateLimitFilter`; a non-`exit` wrapper could be added later if a use case needs it.

## Rejected (not applicable to our architecture)
`__get`/SERVICE_MAP god-object; SwissArmyKnifeModel; eager per-request `loadCurrentUser()` DB read; in-controller MAC trait; controller-side token re-parsing (AuthFilter owns that); message-key i18n + `trackMissingTranslations` (a separate feature, not a BaseController concern); `resolveFormat`/`present`/`view` layer (our `RequestNegotiator` + `respondWith(Result)` already covers negotiation more cleanly).
