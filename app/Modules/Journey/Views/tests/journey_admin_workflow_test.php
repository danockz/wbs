<?php

declare(strict_types=1);

/**
 * JOURNEY ADMIN WRITE-UI workflow wiring test — proves the two membership-journey
 * admin surfaces are no longer JSON-only:
 *   (1) the STAGE LADDER admin (journey/stages) now renders inline create / edit /
 *       deactivate controls posting to the webcsrf-guarded /journey/stages upsert;
 *   (2) the PROPOSAL REVIEW queue (journey/proposals) renders inline approve /
 *       reject forms posting to the webcsrf-guarded proposal routes (Option C
 *       maker-checker), redacting member ids.
 * The controller PRGs browser writes back to the list with a localized flash while
 * keeping JSON for API clients, passes the CSRF token, and carries the group-context
 * filter across the redirect. Plus i18n parity for the new keys and a headless render
 * smoke (self-contained page harness, mirrors journey_i18n_test).
 *
 *   php app/Modules/Journey/Views/tests/journey_admin_workflow_test.php
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
echo "language parity (admin.stages + admin.proposals)\n";
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};
$en    = require $langDir . '/en/Journey.php';
$enAdm = $flatten(['admin' => $en['admin']]);
$needStages = ['metaTitle', 'heading', 'newStage', 'codeLabel', 'codeLocked', 'phaseLabel', 'orderLabel',
    'isEntryLabel', 'isTerminalLabel', 'saveNew', 'saveEdit', 'edit', 'deactivate', 'deactivateConfirm',
    'createdFlash', 'updatedFlash', 'empty'];
$needProps = ['metaTitle', 'heading', 'approve', 'reject', 'disciplerLabel', 'noteLabel',
    'approveConfirm', 'rejectConfirm', 'approvedFlash', 'rejectedFlash', 'member', 'rule', 'empty'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Journey.php";
    foreach ($needStages as $k) {
        chk("$loc admin.stages.$k present", isset($l['admin']['stages'][$k]) && $l['admin']['stages'][$k] !== '');
    }
    foreach ($needProps as $k) {
        chk("$loc admin.proposals.$k present", isset($l['admin']['proposals'][$k]) && $l['admin']['proposals'][$k] !== '');
    }
    $keys = $flatten(['admin' => $l['admin']]);
    chk("$loc mirrors all en admin keys", array_diff($enAdm, $keys) === [], 'missing: ' . implode(',', array_diff($enAdm, $keys)));
    chk("$loc no stray admin keys", array_diff($keys, $enAdm) === [], 'extra: ' . implode(',', array_diff($keys, $enAdm)));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "stages_admin.php exposes create/edit/deactivate\n";
$st = (string) file_get_contents("$viewDir/stages_admin.php");
chk('stages: create/edit form posts to /journey/stages', str_contains($st, 'action="/journey/stages"'));
chk('stages: has a New (create) panel', str_contains($st, '$stageForm([], true)'));
chk('stages: has a per-row Edit panel', str_contains($st, '$stageForm($'));
chk('stages: deactivate posts status=inactive', str_contains($st, 'name="status" value="inactive"'));
chk('stages: deactivate asks for confirm()', str_contains($st, 'deactivateConfirm'));
chk('stages: carries group context on forms', str_contains($st, 'name="group_id" value="<?= esc($ctx'));
chk('stages: forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $st));
chk('stages: renders PRG flash messages', str_contains($st, "session('success')") && str_contains($st, "session('error')"));
chk('stages: code locked on edit path', str_contains($st, 'codeLocked'));
chk('stages: self-contained locale wiring', str_contains($st, '_locale.php') && str_contains($st, '_shell_open.php'));
chk('stages: progressive-enhancement <details>', str_contains($st, '<details'));

echo "proposals.php exposes approve/reject\n";
$pr = (string) file_get_contents("$viewDir/proposals.php");
chk('proposals: approve form to /journey/proposals/.../approve', str_contains($pr, '/approve"'));
chk('proposals: reject form to /journey/proposals/.../reject', str_contains($pr, '/reject"'));
chk('proposals: approve asks for confirm()', str_contains($pr, 'approveConfirm'));
chk('proposals: reject asks for confirm()', str_contains($pr, 'rejectConfirm'));
chk('proposals: approve carries optional discipler_id', str_contains($pr, 'name="discipler_id"'));
chk('proposals: reject carries optional note', str_contains($pr, 'name="note"'));
chk('proposals: forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $pr));
chk('proposals: renders PRG flash messages', str_contains($pr, "session('success')") && str_contains($pr, "session('error')"));
chk('proposals: redacts member id', str_contains($pr, 'redact_id('));
chk('proposals: guard-loads redactor helper', str_contains($pr, 'redactor_helper.php'));
chk('proposals: self-contained locale wiring', str_contains($pr, '_locale.php') && str_contains($pr, '_shell_open.php'));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondJourneyDecision PRG helper', str_contains($ctrl, 'private function respondJourneyDecision'));
chk('listStages renders stages_admin view + csrf', str_contains($ctrl, 'WBS\Journey\Views\stages_admin') && str_contains($ctrl, 'wbsCsrf'));
chk('listProposals renders proposals view + csrf', str_contains($ctrl, 'WBS\Journey\Views\proposals'));
chk('defineStage PRGs via respondJourneyDecision', (bool) preg_match('/function defineStage\(.*?respondJourneyDecision/s', $ctrl));
chk('defineStage scope-checked', (bool) preg_match('/function defineStage\(.*?authorizeGroupScope/s', $ctrl));
chk('approveProposal PRGs with approvedFlash', (bool) preg_match('/function approveProposal\(.*?approvedFlash/s', $ctrl));
chk('rejectProposal PRGs with rejectedFlash', (bool) preg_match('/function rejectProposal\(.*?rejectedFlash/s', $ctrl));
chk('PRG carries group context on redirect', str_contains($ctrl, "'?group_id=' . rawurlencode"));
chk('PRG created vs updated flash from 201', str_contains($ctrl, "\$result->status === 201 ? 'createdFlash' : 'updatedFlash'"));
chk('JSON kept for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "define/approve/reject routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
$routeChecks = [
    'defineStage'     => 'defineStage',
    'approveProposal' => 'approveProposal/$1',
    'rejectProposal'  => 'rejectProposal/$1',
];
foreach ($routeChecks as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label keeps authorize:gamification.manage,any", str_contains($m[0], 'authorize:gamification.manage,any'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 5. Headless render smoke (self-contained page harness) ────────────────────
echo "render smoke — stages + proposals (fr) + flash + empty\n";
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
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__jLoc']  = $loc;
    $GLOBALS['__jLang'] = require $langDir . "/$loc/Journey.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$GLOBALS['__flash'] = [];
$hs = $render("$viewDir/stages_admin.php", [
    'group_id' => null,
    'csrf'     => 'JS1',
    'stages'   => [
        ['code' => 'new_believer', 'name' => 'New Believer', 'phase' => 'win', 'sort_order' => 3, 'is_entry' => 1, 'status' => 'active'],
        ['code' => 'sender', 'name' => 'Sender', 'phase' => 'send', 'sort_order' => 8, 'is_terminal' => 1, 'status' => 'active'],
    ],
], 'fr');
chk('stages: lang=fr dir=ltr', str_contains($hs, 'lang="fr"') && str_contains($hs, 'dir="ltr"'));
chk('stages: create posts to /journey/stages', str_contains($hs, 'action="/journey/stages"'));
chk('stages: row edit prefilled name', str_contains($hs, 'value="New Believer"'));
chk('stages: locked code hidden field on edit', str_contains($hs, 'name="code" value="new_believer"'));
chk('stages: phase select has send option', str_contains($hs, 'value="send"'));
chk('stages: entry badge shown', str_contains($hs, lang('Journey.admin.stages.entryBadge')));
chk('stages: deactivate form posts inactive', str_contains($hs, 'name="status" value="inactive"'));
chk('stages: newStage label translated', str_contains($hs, lang('Journey.admin.stages.newStage')));

$GLOBALS['__flash'] = ['success' => 'Étape enregistrée.'];
$hse = $render("$viewDir/stages_admin.php", ['group_id' => 'grp-9', 'csrf' => 'JS1', 'stages' => []], 'fr');
chk('stages: group scope carried in create form', str_contains($hse, 'name="group_id" value="grp-9"'));
chk('stages: empty state shown', str_contains($hse, lang('Journey.admin.stages.empty')));
chk('stages: success flash rendered', str_contains($hse, 'Étape enregistrée.'));
$GLOBALS['__flash'] = [];

$hp = $render("$viewDir/proposals.php", [
    'group_id'  => null,
    'csrf'      => 'JP1',
    'proposals' => [
        ['id' => 'prop-1', 'user_id' => 'user-0000000000ABC12', 'from_stage' => 'new_believer', 'to_stage' => 'growing',
            'direction' => 'advance', 'rule_code' => 'foundation.done', 'signal_action' => 'journey.signal.course.completed',
            'reason' => 'Completed Foundation', 'project_code' => 'FS2026', 'created_at' => '2026-09-10 10:00:00'],
    ],
], 'fr');
chk('proposals: lang=fr dir=ltr', str_contains($hp, 'lang="fr"') && str_contains($hp, 'dir="ltr"'));
chk('proposals: approve posts to /journey/proposals/prop-1/approve', str_contains($hp, 'action="/journey/proposals/prop-1/approve"'));
chk('proposals: reject posts to /journey/proposals/prop-1/reject', str_contains($hp, 'action="/journey/proposals/prop-1/reject"'));
chk('proposals: shows from→to stages', str_contains($hp, 'new_believer') && str_contains($hp, 'growing'));
chk('proposals: member id redacted', str_contains($hp, '…0ABC12') && ! str_contains($hp, 'user-0000000000ABC12'));
chk('proposals: rule + project shown', str_contains($hp, 'foundation.done') && str_contains($hp, 'FS2026'));
chk('proposals: approve label translated', str_contains($hp, lang('Journey.admin.proposals.approve')));
chk('proposals: both forms carry csrf', substr_count($hp, 'value="JP1"') === 2);

$GLOBALS['__flash'] = ['error' => 'La proposition n’est plus en attente.'];
$hpe = $render("$viewDir/proposals.php", ['group_id' => null, 'csrf' => 'JP1', 'proposals' => []], 'fr');
chk('proposals: empty state shown', str_contains($hpe, lang('Journey.admin.proposals.empty')));
chk('proposals: error flash rendered', str_contains($hpe, 'La proposition'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
