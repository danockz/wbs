# Dynamic menu — prototype (bitset core) results

Working prototype of the best-case design. Code in
`app/Modules/Shared/Navigation/`; verify with the stub harness + benchmark below.
Sandbox has no MySQL/framework, so the grant read (Tier 2) is injected as a
`capabilityProvider` closure — the real one lives in AccessControl later.

## Files

| File | Role |
|------|------|
| `PermissionBits.php` | FROZEN, append-only `code -> bit` map (P=41). One AND builds a requirement mask. Fail-closed `UNSATISFIABLE` sentinel for unknown codes. `selfCheck()` guards invariants. |
| `MenuItem.php` | Immutable item; **precomputes** its `requiredMask` at construction. `visibleTo($word)` = `($word & mask)===mask` — the whole access decision, Θ(1). |
| `MenuCategory.php` | Fixed, ordered, relabelable category buckets. |
| `CoreMenuProvider.php` | Starter catalog (Overview/People/Groups/Events/Learning/Reports/Access/Admin). Permission codes match route `authorize:` filters. |
| `MenuCatalog.php` | Boot-frozen, process-shared structure + parallel `masks[]` array for a tight render loop. |
| `MenuService.php` | Three-tier cascade: Tier 0 ETag (controller), Tier 1 cached word, Tier 2 compute-once. `render()` = AND-fold + bucket + prune. Masks cold word to known bits (defense-in-depth). |
| `tests/menu_stub_test.php` | 24 assertions (invariants, visibility, projection member/leader/admin, anti-drift, tier cascade, ETag). |
| `tests/menu_bench.php` | Throughput + 500k-user cache footprint model. |

## Run

```bash
php app/Modules/Shared/Navigation/tests/menu_stub_test.php   # 24/24 PASS
php app/Modules/Shared/Navigation/tests/menu_bench.php
```

## Measured results (sandbox, PHP 8.4, single core)

**Correctness:** 24/24 — same catalog projects to different menus for member
(public items only) / leader (groups+events+reports) / admin (all incl.
access+admin); typo permission fails closed; version bump triggers exactly one
recompute; ETag varies by version and scope.

**Hot-path throughput (Tier 1):**
- Core AND-fold: **~14.7 ns per item-decision**, ~309 ns per full-menu render →
  **~3.2 M full-menu renders/sec/core**.
- Full `render()` incl. bucketing + array serialization: ~3.6 µs →
  **~275 k/sec/core**. (And Tier 0 / 304 does *none* of this.)

**Cache footprint @ 500,000 concurrent users × 2 profiles (1,000,000 words):**
- Ideal payload (8-byte words): **7.63 MB**
- Realistic Redis incl. per-key overhead (~80 B): **76 MB**
- Contrast — caching a rendered JSON tree per user (~3.1 KB each): **2.89 GB**
  (**~39× larger**) and invalidated by far more events.
- Shared catalog: built **once per process** (~7 KB), reused by every request.

## What this proves for the 500k target

- **Read cost is decoupled from user count and menu size.** A menu render is bit
  arithmetic; adding users adds only 8 bytes of cache each. 500k users of menu
  authority is ~76 MB of Redis — trivially co-resident, even replicated.
- **The common request does ~zero work.** Tier 0 (ETag/304) returns without cache
  or DB; Tier 1 is one 8-byte KV get + nanosecond fold; Tier 2 (the only DB hit)
  runs once per grant change, charged to the writer.
- **Invalidation is O(1)** (`INCR` a version) — no scan, no fan-out — which is what
  makes it safe at fleet scale.

## What happens when there's an update?

"An update" is really FOUR distinct events. Each has its own propagation channel;
none requires a cache scan, and the common read stays best-case throughout.

