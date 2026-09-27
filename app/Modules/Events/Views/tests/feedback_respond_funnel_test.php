<?php

declare(strict_types=1);

/**
 * FEEDBACK/QUIZ RESPONDENT FUNNEL wiring test — closes the section-C member-
 * facing gap where response submission (POST feedback-forms/{id}/responses) was
 * a JSON-only, un-webcsrf'd endpoint with NO browser page. Proves the form now
 * has a no-JS, CSP-safe, privacy-safe respondent submission funnel:
 *
 *   - FeedbackService::respondentView returns a respondent-safe snapshot that
 *     STRIPS quiz secrets (answer_key/points/rubric) from every question, and
 *     reports is_open + already_submitted; resource-light (bounded reads);
 *   - FeedbackController::respond (GET) renders the page (JSON for API); submit
 *     (POST) folds the browser's namespaced fields into the service's answers
 *     list and PRGs back with a localized flash (JSON kept for API);
 *   - POST responses gains webcsrf (guests may still respond — NO auth added);
 *     GET respond route exists; API callers stay header-exempt;
 *   - the view renders rating/boolean/choice/text controls, is csrf-bound, and
 *     shows closed / already-submitted / thank-you fallbacks — CSP-clean;
 *   - i18n parity for the new Events.respond.* block across all 6 locales.
 *
 *   php app/Modules/Events/Views/tests/feedback_respond_funnel_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Events/Language';
$viewDir    = $root . '/app/Modules/Events/Views';
$controller = $root . '/app/Modules/Events/Controllers/FeedbackController.php';
$service    = $root . '/app/Modules/Events/Services/FeedbackService.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for Events.respond.* ──────────────────────────────────────
echo "language parity (Events.respond.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en     = require $langDir . '/en/Events.php';
$enKeys = $flat($en['respond'] ?? []);
chk('en respond block present (>= 17 keys)', count($enKeys) >= 17, (string) count($enKeys));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Events.php";
    $keys = $flat($arr['respond'] ?? []);
    chk("$loc mirrors respond keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
}

// ── 2. Service: privacy-safe respondentView ──────────────────────────────────
echo "service: respondentView (privacy-safe)\n";
$svc = (string) file_get_contents($service);
chk('respondentView() present', str_contains($svc, 'function respondentView'));
// Isolate just the respondentView method body (up to the next `function `).
$rvBody = '';
if (preg_match('/function respondentView\(.*?\n    \}\n/s', $svc, $mm)) {
    $rvBody = $mm[0];
}
chk('respondentView selects only safe question columns', (bool) preg_match("/->select\('id, ordinal, prompt, qtype, choices, required'\)/", $rvBody));
chk('respondentView never selects answer_key/points/rubric', $rvBody !== '' && ! str_contains($rvBody, 'answer_key') && ! str_contains($rvBody, 'rubric') && ! str_contains($rvBody, 'points'));
chk('respondentView reports is_open + already_submitted', str_contains($rvBody, "'is_open'") && str_contains($rvBody, "'already_submitted'"));
chk('respondentView decodes choices JSON', str_contains($rvBody, 'json_decode'));

// ── 3. Controller: respond(GET) + submit(POST) PRG + field folding ───────────
echo "controller: respond(GET) + submit(POST)\n";
$ctrl = (string) file_get_contents($controller);
chk('respond() renders feedback_respond view', (bool) preg_match('/function respond\(.*?Views\\\\\\\\feedback_respond/s', $ctrl));
chk('respond() uses respondentView', (bool) preg_match('/function respond\(.*?respondentView\(/s', $ctrl));
chk('respond() keeps JSON for API', (bool) preg_match('/function respond\(.*?wantsJson\(\)/s', $ctrl));
chk('respond() 404s a missing form', (bool) preg_match('/function respond\(.*?form_not_found/s', $ctrl));
chk('submit() folds browser answers', (bool) preg_match('/function submit\(.*?collectBrowserAnswers\(/s', $ctrl));
chk('submit() PRGs browsers with submittedFlash', (bool) preg_match('/function submit\(.*?respond.*?submittedFlash/s', $ctrl));
chk('submit() keeps JSON for API', (bool) preg_match('/function submit\(.*?wantsJson\(\)/s', $ctrl));
chk('collectBrowserAnswers namespaces by question id', str_contains($ctrl, "\$in['rating']") && str_contains($ctrl, "\$in['answer_text']") && str_contains($ctrl, "\$in['answer_choice']"));
chk('collectBrowserAnswers skips empty answers', (bool) preg_match('/collectBrowserAnswers.*?continue;/s', $ctrl));

// ── 4. Routes: respond GET + responses POST (webcsrf, NO auth) ───────────────
echo "routes: respond GET + responses POST\n";
$routes = (string) file_get_contents($routesFile);
chk('GET respond page route', (bool) preg_match('#feedback-forms/\(:segment\)/respond.*?FeedbackController::respond#', $routes));
if (preg_match('#feedback-forms/\(:segment\)/responses.*?submit.*#', $routes, $m)) {
    chk('POST responses route present', true);
    chk('responses POST has webcsrf', str_contains($m[0], 'webcsrf'));
    chk('responses POST keeps ratelimit', str_contains($m[0], 'ratelimit:event.rsvp'));
    chk('responses POST has NO auth (guests may respond)', ! str_contains($m[0], "'auth'"));
} else {
    chk('POST responses route present', false);
}

// ── 5. View controls: CSP-clean, csrf-bound, no-JS, all question types ────────
echo "feedback_respond.php controls\n";
$src = (string) file_get_contents("$viewDir/feedback_respond.php");
chk('form posts to /feedback-forms/{id}/responses', str_contains($src, '/responses"'));
chk('form carries _csrf bound to token', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('rating radios namespaced by qid', str_contains($src, 'name="rating[<?= $qa ?>]"'));
chk('text answers namespaced by qid', str_contains($src, 'name="answer_text[<?= $qa ?>]"'));
chk('choice/boolean answers namespaced by qid', str_contains($src, 'name="answer_choice[<?= $qa ?>]"'));
chk('renders PRG flash', str_contains($src, "getFlashdata('success')") && str_contains($src, "getFlashdata('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input)\s*=/i', $noC));

// ── 6. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — open form, quiz, closed, already-submitted, empty, no-secrets (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') { return $key; }
        $v = $GLOBALS['__fbLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__fbLang'] = require $langDir . '/fr/Events.php';
$renderer = new class {
    function extend($x) { return ''; }
    function section($x) { return ''; }
    function endSection() { return ''; }
    function render(string $file, array $data): string {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};
$view  = "$viewDir/feedback_respond.php";
$fbForm = ['id' => 'f1', 'event_id' => 'ev9', 'kind' => 'feedback', 'title' => 'Retour', 'status' => 'open'];
$qs = [
    ['id' => 'q-rate', 'ordinal' => 0, 'prompt' => 'Note globale', 'qtype' => 'rating', 'choices' => null, 'required' => true],
    ['id' => 'q-txt', 'ordinal' => 1, 'prompt' => 'Commentaires', 'qtype' => 'text', 'choices' => null, 'required' => false],
    ['id' => 'q-ch', 'ordinal' => 2, 'prompt' => 'Session préférée', 'qtype' => 'choice', 'choices' => ['Matin', 'Après-midi'], 'required' => false],
    ['id' => 'q-bool', 'ordinal' => 3, 'prompt' => 'Reviendriez-vous ?', 'qtype' => 'boolean', 'choices' => null, 'required' => true],
];

// open feedback form
$h = $renderer->render($view, ['formId' => 'f1', 'form' => $fbForm, 'questions' => $qs, 'isOpen' => true, 'alreadySubmitted' => false, 'csrf' => 'TKN']);
chk('open: form posts to responses', str_contains($h, 'action="/feedback-forms/f1/responses"'));
chk('open: rating radios present', str_contains($h, 'name="rating[q-rate]"'));
chk('open: text textarea present', str_contains($h, 'name="answer_text[q-txt]"'));
chk('open: choice select present', str_contains($h, 'name="answer_choice[q-ch]"') && str_contains($h, '>Matin<'));
chk('open: boolean radios present', str_contains($h, 'name="answer_choice[q-bool]"'));
chk('open: required rating marked required', (bool) preg_match('/name="rating\[q-rate\]"[^>]*required/', $h));
chk('open: csrf bound', str_contains($h, 'value="TKN"'));
chk('open: submit button present', str_contains($h, lang('Events.respond.submitBtn')));

// quiz heading
$h = $renderer->render($view, ['formId' => 'f1', 'form' => ['id' => 'f1', 'event_id' => 'ev9', 'kind' => 'quiz', 'title' => 'Quiz', 'status' => 'open'], 'questions' => $qs, 'isOpen' => true, 'alreadySubmitted' => false, 'csrf' => 'TKN']);
chk('quiz: quiz heading shown', str_contains($h, lang('Events.respond.headingQuiz')));

// closed form -> notice, no form
$h = $renderer->render($view, ['formId' => 'f1', 'form' => $fbForm, 'questions' => $qs, 'isOpen' => false, 'alreadySubmitted' => false, 'csrf' => 'TKN']);
chk('closed: closed notice shown', str_contains($h, esc(lang('Events.respond.closedNotice'))));
chk('closed: no submit form', ! str_contains($h, 'action="/feedback-forms/f1/responses"'));

// already submitted -> thank-you, no form
$h = $renderer->render($view, ['formId' => 'f1', 'form' => $fbForm, 'questions' => $qs, 'isOpen' => true, 'alreadySubmitted' => true, 'csrf' => 'TKN']);
chk('submitted: thank-you shown', str_contains($h, lang('Events.respond.thanksHeading')));
chk('submitted: no submit form', ! str_contains($h, 'action="/feedback-forms/f1/responses"'));

// empty form
$h = $renderer->render($view, ['formId' => 'f1', 'form' => $fbForm, 'questions' => [], 'isOpen' => true, 'alreadySubmitted' => false, 'csrf' => 'TKN']);
chk('empty: no-questions notice', str_contains($h, lang('Events.respond.noQuestions')));

// no leaked keys
$h = $renderer->render($view, ['formId' => 'f1', 'form' => $fbForm, 'questions' => $qs, 'isOpen' => true, 'alreadySubmitted' => false, 'csrf' => 'TKN']);
chk('render: no untranslated Events.respond keys leaked', ! str_contains($h, 'Events.respond.'));
chk('render: no answer_key/points leaked to browser', ! str_contains($h, 'answer_key') && ! str_contains($h, 'name="points"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
