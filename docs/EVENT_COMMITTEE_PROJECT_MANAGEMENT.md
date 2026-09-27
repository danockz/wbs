# Event Committee — optional pre-event project management

**Status:** ✅ Implemented 2026-09-21 (schema, services, authority seam, sweep, routes, controllers, four pages, six locales, DI, close-out hook, tests). **Default OFF** everywhere: a body must enable the `event_committee` capability in hierarchical group config before any of it is usable.
**Migration:** `app/Modules/Events/Database/Migrations/2026-09-21-000090_CreateEventCommittees.php` (7 tables, idempotent)
**Support (pure):** `Events/Support/{CommitteeResponsibility,CommitteeOversight,CommitteeConfig,WorkPlan}.php`
**Services:** `Events/Services/{CommitteeService,EventWorkService,CommitteeDecisionService}.php`
**Authority seam:** `Events/Services/CommitteeAuthorityPort.php` + `Events/Services/DelegationAuthorityAdapter.php`
**Sweep:** `Events/Sweep/CommitteeAuthorityExpirySweep.php` (key `events.committee-expiry`, registered in `Shared/Config/Services.php`)
**Controllers / pages:** `Events/Controllers/{CommitteeController,EventPlanController}.php` → `Events/Views/{committee_console,event_plan,committee_hub,committee_queue}.php`
**Tests:** `committee_support_test.php` (228/0), `event_committee_test.php` (245/0), `committee_views_test.php` (172/0), `committee_workflow_test.php` (95/0) — suite total **248 files / 16,048 assertions / 0 failed**
**OpenAPI:** regenerated — 583 operations / 504 paths / 19 tags (`public/openapi.json`)

---

## 1. Why this existed as a gap

An event already had organizers (`event_staff_roster`), a logistics plan, expenses with maker-checker approval, ticketing, check-in and close-out. What it did not have was **a body accountable for delivering it**: nobody was named as responsible for a lane, there was no work breakdown with owners and dates, no way to see what was late, and no trail of who decided what before the event — decisions happened in a group chat and the platform only ever saw the money side of them.

The committee feature closes that without forking anything. It is **optional per event**, it lives entirely in the **pre-event phase** (formed while the event is `draft` or `published`, dissolved at close-out), and an event with no committee behaves **exactly as before**: no new gates, no new screens in the way, no changed defaults.

## 2. The four design decisions this was built on

1. **New Events tables, not a fork of `event_staff_roster`.** A roster row says "this person helps at this event"; a committee seat says "this person is responsible for this lane, holds this delegated capability, until this moment". Different lifetimes, different constraints (one seat per person per committee), different authority consequences — so `event_committees` + `event_committee_members`, and the roster is untouched.
2. **A full work engine.** Workstreams → tasks (owner, due date, status, percent, dependencies, blockers) → milestones, with roll-ups, late/at-risk detection and a topological order. Half a plan is worse than none: a committee that cannot see what is late cannot manage anything.
3. **Authority is time-bounded delegation of EXISTING bits.** No new permission codes were added — the budget stays frozen at 41 bits (`PermissionBits::count()`, asserted in test). A leader delegates `event.logistics.manage` to the chair; the chair sub-delegates the lane's own bit to a member (`finance` → `event.expense.submit`, never `event.expense.approve`). Every delegation is bounded to the event window and revoked when the seat, the committee or the event goes.
4. **Oversight is configurable per group, default OFF.** How much a leader must approve — formation and major decisions, every decision, or observe only — is a hierarchical group config value (`event_committee.oversight`), never env or a global.

## 3. The model

