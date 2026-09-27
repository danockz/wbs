<?php

declare(strict_types=1);

/**
 * CONFIG CATALOG CRUD WORKFLOW wiring test (part 2) — proves the remaining four
 * gamification config admin views are no longer read-only lists: ACTIVITY CATEGORIES,
 * FOLLOW-UP TYPES, FOLLOW-UP METHODS (ConfigController + FollowUpsController) each now
 * render an inline "New" create form + per-row Edit (prefilled) and Disable controls,
 * and RUNTIME CONFIG renders create / edit / delete controls (respecting is_editable),
 * all posting to the webcsrf-guarded upsert/disable/delete routes; the controllers PRG
 * browser writes back to the list with a localized flash while keeping JSON for API
 * clients and passing the CSRF token. Plus i18n parity for the new form keys and a
 * headless render smoke (layout-bound harness).
 *
 *   php app/Modules/Gamification/Views/tests/config_catalog2_crud_test.php
 */

$root        = dirname(__DIR__, 5);
$langDir     = $root . '/app/Modules/Gamification/Language';
$viewDir     = $root . '/app/Modules/Gamification/Views';
$cfgCtrl     = $root . '/app/Modules/Gamification/Controllers/ConfigController.php';
$fuCtrl      = $root . '/app/Modules/Gamification/Controllers/FollowUpsController.php';
$routesFile  = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$commonForm = ['codeLabel', 'codePh', 'codeLocked', 'nameLabel', 'sortLabel',
    'saveNew', 'saveEdit', 'view', 'edit', 'disable', 'disableConfirm',
    'createdFlash', 'updatedFlash', 'disabledFlash'];
$extra = [
    'categories'      => ['newCategory', 'phaseLabel', 'colorLabel', 'descriptionLabel', 'iconLabel'],
    'followupTypes'   => ['newType', 'phaseLabel', 'nextDaysLabel', 'requiresOutcomeLabel', 'descriptionLabel', 'iconLabel'],
    'followupMethods' => ['newMethod', 'multiplierLabel'],
];

// ── 1. i18n parity for the new form.* keys ───────────────────────────────────
echo "language parity (categories / followupTypes / followupMethods form keys)\n";
foreach (['categories', 'followupTypes', 'followupMethods'] as $sec) {
    $enForm = (require $langDir . '/en/Gamification.php')['admin'][$sec]['form'];
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $form = (require $langDir . "/$loc/Gamification.php")['admin'][$sec]['form'] ?? [];
        foreach (array_merge($commonForm, $extra[$sec]) as $k) {
            chk("$loc $sec.form.$k present", isset($form[$k]) && $form[$k] !== '');
        }
        chk("$loc mirrors all en $sec.form keys", array_diff(array_keys($enForm), array_keys($form)) === [],
            'missing: ' . implode(',', array_diff(array_keys($enForm), array_keys($form))));
    }
}
echo "language parity (config form keys)\n";
$cfgKeys = ['newSetting', 'keyLabel', 'keyPh', 'typeLabel', 'valueLabel', 'descriptionLabel',
    'saveNew', 'saveEdit', 'edit', 'newHint', 'delete', 'deleteConfirm', 'readOnlyNote',
    'createdFlash', 'updatedFlash', 'deletedFlash'];
