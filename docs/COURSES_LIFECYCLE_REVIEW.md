# Courses (Enrollment / Progress / Completion) Lifecycle Review

_A code-grounded gap analysis of the **learning lifecycle** — how a course is
authored and published, how a learner enrolls (open/invite/approval), how lessons
unlock (drip) and are progressed, how completion is evaluated against a rule
(required lessons + min score) and recorded idempotently, how it is overridden by
a reviewer, and how completion feeds points/badges and the membership-journey
"integration" signal — plus how these interact with the journey, gamification,
groups and membership lifecycles reviewed earlier._

Prepared 2026-09-15, as the ninth review in the sequence. Method: read the
migration, the two services (Course, Enrollment), the two controllers, the
routes; every finding cites the code it rests on. **No code was changed.**

Scope note: Courses is a small module (one migration, two services) but sits on
the **integration** path — course completion is half the definition of
"integration" (group membership + foundations/membership courses) and a primary
journey-advance signal. So its correctness matters out of proportion to its size.

---

## 1. The courses model at a glance

**Tables** (`000015` CreateCourses — one migration):

- **`courses`** — `status` `draft|published|archived`, `delivery_mode`,
  `enrollment_policy` `open|invite|approval`, `prerequisites` (JSON course ids),
  `completion_rule` (JSON: required_lessons, min_score, attendance, review),
  `points_rule_code`, `badge_code`, owning `group_id`.
- **`course_modules` / `lessons`** — lessons carry `required`, `drip_unlock_at`
  (absolute) or `drip_offset_days` (relative to enrollment), `content_ref`.
- **`enrollments`** — `status` `active|completed|withdrawn`, UNIQUE
  `(course_id, user_id)`, `enrolled_at`/`completed_at`.
- **`learning_progress`** — per lesson, `state` `started|completed`, `score`,
  UNIQUE `(enrollment_id, lesson_id)`.
- **`course_completions`** — idempotent completion, UNIQUE `(enrollment_id)`,
  `verified`, `final_score`, `override_by`/`override_reason`.
- **`learning_content_connections` / `learning_experience_statements`** — optional
  SCORM/xAPI/LTI (policy-gated), inbound statements idempotent on
  `(org, source_ref)`.

**Learner state machine:**

```
course: draft --publish--> published --> archived
enroll: (none) --enroll--> active --completeLesson*--> (evaluate) --> completed
                                                                  \-> withdrawn (schema value; see C-W below)
completion: recorded once (UNIQUE enrollment_id); stages course.completed outbox
```

**The good news up front.** The completion engine is well built and should be
protected:

1. **Completion is idempotent and rule-based** — `course_completions` UNIQUE on
   `enrollment_id`, an early "already completed" no-op, a required-lessons check,
   an optional `min_score` average gate, and a race-safe insert-catch. A
   redelivered evaluation cannot double-complete or double-reward.
2. **Progress and enrollment are idempotent** — `learning_progress` UNIQUE
   `(enrollment_id, lesson_id)` with upsert; `enroll` dedups on the enrollment
   UNIQUE and returns `deduplicated`.
3. **The reward + journey signal are staged transactionally on the outbox** —
   `recordCompletion` flips the enrollment to `completed`, then stages
   `course.completed` with `points_rule_code`, `badge_code`, `group_id`, and
   `course_code` so the gamification award (idempotent on `source_ref`) and the
   Option-C journey signal both fire from one durable event.
4. **Override is audited** — `overrideCompletion` requires a reviewer id + a
   non-empty reason, recorded on the completion row.
5. **Authoring routes were hardened** — `publish` / `addLesson` carry
   `auth` + `authorize:course.create,any` + `webcsrf` (a route comment notes they
   were previously ungated and fixed).

The findings below are the gaps — and the first is HIGH because it's an access-
control hole on the learner path.

---

## 2. Findings, ranked

### CO1 — `completeLesson` is an IDOR: any learner can progress/complete anyone's enrollment (HIGH)