| Update | Trigger | What moves | Propagation | Read-path effect |
|--------|---------|-----------|-------------|------------------|
| **1. Authority change** — a user's grants change | assign/revoke role, delegation, break-glass, rule/ABAC edit | `version` (per-subject/org counter) → **`INCR`** | old word key `…:v{n}` is orphaned; next read misses → Tier 2 recompute **once**, re-cached under `…:v{n+1}` | first read after change = one grant read; then Tier 1 again |
| **2. Feature flag flip** — a capability turned on/off for a group | `EffectiveConfigResolver` write | folded into `version` the same way (bit set/cleared in the recomputed word) | same as #1 | same |
| **3. Scope switch** — user changes active scope | user action | `scopeGroupId` term in key + ETag | different key → its own cached word (per profile) | Tier 1 hit for a scope already visited; else one Tier 2 |
| **4. Structure change** — the menu catalog itself changes (new item, relabel, reorder, re-permission) | **deploy** | **`catalogVersion()`** — xxh128 fingerprint of all item id/label/route/order/category/mask | folded into BOTH the ETag and the word cache key | see below |

### Why structure changes needed a real fix

The naive assumption "a deploy restarts the process, so structure can't be stale"
is **false at fleet scale**. During a rolling deploy, some nodes serve the new
catalog and some the old. A client holding the new ETag could revalidate against an
old node, get `304`, and be pinned to a stale menu indefinitely.

Fix: the ETag and the word cache key now include `MenuCatalog::catalogVersion()` —
a deterministic fingerprint that is **identical on nodes running the same catalog**
and **different the instant any item changes**. Consequences, all tested:

- A structure change ⇒ different fingerprint ⇒ different ETag ⇒ every client
  revalidates and gets the new menu, **regardless of which node answers** (no
  reliance on deploy timing).
- Two nodes on the same build compute the **same** fingerprint ⇒ a client bouncing
  between them during a deploy never flip-flops.
- The word cache is **re-keyed** by the fingerprint too, so a newly added item that
  needs a bit the old word never carried triggers exactly one recompute — the old
  word can't silently under-grant.
- A **relabel-only** change (no permission delta) still moves the fingerprint, so
  cosmetic updates propagate too.

### Cost of an update (still best-case-friendly)

- Authority/flag/scope updates: **O(1)** to invalidate (`INCR` / new key); **one**
  Tier-2 recompute per affected (user, scope), amortized to the write.
- Structure update (deploy): **O(1)** to invalidate globally (the fingerprint
  changes for everyone at once); each user pays **one** Tier-2 recompute on their
  next visit, then back to Tier 1/0. No mass precompute, no cache flush needed —
  stale entries just age out by TTL.
- No update path scans or enumerates keys; invalidation is always by version/key
  change, which is what keeps it safe at 500k.

## Toward "a mixture of dynamism and hardcoding" (your note for later)

The prototype already splits along that seam:
- **Hardcoded / compiled:** the permission→bit vocabulary and the menu catalog
  structure (boot-frozen, shared, immutable). Cheapest possible to read.
- **Dynamic:** *who* holds which bits (capability word from grants, per scope),
  computed at write time and versioned.

Natural next optimisations when you formalise the 500k path:
1. **Precomputed role-word table.** Since capability is a function of role-set, cache
   `roleSet -> word` (a few dozen entries org-wide) and compose a user's word by
   OR-ing their roles' words — Tier 2 becomes an in-memory OR, not a grant query.
2. **Edge/CDN Tier 0.** The ETag is a pure function of (version, scope); a CDN or
   reverse proxy can serve 304s without touching the app tier at all.
3. **Client-held word.** Ship the 8-byte word (signed) to the client; SPA renders
   the menu locally and only revalidates the version — near-zero server involvement
   between grant changes.
4. **Fixed-width `uint64[]`** if P ever exceeds 64 (currently 41; 23 bits of head-
   room). Identical asymptotics, constant doubles.

Route filters remain the security boundary throughout — the bitset only accelerates
*display*; a stale bit can only mis-show a link, never bypass the PDP.
