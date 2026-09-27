<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Post-event feedback & quizzes (SRS FR-EVT-012).
 *
 * Two browser consoles turn the JSON-only organizer write actions into no-JS
 * admin pages:
 *   - GET events/{id}/feedback-forms      — the event's forms + create form +
 *     per-form open/close controls
 *   - GET feedback-forms/{id}/questions   — a form's questions + add-question form
 * The respondent SUBMIT endpoint (rate-limited) and per-response REVIEW stay
 * JSON/API. Aggregate keeps its bespoke view. Console POSTs are webcsrf-guarded.
 */
final class FeedbackController extends BaseController
{
    /**
     * GET events/{id}/feedback-forms — the event FEEDBACK CONSOLE: list the
     * event's feedback/quiz forms (kind, status, question/response counts) plus a
     * no-JS PRG create form and per-form open/close actions. API clients get JSON.
     */
    public function console(string $eventId = '')
    {
        $forms = EventServices::feedback()->listFormsForEvent($eventId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['event_id' => $eventId, 'forms' => $forms]));
        }

        return $this->renderForm('WBS\Events\Views\feedback_console', [
            'eventId' => $eventId,
            'forms'   => $forms,
        ]);
    }

    /**
     * GET feedback-forms/{id}/questions — the QUESTIONS CONSOLE for a single form:
     * the form header, its ordered questions, and a no-JS PRG add-question form.
     */
    public function questionsConsole(string $formId = '')
    {
        $bundle = EventServices::feedback()->formWithQuestions($formId);

        if ($bundle['form'] === null) {
            $nf = Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');

            return $this->wantsJson() ? $this->respondWith($nf) : redirect()->to('/events')->with('error', $this->errText((string) $nf->message));
        }

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($bundle));
        }

        return $this->renderForm('WBS\Events\Views\feedback_questions', [
            'form'      => $bundle['form'],
            'questions' => $bundle['questions'],
        ]);
    }

    public function createForm(string $eventId = '')
    {
        $result = EventServices::feedback()->createForm(
            $this->orgId(),
            $eventId,
            $this->input(),
            $this->actorId(),
        );

        return $this->respondFeedbackEvent($result, $eventId, 'formCreatedFlash');
    }

    public function addQuestion(string $formId = '')
    {
        $result = EventServices::feedback()->addQuestion($formId, $this->input());

        // Redirect back to this form's questions console for browsers.
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = '/feedback-forms/' . rawurlencode($formId) . '/questions';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.feedback.questionAddedFlash'));
    }

    public function openForm(string $formId = '')
    {
        $result = EventServices::feedback()->openForm($formId);

        return $this->respondFeedbackEvent($result, (string) ($result->data['event_id'] ?? ''), 'formOpenedFlash');
    }

    public function closeForm(string $formId = '')
    {
        $result = EventServices::feedback()->closeForm($formId);

        return $this->respondFeedbackEvent($result, (string) ($result->data['event_id'] ?? ''), 'formClosedFlash');
    }

    /**
     * GET feedback-forms/{id}/respond — the RESPONDENT-facing submission page:
     * the form's questions rendered as a no-JS survey/quiz, or a closed / already-
     * submitted / not-found notice. Quiz secrets (answer keys, points) never reach
     * the browser (respondentView strips them). API clients get the same snapshot
     * as JSON. `renderForm` mints the `_csrf` the submit POST needs — including
     * for anonymous guests, who may respond (the route has no `auth`).
     */
    public function respond(string $formId = '')
    {
        $view = EventServices::feedback()->respondentView($formId, $this->actorId('respondent_id'));

        if ($view['form'] === null) {
            $nf = Result::notFound('feedback.form_not_found', 'FORM_NOT_FOUND');

            return $this->wantsJson() ? $this->respondWith($nf) : redirect()->to('/events')->with('error', $this->errText((string) $nf->message));
        }

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($view));
        }

        return $this->renderForm('WBS\\Events\\Views\\feedback_respond', [
            'formId'          => $formId,
            'form'            => $view['form'],
            'questions'       => $view['questions'],
            'isOpen'          => $view['is_open'],
            'alreadySubmitted' => $view['already_submitted'],
        ]);
    }

    public function submit(string $formId = '')
    {
        $in      = $this->input();
        $answers = $in['answers'] ?? [];
        // A browser posts flat answer_* / rating_* fields keyed by question id;
        // API callers post a structured `answers` list. Normalize both.
        if (! is_array($answers) || $answers === []) {
            $answers = $this->collectBrowserAnswers($in);
        }

        $result = EventServices::feedback()->submitResponse(
            $this->orgId(),
            $formId,
            $this->actorId('respondent_id'),
            is_array($answers) ? $answers : [],
            [
                'respondent_type' => $in['respondent_type'] ?? 'attendee',
                'teaching_impact' => $in['teaching_impact'] ?? null,
            ],
        );

        // API clients keep the raw Result; browsers PRG back to the respond page
        // with a localized flash (the page then shows the thank-you state).
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = '/feedback-forms/' . rawurlencode($formId) . '/respond';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.respond.submittedFlash'));
    }

    /**
     * Fold a browser survey POST into the structured answers list the service
     * expects. Fields are namespaced by question id: `rating[{qid}]`,
     * `answer_text[{qid}]`, `answer_choice[{qid}]`. Empty answers are skipped.
     *
     * @param array<string,mixed> $in
     * @return list<array<string,mixed>>
     */
    private function collectBrowserAnswers(array $in): array
    {
        $ratings = is_array($in['rating'] ?? null) ? $in['rating'] : [];
        $texts   = is_array($in['answer_text'] ?? null) ? $in['answer_text'] : [];
        $choices = is_array($in['answer_choice'] ?? null) ? $in['answer_choice'] : [];

        $qids = array_unique(array_merge(array_keys($ratings), array_keys($texts), array_keys($choices)));
        $out  = [];
        foreach ($qids as $qid) {
            $rating = $ratings[$qid] ?? null;
            $text   = $texts[$qid] ?? null;
            $choice = $choices[$qid] ?? null;
            $hasRating = $rating !== null && $rating !== '';
            $hasText   = $text !== null && trim((string) $text) !== '';
            $hasChoice = $choice !== null && $choice !== '';
            if (! $hasRating && ! $hasText && ! $hasChoice) {
                continue;
            }
            $out[] = [
                'question_id'   => (string) $qid,
                'rating'        => $hasRating ? (int) $rating : null,
                'answer_text'   => $hasText ? (string) $text : null,
                'answer_choice' => $hasChoice ? (string) $choice : null,
            ];
        }

        return $out;
    }

    public function reviewScore(string $responseId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::feedback()->reviewScore(
            $responseId,
            (int) ($in['score'] ?? 0),
            isset($in['pass_score']) ? (int) $in['pass_score'] : null,
        ));
    }

    public function aggregate(string $formId = '')
    {
        $result = EventServices::feedback()->aggregate($formId);

        // API clients get JSON; browsers get the bespoke feedback-aggregate view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Feedback aggregate', 'form ' . $formId);
        }

        return $this->respondWith(
            $result,
            'WBS\Events\Views\feedback_aggregate',
            null,
            ['aggregate' => $result->data],
        );
    }

    /**
     * PRG for a browser feedback write scoped to an event: API clients keep the
     * raw Result (JSON); browsers redirect back to the event feedback console with
     * a localized success flash, or the failing Result's message as an error flash.
     */
    private function respondFeedbackEvent(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/feedback-forms' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.feedback.' . $okKey));
    }
}
