# Onboarding: Group Placement & Inactivity Transfer

**Status:** ✅ Implemented 2026-09-20 · ✅ review queue (maker–checker) added 2026-09-20
**Modules:** `Referrals` (placement, transfers, transfer review queue), `Groups` (belonging), `Admin` (config)
**Tests:** `prospect_onboarding_placement_test.php` (79/0), `prospect_transfer_review_test.php` (117/0), `transfer_queue_views_test.php` (130/0), `join_group_side_effects_test.php` (32/0)
**SRS:** FR-REF-6, FR-REF-7, **FR-REF-7b**, FR-REF-8 (`docs/SRS_CURRENT_STATE.md` §5.8)

---

## 1. The three rules

| # | Rule | Where it lives |
|---|------|----------------|
| **R1** | **Every system user belongs to a particular group.** | `GroupMembershipService::ensureBelonging()`, asserted from `ContactBookService::ensureContactUser()` |
| **R2** | **A prospect automatically joins the mentor's/sponsor's group and is NOT given a choice of which group to join.** | `ProspectGroupResolver`, applied by `createContact()` / `bulkCreate()` / `recordDecision('join_group')` |
| **R3** | **Membership transfers to the group of the mentor/sponsor who follows them up (to attend their event) after X weeks of inactivity**, X being hierarchically-group-configurable. | `ProspectTransferService` + `group_configurations` capability `referrals.prospect_transfer` |
| **R3b** | **A body can require a second leader to approve that transfer before it happens** (maker–checker), also hierarchically-group-configurable and **OFF by default**. | `ProspectTransferReviewService` + the same capability's `requires_review` key (§4.5) |

---

## 2. R1 — everyone belongs

`GroupMembershipService::ensureBelonging($orgId, $userId, $groupId, $opts)`:

- **No-op** when the user already holds an active membership **anywhere**. Belonging is additive and is never *moved* by this method — moving is R3's job.
- Otherwise attaches the user to `$groupId` as an ordinary `member`, `source = system`, **no approval queue** (nobody has to approve being placed in the group their own mentor already belongs to).
- With no group to attach to and no existing membership it **fails loudly** (`NO_GROUP_FOR_BELONGING`) rather than inventing one: an unplaced user is a data gap to surface, not to paper over.

It is called whenever a contact's platform account is linked or created, and re-asserted for accounts linked *before* this invariant existed, so old contacts converge instead of staying unplaced.

Reachable through the Referrals seam `GroupMembershipPort::ensureBelonging()` → `GroupMembershipAdapter`, so the outreach module still depends on one narrow port rather than the whole Groups module.

---

## 3. R2 — placement is derived, never chosen

### 3.1 A mentor's "home" group

`ProspectGroupResolver::homeGroupOf($orgId, $userId)` ranks the user's **active** memberships:

1. a group they **lead** (`leader` / `coordinator` / `admin`) beats a group they merely attend;
2. then the **deepest** group in the hierarchy (National → Region → Area → Local Assembly → Fellowship → Senior Cell → Cell), because the most local body is where they actually bring people;
3. then the **earliest** joined.

Returns `null` when the user holds no active membership, and an **archived/torn-down group is not a home**. Ranking happens in PHP over two bounded reads (memberships, then `whereIn` on groups) rather than in a JOIN with an `ORDER BY` expression — no dialect-specific SQL, and no ambiguity between a *membership* status and a *group* status.

### 3.2 Placement

`placementFor($orgId, $mentorUserId, $requestedGroupId)` returns the mentor's home group. The submitted `$requestedGroupId` is **advisory only** — it is honoured solely when the mentor has no home group (so a brand-new leader's contacts are still placed rather than orphaned, and staff bulk sign-up — which passes the leader's own group — keeps working).

Applied at every write that sets a contact's group:

| Writer | Behaviour |
|---|---|
| `createContact()` | `assigned_group_id` = the owner's home group. A submitted value that differs is **overridden**. The result reports `placement_source: 'mentor_group'`. |
| `bulkCreate()` | Staff are bounded to their own scope (unchanged), and the group they pass **is** their home group, so the same rule holds from the leader's side. |
| `recordDecision('join_group')` | Targets the contact's **placement** (`assigned_group_id`), falling back to `placementFor()` for a legacy row with none. A submitted `target_group_id` **cannot relocate** a prospect. |
| `updateContact()` | `assigned_group_id` is **deliberately not editable**. |

