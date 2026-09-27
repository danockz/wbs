<?php

declare(strict_types=1);

/**
 * COURSE-AUTHORING WRITE-UI wiring test — proves the course overview page is no
 * longer read-only: it renders an authoring card with a publish control (only
 * while the course is unpublished) and an add-lesson form, both posting to the
 * now-gated write routes (auth + authorize:course.create,any + webcsrf), and the
 * controller PRGs back to the course page with a localized flash while keeping
 * JSON for API clients. Plus i18n parity for the new authoring keys and a
 * headless render smoke of the layout-extending overview view.
 *
 *   php app/Modules/Courses/Views/tests/authoring_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Courses/Language';
$viewDir    = $root . '/app/Modules/Courses/Views';
$controller = $root . '/app/Modules/Courses/Controllers/CourseController.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "language parity (new authoring keys)\n";
$authoringKeys = [
    'heading', 'publishBtn', 'publishHint', 'publishConfirm', 'publishedFlash',
    'lessonHeading', 'lessonTitleLabel', 'lessonTitlePh', 'lessonPositionLabel',
    'lessonRequiredLabel', 'lessonContentLabel', 'lessonContentPh', 'lessonAddBtn',
    'lessonAddedFlash', 'publishedNote',
];
$enAuthoring = (require $langDir . '/en/Courses.php')['authoring'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Courses.php";
    chk("$loc has authoring block", isset($l['authoring']) && is_array($l['authoring']));
    foreach ($authoringKeys as $k) {
        chk("$loc authoring.$k present", isset($l['authoring'][$k]) && $l['authoring'][$k] !== '');
    }
    chk("$loc mirrors all en authoring keys", array_keys($l['authoring']) === array_keys($enAuthoring),
        implode(',', array_diff(array_keys($enAuthoring), array_keys($l['authoring']))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "overview.php exposes authoring controls\n";
$v = (string) file_get_contents("$viewDir/overview.php");
chk('renders an authoring card', str_contains($v, 'Courses.authoring.heading'));
chk('has publish form to /publish', str_contains($v, '/publish"'));
chk('publish asks for confirm()', str_contains($v, 'authoring.publishConfirm'));
chk('publish only when not published', str_contains($v, "\$status !== 'published'"));
chk('has add-lesson form to /lessons', str_contains($v, '/lessons"'));
chk('add-lesson has title field', str_contains($v, 'name="title"'));
chk('add-lesson has position field', str_contains($v, 'name="position"'));
chk('add-lesson has content_ref field', str_contains($v, 'name="content_ref"'));
chk('add-lesson has required checkbox', str_contains($v, 'name="required"'));
chk('forms carry _csrf', substr_count($v, 'name="_csrf"') >= 2);
chk('authoring card gated on a csrf token', str_contains($v, '$authCsrf !== null'));
chk('renders PRG flash messages', str_contains($v, "getFlashdata('success')") && str_contains($v, "getFlashdata('error')"));
chk('resolves token from request wbsCsrf', str_contains($v, 'wbsCsrf'));

// ── 3. Routes gated + webcsrf ────────────────────────────────────────────────
echo "authoring routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['publish', 'addLesson'] as $fn) {
    if (preg_match('#^.*CourseController::' . $fn . '/\$1.*$#m', $routes, $m)) {
        chk("$fn route present", true);
        chk("$fn requires auth", str_contains($m[0], "'auth'"));
        chk("$fn requires authorize:course.create", str_contains($m[0], 'authorize:course.create'));
        chk("$fn webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$fn route present", false);
    }
}
// enrol is now the SELF-SERVICE learner flow: auth + webcsrf (browser) while API
// callers stay CSRF-exempt via the filter's Bearer/header check; still rate-limited
// and NOT gated by course.create (any authenticated member may enrol themselves).
// See learner_flow_crud_test.php for the full learner-flow coverage.
if (preg_match('#^.*EnrollmentController::enrol/\$1.*$#m', $routes, $me)) {
    chk('enrol is auth + webcsrf + ratelimit (self-service)',
        str_contains($me[0], "'auth'") && str_contains($me[0], 'webcsrf') && str_contains($me[0], 'ratelimit:course.enroll'));
    chk('enrol not gated by course.create', ! str_contains($me[0], 'course.create'));
}

// ── 4. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondAuthoring PRG helper', str_contains($ctrl, 'private function respondAuthoring'));
chk('PRG redirects to the course page', str_contains($ctrl, "'/courses/' . rawurlencode(\$courseId)"));
chk('publish flashes publishedFlash', str_contains($ctrl, "'publishedFlash'"));
chk('addLesson flashes lessonAddedFlash', str_contains($ctrl, "'lessonAddedFlash'"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('show passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — overview (fr draft, ar published, no-token read)\n";
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
        return $v;
    }
}
if (! function_exists('session')) {
    function session() {
        return new class {
            function getFlashdata($k) { return $GLOBALS['__flash'][$k] ?? null; }
        };
    }
}
// service('request') returning a request-ish object carrying wbsCsrf when set.
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            public $wbsCsrf;
            public function __construct() { $this->wbsCsrf = $GLOBALS['__wbsCsrf'] ?? null; }
            function getLocale() { return $GLOBALS['__cLoc'] ?? 'en'; }
        };
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLoc']  = $loc;
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Courses.php";
    $renderer           = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/overview.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$draft = ['course_id' => 'crs-1', 'title' => 'Discipleship', 'status' => 'draft',
    'lesson_count' => 2, 'required_count' => 1, 'lessons' => []];
$pub   = ['course_id' => 'crs-2', 'title' => 'Foundations', 'status' => 'published',
    'lesson_count' => 5, 'required_count' => 5, 'lessons' => []];

// fr, draft, WITH token → publish + add-lesson forms present, publish enabled.
$GLOBALS['__flash']   = ['success' => 'Course published.'];
$GLOBALS['__wbsCsrf'] = null;
$h = $render('overview', ['result' => $draft, 'csrf' => 'TOK1'], 'fr');
chk('draft: publish form posts to /courses/crs-1/publish', str_contains($h, 'action="/courses/crs-1/publish"'));
chk('draft: add-lesson form posts to /courses/crs-1/lessons', str_contains($h, 'action="/courses/crs-1/lessons"'));
chk('draft: publish button label translated (fr)', str_contains($h, 'Publier le cours'));
chk('draft: add-lesson title field present', str_contains($h, 'name="title"'));
chk('draft: forms carry the csrf token', substr_count($h, 'value="TOK1"') >= 2);
chk('draft: success flash rendered', str_contains($h, 'Course published.'));
$GLOBALS['__flash'] = [];

// ar, published, WITH token → NO publish form, published note shown, add-lesson still present.
$h2 = $render('overview', ['result' => $pub, 'csrf' => 'TOK2'], 'ar');
chk('published: no publish form', ! str_contains($h2, '/courses/crs-2/publish'));
chk('published: shows published note', str_contains($h2, 'منشورة بالفعل'));
chk('published: add-lesson form still present', str_contains($h2, 'action="/courses/crs-2/lessons"'));

// no token at all (neither $csrf nor request->wbsCsrf) → NO authoring card.
$GLOBALS['__wbsCsrf'] = null;
$h3 = $render('overview', ['result' => $draft], 'en');
chk('no-token: authoring card hidden', ! str_contains($h3, '/courses/crs-1/publish') && ! str_contains($h3, '/courses/crs-1/lessons'));
chk('no-token: still shows course title', str_contains($h3, 'Discipleship'));

// token from request->wbsCsrf (no explicit $csrf) → card appears.
$GLOBALS['__wbsCsrf'] = 'REQTOK';
$h4 = $render('overview', ['result' => $draft], 'en');
chk('request-token: authoring card appears', str_contains($h4, 'action="/courses/crs-1/publish"'));
chk('request-token: uses the request token', str_contains($h4, 'value="REQTOK"'));
$GLOBALS['__wbsCsrf'] = null;

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
