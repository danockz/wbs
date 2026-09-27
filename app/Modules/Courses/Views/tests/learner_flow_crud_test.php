<?php

declare(strict_types=1);

/**
 * LEARNER SELF-SERVICE FLOW wiring test — closes the section-C member-facing gap
 * where course ENROL + MARK-LESSON-COMPLETE were POST-only, had NO browser
 * controls, and their routes were missing auth/webcsrf (lesson-complete had
 * neither). Proves the syllabus page now drives a no-JS, CSP-safe, guarded
 * learner flow:
 *
 *   - EnrollmentService::learnerView returns enrollment + per-lesson completed
 *     flag + a required-lesson progress rollup (resource-light: bounded reads);
 *   - syllabus() is learner-aware (passes the learnerView payload + csrf +
 *     current user) while API clients keep the flat lessons list;
 *   - enrol() enrols the SESSION user and PRGs back to the syllabus; a browser
 *     completeLesson() PRGs back too; both keep JSON for API;
 *   - POST enrol + POST complete now carry auth + webcsrf (+ enrol keeps its
 *     ratelimit);
 *   - the view renders an Enrol button (unenrolled), a progress bar (enrolled),
 *     and a Mark-complete form per unlocked, not-done lesson — CSP-clean;
 *   - i18n parity for the new Courses.learner.* block across all 6 locales.
 *
 *   php app/Modules/Courses/Views/tests/learner_flow_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Courses/Language';
$viewDir    = $root . '/app/Modules/Courses/Views';
$controller = $root . '/app/Modules/Courses/Controllers/EnrollmentController.php';
$service    = $root . '/app/Modules/Courses/Services/EnrollmentService.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for Courses.learner.* ─────────────────────────────────────
echo "language parity (Courses.learner.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en     = require $langDir . '/en/Courses.php';
$enKeys = $flat($en['learner'] ?? []);
chk('en learner block present (>= 10 keys)', count($enKeys) >= 10, (string) count($enKeys));
chk('en progressLabel keeps {0}/{1}/{2}', str_contains((string) ($en['learner']['progressLabel'] ?? ''), '{0}')
    && str_contains((string) ($en['learner']['progressLabel'] ?? ''), '{1}')
    && str_contains((string) ($en['learner']['progressLabel'] ?? ''), '{2}'));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Courses.php";
    $keys = $flat($arr['learner'] ?? []);
    chk("$loc mirrors learner keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc progressLabel keeps placeholders", str_contains((string) ($arr['learner']['progressLabel'] ?? ''), '{0}')
        && str_contains((string) ($arr['learner']['progressLabel'] ?? ''), '{2}'));
}

// ── 2. Service exposes learnerView with progress + per-lesson state ───────────
echo "service: learnerView progress + completed flags\n";
$svc = (string) file_get_contents($service);
chk('learnerView() present', str_contains($svc, 'function learnerView'));
chk('learnerView batches completed lesson ids (one read)',
    (bool) preg_match('/function learnerView.*?learning_progress.*?state.*?completed/s', $svc));
chk('learnerView reports is_enrolled', str_contains($svc, "'is_enrolled'"));
chk('learnerView reports is_completed', str_contains($svc, "'is_completed'"));
chk('learnerView rolls up required progress', str_contains($svc, "'required_total'") && str_contains($svc, "'percent'"));
// Isolate the lesson loop body and assert it contains no DB call (batch-before-loop).
$loopBody = '';
if (preg_match('/foreach \(\$lessons as \$i => \$l\) \{(.*?)\n        \}/s', $svc, $lm)) {
    $loopBody = $lm[1];
}
chk('learnerView does not fan-out per lesson (no query in the lesson loop)',
    $loopBody !== '' && ! str_contains($loopBody, '$this->db->table'));

// ── 3. Controller wiring: learner-aware syllabus + PRG + JSON ────────────────
echo "controller: syllabus learner-aware + enrol/complete PRG\n";
$ctrl = (string) file_get_contents($controller);
chk('syllabus uses learnerView', str_contains($ctrl, 'learnerView'));
chk('syllabus passes csrf', (bool) preg_match('/function syllabus\(.*?\'csrf\'/s', $ctrl));
chk('syllabus passes user_id', (bool) preg_match('/function syllabus\(.*?\'user_id\'/s', $ctrl));
chk('syllabus keeps flat lessons for JSON', (bool) preg_match('/function syllabus\(.*?wantsJson\(\).*?lessons/s', $ctrl));
chk('enrol uses the SESSION user', (bool) preg_match('/function enrol\(.*?currentUserId\(/s', $ctrl));
chk('enrol PRGs via respondEnrollment', (bool) preg_match('/function enrol\(.*?respondEnrollment/s', $ctrl));
chk('completeLesson PRGs back to syllabus', (bool) preg_match('/function completeLesson\(.*?respondEnrollment/s', $ctrl));
chk('completeLesson reads course_id for the redirect', (bool) preg_match('/function completeLesson\(.*?course_id/s', $ctrl));
chk('respondEnrollment keeps JSON for API', (bool) preg_match('/function respondEnrollment.*?wantsJson\(\).*?respondWith/s', $ctrl));
chk('respondEnrollment redirects with flash', (bool) preg_match('/function respondEnrollment.*?redirect\(\)->to.*?with\(/s', $ctrl));

// ── 4. Routes: enrol + complete now auth + webcsrf ───────────────────────────
echo "routes: enrol + complete gated (auth + webcsrf)\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*/enrol.*EnrollmentController::enrol.*$#m', $routes, $m)) {
    chk('enrol route present', true);
    chk('enrol has auth', str_contains($m[0], "'auth'"));
    chk('enrol has webcsrf', str_contains($m[0], 'webcsrf'));
    chk('enrol keeps ratelimit', str_contains($m[0], 'ratelimit:course.enroll'));
} else {
    chk('enrol route present', false);
}
if (preg_match('#^.*lessons/\(:segment\)/complete.*$#m', $routes, $m)) {
    chk('complete route present', true);
    chk('complete has auth', str_contains($m[0], "'auth'"));
    chk('complete has webcsrf', str_contains($m[0], 'webcsrf'));
} else {
    chk('complete route present', false);
}