So placement has exactly three writers — creation (derived), transfer (R3), and `onGroupTornDown()` (group lifecycle: merge survivor, else nearest live ancestor). A free-text field edit could otherwise move a person between groups with no sponsor re-parent, no membership change and no audit trail.

> **Interaction with the `join_group` decision requirement.** The standing rule that a prospect carries ≥3 dated decisions, one of which must be `join_group`, is unchanged — the decision still exists and is still the hinge of conversion→integration (membership + journey signal). What changed is *who picks the group*: the system, from the mentor.

---

## 4. R3 — inactivity transfer

### 4.1 The policy is hierarchical group config

Capability **`referrals.prospect_transfer`** in `group_configurations`, resolved by `EffectiveConfigResolver` **off the group that currently holds the contact** — so a body that wants a longer grace period sets it for its own subtree, and a child inherits its parent's value unless it overrides.

```json
{"enabled": true, "inactive_weeks": 8, "requires_review": false}
```

A bare integer (`8`), a numeric string (`"8"`) or the same object as a JSON string are all accepted. Normalization rules (`ProspectTransferService::normalizePolicy()`, which always returns all three keys):

| Input | Result |
|---|---|
| absent / not set / `null` / garbage | **OFF** (0 weeks, no review) — the default |
| `{"enabled": false, …}` or `0` | **OFF** |
| `8`, `"8"`, `{"enabled":true,"inactive_weeks":8}`, `'{"enabled":true,"inactive_weeks":8}'` | 8 weeks, review **OFF** |
| `{"enabled":true,"inactive_weeks":8,"requires_review":true}` | 8 weeks, review **ON** (§4.5) |
| `9999` / `-3` | clamped to `MAX_WEEKS` (104) / OFF |
| `{"enabled":true,"inactive_weeks":0,"requires_review":true}` | **OFF entirely** — a zero threshold disables transfers, so there is nothing to review |

Read back through `policyFor($orgId, $groupId)` (the whole shape), `thresholdWeeks()` (the X) and `requiresReview()` (the gate) — all three resolved off the group that currently holds the contact.

### 4.2 The decision (read-only)

`evaluate($orgId, $contact, $newMentorId)` writes nothing and returns the verdict plus everything needed to act on it:

| Verdict | Meaning |
|---|---|
| `inactive_threshold_met` (`due: true`) | Quiet ≥ X weeks **and** a different mentor in a different group is following up ⇒ transfer |
| `still_active` | Inside the window — the current mentor keeps the relationship |
| `same_mentor` | The owner following up their own contact; nothing to transfer |
| `same_group` | A different mentor **in the same body** ⇒ a sponsor re-parent, not a group transfer |
| `transfer_disabled` | No policy (or OFF) for that subtree |
| `new_mentor_has_no_group` | The follower has no home group ⇒ nowhere to take them |
| `no_activity_baseline` | Neither `last_contacted_at` nor `created_at` is set ⇒ never transferred on a guess |
| `no_new_mentor` | No follower supplied |

Inactivity is measured from `last_contacted_at`, falling back to `created_at`. **The evaluation happens before anything is written**, so the new mentor's own touch can never reset the clock it is being measured against.

### 4.3 The move

`apply()` runs in a transaction and:

1. **ends** the prospect's active `group_members` row in the old group (`leave()` with reason `inactivity_transfer`) — the row stays as history, its one-active slot is freed;
2. **opens** a membership in the new mentor's group as an assisted `system` join with `requires_approval = false` (a mentor following up is a valid invite source, so the target group admits them instead of queueing an approval);
3. **re-parents** the sponsorship (`SponsorshipService::assign(…, 'inactivity_transfer')`) so the upline matches the new belonging;
4. **re-points** `prospects.owner_user_id` + `assigned_group_id`, and records the touch (`last_contacted_at`, `follow_up_count + 1`);
5. **appends** provenance to `prospect_group_transfers`.

Issued referral attributions, points, certificates and past contributions are **untouched** — the person's history stays where it happened.

### 4.4 Provenance — `prospect_group_transfers`

Append-only (migration `000086`). One row per placement change:

