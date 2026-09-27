<?php

declare(strict_types=1);

/**
 * FEEDBACK consoles — the write face of FeedbackController's console +
 * questionsConsole and the createForm / addQuestion / openForm / closeForm
 * write actions.
 *
 * Two browser pages replace JSON-only endpoints:
 *   - GET events/{id}/feedback-forms    → forms list + create form + open/close
 *   - GET feedback-forms/{id}/questions → form header + questions + add-question
 * Respondent SUBMIT (rate-limited) + per-response REVIEW stay JSON; aggregate
 * keeps its bespoke view. This test covers key parity, both views' states, PRG
 * wiring, and route guards.
 *
 *   php app/Modules/Events/Views/tests/feedback_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl   = file_get_contents($root . '/app/Modules/Events/Controllers/FeedbackController.php');
$svc    = file_get_contents($root . '/app/Modules/Events/Services/FeedbackService.php');
$routes = file_get_contents($root . '/app/Config/Routes.php');

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

echo "language parity (Events.feedback.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['feedback'] ?? []);
chk('en defines Events.feedback.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['feedback'] ?? []));
    chk("$loc feedback.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);

// ---- event feedback console ----------------------------------------------
$cfile = $viewDir . '/feedback_console.php';
echo "\nview: feedback console — empty\n";
$h = $render($cfile, ['forms' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('empty forms hint', str_contains($h, 'No feedback forms yet.'));
chk('create form posts to /events/ev-1/feedback-forms', str_contains($h, 'action="/events/ev-1/feedback-forms"'));
chk('create form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('required title field', str_contains($h, 'name="title" required'));
chk('kind select feedback/quiz', str_contains($h, 'value="feedback"') && str_contains($h, 'value="quiz"'));
chk('scoring select', str_contains($h, 'name="scoring"') && str_contains($h, 'value="automatic"'));
chk('visibility select', str_contains($h, 'name="visibility"'));

echo "\nview: feedback console — populated (draft + open)\n";
$h = $render($cfile, ['forms' => [
    ['id' => 'f-1', 'kind' => 'feedback', 'title' => 'Post-event survey', 'status' => 'draft', 'question_count' => 3, 'response_count' => 0],
    ['id' => 'f-2', 'kind' => 'quiz', 'title' => 'Module quiz', 'status' => 'open', 'question_count' => 10, 'response_count' => 42],
], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'en');
chk('renders form titles', str_contains($h, 'Post-event survey') && str_contains($h, 'Module quiz'));
chk('draft form shows open action', str_contains($h, 'action="/feedback-forms/f-1/open"'));
chk('open form shows close action', str_contains($h, 'action="/feedback-forms/f-2/close"'));
chk('draft form has no close action', ! str_contains($h, 'action="/feedback-forms/f-1/close"'));
chk('open form has no open action', ! str_contains($h, 'action="/feedback-forms/f-2/open"'));
chk('title links to questions console', str_contains($h, 'href="/feedback-forms/f-1/questions"'));
chk('question/response counts shown', str_contains($h, '>10<') && str_contains($h, '>42<'));
chk('status pills rendered', str_contains($h, 'pill draft') && str_contains($h, 'pill open'));

// ---- questions console ---------------------------------------------------
$qfile = $viewDir . '/feedback_questions.php';
echo "\nview: questions console — empty\n";
$h = $render($qfile, ['form' => ['id' => 'f-1', 'event_id' => 'ev-1', 'title' => 'Post-event survey', 'kind' => 'feedback', 'status' => 'draft'], 'questions' => [], 'csrf' => 'TKN'], 'en');
chk('shows form title', str_contains($h, 'Post-event survey'));
chk('back link to event forms', str_contains($h, 'href="/events/ev-1/feedback-forms"'));
chk('empty questions hint', str_contains($h, 'No questions yet.'));
chk('add-question form posts correctly', str_contains($h, 'action="/feedback-forms/f-1/questions"'));
chk('required prompt field', str_contains($h, 'name="prompt" required'));
chk('qtype select', str_contains($h, 'name="qtype"') && str_contains($h, 'value="boolean"'));
chk('required checkbox', str_contains($h, 'name="required" value="1"'));

echo "\nview: questions console — populated\n";
$h = $render($qfile, ['form' => ['id' => 'f-1', 'event_id' => 'ev-1', 'title' => 'Quiz', 'kind' => 'quiz', 'status' => 'open'], 'questions' => [
    ['ordinal' => 1, 'prompt' => 'Capital of Ghana?', 'qtype' => 'text', 'points' => 5, 'required' => 1],
    ['ordinal' => 2, 'prompt' => 'Rate the session', 'qtype' => 'rating', 'points' => 0, 'required' => 0],
], 'csrf' => 'TKN'], 'en');
chk('renders question prompts', str_contains($h, 'Capital of Ghana?') && str_contains($h, 'Rate the session'));
chk('required question shows Yes', str_contains($h, 'Yes'));
chk('no empty hint when populated', ! str_contains($h, 'No questions yet.'));

echo "\nview: escaping + i18n + RTL\n";
$h = $render($qfile, ['form' => ['id' => 'f-1', 'event_id' => 'ev-1', 'title' => '<x>', 'kind' => 'quiz', 'status' => 'open'], 'questions' => [['ordinal' => 1, 'prompt' => '<script>a</script>', 'qtype' => 'text', 'points' => 0, 'required' => 0]], 'csrf' => 'TKN'], 'en');
chk('escapes question prompt', ! str_contains($h, '<script>a</script>') && str_contains($h, '&lt;script&gt;'));
$h = $render($cfile, ['forms' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'fr');
chk('fr localized console heading', str_contains($h, 'Retour sur l'));
$h = $render($cfile, ['forms' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN'], 'ar');
chk('ar console dir=rtl', str_contains($h, 'dir="rtl"'));

echo "\ncontroller wiring\n";
chk('console action exists', str_contains($ctrl, 'function console('));
chk('console renders feedback_console view', str_contains($ctrl, 'WBS\Events\Views\feedback_console'));
chk('questionsConsole action exists', str_contains($ctrl, 'function questionsConsole('));
chk('questionsConsole renders feedback_questions view', str_contains($ctrl, 'WBS\Events\Views\feedback_questions'));
chk('questionsConsole 404s missing form', str_contains($ctrl, "'FORM_NOT_FOUND'"));
chk('createForm uses respondFeedbackEvent', (bool) preg_match('/function createForm.*?respondFeedbackEvent/s', $ctrl));
chk('openForm uses respondFeedbackEvent', (bool) preg_match('/function openForm.*?respondFeedbackEvent/s', $ctrl));
chk('closeForm uses respondFeedbackEvent', (bool) preg_match('/function closeForm.*?respondFeedbackEvent/s', $ctrl));
chk('addQuestion redirects to questions console', (bool) preg_match('#function addQuestion.*?/feedback-forms/.*?/questions#s', $ctrl));
// submit() is now respondent-facing: JSON for API, PRG for the browser. It still
// calls submitResponse; the no-JS respondent funnel lives in feedback_respond_funnel_test.php.
chk('submit keeps JSON for API + calls submitResponse', (bool) preg_match('/function submit.*?submitResponse\\(/s', $ctrl) && (bool) preg_match('/function submit.*?wantsJson\\(\\)/s', $ctrl));
chk('reviewScore stays raw JSON respondWith', (bool) preg_match('/function reviewScore.*?respondWith\(EventServices::feedback\(\)->reviewScore/s', $ctrl));
chk('aggregate keeps bespoke view', str_contains($ctrl, 'feedback_aggregate'));
chk('flash keys used', str_contains($ctrl, 'formCreatedFlash') && str_contains($ctrl, 'formOpenedFlash') && str_contains($ctrl, 'formClosedFlash') && str_contains($ctrl, 'questionAddedFlash'));

echo "\nservice wiring\n";
chk('listFormsForEvent() read helper exists', str_contains($svc, 'function listFormsForEvent('));
chk('listFormsForEvent annotates counts', str_contains($svc, "'question_count'") && str_contains($svc, "'response_count'"));
chk('formWithQuestions() read helper exists', str_contains($svc, 'function formWithQuestions('));
chk('setFormStatus result carries event_id', (bool) preg_match("/function setFormStatus.*?'event_id'\s*=>\s*\\\$form\['event_id'\]/s", $svc));

echo "\nroutes\n";
chk('GET event feedback console', (bool) preg_match('#get\([^\n]*\(:segment\)/feedback-forms\x27[^\n]*FeedbackController::console#', $routes));
chk('POST create form webcsrf', (bool) preg_match('#post\(\x27\(:segment\)/feedback-forms\x27.*?createForm.*?webcsrf#s', $routes));
chk('GET questions console', (bool) preg_match('#get\(\x27\(:segment\)/questions\x27.*?questionsConsole#', $routes));
chk('POST questions webcsrf', (bool) preg_match('#post\(\x27\(:segment\)/questions\x27.*?addQuestion.*?webcsrf#s', $routes));
chk('open POST webcsrf', (bool) preg_match('#\(:segment\)/open\x27.*?openForm.*?webcsrf#s', $routes));
chk('close POST webcsrf', (bool) preg_match('#\(:segment\)/close\x27.*?closeForm.*?webcsrf#s', $routes));
// Respondent submit is now webcsrf-guarded (renderForm mints the cookie for guests
// too) AND rate-limited, but still carries NO `auth` — guests may respond.
chk('respondent submit is webcsrf + ratelimit, no auth', (bool) preg_match('#responses\\x27.*?submit.*?webcsrf.*?ratelimit#', $routes) && ! (bool) preg_match("#responses\\x27.*?submit.*?'auth'#", $routes));
chk('GET respond page route exists', (bool) preg_match('#feedback-forms/\\(:segment\\)/respond\\x27.*?FeedbackController::respond#', $routes));

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