`POST enrollments/(:segment)/lessons/(:segment)/complete` carries `auth` +
`webcsrf`, but the controller passes the URL `enrollmentId` straight into
`EnrollmentService::completeLesson` **without checking the authenticated user owns
that enrollment** (confirmed: the controller reads `score` + `course_id` for the
PRG and calls the service; the service loads progress by `enrollment_id` only). No
ownership/scope check exists on either side.

Consequence: a logged-in learner can pass **any** `enrollment_id` and mark lessons
complete on another person's enrollment — which then flows into
`evaluateCompletion`, and if the rule is met, **records a completion and stages
the points/badge award + the journey-advance signal for that other person.** So
one user can forge another user's course completion, granting them points, a
badge, and a membership-journey stage advance (the "integration" milestone). This
is a direct object reference vulnerability on the exact path that feeds
recognition and journey progression.

**Fix direction:** resolve the enrollment, confirm `enrollment.user_id ===
currentUserId()` (or the caller holds a teaching/`authorize:` capability over the
course's group scope for instructor-marked progress), and deny otherwise. Add a
test asserting a non-owner is refused. This is the highest-priority fix in the
module.

### CO2 — `enroll` ignores `enrollment_policy`, `prerequisites`, and course `status` (HIGH)

`EnrollmentService::enroll` inserts an `active` enrollment after only the
duplicate check. It never consults:

- **`enrollment_policy`** — an `invite`- or `approval`-gated course enrolls anyone
  who calls the endpoint exactly like an `open` one. There is no invite-token
  check and no pending/approval state (the enrollment jumps straight to `active`).
  This is the same fail-open the events invite path was explicitly designed to
  avoid (G5 invite-only is fail-closed); here it's fail-open.
- **`prerequisites`** — a learner can enroll in (and complete) an advanced course
  without completing its prerequisite courses; the JSON list is never read.
- **course `status`** — nothing stops enrolling in a `draft` or `archived`
  course, or one belonging to a different org.

Consequence: the access model the schema encodes (invite/approval/prereqs) is
inert; enrollment is effectively always open on any course whose id you know.

**Fix direction:** in `enroll`, load the course and enforce `status='published'`
+ org match; branch on `enrollment_policy` (open → active; invite → require a
single-use token à la the events invite mechanism; approval → create a `pending`
enrollment for leader approval); and verify `prerequisites` are completed by the
learner. Note the `enrollments.status` enum has no `pending` value — add one for
the approval path.

### CO3 — Drip scheduling is display-only; completion doesn't enforce lesson unlock (MED)

`learnerView`/`syllabusFor` compute a drip-aware syllabus (each lesson flagged
locked/available relative to `enrolled_at`), but `completeLesson` **never checks
`drip_unlock_at`/`drip_offset_days`** before recording progress. So a learner (or
a script) can complete a lesson that is still drip-locked in the UI, and — if it's
a required lesson — drive completion ahead of the intended schedule. Drip is a
presentation concern only, not an enforced gate.

**Fix direction:** in `completeLesson`, resolve the lesson's effective unlock time
against the enrollment date and refuse progress on a still-locked lesson (unless
an instructor override). Reuse the same resolution `syllabusFor` already computes.

### CO4 — `completion_rule` supports `attendance` and `review` but neither is implemented (MED)

The `completion_rule` JSON schema documents four criteria — required_lessons,
min_score, **attendance**, **review** — but `evaluateCompletion` implements only
the first two. A course configured to require attendance (e.g. an instructor-led
or physical `delivery_mode`) or a manual review step will **auto-complete on
lessons+score alone**, silently ignoring the attendance/review gate the author
set. This is a "config promises behaviour the engine doesn't honour" gap (cf.
Notifications N4 digests, Journey stage-links).

**Fix direction:** implement the `attendance` branch (join to the Events/
attendance records the course is linked to) and the `review` branch (hold
completion in a pending-review state until a reviewer signs off — reuse the
`overrideCompletion` reviewer path). Until implemented, reject a
`completion_rule` that sets those keys rather than silently ignoring them.

### CO5 — `overrideCompletion` has no route and no maker-checker (MED)

`overrideCompletion` (reviewer + reason) exists as a service method but has **no
route** exposing it (grep of the courses routes shows only publish/lessons/enrol/
complete). So the audited manual-completion path is unreachable over HTTP. When it
is wired, note it currently records the reviewer but does **not** enforce
`reviewer ≠ learner` — a learner who is also a reviewer could self-override.
Given completion mints points + a badge + a journey advance, it should meet the
same SoD bar as the refund/role-grant overrides elsewhere.

**Fix direction:** add a gated route (`authorize:` a teaching/completion-override
capability + `webcsrf`), and enforce reviewer ≠ learner (SoD) in the service.

### CO6 — No withdraw path and no reaction to account/course teardown (MED — cross-review coupling)

`enrollments.status` includes `withdrawn`, but **no method ever sets it** — there
is no learner-withdraw or admin-unenroll path. And nothing reacts when the
underlying person or course is torn down:

- **Account deactivate/merge (M1/M2):** a deactivated learner's `active`
  enrollments persist; a merged learner's enrollments/completions aren't moved to
  the survivor.
- **Course archived:** archiving a course (status → `archived`) doesn't stop
  in-flight enrollments or prevent new completions; `evaluateCompletion` still
  runs and rewards.
- **Group dissolve (GR2):** a course's owning `group_id` can be dissolved while
  the course keeps enrolling and rewarding.

Same lifecycle-cascade gap logged across the platform (M1/M2 ↔ AC3, J4, C8, R7,
N7, G5).

**Fix direction:** add withdraw/unenroll (→ `withdrawn`, stop drip/rewards);
consume the account and group teardown signals to withdraw/reassign enrollments;
block new completions on an archived course.

### CO7 — No service/controller tests for the enrollment/completion lifecycle (MED)

There is no `Services/tests/` or `Controllers/tests/` directory for Courses. The
IDOR (CO1), the policy/prereq bypass (CO2), drip enforcement (CO3), and the
completion rule branches (CO4) are all untested — which is why CO1/CO2 slipped
through. Given this module feeds recognition and journey progression, coverage is
thin.

**Fix direction:** add tests for enrollment-policy branching, prerequisite
enforcement, ownership on completeLesson (CO1), drip lock (CO3), and each
completion-rule criterion — red before the fixes.

---

## 3. Suggested sequencing

1. **CO7** — add the enrollment/completion tests (red), esp. ownership + policy.
2. **CO1** — fix the completeLesson IDOR (access-control hole on the reward path).
3. **CO2** — enforce enrollment_policy + prerequisites + course status on enroll.
4. **CO3** — enforce drip unlock in completeLesson.
5. **CO4** — implement (or reject) the attendance/review completion criteria.
6. **CO5** — expose overrideCompletion behind a gated route + SoD.
7. **CO6** — withdraw path + consume account/group teardown signals.

## 4. Cross-review couplings (explicit)

- **CO1/CO2 ↔ Referrals R1 / Membership M3 / Groups GR1 / ACL AC11 / Journey J5 /
  Gamification G6 / Notifications N5:** the recurring authorization-gap theme —
  here as an IDOR (CO1) and a fail-open policy (CO2).
- **CO6 ↔ Membership M1/M2, Groups GR2, ACL AC3, Journey J4, Contributions C8,
  Referrals R7, Notifications N7:** account/group teardown must fan out.
- **CO2/CO4 ↔ Journey (integration) + Gamification:** course completion is a
  primary journey-advance signal and reward trigger; a forged/premature
  completion (CO1/CO3) propagates into stage advance + points.
- **CO4 ↔ Notifications N4 / Journey J7:** "config promises behaviour the engine
  doesn't implement" recurs.

No code was changed in the course of this review.
