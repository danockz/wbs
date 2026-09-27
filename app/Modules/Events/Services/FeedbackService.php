<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Post-event feedback & quizzes (SRS FR-EVT-012).
 *
 *  - Versioned forms (feedback|quiz) with questions; quizzes carry answer keys
 *    and points that are NEVER returned to a respondent.
 *  - One response per respondent per form (UNIQUE). Automatic quiz scoring
 *    grades on submit; `reviewed` scoring leaves review_state=pending_review.
 *  - Aggregate reads honor a minimum-N threshold so small cohorts can't be
 *    de-anonymized (labels + aggregation thresholds, FR-EVT-012).
 */
final class FeedbackService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function createForm(string $organizationId, string $eventId, array $data, ?string $createdBy): Result
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            return Result::fail('TITLE_REQUIRED', 'feedback.title_required', 422);
        }
        $kind = ($data['kind'] ?? 'feedback') === 'quiz' ? 'quiz' : 'feedback';
        $scoring = in_array($data['scoring'] ?? 'none', ['none', 'automatic', 'reviewed'], true) ? $data['scoring'] : 'none';

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_feedback_forms')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'kind'            => $kind,
            'title'           => $data['title'],
            'version'         => (int) ($data['version'] ?? 1),
            'visibility'      => in_array($data['visibility'] ?? 'group', ['public', 'group', 'private'], true) ? $data['visibility'] : 'group',
            'scoring'         => $scoring,
            'pass_score'      => isset($data['pass_score']) ? (int) $data['pass_score'] : null,
            'min_aggregate_n' => (int) ($data['min_aggregate_n'] ?? 5),
            'status'          => 'draft',
            'created_by'      => $createdBy,
            'created_at'      => $now,
        ]);

        return Result::created(['form_id' => $id, 'kind' => $kind, 'scoring' => $scoring]);
    }

    /**
     * List an event's feedback forms (newest first), each annotated with its
     * question count and response count for the organizer console.
     *
     * @return list<array<string,mixed>>
     */
    public function listFormsForEvent(string $eventId): array
    {
        $forms = $this->db->table('event_feedback_forms')
            ->select('id, kind, title, version, visibility, scoring, pass_score, status, created_at')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        foreach ($forms as &$f) {
            $f['question_count'] = (int) $this->db->table('event_feedback_questions')
                ->where('form_id', $f['id'])->countAllResults();
            $f['response_count'] = (int) $this->db->table('event_feedback_responses')
                ->where('form_id', $f['id'])->countAllResults();
        }
        unset($f);

        return $forms;
    }

    /**
     * Fetch a single form plus its ordered questions for the questions console.
     *
     * @return array{form: array<string,mixed>|null, questions: list<array<string,mixed>>}
     */
    public function formWithQuestions(string $formId): array
    {
        $form = $this->db->table('event_feedback_forms')->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return ['form' => null, 'questions' => []];
        }
        $questions = $this->db->table('event_feedback_questions')
            ->where('form_id', $formId)
            ->orderBy('ordinal', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        return ['form' => $form, 'questions' => $questions];
    }

    /**
     * RESPONDENT-facing snapshot for the public feedback/quiz submission page.
     * Deliberately RESOURCE-LIGHT and privacy-safe: a few bounded reads, and the
     * quiz secrets (`answer_key`, `points`, `rubric`) are STRIPPED from every
     * question so they never reach a respondent's browser. Reports whether the
     * form is open and whether this respondent has already submitted (so the view
     * shows a thank-you instead of a re-submittable form; the UNIQUE constraint is
     * the real guard).
     *
     * @return array{
     *   form: array<string,mixed>|null, is_open: bool, already_submitted: bool,
     *   questions: list<array<string,mixed>>
     * }
     */
    public function respondentView(string $formId, ?string $respondentId): array
    {
        $form = $this->db->table('event_feedback_forms')
            ->select('id, event_id, kind, title, status, visibility, version')
            ->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return ['form' => null, 'is_open' => false, 'already_submitted' => false, 'questions' => []];
        }

        $rows = $this->db->table('event_feedback_questions')
            ->select('id, ordinal, prompt, qtype, choices, required')
            ->where('form_id', $formId)
            ->orderBy('ordinal', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        $questions = [];
        foreach ($rows as $q) {
            $choices = null;
            if (($q['choices'] ?? null) !== null) {
                $decoded = json_decode((string) $q['choices'], true);
                $choices = is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
            }
            $questions[] = [
                'id'       => (string) $q['id'],
                'ordinal'  => (int) $q['ordinal'],
                'prompt'   => (string) $q['prompt'],
                'qtype'    => (string) $q['qtype'],
                'choices'  => $choices,
                'required' => (int) ($q['required'] ?? 0) === 1,
            ];
        }

        $already = false;
        if ($respondentId !== null && $respondentId !== '') {
            $already = $this->db->table('event_feedback_responses')
                ->where('form_id', $formId)->where('respondent_id', $respondentId)
                ->countAllResults() > 0;
        }

        return [
            'form'              => $form,
            'is_open'           => ($form['status'] ?? '') === 'open',
            'already_submitted' => $already,
            'questions'         => $questions,
        ];
    }

    /** @param array<string,mixed> $data */
    public function addQuestion(string $formId, array $data): Result
    {
        $form = $this->db->table('event_feedback_forms')->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');
        }
        if (trim((string) ($data['prompt'] ?? '')) === '') {
            return Result::fail('PROMPT_REQUIRED', 'feedback.prompt_required', 422);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_feedback_questions')->insert([
            'id'         => $id,
            'form_id'    => $formId,
            'event_id'   => $form['event_id'],
            'ordinal'    => (int) ($data['ordinal'] ?? 0),
            'prompt'     => $data['prompt'],
            'qtype'      => in_array($data['qtype'] ?? 'rating', ['rating', 'text', 'choice', 'boolean'], true) ? $data['qtype'] : 'rating',
            'choices'    => isset($data['choices']) ? json_encode($data['choices'], JSON_UNESCAPED_UNICODE) : null,
            'answer_key' => $data['answer_key'] ?? null,
            'points'     => (int) ($data['points'] ?? 0),
            'rubric'     => $data['rubric'] ?? null,
            'required'   => ! empty($data['required']) ? 1 : 0,
            'created_at' => $now,
        ]);

        return Result::created(['question_id' => $id]);
    }

    /** Open a form for responses. */
    public function openForm(string $formId): Result
    {
        return $this->setFormStatus($formId, 'open');
    }

    /** Close a form. */
    public function closeForm(string $formId): Result
    {
        return $this->setFormStatus($formId, 'closed');
    }

    /**
     * Submit a response. One per respondent per form. For an `automatic` quiz
     * the score is computed immediately; `reviewed` leaves it pending review.
     *
     * @param array<string,mixed> $answers list of [question_id, rating?, answer_text?, answer_choice?]
     * @param array<string,mixed> $opts    respondent_type, visibility, teaching_impact
     */
    public function submitResponse(string $organizationId, string $formId, ?string $respondentId, array $answers, array $opts = []): Result
    {
        $form = $this->db->table('event_feedback_forms')->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');
        }
        if ($form['status'] !== 'open') {
            return Result::fail('FORM_NOT_OPEN', 'feedback.form_not_open', 409, ['status' => $form['status']]);
        }

        $questions = $this->db->table('event_feedback_questions')->where('form_id', $formId)->get()->getResultArray();
        $qById     = [];
        foreach ($questions as $q) {
            $qById[$q['id']] = $q;
        }

        $isQuiz    = $form['kind'] === 'quiz';
        $autoScore = $isQuiz && $form['scoring'] === 'automatic';
        $now       = $this->clock->nowUtcMicro();
        $responseId = Uuid::v7();

        $score    = 0;
        $maxScore = 0;
        if ($isQuiz) {
            foreach ($questions as $q) {
                $maxScore += (int) $q['points'];
            }
        }

        $this->db->transStart();
        try {
            $this->db->table('event_feedback_responses')->insert([
                'id'              => $responseId,
                'organization_id' => $organizationId,
                'form_id'         => $formId,
                'event_id'        => $form['event_id'],
                'respondent_id'   => $respondentId,
                'respondent_type' => in_array($opts['respondent_type'] ?? 'attendee', ['attendee', 'staff', 'guest'], true) ? $opts['respondent_type'] : 'attendee',
                'visibility'      => $form['visibility'],
                'form_version'    => (int) $form['version'],
                'score'           => null,
                'max_score'       => $isQuiz ? $maxScore : null,
                'passed'          => null,
                'review_state'    => ($isQuiz && $form['scoring'] === 'reviewed') ? 'pending_review' : 'final',
                'teaching_impact' => $opts['teaching_impact'] ?? null,
                'submitted_at'    => $now,
                'created_at'      => $now,
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('ALREADY_SUBMITTED', 'feedback.already_submitted', 409);
        }

        foreach ($answers as $ans) {
            $qid = (string) ($ans['question_id'] ?? '');
            $q   = $qById[$qid] ?? null;
            if ($q === null) {
                continue;
            }
            $awarded   = null;
            $isCorrect = null;
            if ($autoScore && $q['answer_key'] !== null) {
                $given     = (string) ($ans['answer_choice'] ?? $ans['answer_text'] ?? '');
                $isCorrect = hash_equals((string) $q['answer_key'], $given) ? 1 : 0;
                $awarded   = $isCorrect ? (int) $q['points'] : 0;
                $score    += $awarded;
            }
            $this->db->table('event_feedback_answers')->insert([
                'id'             => Uuid::v7(),
                'response_id'    => $responseId,
                'question_id'    => $qid,
                'rating'         => isset($ans['rating']) ? (int) $ans['rating'] : null,
                'answer_text'    => $ans['answer_text'] ?? null,
                'answer_choice'  => $ans['answer_choice'] ?? null,
                'awarded_points' => $awarded,
                'is_correct'     => $isCorrect,
                'created_at'     => $now,
            ]);
        }

        if ($autoScore) {
            $passed = $form['pass_score'] !== null ? ($score >= (int) $form['pass_score'] ? 1 : 0) : null;
            $this->db->table('event_feedback_responses')->where('id', $responseId)->update([
                'score'  => $score,
                'passed' => $passed,
            ]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('SUBMIT_FAILED', 'feedback.submit_failed', 500);
        }

        return Result::created([
            'response_id' => $responseId,
            'scored'      => $autoScore,
            'score'       => $autoScore ? $score : null,
            'max_score'   => $isQuiz ? $maxScore : null,
        ]);
    }

    /** Reviewer sets the score for a `reviewed`-scoring quiz response. */
    public function reviewScore(string $responseId, int $score, ?int $passScore = null): Result
    {
        $resp = $this->db->table('event_feedback_responses')->where('id', $responseId)->get()->getRowArray();
        if ($resp === null) {
            return Result::notFound('feedback.response_not_found', 'RESPONSE_NOT_FOUND');
        }
        $passed = $passScore !== null ? ($score >= $passScore ? 1 : 0) : $resp['passed'];
        $this->db->table('event_feedback_responses')->where('id', $responseId)->update([
            'score'        => max(0, $score),
            'passed'       => $passed,
            'review_state' => 'reviewed',
        ]);

        return Result::ok(['response_id' => $responseId, 'score' => max(0, $score), 'passed' => $passed]);
    }

    /**
     * Aggregate results honoring the form's minimum-N threshold. Below the
     * threshold, per-question aggregates are suppressed to protect respondents.
     */
    public function aggregate(string $formId): Result
    {
        $form = $this->db->table('event_feedback_forms')->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');
        }

        $n = (int) $this->db->table('event_feedback_responses')->where('form_id', $formId)->countAllResults();
        $minN = (int) $form['min_aggregate_n'];

        if ($n < $minN) {
            return Result::ok([
                'form_id'    => $formId,
                'responses'  => $n,
                'suppressed' => true,
                'reason'     => 'below_aggregation_threshold',
                'min_n'      => $minN,
            ]);
        }

        // Per-question rating averages (ratings only).
        $ratings = $this->db->query(
            'SELECT q.id AS question_id, q.prompt, AVG(a.rating) AS avg_rating, COUNT(a.rating) AS n
             FROM event_feedback_questions q
             LEFT JOIN event_feedback_answers a ON a.question_id = q.id
             WHERE q.form_id = ? AND q.qtype = "rating"
             GROUP BY q.id, q.prompt',
            [$formId],
        )->getResultArray();

        $scoreAgg = null;
        if ($form['kind'] === 'quiz') {
            $row = $this->db->query(
                'SELECT AVG(score) AS avg_score, MAX(max_score) AS max_score,
                        SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passers,
                        COUNT(score) AS scored
                 FROM event_feedback_responses WHERE form_id = ?',
                [$formId],
            )->getRowArray();
            $scoreAgg = [
                'avg_score' => $row['avg_score'] !== null ? round((float) $row['avg_score'], 2) : null,
                'max_score' => $row['max_score'] !== null ? (int) $row['max_score'] : null,
                'passers'   => (int) ($row['passers'] ?? 0),
                'scored'    => (int) ($row['scored'] ?? 0),
            ];
        }

        return Result::ok([
            'form_id'    => $formId,
            'kind'       => $form['kind'],
            'responses'  => $n,
            'suppressed' => false,
            'ratings'    => array_map(static fn ($r) => [
                'question_id' => $r['question_id'],
                'prompt'      => $r['prompt'],
                'avg_rating'  => $r['avg_rating'] !== null ? round((float) $r['avg_rating'], 2) : null,
                'n'           => (int) $r['n'],
            ], $ratings),
            'quiz'       => $scoreAgg,
        ]);
    }

    private function setFormStatus(string $formId, string $status): Result
    {
        $form = $this->db->table('event_feedback_forms')->where('id', $formId)->get()->getRowArray();
        if ($form === null) {
            return Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');
        }
        $this->db->table('event_feedback_forms')->where('id', $formId)->update([
            'status'     => $status,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['form_id' => $formId, 'event_id' => $form['event_id'], 'status' => $status]);
    }
}
