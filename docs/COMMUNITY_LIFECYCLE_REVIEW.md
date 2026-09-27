# Community (Feed / Posts / Comments / Moderation) Lifecycle Review

_A code-grounded gap analysis of the **content lifecycle** — how a post/comment is
authored (with allowlist-sanitized HTML), how visibility (private/group/hierarchy/
public/archived) is enforced on read, how content is reported and how a moderator
acts (hide/delete/restore/lock/mute/ban/escalate) with a mandatory reason — plus
how content should react to author account/group teardown._

Prepared 2026-09-15. Method: read `FeedService`, `ModerationService`,
`ContentSanitizer`, the controllers, the migration, the routes; every finding
cites code. **No code was changed.** This is the last of the previously-uncovered
modules.

---

## 1. The community model at a glance

- **`community_posts`** — `visibility` private|group|hierarchy|public|archived,
  `status` active|hidden|deleted, `body` (raw) + `body_html` (sanitized), `kind`
  post|announcement, `pinned`, owning `group_id`.
- **`community_comments`** — threaded (`parent_id`), `status` active|…
- **`community_reactions`**, **`community_topics`** / **`community_post_topics`**
  (hashtag extraction).
- **`content_reports`** — `status` open|actioned|dismissed|escalated, UNIQUE
  `(subject_type, subject_id, reporter_id)` (one report per reporter).
- **`moderation_actions`** — hide|delete|restore|lock|unlock|mute|ban|escalate,
  `reason` NOT NULL (mandatory), `moderator_id`.

**The good news up front — protect these:**

1. **Reads are PDP-backed defence-in-depth.** `feed()` prefilters by status +
   visibility in SQL, then re-checks **every** candidate row through
   `canView()` → `AuthorizationService.isAllowed('community.post.view', …)`. Public
   is open, author always sees own, everything else defers to the PDP with
   `visibility`+`group_id` attributes. Visibility is not trusted from the SQL
   filter alone — good.
2. **Moderation is gated and audited.** The `moderate` route carries
   `authorize:community.moderate` (+ `webcsrf`); `act()` **requires a non-empty
   reason** and writes a `moderation_actions` row with the moderator id. Reporting
   is idempotent (UNIQUE per reporter → `already_reported`). This is a clean
   maker-checker-ish moderation trail.
3. **HTML is allowlist-sanitized, not "cleaned".** `ContentSanitizer` keeps a
   small tag allowlist and strips everything else (raw `body` kept for edit,
   `body_html` for render) — the correct posture, and CSP-safe.
4. **Authoring routes require auth + CSRF**; unauth feed reads are restricted to
   `public` by the service.
5. **User-level mute/ban is delegated to AccessControl**, not reimplemented
   (`act()` explicitly defers user-level actions elsewhere) — no parallel
   ban system.

The gaps are a pagination correctness bug, missing lifecycle cascade, and edit/
sanitization-on-update seams.

---

## 2. Findings, ranked

### CM1 — Feed pagination under-fills pages (and can hide content) because PDP filtering happens after the DB limit (MED)

`feed()` fetches `limit+1` rows from the DB, then drops any the viewer can't
`canView()`. Because the visibility SQL filter is deliberately broad (any logged-in
viewer's query includes `group`, `hierarchy`, **and `private`**, relying on
`canView` to reject), a page can come back **mostly filtered out** — the DB
returns 21 rows, `canView` rejects most, and the viewer sees a handful (or an
empty page) even though many viewable posts exist deeper in the table. There's also
no cursor/offset, so "load more" can't recover the skipped-over viewable posts.

Consequence: not a security hole (canView is correct), but a **correctness/UX
bug** — feeds appear sparse or empty for viewers whose visible content is
interleaved with lots of non-visible content, and pagination can't page past it.

**Fix direction:** either push the effective visibility scope into the query
(resolve the viewer's group scope via `GroupScopeResolver` and constrain
`group_id`/visibility in SQL so the DB returns mostly-viewable rows), or loop-fetch
until the page is filled, with a real cursor. Prefer the former (resource-light,
consistent with the menu/scope rules).

### CM2 — No reaction to author account/group teardown (MED — cross-review coupling)

Nothing in Community reacts to `account.deactivated/suspended/anonymized/merged` or
`group.archived/dissolved/merged` (grep: none). Consequences:

