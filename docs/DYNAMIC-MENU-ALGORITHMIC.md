# The dynamic menu, done by someone obsessed with best-case complexity

An addendum to `DYNAMIC-MENU-DESIGN.md`. Same feature; ruthless about the *lower
bound* of work per request and per byte of cache. The governing question is not
"how do I make a request cheap?" but **"what is the information-theoretic minimum
work for the answer, and how do I make that minimum the common case?"**

---

## 0. The theorist's reframing

Best-case analysis is usually a footnote (Ω, the luckiest input). Here we *design
the input distribution* so the lucky case is the case that actually happens. The
craft is entirely in **amortization and precomputation**: push all real work to the
rare write, so the overwhelmingly common read hits the best case by construction.

We optimise two separate cost axes, because they have different lower bounds:

- **Read cost** per navigation (the hot path). Target best case: **Θ(1)**, no DB,
  no allocation, ideally no bytes over the wire.
- **Space** in cache. Target: **O(1) per (user,scope)** — a handful of bytes, not a
  serialized tree.

---

## 1. The structural discovery that changes everything

`P = 41` distinct permission codes in the platform today.

**41 ≤ 64.** The entire permission universe of a subject fits in a **single 64-bit
integer** — one machine word. This collapses the problem from "database of grants"
to "bit arithmetic," and it is the hinge the whole design swings on.

Assign each permission code a stable bit index `b: code → {0..63}` (a compile-time
perfect hash — codes are known and finite). Then:

- A **subject's capability** in a given scope = a bitset `G` (which permissions they
  hold). One `uint64`.
- A **menu item's requirement** = a bitset `R` with exactly the bits it needs
  (usually one). Precomputed once at boot.
- **"Can the user see this item?"** = `(G & R) == R`. A single AND + compare.
  **Θ(1), branch-predictable, no memory traffic.**

Rendering a menu of *any* size is then a fold of AND-compares over a small array —
`O(items)` machine instructions, **zero** queries, **zero** allocations on a hit.
Menu size stops mattering: 10 items or 100, it is nanoseconds either way.

> If `P` ever crosses 64, we don't panic: we go to a fixed-width `uint64[⌈P/64⌉]`
> (2 words at P=128). The asymptotics are identical; the constant doubles. We are
> nowhere near it.

---

## 2. The scope dimension, without exploding space

Permission alone is not enough — the menu is **per scope**. Naively that is a
bitset per (subject × scope-group), which could be many. Two observations kill the
blowup:

**(a) Equivalence classes.** A subject's *effective* capability is identical across
every group where they hold the same role set. Menus don't vary per leaf group;
they vary per **distinct (role-set)**. In practice a subject has O(1) distinct
capability profiles (e.g. "as org_admin", "as leader of my branch", "as member").
So we cache **one bitset per profile**, and map scope → profile in O(1). Space per
subject is **O(number of distinct roles they hold)** — tiny and bounded, not
O(groups).

**(b) The bitset *is* the cache value.** We never serialize a MenuTree per user.
The cached artifact is the `uint64` capability word (per profile). The tree
structure — categories, labels, order, icons — is **identical for everyone** and
lives in one process-wide immutable catalog built at boot. Rendering = walk the
shared catalog, AND each item's `R` against the user's `G`. So:

> **Cache space = one machine word per (subject, profile).** Not a document. Not
> per-item. A `uint64`. Millions of users cost megabytes, not gigabytes.

---

## 3. Making the best case the common case (amortized to the write)

Best-case reads only pay off if writes are rare — which they are: grants change on
role assignment/revocation, not on navigation. So we do **all** the expensive PDP
work exactly once, at the write, and stamp a version.

- On any grant/config write (the writers already exist:
  `RoleAssignmentService`, `DelegationService`, `AccessRequestService`,
  `BreakGlassService`, `RuleService`, `AbacPolicyService`), recompute the affected
  profile's `uint64` **once** and `INCR` a version counter. Cost is charged to the
  actor who caused it, at the moment they caused it — perfect amortization.
- The read path never recomputes. It fetches the word by
  `key = subj:{id}:prof:{h}:v{ver}` — a single O(1) KV get of 8 bytes.

**Amortized cost per navigation → Θ(1)** with a constant of "one AND per visible
item," and amortized DB cost → 0 (writes dominate the compute, reads dominate the
frequency, and they never overlap).

---

## 4. Squeezing the hot path to its floor: three tiers, each a strict best case

Think of it as a cost cascade; each tier is the best case that makes the next tier
unnecessary. The design target is that Tier 0 serves the vast majority of hits.

