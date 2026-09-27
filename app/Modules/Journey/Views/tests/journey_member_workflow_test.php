<?php

declare(strict_types=1);

/**
 * JOURNEY MEMBER DETAIL write-UI workflow wiring test — proves the per-member
 * journey page (journey/members/{id}) is no longer JSON-only: it renders the
 * member's current stage + phase + status, a transition timeline, and the manual
 * controls a leader uses — advance/set stage, pause/resume/archive, or OPEN a
 * journey when none exists — each POSTing to the webcsrf-guarded per-member routes.
 * The controller PRGs browser writes back to the member page with a localized
 * flash (JSON kept for API clients), passes the CSRF token, and carries the group
 * context across the redirect. Plus i18n parity for the new keys and a headless
 * render smoke (self-contained page harness, mirrors journey_i18n_test).
 *
 *   php app/Modules/Journey/Views/tests/journey_member_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Journey/Language';
$viewDir    = $root . '/app/Modules/Journey/Views';
$controller = $root . '/app/Modules/Journey/Controllers/JourneyController.php';
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
echo "language parity (admin.member.*)\n";
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};
$en    = require $langDir . '/en/Journey.php';
$enKeys = $flatten(['member' => $en['admin']['member']]);
$need = ['metaTitle', 'heading', 'noJourney', 'openHeading', 'openBtn', 'stageLabel', 'moveHeading',
    'toStageLabel', 'moveBtn', 'moveConfirm', 'statusHeading', 'timelineHeading', 'noHistory',
    'openedFlash', 'transitionedFlash', 'statusFlash', 'disciplerLabel', 'reasonLabel', 'currentTag',
    'status.active', 'status.paused', 'status.completed', 'status.archived'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Journey.php";
    $m = $l['admin']['member'] ?? [];
    $flat = $flatten($m);
    foreach ($need as $k) {
        chk("$loc admin.member.$k present", in_array($k, $flat, true) && (is_array($m) && $m !== []));
    }
    chk("$loc mirrors all en member keys", array_diff($enKeys, $flatten(['member' => $m])) === [],
        'missing: ' . implode(',', array_diff($enKeys, $flatten(['member' => $m]))));
    chk("$loc no stray member keys", array_diff($flatten(['member' => $m]), $enKeys) === [],
        'extra: ' . implode(',', array_diff($flatten(['member' => $m]), $enKeys)));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "member.php exposes open/transition/status controls\n";
$src = (string) file_get_contents("$viewDir/member.php");
chk('open form posts to .../open', str_contains($src, '/open" class'));
chk('transition form posts to .../transition', str_contains($src, '/transition" class'));
chk('status form posts to .../status', str_contains($src, '/status"'));
chk('transition asks for confirm()', str_contains($src, 'moveConfirm'));
chk('status asks for confirm()', str_contains($src, 'statusConfirm'));
chk('to_stage select present', str_contains($src, 'name="to_stage"'));
chk('transition carries optional discipler_id', str_contains($src, 'name="discipler_id"'));
chk('transition carries optional reason', str_contains($src, 'name="reason"'));
chk('forms carry group context', str_contains($src, 'name="group_id" value="<?= esc($ctx'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('renders a transition timeline', str_contains($src, 'class="timeline"') && str_contains($src, '$history'));
chk('redacts member id', str_contains($src, 'redact_id('));
chk('guard-loads redactor helper', str_contains($src, 'redactor_helper.php'));
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
chk('current stage disabled in the move dropdown', str_contains($src, "\$sc === \$current ? 'disabled'"));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondMemberDecision PRG helper', str_contains($ctrl, 'private function respondMemberDecision'));
chk('showForUser renders member view + csrf', str_contains($ctrl, 'WBS\Journey\Views\member') && str_contains($ctrl, 'wbsCsrf'));
chk('showForUser passes the ladder for the stage dropdown', str_contains($ctrl, "'ladder'  => JourneyServices::journey()->ladder"));
chk('openJourney PRGs with openedFlash', (bool) preg_match('/function openJourney\(.*?openedFlash/s', $ctrl));
chk('transition PRGs with transitionedFlash', (bool) preg_match('/function transition\(.*?transitionedFlash/s', $ctrl));
chk('setStatus PRGs with statusFlash', (bool) preg_match('/function setStatus\(.*?statusFlash/s', $ctrl));
chk('member PRG redirects to /journey/members/{id}', str_contains($ctrl, "'/journey/members/' . rawurlencode(\$userId)"));
chk('member PRG carries group context', (bool) preg_match('/respondMemberDecision.*?group_id=.*?rawurlencode/s', $ctrl));
chk('per-member moves stay scope-checked', str_contains($ctrl, 'authorizeGroupScope(self::SCOPE_ACTION'));
chk('JSON kept for API clients (showForUser)', (bool) preg_match('/function showForUser\(.*?if \(\$this->wantsJson\(\)\)/s', $ctrl));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "per-member routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['members/(:segment)/open', 'members/(:segment)/transition', 'members/(:segment)/status'] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . "'.*Journey.*$#m", $routes, $m)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$needle route present", false);
    }
}

// ── 5. Headless render smoke (self-contained page harness) ────────────────────
echo "render smoke — member (fr): with journey, no journey, flash\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            function getLocale() { return $GLOBALS['__jLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c) {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Journey') { return $key; }
        $v = $GLOBALS['__jLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__jLoc']  = $loc;
    $GLOBALS['__jLang'] = require $langDir . "/$loc/Journey.php";
    extract($data);
    ob_start();
    include "$viewDir/member.php";
    return (string) ob_get_clean();
};

$ladder = [
    ['code' => 'new_believer', 'name' => 'New Believer', 'phase' => 'win', 'sort_order' => 3],
    ['code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'sort_order' => 4],
    ['code' => 'worker', 'name' => 'Worker', 'phase' => 'build', 'sort_order' => 6],
];

$GLOBALS['__flash'] = [];
$h = $render([
    'user_id'  => 'user-0000000000ABC12',
    'group_id' => null,
    'ladder'   => $ladder,
    'csrf'     => 'JM1',
    'journey'  => ['id' => 'j-1', 'stage_code' => 'growing', 'stage_phase' => 'build', 'status' => 'active',
        'stage_entered_at' => '2026-09-01 08:00:00', 'previous_stage' => 'new_believer', 'source' => 'manual'],
    'history'  => [
        ['from_stage' => '', 'to_stage' => 'new_believer', 'direction' => 'open', 'source' => 'manual', 'created_at' => '2026-08-01 09:00:00'],
        ['from_stage' => 'new_believer', 'to_stage' => 'growing', 'direction' => 'advance', 'source' => 'manual',
            'discipler_id' => 'user-0000000000DISC9', 'reason' => 'Finished foundation', 'created_at' => '2026-09-01 08:00:00'],
    ],
], 'fr');
chk('member: lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('member: shows current stage name', str_contains($h, 'Growing'));
// The raw id legitimately appears in form action= URLs (route param). What must be
// redacted is the human-facing HERO line showing WHO this journey belongs to.
chk('member: member id redacted in hero line', str_contains($h, '…0ABC12'));
chk('member: transition form to /journey/members/.../transition', str_contains($h, 'action="/journey/members/user-0000000000ABC12/transition"'));
chk('member: current stage disabled in dropdown', str_contains($h, 'value="growing" disabled'));
chk('member: status form to .../status', str_contains($h, 'action="/journey/members/user-0000000000ABC12/status"'));
chk('member: timeline shows the open event', str_contains($h, lang('Journey.admin.member.tlOpened') !== 'Journey.admin.member.tlOpened' ? 'New Believer' : 'new_believer'));
chk('member: timeline shows advance move', str_contains($h, 'New Believer') && str_contains($h, 'Growing'));
chk('member: discipler id redacted in timeline', str_contains($h, '…0DISC9') && ! str_contains($h, 'user-0000000000DISC9'));
chk('member: move label translated', str_contains($h, lang('Journey.admin.member.moveBtn')));

// Discipler is an ENTITY REFERENCE → must be a picker (name → user_id) from the
// group roster, not a raw ID text box, when the roster is supplied.
$hd = $render([
    'user_id'  => 'user-0000000000ABC12',
    'group_id' => 'grp-5',
    'ladder'   => $ladder,
    'csrf'     => 'JM1',
    'journey'  => ['id' => 'j-1', 'stage_code' => 'growing', 'stage_phase' => 'build', 'status' => 'active',
        'stage_entered_at' => '2026-09-01 08:00:00', 'previous_stage' => 'new_believer', 'source' => 'manual'],
    'history'  => [],
    'disciplers' => [
        ['user_id' => 'user-0000000000DISC9', 'display_name' => 'Ama Owusu'],
        ['user_id' => 'user-0000000000DISC8', 'display_name' => 'Kofi Mensah'],
    ],
], 'fr');
chk('member(roster): discipler is a <select>', str_contains($hd, '<select id="discipler_id" name="discipler_id">'));
chk('member(roster): option value = user_id', str_contains($hd, 'value="user-0000000000DISC9"'));
chk('member(roster): option shows display name', str_contains($hd, 'Ama Owusu') && str_contains($hd, 'Kofi Mensah'));
chk('member(roster): includes a none/default option', str_contains($hd, lang('Journey.admin.member.disciplerNone')));
// Empty roster → graceful free-text fallback (maxlength-bounded).
$he = $render([
    'user_id'  => 'user-0000000000ABC12', 'group_id' => 'grp-5', 'ladder' => $ladder, 'csrf' => 'JM1',
    'journey'  => ['id' => 'j-1', 'stage_code' => 'growing', 'stage_phase' => 'build', 'status' => 'active',
        'stage_entered_at' => '2026-09-01 08:00:00', 'previous_stage' => 'new_believer', 'source' => 'manual'],
    'history'  => [], 'disciplers' => [],
], 'fr');
chk('member(no roster): discipler falls back to bounded text input', str_contains($he, 'name="discipler_id" maxlength="64"'));

// no journey → open form
$GLOBALS['__flash'] = ['success' => 'Parcours ouvert.'];
$hn = $render([
    'user_id'  => 'user-0000000000ABC12',
    'group_id' => 'grp-5',
    'ladder'   => $ladder,
    'csrf'     => 'JM1',
    'journey'  => null,
    'history'  => [],
], 'fr');
chk('member(no journey): shows no-journey empty state', str_contains($hn, lang('Journey.admin.member.noJourney')));
chk('member(no journey): open form present', str_contains($hn, 'action="/journey/members/user-0000000000ABC12/open"'));
chk('member(no journey): open form carries group context', str_contains($hn, 'name="group_id" value="grp-5"'));
chk('member(no journey): entry-stage option present', str_contains($hn, lang('Journey.admin.member.entryStageOption')));
chk('member(no journey): success flash rendered', str_contains($hn, 'Parcours ouvert.'));
$GLOBALS['__flash'] = [];

// no ladder → open disabled + hint
$hnl = $render(['user_id' => 'u1', 'group_id' => null, 'ladder' => [], 'csrf' => 'JM1', 'journey' => null, 'history' => []], 'fr');
chk('member(no ladder): open button disabled', str_contains($hnl, 'disabled'));
chk('member(no ladder): shows define-ladder hint', str_contains($hnl, lang('Journey.admin.member.noLadder')));

// ar RTL
$ha = $render(['user_id' => 'u1', 'group_id' => null, 'ladder' => $ladder, 'csrf' => 'JM1',
    'journey' => ['id' => 'j', 'stage_code' => 'growing', 'stage_phase' => 'build', 'status' => 'paused', 'stage_entered_at' => '2026-09-01', 'previous_stage' => '', 'source' => 'rule'],
    'history' => []], 'ar');
chk('member(ar): lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('member(ar): paused status label localized', str_contains($ha, lang('Journey.admin.member.status.paused')));
chk('member(ar): no-history empty state shown', str_contains($ha, lang('Journey.admin.member.noHistory')));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
