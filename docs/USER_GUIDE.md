# Win–Build–Send (WBS) Platform — User Guide

Welcome to the WBS platform. This guide is organised by **who you are** (your
role/level) and shows you **how to do the things that role can do**. Start with
[Everyone](#part-1--everyone-getting-started), then jump to the section for your
role.

> **How access works (read this first).** What you can *do* is decided by three
> things working together:
> 1. **Your role(s)** — a bundle of permissions (e.g. *Event Organizer*).
> 2. **Your scope** — which group(s) a role applies to. A role can be granted
>    org-wide or for one group and its sub-groups.
> 3. **Feature configuration** — many features are switched on **per group**, and
>    settings are **inherited** from parent groups. If a feature looks missing, it
>    may simply be turned off for your group (see [Admins](#part-9--organization-administrator)).
>
> Every sensitive action is **audited**, and approval steps enforce **segregation
> of duties** — the person who *requests* something can never be the person who
> *approves* it.

---

## Roles at a glance

| Level | Role | What it's for |
|------:|------|---------------|
| 1 | **Member** | Everyone. Participate, give, refer, learn, see your progress. |
| 2 | **Event Organizer** | Plan and run events, streams, meetings, tickets, logistics. |
| 2 | **Notification Manager** | Send announcements and campaigns. |
| 2 | **Community Moderator** | Keep feeds and live chat healthy; manage gamification. |
| 2 | **Reporting Analyst** | View and export dashboards and reports. |
| 3 | **Finance Officer** | Approve refunds, contributions, expenses, provider connections. |
| 4 | **Organization Administrator** | Full control: roles, policies, identity, settings, group config. |
| ★ | **Special access** | Time-boxed *access requests*, *delegation*, and *break-glass*. |

A person can hold **several roles at once** (e.g. a Member who is also an Event
Organizer for one group). Your effective abilities are the **union** of all your
roles within their scopes.

---

# Part 1 — Everyone (getting started)

## 1.1 Create your account & sign in

- **Register:** submit your details on the sign-up form. Depending on your
  organisation's policy you may need to verify email and/or phone, and minors may
  be gated by an age policy.
- **Invited by your group?** You'll receive a **set-password / accept-invite**
  link. Open it, choose a password, and your account activates.
- **Sign in:** use the **Login** page. If you have multi-factor turned on, you'll
  be prompted for your **MFA** code after your password.
- **Social sign-in:** you can sign in with a linked Google/Microsoft account, and
  link or unlink social logins later from your profile.

**Password rules:** at least **12 characters** with a mix of character types, and
your password is checked against a **known-breach list** — if it's been exposed in
a breach you'll be asked to pick another. (Your organisation can tune this.)

## 1.2 Turn on multi-factor authentication (MFA)

1. Go to your security settings and choose **Enrol authenticator (TOTP)**.
2. Scan the QR/secret into your authenticator app.
3. Enter the 6-digit code to **confirm**. Save the **recovery codes** somewhere safe.

After this, sign-in asks for a code, and sensitive actions may require you to have
a recent strong-MFA verification.

## 1.3 Manage your sessions & profile

- **See "who am I":** your profile shows your identity, roles, and groups.
- **Active sessions:** view every device/session signed in as you, and **revoke**
  any you don't recognise.
- **API tokens:** if you integrate scripts, you can mint **scoped, hashed** API
  tokens and rotate/refresh them. Treat them like passwords.

## 1.4 Your home dashboard

Your **dashboard ("my home")** is self-scoped — it shows *your* points, rank,
streaks, badges, group memberships, and recent activity. No special permission is
needed; it's just for you.

## 1.5 Join and take part in groups

- **Browse groups:** the public directory (`/g`) lists groups **by location**,
  nested Country → State/Region → City to mirror the world-geography reference
  data; each group links to its own landing page. Groups whose location isn't
  set yet gather under an "Other locations" section.
- **Membership:** you can belong to **multiple groups**, each with a type/role
  (e.g. member, volunteer). Some groups admit you instantly; others require
  **approval** — you'll see your request move from *pending* to *approved*.
- **Leave a group** at any time from your memberships list.

> **Important — you're not limited to your own groups.** You may attend any event
> you're allowed into and give to **any** group's cause. Credit for what you do is
> attributed to the **receiving** group (the event's or cause's group); if there's
> no receiving group, it falls back to your own primary group, and otherwise to the
> organisation. Your personal total is the **sum of everything you do across all
> groups**.

## 1.6 The Win–Build–Send activities you can do as a member

**Win — invite & refer**
- **Create a referral link:** generate your personal cloaked invite link.
- **Share it:** when someone opens `/(your link)` and signs up, the conversion is
  **attributed to you** automatically.
- **See your impact:** view your **referrer analytics** and your **sponsor chain**.

**Build — show up & grow**
- **Attend events:** RSVP/register, then **check in** at the event (QR code, a
  kiosk, or a code the organiser gives you). You can attend events across groups.
- **Give to a cause:** open a cause, see its **progress bar**, and **contribute**.
  Giving during a live stream works **without leaving the stream page**. You'll get
  confirmation once the payment provider confirms the money — nothing is faked.
- **Learn:** **enrol** in a course, work through lessons, and complete it.
- **Request a certificate:** where an event/course offers one, request your
  certificate; each has a public **verification** page so it can be trusted.

**Send — participate & follow up**
- **Community feed:** create posts, comment, and react. **Report** anything that
  breaks the rules.
- **Give feedback:** fill in event feedback forms and quizzes.
- **Follow-ups:** if your group runs follow-up care, your interactions (outcomes,
  needs, next steps) are recorded and count as Build/Send activity.

## 1.7 See your progress (gamification)

- **Points & leaderboards:** you earn points for participating; leaderboards rank
  you **org-wide and within each group**.
- **Achievements, streaks, ranks:** unlock badges, keep streaks alive, and climb
  ranks. Group **campaigns/projects** may offer time-boxed targets with their own
  awards.

## 1.8 Need more access?

If you need to do something your role doesn't allow, **submit an access request**
(see [Special Access](#part-8--special-access-everyone-can-request)). You state the
scope, reason, and how long you need it; an approver decides.

---

# Part 2 — Event Organizer

You plan and run events end to end. Your permissions include `event.create`,
`attendance.check_in`, `stream.create`, `stream.moderate`, `meeting.manage`,
`venue.manage`, and the ticket/logistics/expense/feedback/certificate/media
management rights.

## 2.1 Create and schedule an event

1. **Create the event** (title, group, mode — in-person / virtual / hybrid,
   times). It's credited to the **event's group**.
2. **Schedule approval:** if your organisation requires it, submit the schedule
   for approval (`event.schedule.approve` holder signs off).
3. **Publish** when ready — the event becomes visible/RSVP-able.

## 2.2 Venues & virtual access

- **Manage venues:** add/edit venues (address, capacity) for in-person events.
- **Virtual access:** for online events, configure the join/embed access.

## 2.3 Tickets & orders

- **Ticket types:** create tiers with prices/quantities.
- **Promo codes & holds:** issue promo codes and place ticket holds.
- **Orders:** track the order lifecycle (checkout → paid → reconciled) and issue
  refunds where policy allows.

## 2.4 Attendance & check-in

- **QR check-in:** attendees show a QR; scan to check in.
- **Kiosk mode:** set up a **kiosk** for self check-in at the door.
- **Manual check-in:** check someone in by hand when needed.
- **Offline scans:** captured offline and reconciled later — nobody is turned away
  by a bad signal.
- **Cross-group attendance:** someone from another group can attend; the system
  keeps one active attendance and attributes it to the right group.

## 2.5 Logistics

Plan the operational side: **catering, seating, staff, suppliers, resources,
needs**, and a logistics **plan/projection** you can refresh as numbers change.

## 2.6 Live streaming

1. **Create a stream** and add one or more **destinations** (YouTube, Twitch,
   Facebook, custom RTMP…).
2. **Provision** the destinations, then **go live**, and **end**/**archive** when done.
3. **Console & co-hosts:** run the broadcast console, invite **co-hosts**, issue
   co-host tokens, and manage **overlays**.
4. **Engagement:** run **polls**, watch **reactions**, and moderate **live chat**
   (chat is always platform-native, audited and moderated).
5. **In-stream giving:** attach a **cause + giving widget** so viewers can give
   without leaving the page.
6. **Honest metrics & relay health:** viewer numbers are tagged as exact or
   estimated (never fabricated). If the relay degrades, an **incident** opens with
   alerts and a documented **bypass** procedure — metrics are marked degraded
   rather than pretending everything is fine.

## 2.7 Meetings/webinars

**Manage meetings** on providers like Zoom, Google Meet, or Teams. The platform
creates the real meeting and issues per-user join links; provider presence is used
as **candidate evidence** for attendance, never auto-marked.

## 2.8 After the event

- **Feedback forms & quizzes:** build forms, add questions, open/close them, and
  **review** scored responses; view **aggregates**.
- **Certificates:** issue/request certificates for attendees; each is verifiable.
- **Media:** upload event media — it's scanned and EXIF-stripped by default and
  goes through a **review** step before it's shown.
- **Expenses:** submit event expenses (`event.expense.submit`); a Finance Officer
  approves and reconciles them.
- **Event report:** roll up a per-group event report snapshot.

---

# Part 3 — Notification Manager

You hold `notification.send`. You reach members with the right message on the right
channel.

**How to send:**
1. **Preference centre:** members control which channels they accept — respect it;
   the platform enforces it.
2. **Compose a campaign** (audience, channel — email/SMS/push, content).
3. **Broadcasts** that are large or sensitive may need **approval**
   (`notification.broadcast.approve`) before they go out.
4. **Send** and track delivery.

> Notifications go out through configured providers with documented **fallbacks**,
> so a single provider outage doesn't silently drop messages.

---

# Part 3b — Course Author / Instructor

Holders of `course.create` build learning content; `course.completion.override`
lets you correct a learner's completion record.

**How to build a course:**
1. **Create the course** (title, description, group).
2. **Add lessons** and a **syllabus**.
3. **Publish** it so members can **enrol** and work through lessons.
4. **Track learners:** view enrolments; where justified, **override a completion**
   (e.g. credit prior learning) — the override is audited.

Members enrol and complete lessons themselves (see [Everyone → Learn](#16-the-winbuildsend-activities-you-can-do-as-a-member)).

---

# Part 4 — Community Moderator

You hold `community.moderate`, `stream.moderate`, and `gamification.manage`.

**Moderate the feed**
- Review **reported** posts/comments in the moderation queue.
- Take **moderation actions** (hide, warn, remove) — each action is recorded.

**Moderate live streams**
- Moderate **live chat**, manage polls/reactions, and step in on a broadcast when
  needed.

**Manage gamification**
- Configure **achievements, streaks, ranks**, and **activity categories/catalog**;
  create and run **group campaigns/projects**. You can disable definitions without
  destroying history.

---

# Part 5 — Reporting Analyst

You hold `report.view` and `report.export`.

- **Dashboards:** open reporting dashboards for the org and for groups you can see.
- **Export:** export data sets/reports (e.g. CSV) for offline analysis.
- **Snapshots:** capture point-in-time report snapshots where offered.

Your access is **read/export only** — you can't change operational data, which
keeps reporting trustworthy.

---

# Part 6 — Finance Officer

You hold `contribution.refund.approve`, `contribution.manage`,
`integration.connection.approve`, and `event.expense.approve`. You're the
**checker** on money movements.

## 6.1 Refunds (maker-checker)

1. Someone **requests** a refund (`contribution.refund.request`).
2. You **review** it and **approve** or reject. **You cannot approve a refund you
   requested** — segregation of duties is enforced.
3. Approved refunds are executed against the provider; history is never rewritten.

## 6.2 Contributions & causes

- **Manage causes** and contribution records.
- **Reconcile** incoming contributions; posted ledger rows are immutable —
  corrections are new entries, not edits.

## 6.3 Event expenses

Review submitted expenses, **approve**/**reimburse**, and **reconcile** them
against the event budget.

## 6.4 Approve provider connections

When someone configures a **payment/notification provider connection**, you're the
approval step (`integration.connection.approve`) before it can go active — so money
and messaging only flow through reviewed, approved integrations.

---

# Part 7 — Integrations (Provider Configuration)

Anyone with `provider.configure` manages how the platform talks to outside
providers. Two paths, depending on the provider:

## 7.1 No-code: connector profiles (conforming providers)

If a provider fits a certified protocol family, onboard it as a **connector
profile** — pure configuration, no code:
1. **Create the profile** (family, canonical operation, approved host, field
   mappings). The server **validates** it against a safe, finite vocabulary.
2. Move it along the **certification path**: draft → sandbox-verified →
   security-review → finance-review → approved → active.
3. **Connect:** create a **connection**, add **credentials** (stored encrypted,
   never shown again), **test**, **submit**, and **activate**.

## 7.2 Custom-adapter SDK (non-conforming providers)

If a provider can't be expressed as a profile, it's added as a **versioned, signed,
reviewed module** — an extension of the catalogue, *not* runtime code injection.
Full details in **`docs/custom-adapter-sdk.md`**. Lifecycle:

1. **Register** an allowlisted adapter class — its declared **manifest**
   (capabilities, hosts, credentials) is validated.
2. **Contract-test** it — the SDK conformance suite must pass; on pass the manifest
   is **signed**.
3. **Advance** to security review, then **approve** (a *different* person than the
   submitter — segregation of duties).
4. **Activate** — the manifest is **published to the shared catalogue** and the
   rest of the platform uses it exactly like a built-in provider.
5. **Revoke** if needed; the published entry is deprecated and history preserved.

## 7.3 Reliability

- **Fallback matrix:** see, per feature, the primary provider path and its
  documented fallback.
- **Circuits:** watch provider **circuit-breaker** health and **reset** a circuit
  after you've fixed the underlying issue.

---

# Part 8 — Special Access (everyone can request)

These workflows let you get *more* access safely and temporarily.

## 8.1 Access requests (the normal way to get more)

1. **Submit a request:** state the **scope** (which group/permission), a **reason**,
   and a **duration**.
2. An approver with `access.request.approve` **approves/rejects**. They **can't
   approve their own** request.
3. Approved access is **time-boxed** — it **expires** automatically. You can
   **renew** before it lapses, and it can be **revoked** early.

## 8.2 Delegation of authority

If you hold a permission, you can **delegate** it to someone else for a period —
useful when you go on leave. You can only delegate what you actually hold, and the
delegation is recorded and expires.

## 8.3 Break-glass (emergencies only)

For genuine emergencies, holders of `access.break_glass` can invoke **break-glass**
access: it requires **strong MFA and a reason**, grants narrow access briefly, and
is **reviewed after the fact** (`access.break_glass.review`). Not for routine use.

---

# Part 9 — Organization Administrator

You hold **all** permissions, including `admin.manage`, `access.role.manage`,
`access.policy.manage`, `access.assignment.manage`, and `identity.manage`. You
configure the platform and delegate authority to others.

## 9.1 Roles & assignments (RBAC)

- **Role catalogue:** create/edit roles and the permissions inside them
  (`access.role.manage`). The system enforces sensible org-wide coverage.
- **Assign roles:** grant a role to a person, **scoped** org-wide or to a specific
  group and its descendants (`access.assignment.manage`). Assignments can have
  effective windows and expire.

## 9.2 Fine-grained rules (ABAC)

Beyond roles, add **ABAC policies** (`access.policy.manage`) — declarative
conditions (e.g. require strong MFA, restrict by attribute). Policies are **data,
not code**, and the engine is **default-deny**: if nothing grants access, it's
denied.

## 9.3 Identity administration

With `identity.manage` you run the **account lifecycle** (prospect → active →
suspended/locked → deactivated → anonymised/merged), always with a **reason**,
**approval**, and audit. **Merges are maker-checker** — the requester can't approve
their own merge. Suspending/locking an account revokes its sessions and tokens.

## 9.4 Settings, feature flags & group configuration

This is how you turn features on/off:

- **Org settings & feature flags:** set organisation-wide settings and flags
  (`admin.manage`).
- **Per-group configuration:** set a config value on a group, and **resolve** the
  **effective** value for a group. Config is **inheritance-aware** — a child group
  inherits its ancestors' settings unless it overrides them. **Default is OFF**: a
  feature does nothing until a group in the ancestry enables it.
- **Rule of thumb:** to enable a capability for a branch of the org, set it on the
  top group of that branch; every group beneath inherits it.

## 9.5 Group lifecycle & structure

- **Browse the hierarchy** at **Groups → Group hierarchy** (`/groups`), with two
  views you can toggle between:
  - **By hierarchy** (default): a tree of every group, indented by level
    (National → … → Cell), each row showing its **classification (kind)** badge,
    with quick links to open, edit, move, create, or delete each node.
  - **By location**: the same groups nested by their geography
    (Country → State/Region → City), matching the public directory.
- **Group kinds ↔ groups:** the **Group kinds** catalogue (`/group-kinds`) shows,
  for each kind, **how many groups** are classified under it; open a kind to see
  the **list of those groups** (drill-down) and jump to any of them.
- **Create/move groups** (`group.create`, `group.move`) to shape the hierarchy.
- **Edit core fields** (`group.change.approve`) — a group's **name**, **slug**,
  **type** (National → Region → Area → Local Assembly → Fellowship → Senior Cell
  → Cell), and **classification (kind)**. Editing these never moves the group or
  changes its scope; re-parenting is a separate **Move** action.
- **Delete an empty group** (`group.change.approve`) — a group with **no
  sub-groups, no members, and no cross-cut links** can be permanently deleted
  from the hierarchy browser. Populated or parent groups can't be hard-deleted;
  retire them through the **lifecycle** instead (below), which preserves history.
- **Lifecycle:** **archive ⇄ reactivate**, or terminally **dissolve** / **merge**
  groups — each with a stated reason and evidence. Dissolve is refused while a
  group still has active children or members; merge re-parents children and
  transfers members safely.
- **Group profiles & approvals:** update group profiles and approve group changes
  (`group.change.approve`).

## 9.6 Sponsor (referral) reassignment — maker-checker

Moving a member to a different sponsor is a **reviewed** action:
1. Someone **submits** a reassignment (member, new sponsor, reason, effective date).
   The request shows the **before/after** sponsor chain and a **descendant-impact**
   assessment.
2. A holder of `sponsor.reassign.approve` **approves** — and **can't approve one
   they submitted**. Approval re-checks eligibility (no cycles/self-sponsor),
   performs the move, and **recalculates only the affected, non-final metrics**.
3. **History is never rewritten** — past attribution, ledgers, certificates and
   closed-season results stay intact.

## 9.7 Oversight

- **Audit log:** every sensitive action is recorded in an append-only,
  tamper-evident (hash-chained) log.
- **Reports:** use analyst dashboards/exports to monitor the whole organisation.

---

## Appendix A — Permission → what it lets you do

| Permission | Lets you… |
|------------|-----------|
| `referral.link.create` | Create your personal referral/invite links |
| `group.create` / `group.move` | Create groups / move them in the hierarchy |
| `group.change.approve` | Approve group profile/structure changes |
| `event.create` | Create events |
| `event.schedule.approve` | Approve an event's schedule |
| `attendance.check_in` | Check attendees in (QR/kiosk/manual) |
| `event.tickets.manage` / `event.logistics.manage` | Manage tickets/orders / logistics |
| `event.expense.submit` / `event.expense.approve` | Submit / approve event expenses |
| `event.feedback.manage` | Build & review feedback forms/quizzes |
| `event.certificate.manage` | Issue/manage certificates |
| `event.media.manage` | Manage & review event media |
| `contribution.refund.request` / `contribution.refund.approve` | Request / approve refunds |
| `contribution.manage` | Manage causes & contributions |
| `integration.connection.approve` | Approve provider connections |
| `provider.configure` | Configure providers, profiles & custom adapters |
| `notification.send` / `notification.broadcast.approve` | Send / approve broadcasts |
| `course.create` / `course.completion.override` | Build courses / override completion |
| `stream.create` / `stream.moderate` | Run streams / moderate them |
| `meeting.manage` | Manage meetings/webinars |
| `venue.manage` | Manage venues |
| `community.moderate` | Moderate community content |
| `gamification.manage` | Configure points/achievements/ranks/campaigns |
| `report.view` / `report.export` | View / export reports |
| `access.request.approve` | Approve access requests |
| `access.assignment.manage` | Assign roles to people |
| `access.role.manage` / `access.policy.manage` | Manage roles / ABAC policies |
| `access.break_glass` / `access.break_glass.review` | Invoke / review emergency access |
| `identity.manage` | Run account lifecycle & merges |
| `admin.manage` | Org settings, flags, group configuration |

## Appendix B — Common questions

- **"I can't see a feature I expect."** It's probably **turned off for your group**.
  Ask an admin to enable it on your group (or an ancestor). Remember default is OFF.
- **"Why was I denied?"** The engine is **default-deny**; you either lack the
  permission, the **scope** (wrong group), or a **policy** (e.g. strong MFA) isn't
  satisfied. The denial message names the reason.
- **"Can I approve my own request/refund/merge/reassignment?"** No — **segregation
  of duties** blocks self-approval everywhere.
- **"Will editing history change past results?"** No. Ledgers, attribution,
  certificates and closed seasons are immutable; corrections are new records.
- **"I belong to Group A — can I give to Group B's cause?"** Yes. Credit goes to the
  **receiving** group; your personal total sums everything you do across all groups.
```
