<?php

declare(strict_types=1);

/**
 * FOLLOW-UP RECORD CRUD WORKFLOW wiring test — proves the follow-up RECORD itself
 * is now a first-class browser workflow, not just a JSON API endpoint:
 *
 *   - GET /gamification/follow-ups/new renders a capture FORM (FollowUpsController
 *     ::recordFollowUpForm) whose entity-reference fields are PICKERS: subject is
 *     a <select> drawn from the follower's scope roster (free-text fallback when
 *     empty), type/method are <select>s of the active catalogs, status/health are
 *     fixed-vocabulary <select>s. Posts to the webcsrf-guarded POST follow-ups.
 *   - GET /gamification/follow-ups/{id} (followup_show) carries inline EDIT
 *     (POST .../update, a browser alias for PATCH) + CANCEL (POST .../cancel)
 *     controls, hidden for an already-cancelled record; type/method/subject are
 *     immutable there.
 *   - The three record WRITE routes now carry the webcsrf filter, and the new
 *     form/update routes exist.
 *   - The controller PRGs browser writes with localized flashes and the service
 *     exposes recordStatuses()/healthLevels().
 *   - i18n parity for the new followupRecord.* keys across all six locales.
 *
 *   php app/Modules/Gamification/Views/tests/followup_record_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$fuCtrl     = $root . '/app/Modules/Gamification/Controllers/FollowUpsController.php';
$svc        = $root . '/app/Modules/Gamification/Services/FollowUpService.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ---------------------------------------------------------------------------
// i18n parity for the new followupRecord.* block (leaf keys identical, 6 locales).
// ---------------------------------------------------------------------------
$locales = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
$flatten = static function (array $a, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out = array_merge($out, $flatten($v, $key));
        } else {
            $out[] = $key;
        }
    }
    sort($out);

    return $out;
};

$baseKeys = null;
foreach ($locales as $loc) {
    $arr = require $langDir . "/$loc/Gamification.php";
    $rec = $arr['admin']['followupRecord'] ?? null;
    chk("lang[$loc] has admin.followupRecord block", is_array($rec));
    if (! is_array($rec)) {
        continue;
    }
    $keys = $flatten($rec);
    if ($baseKeys === null) {
        $baseKeys = $keys;
        chk('lang[en] followupRecord key count > 40', count($keys) > 40, (string) count($keys));
    } else {
        chk("lang[$loc] followupRecord keys match en", $keys === $baseKeys,
            'diff: ' . implode(',', array_merge(array_diff($keys, $baseKeys), array_diff($baseKeys, $keys))));
    }
}

// ---------------------------------------------------------------------------
// Layout-bound render harness (same pattern as config_catalog2_crud_test).
// ---------------------------------------------------------------------------
if (! function_exists('lang')) {
    function lang(string $key, array $args = [])
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Gamification') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }

        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $ctx = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$GLOBALS['__flash'] = [];
if (! function_exists('session')) {
    function session()
    {
        return new class {
            public function getFlashdata($k = null)
            {
                return $k === null ? ($GLOBALS['__flash'] ?? []) : ($GLOBALS['__flash'][$k] ?? null);
            }
        };
    }
}

$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        public function extend($x)
        {
            return '';
        }

        public function section($x)
        {
            return '';
        }

        public function endSection()
        {
            return '';
        }
    };
    $bound = Closure::bind(function () use ($data, $viewDir, $view) {
        extract($data);
        ob_start();
        include "$viewDir/$view.php";

        return (string) ob_get_clean();
    }, $renderer, $renderer);

    return $bound();
};

// ---------------------------------------------------------------------------
// Capture form: pickers for the entity-reference fields.
// ---------------------------------------------------------------------------
$rec = $render('followup_record', [
    'csrf'     => 'FRX',
    'types'    => [
        ['code' => 'first_visit', 'name' => 'First Visit', 'phase' => 'win'],
        ['code' => 'discipleship', 'name' => 'Discipleship', 'phase' => 'build'],
    ],
    'methods'  => [
        ['code' => 'call', 'name' => 'Phone Call'],
        ['code' => 'visit', 'name' => 'Home Visit'],
    ],
    'subjects' => [
        ['id' => 'u-1', 'display_name' => 'Ama Mensah'],
        ['id' => 'u-2', 'display_name' => 'Kofi Boateng'],
    ],
    'statuses' => ['pending', 'in_progress', 'completed', 'no_response', 'cancelled'],
    'health'   => ['excellent', 'good', 'okay', 'challenged', 'very_challenged'],
    'old'      => [],
    'error'    => '',
], 'en');

chk('record form posts to /gamification/follow-ups', str_contains($rec, 'action="/gamification/follow-ups"'));
chk('record form carries csrf', str_contains($rec, 'name="_csrf" value="FRX"'));
chk('subject rendered as SELECT (picker)', str_contains($rec, 'name="subject_user_id"') && str_contains($rec, '<option value="u-1"'));
chk('subject option shows display name not just id', str_contains($rec, '>Ama Mensah<'));
chk('subject NOT a bare free-text input when roster present', ! str_contains($rec, 'type="text" id="subject_user_id"'));
chk('type picker uses code as value + name as label', str_contains($rec, '<option value="first_visit"') && str_contains($rec, '>First Visit<'));
chk('type picker groups by phase (optgroup)', str_contains($rec, '<optgroup'));
chk('method picker present', str_contains($rec, 'name="method_code"') && str_contains($rec, '<option value="call"'));
chk('status picker present', str_contains($rec, 'name="status"') && str_contains($rec, '<option value="completed"'));
chk('health picker present', str_contains($rec, 'name="spiritual_health"') && str_contains($rec, '<option value="good"'));
chk('has summary/outcome/needs fields', str_contains($rec, 'name="summary"') && str_contains($rec, 'name="outcome"') && str_contains($rec, 'name="needs"'));
chk('has next follow-up scheduling', str_contains($rec, 'name="next_follow_up_at"') && str_contains($rec, 'name="next_notes"'));

// Free-text fallback when no scope roster.
$recEmpty = $render('followup_record', [
    'csrf' => 'FRX', 'types' => [], 'methods' => [], 'subjects' => [],
    'statuses' => ['completed'], 'health' => ['good'], 'old' => [], 'error' => '',
], 'en');
chk('subject degrades to free-text input when roster empty', str_contains($recEmpty, 'type="text" id="subject_user_id"'));

// Sticky values + error re-render.
$recErr = $render('followup_record', [
    'csrf' => 'FRX',
    'types' => [['code' => 'first_visit', 'name' => 'First Visit', 'phase' => 'win']],
    'methods' => [['code' => 'call', 'name' => 'Phone Call']],
    'subjects' => [['id' => 'u-1', 'display_name' => 'Ama Mensah']],
    'statuses' => ['pending', 'completed'], 'health' => ['good'],
    'old' => ['type_code' => 'first_visit', 'method_code' => 'call', 'subject_user_id' => 'u-1', 'status' => 'pending', 'summary' => 'Had a good chat'],
    'error' => 'Something went wrong',
], 'en');
chk('error banner rendered on failure re-render', str_contains($recErr, 'Something went wrong'));
chk('sticky: type preselected', str_contains($recErr, '<option value="first_visit" selected'));
chk('sticky: subject preselected', str_contains($recErr, '<option value="u-1" selected'));
chk('sticky: status preselected', str_contains($recErr, '<option value="pending" selected'));
chk('sticky: summary preserved', str_contains($recErr, 'Had a good chat'));

// ---------------------------------------------------------------------------
// Detail page: edit + cancel controls, immutability, cancelled state.
// ---------------------------------------------------------------------------
$showData = [
    'followup' => [
        'id' => 'fu-9', 'subject_user_id' => 'u-1', 'follower_user_id' => 'ldr-1',
        'type_code' => 'first_visit', 'method_code' => 'call', 'status' => 'completed',
        'performed_at' => '2026-09-10 10:00', 'summary' => 'Great visit', 'outcome' => 'Will attend',
        'spiritual_health' => 'good', 'needs' => ['prayer', 'transport'],
        'next_follow_up_at' => '2026-09-20 10:00', 'next_notes' => 'bring booklet',
    ],
    'canceled' => false,
    'statuses' => ['pending', 'in_progress', 'completed', 'no_response', 'cancelled'],
    'health'   => ['excellent', 'good', 'okay', 'challenged', 'very_challenged'],
    'csrf'     => 'SHX',
];
$show = $render('followup_show', $showData, 'en');
chk('detail: edit form posts to /update alias', str_contains($show, 'action="/gamification/follow-ups/fu-9/update"'));
chk('detail: cancel form posts to /cancel', str_contains($show, 'action="/gamification/follow-ups/fu-9/cancel"'));
chk('detail: edit carries csrf', str_contains($show, 'name="_csrf" value="SHX"'));
chk('detail: status picker preselects current', str_contains($show, '<option value="completed" selected'));
chk('detail: health picker preselects current', str_contains($show, '<option value="good" selected'));
chk('detail: needs pre-joined into edit input', str_contains($show, 'value="prayer, transport"'));
chk('detail: summary prefilled in edit', str_contains($show, 'Great visit'));
chk('detail: type/method NOT editable (no name=type_code input/select in form)', ! str_contains($show, 'name="type_code"') && ! str_contains($show, 'name="method_code"'));
chk('detail: cancel has confirm guard', str_contains($show, 'confirm('));

// Cancelled record: banner, no write controls.
$showCancelled = $render('followup_show', ['followup' => ['id' => 'fu-9', 'status' => 'cancelled'], 'canceled' => true, 'statuses' => ['cancelled'], 'health' => [], 'csrf' => 'SHX'], 'en');
chk('cancelled: shows banner', str_contains($showCancelled, lang('Gamification.admin.followupRecord.canceledBanner')));
chk('cancelled: NO edit form', ! str_contains($showCancelled, '/update"'));
chk('cancelled: NO cancel form', ! str_contains($showCancelled, '/cancel"'));

// ---------------------------------------------------------------------------
// Routes: webcsrf on the 3 write routes + new form/update routes exist.
// ---------------------------------------------------------------------------
$routes = file_get_contents($routesFile);
chk('route: GET follow-ups/new -> recordFollowUpForm', (bool) preg_match('/get\(.follow-ups\/new.,.*recordFollowUpForm/', $routes));
chk('route: POST follow-ups has webcsrf', (bool) preg_match("/post\('follow-ups',.*recordFollowUp'.*webcsrf/", $routes));
chk('route: PATCH follow-ups/{id} has webcsrf', (bool) preg_match("/patch\('follow-ups\/\(:segment\)',.*updateFollowUp.*webcsrf/", $routes));
chk('route: POST follow-ups/{id}/update alias -> updateFollowUp + webcsrf', (bool) preg_match("/post\('follow-ups\/\(:segment\)\/update',.*updateFollowUp.*webcsrf/", $routes));
chk('route: POST follow-ups/{id}/cancel has webcsrf', (bool) preg_match("/post\('follow-ups\/\(:segment\)\/cancel',.*cancelFollowUp.*webcsrf/", $routes));

// ---------------------------------------------------------------------------
// Controller + service wiring.
// ---------------------------------------------------------------------------
$ctrl = file_get_contents($fuCtrl);
chk('controller: recordFollowUpForm action', str_contains($ctrl, 'public function recordFollowUpForm('));
chk('controller: subjectsInScope scope helper', str_contains($ctrl, 'private function subjectsInScope('));
chk('controller: uses listDistinctForGroups', str_contains($ctrl, 'listDistinctForGroups('));
chk('controller: record PRG redirect on browser', str_contains($ctrl, "redirect()->to('/gamification/follow-ups/'"));
chk('controller: record failure re-renders form with error', str_contains($ctrl, "'error'    => (string) \$result->message") || str_contains($ctrl, "'error'"));
chk('controller: update PRG w/ updatedFlash', str_contains($ctrl, 'followupRecord.updatedFlash'));
chk('controller: cancel PRG w/ canceledFlash', str_contains($ctrl, 'followupRecord.canceledFlash'));
chk('controller: show passes statuses/health/csrf', str_contains($ctrl, "'statuses' => GamificationServices::followUps()->recordStatuses()"));

$svcSrc = file_get_contents($svc);
chk('service: recordStatuses() accessor', str_contains($svcSrc, 'public function recordStatuses(): array'));
chk('service: healthLevels() accessor', str_contains($svcSrc, 'public function healthLevels(): array'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