$enCfg = (require $langDir . '/en/Gamification.php')['admin']['config']['form'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $form = (require $langDir . "/$loc/Gamification.php")['admin']['config']['form'] ?? [];
    foreach ($cfgKeys as $k) {
        chk("$loc config.form.$k present", isset($form[$k]) && $form[$k] !== '');
    }
    chk("$loc mirrors all en config.form keys", array_diff(array_keys($enCfg), array_keys($form)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enCfg), array_keys($form))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
$views = [
    'activity_categories' => '/gamification/activity-categories',
    'followup_types'      => '/gamification/follow-up-types',
    'followup_methods'    => '/gamification/follow-up-methods',
];
foreach ($views as $file => $postPath) {
    echo "$file.php exposes create/edit/disable controls\n";
    $src = (string) file_get_contents("$viewDir/$file.php");
    chk("$file: create/edit form posts to $postPath", str_contains($src, 'action="' . $postPath . '"'));
    chk("$file: has a New (create) panel", str_contains($src, '$defineForm([], true)'));
    chk("$file: has a per-row Edit panel", str_contains($src, '$defineForm($'));
    chk("$file: has a Disable form", str_contains($src, '/disable"'));
    chk("$file: disable asks for confirm()", str_contains($src, 'disableConfirm'));
    chk("$file: forms carry _csrf bound to \$csrf", (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
    chk("$file: renders PRG flash messages", str_contains($src, "session('success')") && str_contains($src, "session('error')"));
    chk("$file: code locked on edit path", str_contains($src, 'codeLocked'));
    chk("$file: progressive-enhancement <details>", str_contains($src, '<details'));
}
echo "config_list.php exposes create/edit/delete controls (is_editable respected)\n";
$cfg = (string) file_get_contents("$viewDir/config_list.php");
chk('config: new-setting form posts to /gamification/config', str_contains($cfg, 'action="/gamification/config"'));
chk('config: edit form posts to /gamification/config/{key}', str_contains($cfg, 'action="/gamification/config/<?= esc($kc'));
chk('config: delete form posts to .../delete', str_contains($cfg, '/delete"'));
chk('config: delete asks for confirm()', str_contains($cfg, 'deleteConfirm'));
chk('config: gates controls on is_editable', str_contains($cfg, '$isEditable'));
chk('config: read-only note for locked keys', str_contains($cfg, 'readOnlyNote'));
chk('config: forms carry _csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $cfg));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controllers PRG + csrf\n";
$cc = (string) file_get_contents($cfgCtrl);
chk('ConfigController has respondConfigDecision', str_contains($cc, 'private function respondConfigDecision'));
chk('defineActivityCategory PRGs', (bool) preg_match('/function defineActivityCategory\(.*?respondConfigDecision/s', $cc));
chk('disableActivityCategory PRGs w/ disabledFlash', (bool) preg_match('/function disableActivityCategory\(.*?disabledFlash/s', $cc));
chk('setConfig PRGs to /gamification/config', (bool) preg_match('/function setConfig\(.*?respondConfigDecision.*?\/gamification\/config/s', $cc));
chk('deleteConfig PRGs w/ deletedFlash', (bool) preg_match('/function deleteConfig\(.*?deletedFlash/s', $cc));
chk('category + config lists pass csrf', substr_count($cc, "'csrf'") >= 2 && str_contains($cc, 'wbsCsrf'));

$fu = (string) file_get_contents($fuCtrl);
chk('FollowUpsController has respondConfigDecision', str_contains($fu, 'private function respondConfigDecision'));
chk('defineFollowUpType PRGs', (bool) preg_match('/function defineFollowUpType\(.*?respondConfigDecision/s', $fu));
chk('disableFollowUpType PRGs w/ disabledFlash', (bool) preg_match('/function disableFollowUpType\(.*?disabledFlash/s', $fu));
chk('defineFollowUpMethod PRGs', (bool) preg_match('/function defineFollowUpMethod\(.*?respondConfigDecision/s', $fu));
chk('disableFollowUpMethod PRGs w/ disabledFlash', (bool) preg_match('/function disableFollowUpMethod\(.*?disabledFlash/s', $fu));
chk('type + method lists pass csrf', substr_count($fu, "'csrf'") >= 2 && str_contains($fu, 'wbsCsrf'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "define/disable/delete routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
$routeChecks = [
    'defineActivityCategory'  => "post('activity-categories'",
    'disableActivityCategory' => 'disableActivityCategory/$1',
    'defineFollowUpType'      => "post('follow-up-types'",
    'disableFollowUpType'     => 'disableFollowUpType/$1',
    'defineFollowUpMethod'    => "post('follow-up-methods'",
    'disableFollowUpMethod'   => 'disableFollowUpMethod/$1',
    'setConfig'               => "post('config/(:segment)'",
    'deleteConfigPost'        => "post('config/(:segment)/delete'",
];
foreach ($routeChecks as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — each catalog (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Gamification') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir, $view) {
        extract($data);
        ob_start();
        include "$viewDir/$view.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = [];
$hc = $render('activity_categories', ['csrf' => 'AC', 'categories' => [
    ['code' => 'small_group', 'name' => 'Small Group', 'phase' => 'build', 'status' => 'active', 'sort_order' => 1],
]], 'fr');
chk('categories: create posts to /gamification/activity-categories', str_contains($hc, 'action="/gamification/activity-categories"'));
chk('categories: row edit prefilled name', str_contains($hc, 'value="Small Group"'));
chk('categories: phase select has send option', str_contains($hc, 'value="send"'));
chk('categories: disable form for the row', str_contains($hc, 'action="/gamification/activity-categories/small_group/disable"'));

$ht = $render('followup_types', ['csrf' => 'FT', 'types' => [
    ['code' => 'first_call', 'name' => 'First Call', 'phase' => 'build', 'requires_outcome' => 1, 'default_next_days' => 3, 'status' => 'active'],
]], 'fr');
chk('types: create posts to /gamification/follow-up-types', str_contains($ht, 'action="/gamification/follow-up-types"'));
chk('types: row edit prefilled name', str_contains($ht, 'value="First Call"'));
chk('types: requires_outcome checkbox checked', str_contains($ht, 'name="requires_outcome" value="1" checked'));
chk('types: disable form for the row', str_contains($ht, 'action="/gamification/follow-up-types/first_call/disable"'));

$hm = $render('followup_methods', ['csrf' => 'FM', 'methods' => [
    ['code' => 'home_visit', 'name' => 'Home Visit', 'multiplier_key' => 'visit', 'status' => 'active'],
]], 'fr');
chk('methods: create posts to /gamification/follow-up-methods', str_contains($hm, 'action="/gamification/follow-up-methods"'));
chk('methods: row edit prefilled name', str_contains($hm, 'value="Home Visit"'));
chk('methods: disable form for the row', str_contains($hm, 'action="/gamification/follow-up-methods/home_visit/disable"'));

echo "render smoke — config editable vs read-only (fr)\n";
$hcfg = $render('config_list', ['csrf' => 'CG', 'config' => [
    'season.autostart' => ['value' => true, 'type' => 'boolean', 'description' => 'Auto-start', 'is_editable' => true],
    'engine.version'   => ['value' => '9', 'type' => 'integer', 'description' => 'locked', 'is_editable' => false],
]], 'fr');
chk('config: editable key has edit form', str_contains($hcfg, 'action="/gamification/config/season.autostart"'));
chk('config: editable key has delete form', str_contains($hcfg, 'action="/gamification/config/season.autostart/delete"'));
chk('config: read-only key has NO edit form', ! str_contains($hcfg, 'action="/gamification/config/engine.version"'));
chk('config: read-only key shows note', str_contains($hcfg, lang('Gamification.admin.config.form.readOnlyNote')));
chk('config: new-setting panel present', str_contains($hcfg, 'action="/gamification/config"'));
chk('config: boolean value rendered as true', str_contains($hcfg, 'value="true"'));

$GLOBALS['__flash'] = ['success' => 'Enregistré.'];
$he = $render('followup_methods', ['csrf' => 'FM', 'methods' => []], 'fr');
chk('empty state + create panel shown', str_contains($he, lang('Gamification.admin.followupMethods.empty')) && str_contains($he, 'action="/gamification/follow-up-methods"'));
chk('success flash rendered', str_contains($he, 'Enregistré.'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
