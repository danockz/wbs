# Role Quick-Start Cards

One page per role — the few things you'll do most, and how. Keep your card handy;
the full detail lives in the **User Guide**.

---

## Member — Quick Start

**You can:** participate, invite, give, learn, and track your progress.

**First 5 minutes**
1. **Sign in** (accept your invite link if you were invited) and **enrol MFA**.
2. Open **my home** — your points, rank, streaks, badges and groups.
3. **Join a group** from the directory (some join instantly, some need approval).

**Do the core things**
- **Refer someone:** create your **referral link** and share it — sign-ups are
  credited to you automatically. Track it under **referrer analytics**.
- **Attend an event:** register, then **check in** (QR / kiosk / code). You may
  attend events across groups.
- **Give to a cause:** open a cause, watch its **progress bar**, and **contribute**
  (works inside a live stream too). Money confirms via the provider.
- **Learn:** **enrol** in a course and complete lessons; **request a certificate**
  where offered.
- **Engage:** post/comment/react in the feed, and fill in **feedback** forms.

**Remember**
- Your total is the **sum of everything you do across all groups**.
- Need more access? **Submit an access request** (scope + reason + duration).

---

## Event Organizer — Quick Start

**You hold:** `event.create`, `attendance.check_in`, `stream.create`,
`stream.moderate`, `meeting.manage`, `venue.manage`, plus ticket/logistics/
expense/feedback/certificate/media management.

**Run an event, start to finish**
1. **Create** the event (group, mode, times) → submit **schedule** for approval if
   required → **publish**.
2. **Set up entry:** ticket types, promo codes; add a **venue** or configure
   **virtual access**.
3. **At the door:** **QR check-in**, a **kiosk** for self check-in, or **manual**;
   offline scans reconcile later.
4. **Go live (optional):** create a stream → add destinations → **provision** →
   **go live** → **end/archive**. Run polls, moderate chat, attach **in-stream
   giving**.
5. **Afterwards:** open **feedback forms**, **issue certificates**, **review media**,
   **submit expenses**, and roll up the **event report**.

**Watch for**
- Metrics are **honest** (exact vs estimated); relay problems raise an **incident**
  with a documented bypass.
- **Expenses you submit** are approved by Finance — you can't approve your own.

---

## Course Author / Instructor — Quick Start

**You hold:** `course.create` (and optionally `course.completion.override`).

**Build a course**
1. **Create** the course (title, group).
2. **Add lessons** and a **syllabus**.
3. **Publish** so members can **enrol**.
4. **Correct records** only when justified via **completion override** (audited).

---

## Notification Manager — Quick Start

**You hold:** `notification.send`.

**Send an announcement**
1. Check the **preference centre** — members' channel choices are enforced.
2. **Compose a campaign** (audience, channel, content).
3. Large/sensitive **broadcasts** may need **approval** before sending.
4. **Send** and track delivery (providers have documented fallbacks).

---

## Community Moderator — Quick Start

**You hold:** `community.moderate`, `stream.moderate`, `gamification.manage`.

**Keep things healthy**
- Work the **moderation queue** — review **reports**, take **actions** (all logged).
- Moderate **live chat**, polls and reactions during streams.
- Configure **achievements / streaks / ranks / activity catalog** and run **group
  campaigns**; disable definitions without losing history.

---

## Reporting Analyst — Quick Start

**You hold:** `report.view`, `report.export`.

**Get the numbers**
- Open **dashboards** for the org and groups you can see.
- **Export** data sets (e.g. CSV) and capture **snapshots**.
- Read/export only — you can't change operational data, which keeps reports trusted.

---

## Finance Officer — Quick Start

**You hold:** `contribution.refund.approve`, `contribution.manage`,
`integration.connection.approve`, `event.expense.approve`.

**Approve money movements (you're the checker)**
- **Refunds:** review a request → **approve/reject**. You **can't approve one you
  requested**.
- **Contributions:** manage causes and **reconcile** — ledger rows are immutable;
  corrections are new entries.
- **Expenses:** **approve / reimburse / reconcile** against the event budget.
- **Provider connections:** you're the **approval gate** before a payment/
  notification connection goes active.

---

## Integrations (Provider Configuration) — Quick Start

**You hold:** `provider.configure`.

**Onboard a provider**
- **Conforming provider → no-code profile:** create → certify (draft → …→ active) →
  create a **connection**, add **credentials**, **test**, **activate**.
- **Non-conforming provider → custom adapter:** **register** allowlisted class →
  **contract-test** (signs on pass) → **security review** → **approve** (different
  person) → **activate** (publishes to the catalogue). See `custom-adapter-sdk.md`.
- **Reliability:** watch the **fallback matrix** and **reset circuits** after a fix.
- **Per-body SMS accounts** (`/notifications/credentials`, Notifications): each
  hierarchical body adds its **own** provider account, stores its secret
  (write-only — storing again rotates the slot) and picks how far **down** its
  subtree that account is shared: `self`, `self and descendants`, `descendants
  only`, or hand-picked groups (cross-cut opt-in). A body with neither its own
  account nor a share covering it is **refused**, not sent on somebody else's key.
  The page shows that body's effective sending chain; testing, approval and
  activation stay here on the connections page.

---

## Organization Administrator — Quick Start

**You hold:** everything, incl. `admin.manage`, `access.role.manage`,
`access.policy.manage`, `access.assignment.manage`, `identity.manage`.

**The high-frequency tasks**
- **Grant access:** assign a **role**, **scoped** org-wide or to a group + its
  descendants. Add **ABAC policies** for fine rules (default-deny).
- **Turn features on:** set **per-group config** on the top group of a branch —
  children **inherit** it (default is OFF).
- **Identity:** run account **lifecycle** and **merges** (maker-checker) with reason
  + audit.
- **Structure:** create/move groups; **archive / reactivate / dissolve / merge**
  with reasons.
- **Sponsor reassignment:** approve moves (checker ≠ submitter); history is never
  rewritten.
- **Oversee:** read the **audit log** and analyst reports.

---

## Special Access (anyone) — Quick Start

- **Access request:** ask for more — scope + reason + **duration**; it **expires**
  and can be **renewed/revoked**. No self-approval.
- **Delegation:** hand a permission you hold to someone for a period (e.g. leave).
- **Break-glass:** emergencies only — strong MFA + reason, narrow & brief,
  **reviewed afterwards**.