// ── 5. View controls are CSP-clean, csrf-bound, no-JS ────────────────────────
echo "syllabus.php learner controls\n";
$src = (string) file_get_contents("$viewDir/syllabus.php");
chk('enrol form posts to /courses/{id}/enrol', str_contains($src, '/enrol"'));
chk('complete form posts to /enrollments/{id}/lessons/{lid}/complete', str_contains($src, '/complete"'));
chk('complete form carries course_id hidden field', str_contains($src, 'name="course_id"'));
chk('forms carry _csrf bound to token', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$authCsrf/', $src));
chk('renders a progress bar', str_contains($src, 'lv-prog'));
chk('renders PRG flash', str_contains($src, "getFlashdata('success')") && str_contains($src, "getFlashdata('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noC));

// ── 6. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — unenrolled / enrolled+progress / completed (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Courses') { return $key; }
        $v = $GLOBALS['__cLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Courses.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/syllabus.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$lessons = [
    ['lesson_id' => 'L1', 'position' => 1, 'title' => 'Intro', 'required' => true, 'locked' => false, 'completed' => false, 'content_ref' => 'ref1'],
    ['lesson_id' => 'L2', 'position' => 2, 'title' => 'Deux',  'required' => true, 'locked' => true,  'completed' => false, 'unlock_at' => '2026-10-01'],
    ['lesson_id' => 'L3', 'position' => 3, 'title' => 'Trois', 'required' => false, 'locked' => false, 'completed' => true],
];

// Unenrolled learner (has token + user): Enrol button shown, no complete forms.
$hu = $render([
    'result'  => ['course_id' => 'C1', 'is_enrolled' => false, 'is_completed' => false, 'enrollment' => null,
                  'lessons' => $lessons, 'progress' => ['required_total' => 2, 'required_done' => 0, 'total' => 3, 'done' => 1, 'percent' => 0]],
    'csrf'    => 'TOK', 'user_id' => 'u1',
], 'fr');
chk('unenrolled: enrol form to /courses/C1/enrol', str_contains($hu, 'action="/courses/C1/enrol"'));
chk('unenrolled: csrf token present', str_contains($hu, 'value="TOK"'));
chk('unenrolled: no complete form yet', ! str_contains($hu, '/complete"'));

// Enrolled + in progress: progress bar + a Mark-complete form for the unlocked, not-done lesson (L1).
$he = $render([
    'result'  => ['course_id' => 'C1', 'is_enrolled' => true, 'is_completed' => false,
                  'enrollment' => ['id' => 'E9', 'status' => 'active', 'enrolled_at' => '2026-09-01 00:00:00'],
                  'lessons' => $lessons, 'progress' => ['required_total' => 2, 'required_done' => 0, 'total' => 3, 'done' => 1, 'percent' => 0]],
    'csrf'    => 'TOK', 'user_id' => 'u1',
], 'fr');
chk('enrolled: progress bar rendered', str_contains($he, 'lv-prog'));
chk('enrolled: mark-complete form for unlocked L1', str_contains($he, 'action="/enrollments/E9/lessons/L1/complete"'));
chk('enrolled: course_id hidden field in complete form', str_contains($he, 'name="course_id" value="C1"'));
chk('enrolled: locked L2 has NO complete form', ! str_contains($he, '/lessons/L2/complete"'));
chk('enrolled: done L3 shows completed tag, no form', ! str_contains($he, '/lessons/L3/complete"'));
chk('enrolled: no enrol button (already enrolled)', ! str_contains($he, '/courses/C1/enrol"'));

// Completed course: completion note, no action forms.
$hc = $render([
    'result'  => ['course_id' => 'C1', 'is_enrolled' => true, 'is_completed' => true,
                  'enrollment' => ['id' => 'E9', 'status' => 'completed', 'enrolled_at' => '2026-09-01 00:00:00'],
                  'lessons' => $lessons, 'progress' => ['required_total' => 2, 'required_done' => 2, 'total' => 3, 'done' => 3, 'percent' => 100]],
    'csrf'    => 'TOK', 'user_id' => 'u1',
], 'fr');
chk('completed: shows course-completed note', str_contains($hc, lang('Courses.learner.courseCompleted')));
chk('completed: no complete forms', ! str_contains($hc, '/complete"'));

// Anonymous (no user): sign-in prompt, no forms, no crash.
$ha = $render([
    'result'  => ['course_id' => 'C1', 'is_enrolled' => false, 'is_completed' => false, 'enrollment' => null,
                  'lessons' => $lessons, 'progress' => ['required_total' => 2, 'required_done' => 0, 'total' => 3, 'done' => 0, 'percent' => 0]],
    'csrf'    => '', 'user_id' => '',
], 'fr');
chk('anon: sign-in prompt shown', str_contains($ha, lang('Courses.learner.signInToEnrol')));
chk('anon: no enrol form', ! str_contains($ha, '/enrol"'));
chk('render: no untranslated Courses.learner keys leaked', ! str_contains($he, 'Courses.learner.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
