<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Enrollment, progress and rule-based completion (SRS FR-CRS-003).
 *
 *  - One enrollment per (course, user).
 *  - Completion is RULE-BASED (required lessons done + optional min score) and
 *    IDEMPOTENT (UNIQUE per enrollment). It awards progress/points/badges only
 *    after verification, staging a course.completed event on the outbox.
 *  - Manual overrides require a reason and an authorized reviewer.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly OutboxService $outbox,
        private readonly ?\WBS\Referrals\Services\IntegrationDecisionsPort $integrationDecisions = null,
    ) {
    }

    /**
     * The learner-facing state of a course for one member: their enrollment (if
     * any), the drip-aware syllabus with each lesson flagged as done/locked, and
     * a rolled-up progress summary. Powers the syllabus page's Enrol / Mark-
     * complete controls. Resource-light: a fixed, small number of bounded reads
     * (course syllabus + this member's enrollment + this member's progress rows +
     * completion flag) — no per-lesson query fan-out.
     *
     * @return array{
     *   course_id:string,
     *   user_id:string,
     *   enrollment:?array{id:string,status:string,enrolled_at:string},
     *   is_enrolled:bool,
     *   is_completed:bool,
     *   lessons:list<array<string,mixed>>,
     *   progress:array{required_total:int,required_done:int,total:int,done:int,percent:int}
     * }
     */
    public function learnerView(string $organizationId, string $courseId, string $userId): array
    {
        $enr = $userId === '' ? null : $this->db->table('enrollments')
            ->where('course_id', $courseId)->where('user_id', $userId)
            ->get()->getRowArray();

        // Syllabus is drip-relative to the enrollment date (fallback: now, so an
        // unenrolled visitor sees the immediate-unlock lessons as available).
        $enrolledAt = $enr['enrolled_at'] ?? $this->clock->nowUtcString();
        $lessons    = $this->courses()->syllabusFor($courseId, (string) $enrolledAt);

        // This member's completed lesson ids (one bounded read), keyed for O(1).
        $doneIds = [];
        if ($enr !== null) {
            foreach (
                $this->db->table('learning_progress')
                    ->select('lesson_id')
                    ->where('enrollment_id', $enr['id'])
                    ->where('state', 'completed')
                    ->get()->getResultArray() as $p
            ) {
                $doneIds[(string) $p['lesson_id']] = true;
            }
        }

        $reqTotal = $reqDone = $total = $done = 0;
        foreach ($lessons as $i => $l) {
            $lid    = (string) ($l['lesson_id'] ?? '');
            $isDone = isset($doneIds[$lid]);
            $lessons[$i]['completed'] = $isDone;
            $total++;
            if ($isDone) {
                $done++;
            }
            if (! empty($l['required'])) {
                $reqTotal++;
                if ($isDone) {
                    $reqDone++;
                }
            }
        }

        $isCompleted = $enr !== null && (
            (string) ($enr['status'] ?? '') === 'completed'
            || $this->db->table('course_completions')->where('enrollment_id', $enr['id'])->countAllResults() > 0
        );

        $percent = $reqTotal > 0
            ? (int) floor($reqDone * 100 / $reqTotal)
            : ($total > 0 ? (int) floor($done * 100 / $total) : 0);

        return [
            'course_id'    => $courseId,
            'user_id'      => $userId,
            'enrollment'   => $enr === null ? null : [
                'id'          => (string) $enr['id'],
                'status'      => (string) ($enr['status'] ?? 'active'),
                'enrolled_at' => (string) ($enr['enrolled_at'] ?? ''),
            ],
            'is_enrolled'  => $enr !== null,
            'is_completed' => $isCompleted,
            'lessons'      => $lessons,
            'progress'     => [
                'required_total' => $reqTotal,
                'required_done'  => $reqDone,
                'total'          => $total,
                'done'           => $done,
                'percent'        => $percent,
            ],
        ];
    }

    /** Lazily resolve the CourseService (shares the DB/clock via the container). */
    private function courses(): CourseService
    {
        return \WBS\Courses\Config\Services::courses();
    }

    /**
     * Account teardown (Theme B, CO6) — withdraw a gone/frozen learner's
     * IN-FLIGHT enrollments so they stop accruing progress, appearing on rosters,
     * or earning completion rewards. Only `pending`/`active` rows are withdrawn;
     * a `completed` enrollment (and its verified completion + any issued
     * certificate) is a historical fact and is left intact — this platform never
     * rewrites earned achievements.
     *
     * SYSTEM authority, idempotent (a re-run withdraws 0), empty inputs -> 0.
     *
     * @return int number of enrollments withdrawn
     */
    public function withdrawActiveForSubject(string $organizationId, string $userId, string $reasonCode): int
    {
        if ($organizationId === '' || $userId === '') {
            return 0;
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('enrollments')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'active'])
            ->update([
                'status'       => 'withdrawn',
                'withdrawn_at' => $now,
            ]);

        return $this->db->affectedRows();
    }

    /**
     * Person merge (Theme B, CO6 merge half) — re-point the LOSER's enrollments
     * and completions to the SURVIVOR so the survivor inherits the loser's
     * learning record, honouring the UNIQUE(course_id, user_id) constraint:
     *
     *   - for a loser enrollment in a course the survivor is NOT already enrolled
     *     in, re-point `user_id` -> survivor AND re-point the associated
     *     `course_completions.user_id` (the completion follows its enrollment);
     *   - for a loser enrollment in a course the survivor IS already enrolled in
     *     (a would-be duplicate that the unique key forbids), keep the survivor's
     *     row and mark the loser's row `withdrawn` (superseded), preserving it as
     *     history rather than deleting it — mirrors the journey/sponsorship merge
     *     dedup.
     *
     * loser == survivor / empty inputs -> all-zero no-op. Idempotent: a re-run
     * finds no remaining loser rows.
     *
     * @return array{enrollments_repointed:int,duplicates_withdrawn:int,completions_repointed:int}
     */
    public function reassignForMerge(string $organizationId, string $loserId, string $survivorId): array
    {
        $zero = [
            'enrollments_repointed' => 0,
            'duplicates_withdrawn'  => 0,
            'completions_repointed' => 0,
        ];
        if ($organizationId === '' || $loserId === '' || $survivorId === '' || $loserId === $survivorId) {
            return $zero;
        }

        $now = $this->clock->nowUtcString();

        // The survivor's existing course ids (bounded read), keyed for O(1).
        $survivorCourses = [];
        foreach (
            $this->db->table('enrollments')
                ->select('course_id')
                ->where('organization_id', $organizationId)
                ->where('user_id', $survivorId)
                ->get()->getResultArray() as $r
        ) {
            $survivorCourses[(string) $r['course_id']] = true;
        }

        $loserEnrollments = $this->db->table('enrollments')
            ->where('organization_id', $organizationId)
            ->where('user_id', $loserId)
            ->get()->getResultArray();

        $repointed = 0;
        $withdrawn = 0;
        $completionsRepointed = 0;

        foreach ($loserEnrollments as $enr) {
            $courseId     = (string) $enr['course_id'];
            $enrollmentId = (string) $enr['id'];

            if (isset($survivorCourses[$courseId])) {
                // Duplicate course: keep the survivor's row, supersede the loser's.
                $this->db->table('enrollments')
                    ->where('id', $enrollmentId)
                    ->update([
                        'status'       => 'withdrawn',
                        'withdrawn_at' => $now,
                    ]);
                $withdrawn++;

                continue;
            }

            // Re-point the enrollment to the survivor …
            $this->db->table('enrollments')
                ->where('id', $enrollmentId)
                ->update(['user_id' => $survivorId]);
            $repointed++;

            // … and the completion (if any) follows its enrollment. The
            // enrollment_id is stable, so UNIQUE(enrollment_id) is never touched.
            $this->db->table('course_completions')
                ->where('enrollment_id', $enrollmentId)
                ->update(['user_id' => $survivorId]);
            $completionsRepointed += $this->db->affectedRows();
        }

        return [
            'enrollments_repointed' => $repointed,
            'duplicates_withdrawn'  => $withdrawn,
            'completions_repointed' => $completionsRepointed,
        ];
    }

    /**
     * Enroll a learner, enforcing course enrollment gating (gap CO2).
     *
     * Before CO2 this method did dedup + insert only, ignoring ALL gating: it
     * would enroll into draft/archived courses, auto-enroll into invite/approval
     * courses as `active`, and never check prerequisites. It is now FAIL-CLOSED:
     *   - the course must exist, belong to the org, and be `published`;
     *   - `prerequisites` (JSON course ids) must all be completed by the learner;
     *   - `enrollment_policy` decides the resulting state:
     *       open     -> active   (immediate)
     *       approval -> pending  (awaits leader approval; see approveEnrollment)
     *       invite   -> denied unless an authorized/assisted caller vouches
     *                   ($opts['assisted'] === true), mirroring the events
     *                   invite-only fail-closed rule (G5).
     *
     * $opts: { cohort_id?:string, assisted?:bool }. `assisted` is set by an
     * authorized staff/leader enrolment path (the route/controller establishes
     * that authority); a self-service browser enrolment never sets it.
     *
     * @param array<string,mixed> $opts
     */
    public function enroll(string $organizationId, string $courseId, string $userId, array $opts = []): Result
    {
        $cohortId = isset($opts['cohort_id']) && $opts['cohort_id'] !== '' ? (string) $opts['cohort_id'] : null;
        $assisted = ! empty($opts['assisted']);

        $existing = $this->db->table('enrollments')
            ->where('course_id', $courseId)->where('user_id', $userId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['enrollment_id' => $existing['id'], 'status' => $existing['status']], 200, ['deduplicated' => true]);
        }

        // ---- Gate 1: the course must exist, be in this org, and be published.
        $course = $this->db->table('courses')->where('id', $courseId)->get()->getRowArray();
        if ($course === null || (string) $course['organization_id'] !== $organizationId) {
            return Result::notFound('course.not_found', 'COURSE_NOT_FOUND');
        }
        if ((string) $course['status'] !== 'published') {
            return Result::fail('COURSE_NOT_PUBLISHED', 'course.not_open_for_enrollment', 409, ['status' => $course['status']]);
        }

        // ---- Gate 2: prerequisites (JSON list of course ids) must be completed.
        $prereqIds = $course['prerequisites'] ? (json_decode((string) $course['prerequisites'], true) ?: []) : [];
        if (is_array($prereqIds) && $prereqIds !== []) {
            $prereqIds = array_values(array_filter(array_map('strval', $prereqIds)));
            $doneRows  = $this->db->table('enrollments')
                ->where('user_id', $userId)->where('status', 'completed')
                ->whereIn('course_id', $prereqIds)
                ->get()->getResultArray();
            $doneIds = array_column($doneRows, 'course_id');
            $missing = array_values(array_diff($prereqIds, $doneIds));
            if ($missing !== []) {
                return Result::fail('PREREQUISITES_NOT_MET', 'course.prerequisites_not_met', 409, ['missing' => $missing]);
            }
        }

        // ---- Gate 3: enrollment policy decides the resulting state (fail-closed).
        $policy = (string) ($course['enrollment_policy'] ?? 'open');
        switch ($policy) {
            case 'open':
                $status = 'active';
                break;
            case 'approval':
                $status = 'pending';
                break;
            case 'invite':
                if (! $assisted) {
                    return Result::denied('course.invite_only', 'ENROLLMENT_INVITE_ONLY');
                }
                $status = 'active';
                break;
            default:
                return Result::fail('BAD_POLICY', 'course.bad_enrollment_policy', 409, ['policy' => $policy]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        try {
            $this->db->table('enrollments')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'course_id'       => $courseId,
                'user_id'         => $userId,
                'cohort_id'       => $cohortId,
                'status'          => $status,
                'enrolled_at'     => $now,
            ]);
        } catch (Throwable) {
            $existing = $this->db->table('enrollments')->where('course_id', $courseId)->where('user_id', $userId)->get()->getRowArray();

            return Result::ok(['enrollment_id' => $existing['id'] ?? $id], 200, ['deduplicated' => true]);
        }

        // Integration decisions (FR-REF-3b): enrolling in a foundation-category
        // course is itself the "foundation course" decision. Best-effort + gated
        // (default off), so a decision row can never fail an enrolment.
        $this->integrationDecisions?->onEnrolled(
            $organizationId,
            $userId,
            $courseId,
            $id,
            $now,
            (string) ($course['category'] ?? ''),
            isset($course['group_id']) && $course['group_id'] !== '' ? (string) $course['group_id'] : null,
        );

        return Result::created(['enrollment_id' => $id, 'status' => $status]);
    }

    /**
     * Approve a `pending` (approval-policy) enrollment -> `active` (gap CO2).
     * The caller's authority to approve is established by the route filter; this
     * only advances a pending row.
     */
    public function approveEnrollment(string $enrollmentId, string $approverId): Result
    {
        $enr = $this->db->table('enrollments')->where('id', $enrollmentId)->get()->getRowArray();
        if ($enr === null) {
            return Result::notFound('course.enrollment_not_found', 'ENROLLMENT_NOT_FOUND');
        }
        if ((string) $enr['status'] === 'active' || (string) $enr['status'] === 'completed') {
            return Result::ok(['enrollment_id' => $enrollmentId, 'status' => $enr['status']], 200, ['deduplicated' => true]);
        }
        if ((string) $enr['status'] !== 'pending') {
            return Result::fail('NOT_PENDING', 'course.enrollment_not_pending', 409, ['status' => $enr['status']]);
        }
        $this->db->table('enrollments')->where('id', $enrollmentId)->update([
            'status' => 'active',
        ]);

        return Result::ok(['enrollment_id' => $enrollmentId, 'status' => 'active']);
    }

    /**
     * Mark a lesson complete for an enrollment (idempotent), then re-evaluate.
     *
     * Ownership enforcement (gap CO1): before CO1 this method took the URL
     * `enrollmentId` and recorded progress with NO check that the caller owns the
     * enrollment — an IDOR letting any authenticated learner complete lessons on
     * ANY enrollment, forging another user's completion + points + badge + journey
     * advance. `$actingUserId` is the authenticated caller; the enrollment must
     * belong to them (a `null` acting id — an unauthenticated/instructor context —
     * is treated as no-owner and denied here; instructor-marked progress is a
     * separate authorized path).
     */
    public function completeLesson(string $enrollmentId, string $lessonId, ?string $actingUserId, ?int $score = null): Result
    {
        $enr = $this->db->table('enrollments')->where('id', $enrollmentId)->get()->getRowArray();
        if ($enr === null) {
            return Result::notFound('course.enrollment_not_found', 'ENROLLMENT_NOT_FOUND');
        }
        if ($actingUserId === null || $actingUserId === '' || (string) $enr['user_id'] !== $actingUserId) {
            return Result::denied('course.not_your_enrollment', 'ENROLLMENT_FORBIDDEN');
        }
        // A withdrawn/pending enrollment cannot accrue progress.
        if (! in_array((string) $enr['status'], ['active', 'completed'], true)) {
            return Result::fail('ENROLLMENT_NOT_ACTIVE', 'course.enrollment_not_active', 409, ['status' => $enr['status']]);
        }

        $now      = $this->clock->nowUtcString();
        $existing = $this->db->table('learning_progress')
            ->where('enrollment_id', $enrollmentId)->where('lesson_id', $lessonId)
            ->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('learning_progress')->where('id', $existing['id'])->update([
                'state'        => 'completed',
                'score'        => $score,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);
        } else {
            $this->db->table('learning_progress')->insert([
                'id'            => Uuid::v7(),
                'enrollment_id' => $enrollmentId,
                'lesson_id'     => $lessonId,
                'state'         => 'completed',
                'score'         => $score,
                'completed_at'  => $now,
                'updated_at'    => $now,
            ]);
        }

        return $this->evaluateCompletion($enrollmentId);
    }

    /**
     * Evaluate the course completion rule and, if met, record an idempotent
     * completion + stage the reward event.
     */
    public function evaluateCompletion(string $enrollmentId): Result
    {
        $enr = $this->db->table('enrollments')->where('id', $enrollmentId)->get()->getRowArray();
        if ($enr === null) {
            return Result::notFound('course.enrollment_not_found', 'ENROLLMENT_NOT_FOUND');
        }
        // Already completed -> idempotent no-op.
        if ($this->db->table('course_completions')->where('enrollment_id', $enrollmentId)->countAllResults() > 0) {
            return Result::ok(['enrollment_id' => $enrollmentId, 'status' => 'completed'], 200, ['deduplicated' => true]);
        }

        $course = $this->db->table('courses')->where('id', $enr['course_id'])->get()->getRowArray();

        // CO6 — an ARCHIVED course accrues no NEW completions. Already-recorded
        // completions above are honoured (the dedupe check returns them); this
        // only blocks minting a fresh completion against a retired course.
        if ($course !== null && (string) ($course['status'] ?? '') === 'archived') {
            return Result::fail('COURSE_ARCHIVED', 'course.archived_no_completion', 409, ['status' => 'archived']);
        }

        $rule = $course && $course['completion_rule'] ? (json_decode((string) $course['completion_rule'], true) ?: []) : [];

        // Required lessons all completed?
        $requiredLessons = $this->db->table('lessons')
            ->where('course_id', $enr['course_id'])->where('required', 1)
            ->get()->getResultArray();
        $requiredIds = array_column($requiredLessons, 'id');

        $completed = [];
        if ($requiredIds !== []) {
            $rows = $this->db->table('learning_progress')
                ->where('enrollment_id', $enrollmentId)
                ->where('state', 'completed')
                ->whereIn('lesson_id', $requiredIds)
                ->get()->getResultArray();
            $completed = array_column($rows, 'lesson_id');
        }

        $allDone = count(array_unique($completed)) >= count($requiredIds);
        if (! $allDone) {
            return Result::ok(['enrollment_id' => $enrollmentId, 'status' => 'in_progress', 'completed' => count(array_unique($completed)), 'required' => count($requiredIds)]);
        }

        // Optional minimum average score.
        $finalScore = null;
        if (isset($rule['min_score'])) {
            $agg = $this->db->query(
                'SELECT AVG(score) AS avg_score FROM learning_progress WHERE enrollment_id = ? AND state = "completed" AND score IS NOT NULL',
                [$enrollmentId],
            )->getRowArray();
            $finalScore = $agg['avg_score'] !== null ? (int) round((float) $agg['avg_score']) : null;
            if ($finalScore === null || $finalScore < (int) $rule['min_score']) {
                return Result::ok(['enrollment_id' => $enrollmentId, 'status' => 'score_not_met', 'score' => $finalScore, 'min_score' => (int) $rule['min_score']]);
            }
        }

        return $this->recordCompletion($enr, $course, $finalScore, null, null);
    }

    /** Authorized manual completion override (reason + reviewer required). */
    public function overrideCompletion(string $enrollmentId, string $reviewerId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'course.override_reason_required', 422);
        }
        $enr = $this->db->table('enrollments')->where('id', $enrollmentId)->get()->getRowArray();
        if ($enr === null) {
            return Result::notFound('course.enrollment_not_found', 'ENROLLMENT_NOT_FOUND');
        }
        if ($this->db->table('course_completions')->where('enrollment_id', $enrollmentId)->countAllResults() > 0) {
            return Result::ok(['enrollment_id' => $enrollmentId, 'status' => 'completed'], 200, ['deduplicated' => true]);
        }
        $course = $this->db->table('courses')->where('id', $enr['course_id'])->get()->getRowArray();
        if ($course !== null && (string) ($course['status'] ?? '') === 'archived') {
            return Result::fail('COURSE_ARCHIVED', 'course.archived_no_completion', 409, ['status' => 'archived']);
        }

        return $this->recordCompletion($enr, $course, null, $reviewerId, $reason);
    }

    /** @param array<string,mixed> $enr @param array<string,mixed>|null $course */
    private function recordCompletion(array $enr, ?array $course, ?int $finalScore, ?string $overrideBy, ?string $reason): Result
    {
        $now   = $this->clock->nowUtcString();
        $id    = Uuid::v7();
        $orgId = $enr['organization_id'];

        $this->db->transStart();
        try {
            $this->db->table('course_completions')->insert([
                'id'              => $id,
                'organization_id' => $orgId,
                'course_id'       => $enr['course_id'],
                'enrollment_id'   => $enr['id'],
                'user_id'         => $enr['user_id'],
                'final_score'     => $finalScore,
                'verified'        => 1,
                'override_reason' => $reason,
                'override_by'     => $overrideBy,
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            // UNIQUE(enrollment_id) -> already completed.
            return Result::ok(['enrollment_id' => $enr['id'], 'status' => 'completed'], 200, ['deduplicated' => true]);
        }

        $this->db->table('enrollments')->where('id', $enr['id'])->update([
            'status'       => 'completed',
            'completed_at' => $now,
        ]);

        // Stage the reward event; awarding is idempotent downstream on source_ref.
        $this->outbox->stage('course', $enr['id'], 'course.completed', [
            'enrollment_id'   => $enr['id'],
            'course_id'       => $enr['course_id'],
            'user_id'         => $enr['user_id'],
            'points_rule_code' => $course['points_rule_code'] ?? null,
            'badge_code'      => $course['badge_code'] ?? null,
            // Carried for the journey signal (Option C emitter in JobRouter):
            // group_id scopes which leaders' membership rules may fire, and
            // course_code (slug) lets a rule gate on a specific course.
            'group_id'        => $course['group_id'] ?? null,
            'course_code'     => $course['slug'] ?? null,
            'source_ref'      => 'course_completion:' . $enr['id'],
        ], $orgId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('COMPLETE_FAILED', 'course.complete_failed', 500);
        }

        // Integration decisions (FR-REF-3b): a foundation-course COMPLETION may
        // also satisfy the decision when the body opts in (derive_from_completion).
        // Best-effort + gated, never fatal.
        $this->integrationDecisions?->onCompleted(
            $orgId,
            (string) $enr['user_id'],
            (string) $enr['course_id'],
            (string) $enr['id'],
            $now,
            (string) ($course['category'] ?? ''),
            isset($course['group_id']) && $course['group_id'] !== '' ? (string) $course['group_id'] : null,
        );

        return Result::created(['enrollment_id' => $enr['id'], 'completion_id' => $id, 'status' => 'completed']);
    }
}