`from_group_id` / `to_group_id` · `from_owner_user_id` / `to_owner_user_id` · `reason` · `trigger_type` + `trigger_id` (e.g. `event_invite` / `evt-77`) · `threshold_weeks` (**the threshold in force at the time**) · `days_inactive` (observed) · `previous_membership_id` + `membership_id` + `sponsorship_id` · **`request_id`** (the review request that authorized it, `NULL` for an automatic transfer — migration `000087`) · `note` · `created_by` / `created_at`.

`historyFor($orgId, $contactId)` returns the trail newest-first — the answer to "why is this person in this group?" without replaying follow-up history, and (when `request_id` is set) *who approved it*.

### 4.5 The review gate — maker–checker (`requires_review`)

A transfer moves a person between groups **and** re-parents their sponsor, so a body may want a second leader to sign off before it happens. That requirement is the third key of the same hierarchical capability — **never** env, never a global, and **OFF by default**, so a subtree that only set a week count keeps auto-applying exactly as before.

`ProspectTransferReviewService` mirrors `SponsorReassignmentService` deliberately, so the two queues gate, read and behave the same way:

| Step | What happens |
|---|---|
| **Queue** | A due transfer writes `prospect_transfer_requests` (from/to group + owner, the maker, an optional nominated approver, the reason, the trigger, and the **evaluation snapshot** — threshold in force, observed days inactive, verdict — so the checker sees the consequences before deciding) plus a `submit` row in the append-only `prospect_transfer_reviews` trail. **Nothing moves.** Idempotent per (contact, receiving mentor): a second invite while a request is open returns the existing one instead of stacking duplicates. |
| **Segregation of duties** | The maker cannot approve their own request (403), re-asserted in the service on top of the PDP combinator. |
| **Eligibility re-check** | Approval re-runs `evaluate()` against **current** state: a contact followed up while the request sat in the queue, a receiving mentor who has lost their home group, or a subtree whose policy changed ⇒ refused (409) and the request marked `blocked` with the fresh verdict — recorded, never silently applied, and still pending so it can be approved if the facts change again. |
| **No expiry** | There is deliberately **no TTL** and no expiry sweep: a request stays pending until a human decides it. What protects against stale facts is the eligibility re-check above — an aged request whose world has changed is refused (409) and marked `blocked`, still open for a decision, rather than being auto-closed or silently actioned. Age alone never closes a request, and never blocks one either. |
| **Approve** | Delegates to `ProspectTransferService::apply()` with `request_id` in the trigger — so the queue changes **who signs off**, never **what a transfer does**. The request records `decided_by`/`decided_at`/`transfer_id` and the *current* threshold/inactivity (the truth at the moment of approval, not the numbers from when it was queued). |
| **Reject / cancel** | Close the request without moving anyone; a closed request cannot be decided twice (409). The maker may withdraw their own request. |
| **Manual proposal** | A leader may propose a move outright, but it is judged by the **same policy**: a contact still inside the window produces a stored-but-`blocked` request that cannot be approved — no side door around the inactivity rule. (A pure sponsor re-parent with no group move belongs to `SponsorReassignmentService`.) |
| **Leadership scope** | The checker's queue is **not** an org-wide inbox. `GET /pending` filters every row through the same PDP scope check the rest of the platform uses (`canManageGroupScope('sponsor.reassign.approve', group)`, memoised per group), so a leader sees only requests touching their own subtree — **either** side of the move counts, because a leader should see takeovers *into* their cells as well as *out of* them. Out of scope reads as **404** (indistinguishable from absent, never 403), and `approve`/`reject`/`cancel` re-assert it before acting, so a hand-built POST is refused with `ACCESS_OUT_OF_SCOPE`. `SponsorReassignmentController` applies the identical rule keyed on the member's own primary group (`GroupScopeResolver::primaryMembershipGroup`), so both review queues behave alike — and neither invents a second scope implementation. |

Every step writes to the hash-chained audit log (`prospect.transfer.requested` / `.approved` / `.rejected` / `.cancelled`) alongside the transfer's own `prospect.group.transferred`.

**Surfaces** (all under `/referrals/prospect-transfers`, gated by the **frozen** `sponsor.reassign.approve` capability — the same duty as a sponsor reassignment, so no new permission bit was spent):