| Table | What it holds |
| --- | --- |
| `event_committees` | one per event (UNIQUE `event_id`): mandate, chair, `scope_group_id` (the event's group), `oversight_group_id` (whose leader is accountable), oversight mode, head-count limit, sub-delegation switch, grace days, status `active`/`dissolved` + dissolution trail |
| `event_committee_members` | one seat per person per committee (UNIQUE `committee_id, user_id`): responsibility, specific remit, `is_chair`, `delegation_id` (FK → `delegations` **ON DELETE SET NULL**), `delegated_permission`, `scope_mode`/`scope_group_id`, `effective_from`/`effective_to`, status `active`/`removed`/`expired` + removal trail |
| `event_workstreams` | a lane of work: owner, responsibility, weight, dates, **derived** `status`/`progress_pct`/`task_count`/`done_count` |
| `event_tasks` | the work: title, description, workstream, milestone, assignee, responsibility, status, priority, `progress_pct`, `due_at`, estimated/actual hours, blocker reason + timestamps, sort order |
| `event_task_dependencies` | the DAG: `task_id` waits for `depends_on_task_id`, typed (`finish_to_start`, `start_to_start`, `finish_to_finish`) with lag |
| `event_milestones` | proof of progress: date, weight, status `pending`/`met`/`missed`/`cancelled`, evidence, met at/by |
| `event_committee_decisions` | the oversight queue: kind, title, detail, amount, **`required_permission`**, oversight group, requester, status `pending`/`approved`/`rejected`/`cancelled`/`noted`, decider + note, `effect_json`, `effect_applied`. **No expiry column** — like every other review queue on the platform, a request stays pending until a human decides it |

### Vocabularies (fixed, in `Events/Support`)

* **Responsibilities** (11): `chair`, `programme`, `logistics`, `finance`, `communications`, `registration`, `media`, `checkin`, `certificates`, `feedback`, `general`. Each maps to **at most one existing bit**; `general` maps to none (participation only):

  | Lane | Delegated capability |
  | --- | --- |
  | chair / programme / logistics | `event.logistics.manage` |
  | finance | `event.expense.submit` (**submit only** — approval stays with the leader) |
  | communications | `notification.send` |
  | registration | `event.tickets.manage` |
  | media | `event.media.manage` |
  | checkin | `attendance.check_in` |
  | certificates | `event.certificate.manage` |
  | feedback | `event.feedback.manage` |
  | general | — (no delegation) |

* **Oversight rungs** (3): `formation_and_major` (default), `maker_checker_all`, `observe_only`.
* **Decision kinds** (6) → the capability an **approver** must hold: `budget` → `event.expense.approve`; `schedule`, `cancellation`, `publication` → `event.schedule.approve`; `governance`, `other` → `event.create`. Approving therefore demands the same authority as doing the thing directly, so the queue cannot become a way round an existing gate.
* **Effect vocabulary** (what a decision does when it takes effect): `chair.appoint`, `member.add`, `member.remove`, `task.status`, `task.assign`, `milestone.meet`, `milestone.miss`, `workstream.status`, plus `none` (record only). An unknown action is **dropped at request time** and **refused at apply time** — a decision never promises something the platform cannot do.

## 4. Authority: reach is not authority

The single most important rule in this feature. A user whose role assignment is *scoped* to a group can hold only check-in there; scope coverage says nothing about capability. So:

* Every governance write asks the platform's decision point through `CommitteeAuthorityPort::holds($org, $subject, $permission, $groupId)`, which the adapter answers with `AuthorizationService::isAllowed(new AccessRequest(attributes: ['group_id' => $groupId]))` — the **authoritative per-group** question, so MAC, segregation of duties, RuBAC denies, the scope model and **active delegations** all apply exactly as everywhere else.
* No PDP wired, empty subject, empty permission, or a thrown error ⇒ **refuse** (`return false`). A committee never gains authority because the ACL layer is absent.
* `scopeCovers()` / `scopeGroupsForUser()` exist and are used — but only as **READ bounds** for queues ("which rows may this actor see"), never as an authorization decision. Both controllers are free of `scopeCovers` (asserted).

Who may do what:

| Act | Authority asked |
| --- | --- |
| Form a committee, appoint/replace the chair, dissolve | `event.create` over the oversight group |
| Appoint a member (chair path) | the chair must still **hold** `event.logistics.manage`, and the group must allow sub-delegation |
| Work the plan (workstreams/tasks/milestones) | an **active seat** on the committee, *or* `event.logistics.manage` over the event's group |
| Record a decision | a seat, or `event.create` over the oversight group (a leader's own direction) |
| Approve / reject a decision | the decision's own `required_permission` over its oversight group — and **never** by its requester (SoD) |
| Withdraw a decision | its requester, the chair, or a holder of `event.create` |

**Window.** Authority is bounded to `event.ends_at` (or `starts_at`) **+ grace days** (config, 0–90, default 7). If that is already behind us the delegation is refused (`EVENT_WINDOW_CLOSED`) — an event cannot empower anybody after the fact. Duration is clamped to 1..365 days (`DelegationService`'s own limit). A seat stores `scope_mode = self` and `scope_group_id = oversight group`, so a member's reach is one person wide inside one group.

**End of authority.** Three independent paths, all revoking through the ACL layer (which cascades to anything sub-delegated from the grant):

1. `removeMember()` / resignation → seat `removed`, delegation revoked.
2. `dissolve()` and the close-out hook `onEventClosed()` → every seat's delegation revoked, open decisions cancelled, rows kept as history.
3. `expireDue()` via the registered sweep `events.committee-expiry` → seats past `effective_to` become `expired` and their authority is revoked. Idempotent; a second pass finds nothing.

`EventService` calls `onEventClosed()` on **both** closing paths (complete and cancel) and reports `committee_dissolved` in its result, so nothing a committee was empowered to do outlives its event.

## 5. Oversight: maker-checker, configurable, no expiry

`CommitteeDecisionService::request()` is the maker step. It resolves the group's rung from config and decides whether the leader must say yes:

* `observe_only` → nothing is blocked; the decision is recorded as `noted` (still visible, still audited) and its effect applies immediately.
* `maker_checker_all` → everything goes to the leader, whatever its kind or amount.
* `formation_and_major` (default) → formation, chair changes and **major** decisions: `schedule`, `cancellation`, `publication`, `governance`, and `budget` at or above `budget_approval_threshold`. **With no threshold configured, every budget decision is major** — a body that set no limit has not said what the committee may spend alone.

Requests are **idempotent per (committee, kind, title)**: a double click never opens two identical requests. Approval applies the effect **before** marking the row approved, so a decision whose effect fails **stays pending** with the reason visible (audited as `event.committee.decision.approval_blocked`) instead of reading as done. Approval re-checks the **current** world, not the one the request was written in: a committee dissolved or an event closed since ⇒ 409.

Effects route through the *existing* services (`EventWorkService`, `CommitteeService`), so a decision that moves a task still passes the plan's own gate, and a decision that appoints a chair still passes the governance gate. Nothing here bypasses another module's approval: money still goes through expenses, schedule changes still go through the event's own guards.

## 6. The work engine

`EventWorkService` owns the rows; `WorkPlan` owns the maths (pure, DB-free, unit-tested):

* **Progress is derived, never typed.** A `done` task is 100% whatever it stored; in-flight percentages are clamped to 0..99 so "done" is only reached by finishing; `cancelled` tasks leave **every** roll-up. A workstream's status is computed from its tasks (all done ⇒ `done`; any blocked ⇒ `blocked`; any late or due soon ⇒ `at_risk`), and the plan blends tasks (70%) with milestones (30%) when milestones exist.
* **Health** is a verdict, not a number: `blocked` > `behind` (late work or missed/overdue milestones) > `at_risk` (due soon or unassigned) > `complete` > `on_track`.
* **Risk per task:** `blocked` outranks lateness (a blocked task cannot be chased for being late), then `late`, then `due_soon` inside the look-ahead window; finished, cancelled and undated work is `ok`.
* **The graph stays a DAG.** `wouldCreateCycle()` is checked **before** the edge is written; self-dependencies are refused; re-adding an edge is a no-op. Completion is refused while a predecessor still gates the task (typed: `start_to_start` releases once work starts, `finish_to_start`/`finish_to_finish` on completion; a cancelled predecessor gates nothing) unless the actor explicitly forces it.
* **Status transitions** are a table, not vibes: `blocked → done` is refused (unblock first), `done → todo` is allowed (reopen), `cancelled → todo` is allowed, `done → cancelled` is not.
* **Reads:** `plan()` (workstreams with their tasks in topological order, the unstreamed bucket, milestones, roll-ups, attention), `board()` (five no-JS columns, urgent first then by due date), `myTasks()` (what do I owe, across events), `atRisk()`, `progress()`, `eventIdFor()` (so a POST to `/event-plan/tasks/{id}` can redirect back to the right event).

The work engine needs the **capability** enabled for the event's group but **not** a committee: an organizer may plan an event that has no committee at all.

## 7. Configuration

Capability `event_committee`, resolved through `EffectiveConfigResolver` by the existing `EventConfigPort` (mode `ancestor_default_child_override`), parsed by `CommitteeConfig`:

| Key | Default | Range / notes |
| --- | --- | --- |
| `enabled` | `false` | **OFF is off** — whatever else the row says, a disabled row yields `off()` |
| `oversight` | `formation_and_major` | one of the three rungs; unknown ⇒ default |
| `max_members` | `12` | clamped 2..50 |
| `chair_requires_approval` | `true` | chair changes go to the leader |
| `allow_subdelegation` | `true` | may the chair appoint on the leader's behalf |
| `grace_days` | `7` | clamped 0..90, added to the event window |
| `budget_approval_threshold` | `null` | `null` ⇒ every budget decision is major; negative/non-numeric ⇒ dropped; `0` is kept |
| `allow_crosscut` | `false` | include cross-cut groups in the delegation's reach (opt-in, per standing rule) |

Fail-closed everywhere: no group, no config row, unparseable JSON, or a **throwing** config store all yield `off()` (each asserted). `AdminConfigSeeder` ships one row for the demo org with `enabled: false`, so the shape is documented without turning the feature on for anybody.

## 8. Surfaces

**Routes** (`app/Config/Routes.php`). Per-event, inside the existing `events` auth group:

```
GET  events/{id}/committee                     CommitteeController::console
POST events/{id}/committee                     ::form            (webcsrf)
POST events/{id}/committee/dissolve            ::dissolve        (webcsrf)
POST events/{id}/committee/members             ::addMember       (webcsrf)
POST events/{id}/committee/chair               ::appointChair    (webcsrf)
POST events/{id}/committee/decisions           ::requestDecision (webcsrf)
GET  events/{id}/plan                          EventPlanController::console
POST events/{id}/plan/{workstreams|tasks|milestones}   (webcsrf)
```

Cross-event, `auth` at the group level (the services bound the rest to the actor's seats and scope):

```
GET  event-committees                          ::hub
GET  event-committees/decisions                ::queue
POST event-committees/member/{id}/remove       ::removeMember      (webcsrf)
POST event-committees/member/{id}/responsibility ::updateMember    (webcsrf)
POST event-committees/decisions/{id}/{approve|reject|cancel}       (webcsrf)
POST event-plan/workstreams/{id}[/delete]      update / delete     (webcsrf)
POST event-plan/tasks/{id}[/action|/delete|/dependencies[/remove]] (webcsrf)
POST event-plan/milestones/{id}/{action|delete}                    (webcsrf)
```

Two deliberate choices, both asserted by tests:

* **No new permission bits and no `authorize:` filter on these routes.** `AuthorizeFilter` expresses one permission (optionally `,any`) — it cannot say "this event's committee member *or* a holder of `event.logistics.manage`". So the coarse routes are `auth` + `webcsrf` and the **authoritative decision is the service's PDP gate**, which knows the event's group and the actor's seat. The member paths are singular (`member/{id}/…`) so no other module's line-based route scanner mistakes them for its own.
* **The menu item carries no permission mask.** `events.committees` → `event-committees` is a *personal* workspace (my committees / awaiting my decision / my open tasks), bounded server-side like `events.mine`. Masking it with `event.logistics.manage` would hide it from a finance-lane member, who holds `event.expense.submit` and still needs to see their own committee.

**Pages** — four self-contained dark pages, no JavaScript, no external assets (CSP-clean), RTL-correct, all copy localized, every act a PRG form with a `_csrf` bound to `$csrf`:

* `committee_console.php` — mandate, chair, oversight group and mode, members with the capability each holds and the window it runs to, lanes nobody holds, appoint/change-lane/remove/resign/appoint-chair forms, the pending decisions with approve/reject/withdraw, a request form with the effect picker, what needs attention, the group's configuration in force, and the dissolve form. Gated-OFF and no-committee states say so and offer nothing that cannot work.
* `event_plan.php` — derived progress + health, attention buckets, my open tasks, every workstream with its tasks in dependency order (per-task edit, start/block/unblock/done/cancel/reopen/delete, add/remove dependencies), the unstreamed bucket, add-workstream/task/milestone forms, the five-column board, and milestones with meet (evidence) / miss / reopen.
* `committee_hub.php` — read-only cross-event hub: my seats, what awaits my decision, what has been decided, my open tasks.
* `committee_queue.php` — the oversight queue with status/kind filters (GET), per-row approve / reject-with-note / withdraw, the decision's effect, and links into each event's console and plan.

**Menu:** `events.committees` in the EVENTS category (order 42, icon `list`), label `App.menuItems.events_committees` in all six locales.
**i18n:** three new groups in `Events/Language/<loc>/Events.php` × 6 (`committee`, `plan`, `decision`), 320+ keys per locale, parity verified by `catalog_parity_test.php` (218/0) and by the render tests' raw-key-leak assertions.

## 9. What deliberately does not exist

* **No new permission bits** (budget frozen at 41) and **no approval bit is ever delegated** — segregation of duties survives the committee.
* **No fork** of `event_staff_roster`, expenses, scheduling or ticketing: the committee tracks and asks; the existing modules still decide.
* **No expiry** on the oversight queue, and no TTL anywhere in it.
* **No JavaScript**, no PDF, no notification fan-out of its own (a decision is audited and visible; shouting about it is the notifications module's job).
* **No committee for a closed event**, and no authority that outlives one.

## 10. Testing

| File | Proves |
| --- | --- |
| `Events/Support/tests/committee_support_test.php` (228/0) | the lane vocabulary and its two invariants (no approval bit delegated, every bit in the frozen catalogue), the oversight rungs and the full `requiresApproval` matrix, config fail-closed + clamping + round-trip, and all of `WorkPlan`'s arithmetic (completion, risk, roll-ups, derived workstream status, milestone health, cycle refusal, topological order, predecessor gating, dates) — plus that every vocabulary label resolves in all six locales |
| `Events/Services/tests/event_committee_test.php` (245/0) | the services against an in-memory query builder: gating fail-closed, **reach ≠ authority** (a scoped actor with no capability is refused, and the PDP is asked with the group), formation rules and idempotency, the authority window (`event end + grace`), lanes and sub-delegation, head-count, reactivation instead of duplication, removal/resignation/dissolution revoking authority, close-out and the expiry sweep, the whole maker-checker matrix (self-approval, outside-scope, effect failure leaving the decision pending, dissolved committee, closed event, **no expiry**), the work engine's guards, and the console reads |
| `Events/Views/tests/committee_views_test.php` (172/0) | six-locale key parity for the three groups, and per page: locale-aware `<html lang dir>` (RTL for Arabic), `_csrf` in **every rendered** POST form, the right endpoints, no raw key leaks, no JS/inline handlers/external assets, and the gated-OFF / no-committee / empty-plan / empty-queue states |
| `Events/Views/tests/committee_workflow_test.php` (95/0) | wiring: routes webcsrf-guarded with real targets and no invented bits, controllers thin and PRG with no DB access and no `scopeCovers`, the adapter asking the PDP with the group and failing closed, DI handing the same seam to all three services, the close-out hook on both paths, the sweep registered, the schema's uniqueness/FK/no-expiry guarantees, the menu item and its six labels, the seeder shipping the capability disabled, and every literal language key resolving |

## 11. Go-live notes

1. `composer install` (vendor is absent in the sandbox), then `php spark migrate` — migration `000090` is idempotent (`CREATE TABLE IF NOT EXISTS`, and it calls `resetDataCache()` because raw DDL does not invalidate CI4's connection-lifetime schema cache).
2. Nothing changes until a leader enables `event_committee` for their group (`enabled: true`, plus the rung and threshold they want). Default OFF is the shipped state.
3. `public/openapi.json` was regenerated (583 operations / 504 paths); the freshness test enforces that it stays in step with the route table.
4. The sweep `events.committee-expiry` needs to be in the scheduler that runs the other Events sweeps; it is registered in `Shared/Config/Services.php::sweepRegistry()` and covered by `sweep_registration_test.php`.
5. Real MySQL 8.4 (not 9.x — `native_password` is gone there); the JSON columns (`effect_json`) and `DATETIME(6)` timestamps assume it.