- A **deactivated/banned** author's posts stay `active` and visible.
- An **anonymized** author's posts still carry PII in `body`/`body_html` and their
  `author_id` still resolves — the Identity ID3 scrub doesn't reach content.
- A **merged** duplicate's posts/comments/reactions aren't re-pointed to the
  survivor (author attribution stranded).
- A **dissolved group's** feed keeps serving `group`/`hierarchy` posts.

**Fix direction:** subscribe to the Theme B teardown signals — hide/anonymize
content on author deactivate/anonymize (scrub `body`/`body_html`), re-point
authorship on merge (through the authorized path), and archive/hide a dissolved
group's feed.

### CM3 — Post edit doesn't re-sanitize / no edit-lifecycle guard (MED)

`createPost` sanitizes into `body_html`, but the raw `body` is "stored for edit,"
implying an edit path — and no update method re-runs `ContentSanitizer`, nor is
there a guard that a `hidden`/`deleted`/`locked` post can't be edited back into
visibility. If edit exists (or is added) without re-sanitizing, an author can
inject unsanitized HTML on update; if it doesn't guard status, a locked post can be
mutated.

**Fix direction:** ensure any edit path re-sanitizes `body_html` from `body` and
refuses edits on `locked`/`hidden`/`deleted` content; record an edit trail.

### CM4 — `archived` visibility vs `deleted`/`hidden` status is ambiguous, with no retention sweep (LOW–MED) — ✅ RESOLVED 2026-09-16

**Resolution:** Precedence documented + enforced — **status wins over visibility
for serving**: a row is served only when `status='active'`; `hidden`/`deleted`
are never served; `archived` is a visibility (active-but-out-of-default-feed),
orthogonal to status and never purged. Added a `deleted_at` retention anchor
(migration `…000082`, stamped on delete / cleared on restore) and
`ModerationService::purgeDeletedContent()` + `RetentionPurgeSweep`
(`community.retention-purge`, folded into the Theme C runner): bounded,
idempotent hard-purge of `deleted` posts/comments past the grace window,
cascading children, re-asserting the terminal predicate in the DELETE
(restore-safe) and leaving the append-only `moderation_actions` audit intact. See
`docs/TODO_REMAINING_BACKLOG.md` §A.2.


`visibility` includes `archived` while `status` includes `hidden`/`deleted` — two
overlapping ways to take content out of the feed, with no documented precedence and
no lifecycle that moves stale/soft-deleted content to a terminal/retention state.
Soft-deleted (`status='deleted'`) rows linger indefinitely.

**Fix direction:** document the precedence (status wins over visibility for
serving), and add a retention sweep (purge `deleted` older than N days; optionally
auto-`archived` old posts) into the Theme C unified runner.

### CM5 — No `Services/tests/` for the content/moderation lifecycle (MED)

No test directory. The PDP-backed `canView` (the security-critical read path),
moderation action application (hide/delete/restore mapping + mandatory reason),
report idempotency, and sanitization are untested.

**Fix direction:** add tests: `canView` per visibility level (incl. anon → public
only), moderation act mapping + reason-required, report dedupe, sanitizer allowlist
(script/onclick stripped). Red before CM1/CM3.

---

## 3. Suggested sequencing

1. **CM5** — tests (red), `canView` + sanitizer first.
2. **CM1** — fix feed pagination (scope-in-query + cursor).
3. **CM3** — re-sanitize + guard on edit.
4. **CM2** — consume account/group teardown signals.
5. **CM4** — status/visibility precedence + retention sweep.

## 4. Cross-review couplings (explicit)

- **CM2 ↔ Identity ID1/ID2/ID3 (Theme B):** content is a teardown consumer —
  author anonymize must scrub post PII (ID3), merge must re-point authorship (ID2).
- **CM1 ↔ Groups scope / menu resource-light rules:** push visibility scope into
  the query via `GroupScopeResolver` rather than post-filtering.
- **CM4 ↔ Notifications N1 / Events E-C1 / Streaming ST4:** retention/soft-delete
  with no sweep → unified runner (Theme C).
- **Moderation user-mute/ban ↔ AccessControl:** correctly delegated — protect that
  boundary (no parallel ban system).

No code was changed in the course of this review.