| Route | Who | What |
|---|---|---|
| `POST /` | maker (`auth` + `webcsrf`) | propose a transfer for a contact in their own book |
| `GET /pending` | checker (`authorize:sponsor.reassign.approve`) | the queue + maker form — a self-contained dashboard (`transfer_index.php`, localized in 6 languages, RTL-correct, double-submit CSRF) |
| `GET /{id}` | any authenticated | the request, its evaluation snapshot and its review trail (`transfer_show.php`, read-only) |
| `POST /{id}/approve` · `/reject` | checker (`webcsrf`) | decide |
| `POST /{id}/cancel` | maker/staff (`webcsrf`) | withdraw |

Discoverable as the PEOPLE menu item `people.transfers` (label `App.menuItems.people_transfers` in all six locales).

---

## 5. R3 in practice — the invite-link path

`captureGuestFromInvite()` is where a *different* mentor legitimately reaches someone else's prospect (the guest used **their** event link), so it is the trigger point:

1. **One person is one contact.** The guest is matched against the existing book on `email_hash`, else on phone via `phoneKey()` (separators dropped; `+233` / `233` / `00233` folded to the local `0…` form), so `024 123 4567`, `+233241234567` and `233241234567` are one person. A repeat invite **reuses** the contact instead of forking their history.
2. **Evaluate the transfer** against the state *before* this touch.
3. If due → **`requires_review` decides the branch**: OFF (default) ⇒ `apply()` and the follower becomes the owner; ON ⇒ `submitFromEvaluation()` **queues** it for a checker (§4.5) and nothing moves yet.
4. **Register the guest for the event either way.** The host may register a guest they do not (yet) own — `recordAttendance(..., bypassOwnership: true)` — because ownership moves only through the transfer, never as a side effect of an RSVP. Attribution still follows the receiving group.

The result reports `reused`, `transferred`, `transfer_queued` and the `transfer` payload, so the caller can tell a new lead from a takeover from a takeover *awaiting approval* — `transferred` is only true when the belonging actually moved, and a queued one carries `request_id`.

---

## 6. Wiring

```
Referrals\Config\Services::prospectGroups()     → ProspectGroupResolver(db)
Referrals\Config\Services::prospectTransfers()  → ProspectTransferService(
                                                      db, clock, prospectGroups,
                                                      AdminServices::effectiveConfig(),
                                                      GroupServices::memberships(),
                                                      sponsorships(),
                                                      AuditServices::auditLogger())
Referrals\Config\Services::prospectTransferReviews() → ProspectTransferReviewService(
                                                      db, clock, prospectTransfers,
                                                      AuditServices::auditLogger())
Referrals\Config\Services::contactBook()        → …, prospectGroups, prospectTransfers,
                                                      prospectTransferReviews
```

All three collaborators are **optional constructor seams** on `ContactBookService`: unwired, the service keeps its legacy behaviour (a caller-supplied group, no transfers, no queue), so existing callers and tests are unaffected. Production wiring passes all three.

Audit: every applied transfer writes `prospect.group.transferred` to the hash-chained `audit_log` with the from/to groups and owners, the threshold in force, the observed inactivity and the trigger.

---

## 7. Deliberately NOT done here

- ~~**No new routes/UI.**~~ **Done** — the maker–checker queue (§4.5) shipped with its dashboard, detail page, routes and menu item. Placement itself remains service-level: there is still no UI for "choose this contact's group", because R2 says a prospect is never offered a choice.
- **No pre-emptive unassignment sweep.** Transfers fire on the follower's touch, which is what the rule describes ("the mentor who follows up with them"). A nightly sweep that *pre-emptively* moved quiet prospects would relocate people nobody has asked to receive — deliberately not implemented.
- **No expiry sweep.** Review requests do not lapse on a clock: a pending request waits for a human, and the approve-time re-check is the only staleness guard. There is therefore nothing for a nightly job to close, and no `expires_at` column to interpret.
- **`onGroupTornDown()` unchanged.** It re-places contacts at the merge survivor / nearest live ancestor. Re-deriving each contact from its owner's *new* home group would be more precise but is an N-query fan-out during teardown; the roll-up is already tested and safe. Flagged, not changed.
- **No backfill of existing contacts.** Contacts created before this change keep their stored `assigned_group_id`. Placement is asserted from the next write onward; a one-off `spark` command to re-derive placements org-wide is easy to add if you want the historical rows converged.