| Tier | Condition | Work done | Cost |
|------|-----------|-----------|------|
| **0. Not-Modified** | client's `If-None-Match` == current `v{ver}` | compare two short strings, return `304`, empty body | **Θ(1), 0 DB, ~0 bytes, 0 alloc** |
| **1. Warm word** | version changed, but capability word is cached | 1 KV get (8 B) → AND-fold catalog | **Θ(items) instrs, 1 cache read** |
| **2. Cold recompute** | word absent (first login / post-write) | one grant snapshot → build word → store | **~8 queries once**, then never |

The ETag is literally the version stamp, so Tier 0 needs **no cache read at all** —
it is a string compare in the request handler. That is the true best case: the
server proves "nothing changed" without touching cache *or* DB, and sends no body.
For SSR, embed the same version token in the layout so the page's own conditional
request collapses to Tier 0 on every unchanged navigation.

---

## 5. Why this beats "just cache the JSON tree"

Caching a rendered tree is the intuitive move but it is strictly worse on both axes
a theorist cares about:

- **Space:** O(tree size × users) vs **O(1 word × profiles)**. Orders of magnitude.
- **Invalidation:** a JSON tree is invalidated by *many* unrelated changes (a label
  edit, a reorder, a new item, any grant change). The bitset separates **volatile
  authority** (the word, versioned) from **stable structure** (the catalog, boot-
  immutable). You invalidate only the 8 bytes, and only when authority truly moves.
- **Correctness/consistency:** the word is computed by the *same* grant read the PDP
  uses, so menu and route filter cannot disagree (see anti-drift test in the base
  doc). A pre-rendered tree can silently rot.

---

## 6. The catalog as a compile-time artifact (no per-request assembly)

Module-contributed `MenuItem`s are discovered **once** at boot and frozen into:

- an immutable, ordered array (cache-friendly, sequential scan on render), and
- a parallel `uint64[] requirements` array (item i's `R`), so the render loop is a
  tight `for (i) if ((G & R[i]) == R[i]) emit(i)` — vectorizable, no hashing, no map
  lookups, no branching on strings.

Category bucketing and empty-category pruning are done on the already-filtered
index list — `O(items)` with a small constant, on the rare Tier-1/2 path only;
Tier 0 does none of it.

---

## 7. Feature flags without re-introducing cost

Feature gating (`EffectiveConfigResolver`, inheritance-aware) is folded into the
**same word at write time**: a disabled feature simply clears its items' bits in the
profile word. So on the read path a feature-off item is *already* absent from `G` —
no separate flag lookup, no extra branch. Flag changes bump `configVersion`, which
composes into the ETag exactly like `grantVersion`. The hot path stays a single
AND.

---

## 8. Complexity summary (what the PhD actually cares about)

Let `N` = menu items, `P` = permissions (≤64 → one word), `R` = reads, `W` = writes,
with `R ≫ W`.

| Quantity | This design | Naive per-item PDP |
|----------|-------------|--------------------|
| Best-case read (Tier 0) | **Θ(1)**, 0 DB, 0 body | Θ(N) calls × ~8 queries |
| Typical read (Tier 1) | Θ(N) instrs, 1 KV get | Θ(N) calls × ~8 queries |
| Cold read (Tier 2) | ~8 queries once | same, every time |
| Amortized read (R≫W) | **→ Θ(1)** | Θ(N·8) always |
| Cache space / (user,scope) | **O(1) word (8 B)** | O(serialized tree) |
| Invalidation per write | **O(1) `INCR`** (+ recompute affected word) | scan / TTL wait |
| "Can see item?" primitive | **1 AND + 1 compare** | 1 multi-table PDP call |

The whole trick: reduce authority to **one machine word**, make the common request
prove freshness with **one string compare**, and pay the real cost **once, at the
write, charged to whoever caused it.** Best case by construction, and the best case
is what runs almost every time.

---

## 9. Guardrails (so cleverness stays safe)

- The bitset is an **acceleration of**, not a replacement for, `authorize:` filters.
  A wrong/stale bit hides or shows a link; the route still calls the PDP. Security
  is never a function of the cache.
- Bit indices are **assigned once and frozen** (a migration-checked map); reusing a
  retired code's bit would misgrant. The anti-drift CI test also asserts
  `bit(code)` is stable and `R` matches the route's declared permission.
- If Redis is down, `dummy` backup handler → every read falls to Tier 2 (correct,
  just not fast). Degrades in cost, never in correctness.
