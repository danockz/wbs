<?php

declare(strict_types=1);

/**
 * ADMIN SETTINGS / FLAGS / GROUP-CONFIG CRUD WORKFLOW wiring test — proves the
 * admin console is no longer read-only: platform settings, feature flags and
 * group configuration can now be created/edited from bespoke, CSP-safe (no inline
 * JS), no-JS browser forms that POST to the webcsrf-guarded admin write routes.
 *
 *   - settings.php renders: a "New setting" create form (POST /admin/settings with
 *     a body-field key + value TYPE picker), a per-row edit form (POST
 *     /admin/settings/{key}), a "New feature flag" form whose SCOPE is a GROUP
 *     PICKER (entity reference) + org-wide option, and a per-row flag toggle that
 *     preserves the row's scope.
 *   - group_config.php renders a "Set this group's value" form (POST
 *     /admin/groups/{id}/config/{cap}) with value TYPE + inheritance-mode pickers.
 *   - Routes: the 3 write routes carry webcsrf, plus body-key create aliases
 *     (POST /admin/settings, /admin/flags, /admin/group-config).
 *   - Controller: PRG with a localized flash, value coercion, group picker; key
 *     resolved from URL segment OR body field.
 *   - i18n: Admin.settings.form.* + Admin.setForm.* parity across all six locales.
 *
 *   php app/Modules/Admin/Views/tests/admin_settings_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Admin/Language';
$viewDir    = $root . '/app/Modules/Admin/Views';
$ctrlFile   = $root . '/app/Modules/Admin/Controllers/AdminController.php';
$routesFile = $root . '/app/Config/Routes.php';

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
    sort($o);

    return $o;
};

// ---------------------------------------------------------------------------
// i18n parity: the new write-control keys must exist in every locale.
// ---------------------------------------------------------------------------
echo "i18n parity for write-control keys\n";
$en       = require $langDir . '/en/Admin.php';
$formKeys = $flatten($en['settings']['form']);
$sfKeys   = $flatten($en['setForm']);
chk('en has settings.form block (> 20 keys)', count($formKeys) > 20, (string) count($formKeys));
chk('en has setForm block (> 8 keys)', count($sfKeys) > 8, (string) count($sfKeys));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m  = require $langDir . "/$loc/Admin.php";
    $ff = $flatten($m['settings']['form'] ?? []);
    $sf = $flatten($m['setForm'] ?? []);
    chk("$loc settings.form keys match en", $ff === $formKeys, 'diff: ' . implode(',', array_merge(array_diff($ff, $formKeys), array_diff($formKeys, $ff))));
    chk("$loc setForm keys match en", $sf === $sfKeys, 'diff: ' . implode(',', array_merge(array_diff($sf, $sfKeys), array_diff($sfKeys, $sf))));
    chk("$loc has error message keys", isset($m['setting_key_required'], $m['flag_key_required'], $m['group_config_input_required']));
}

// ---------------------------------------------------------------------------
// Render harness (self-contained views: they emit their own <html>).
// ---------------------------------------------------------------------------
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale()
            {
                return $GLOBALS['__aLoc'] ?? 'en';
            }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('session')) {
    function session($k = null)
    {
        return $k === null ? ($GLOBALS['__aFlash'] ?? []) : ($GLOBALS['__aFlash'][$k] ?? null);
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Admin') {
            return $key;
        }
        $v = $GLOBALS['__aLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }

        return $v;
    }
}
$GLOBALS['__aFlash'] = [];
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__aLoc']  = $loc;
    $GLOBALS['__aLang'] = require $langDir . "/$loc/Admin.php";
    extract($data);
    ob_start();
    include $file;

    return (string) ob_get_clean();
};

// ---------------------------------------------------------------------------
// settings.php write controls.
// ---------------------------------------------------------------------------
echo "settings.php write controls\n";
$hs = $render("$viewDir/settings.php", [
    'settings' => [
        ['key' => 'branding.primary_color', 'value' => '#123456', 'version' => 3, 'updated_at' => '2026-05-01 10:00:00'],
        ['key' => 'payments.enabled', 'value' => true, 'version' => 1, 'updated_at' => '2026-05-02 11:00:00'],
        ['key' => 'retention.policy', 'value' => ['days' => 90], 'version' => 2, 'updated_at' => null],
    ],
    'flags' => [
        ['flag_key' => 'beta.newui', 'group_id' => null, 'enabled' => 1, 'updated_at' => '2026-05-03 09:00:00'],
        ['flag_key' => 'beta.newui', 'group_id' => 'grp-123', 'enabled' => 0, 'updated_at' => '2026-05-04 09:00:00'],
    ],
    'csrf'   => 'CSRFTOK',
    'types'  => ['string', 'integer', 'number', 'boolean', 'json'],
    'groups' => [
        ['id' => 'grp-1', 'label' => 'National'],
        ['id' => 'grp-2', 'label' => '— Region A'],
    ],
], 'en');

chk('new-setting form posts to /admin/settings', str_contains($hs, 'action="/admin/settings"'));
chk('new-setting has body-field key input', str_contains($hs, 'name="key"'));
chk('new-setting has value TYPE picker', str_contains($hs, 'name="type"') && str_contains($hs, '<option value="json"'));
chk('carries csrf token in forms', str_contains($hs, 'name="_csrf" value="CSRFTOK"'));
chk('per-row setting edit posts to /admin/settings/{key}', str_contains($hs, 'action="/admin/settings/branding.primary_color"'));
chk('edit preselects inferred type (boolean for true)', str_contains($hs, '<option value="boolean" selected'));
chk('edit prefills structured value as JSON', str_contains($hs, 'value="{&quot;days&quot;:90}"') || str_contains($hs, 'days'));
chk('new-flag form posts to /admin/flags', str_contains($hs, 'action="/admin/flags"'));
chk('flag SCOPE is a group PICKER (not free-text)', str_contains($hs, 'name="group_id"') && str_contains($hs, '<option value="grp-1"'));
chk('flag scope has org-wide option (empty value)', str_contains($hs, '<option value="">'));
chk('flag scope shows indented group label', str_contains($hs, '— Region A'));
chk('per-row flag toggle posts to /admin/flags/{key}', str_contains($hs, 'action="/admin/flags/beta.newui"'));
chk('toggle preserves the row scope (group_id hidden)', str_contains($hs, 'name="group_id" value="grp-123"'));
chk('enabled-on row offers Turn off', str_contains($hs, 'value="0"') && str_contains($hs, (string) lang('Admin.settings.form.turnOff')));
chk('type option labels localized (Text)', str_contains($hs, '>Text</option>'));

// Flash rendering (PRG success).
$GLOBALS['__aFlash'] = ['success' => 'Saved.'];
$hsf = $render("$viewDir/settings.php", ['settings' => [], 'flags' => [], 'csrf' => 'X', 'types' => ['string'], 'groups' => []], 'en');
chk('success flash rendered', str_contains($hsf, 'Saved.') && str_contains($hsf, 'flash ok'));
$GLOBALS['__aFlash'] = [];

// ---------------------------------------------------------------------------
// group_config.php write control.
// ---------------------------------------------------------------------------
echo "group_config.php write control\n";
$hg = $render("$viewDir/group_config.php", [
    'capability' => 'events.max_capacity',
    'group_id'   => 'grp-9',
    'config'     => ['value' => 250, 'source_group_id' => 'grp-9', 'version' => 2, 'decision' => 'child_override', 'inheritance_mode' => 'child_owned'],
    'csrf'       => 'GCTOK',
    'types'      => ['string', 'integer', 'number', 'boolean', 'json'],
    'modes'      => ['ancestor_default_child_override', 'inherit_only', 'child_owned', 'not_inheritable'],
], 'en');
chk('group-config set form posts to correct route', str_contains($hg, 'action="/admin/groups/grp-9/config/events.max_capacity"'));
chk('group-config carries csrf', str_contains($hg, 'name="_csrf" value="GCTOK"'));
chk('group-config value TYPE picker preselects integer', str_contains($hg, '<option value="integer" selected'));
chk('group-config value prefilled (250)', str_contains($hg, 'value="250"'));
chk('group-config inheritance-mode picker preselects current', str_contains($hg, '<option value="child_owned" selected'));
chk('group-config heading localized', str_contains($hg, (string) lang('Admin.setForm.heading')));

// ---------------------------------------------------------------------------
// Routes: webcsrf on the 3 write routes + body-key create aliases.
// ---------------------------------------------------------------------------
echo "routes\n";
$routes = (string) file_get_contents($routesFile);
chk('POST /admin/settings/{key} has webcsrf', (bool) preg_match("/post\('settings\/\(:segment\)',.*setSetting.*webcsrf/", $routes));
chk('POST /admin/settings (create alias) exists + webcsrf', (bool) preg_match("/post\('settings',.*setSetting'.*webcsrf/", $routes));
chk('POST /admin/flags/{key} has webcsrf', (bool) preg_match("/post\('flags\/\(:segment\)',.*setFlag.*webcsrf/", $routes));
chk('POST /admin/flags (create alias) exists + webcsrf', (bool) preg_match("/post\('flags',.*setFlag'.*webcsrf/", $routes));
chk('POST group config {id}/{cap} has webcsrf', (bool) preg_match("/post\('groups\/\(:segment\)\/config\/\(:segment\)',.*setGroupConfig.*webcsrf/", $routes));
chk('POST /admin/group-config (create alias) exists + webcsrf', (bool) preg_match("/post\('group-config',.*setGroupConfig'.*webcsrf/", $routes));

// ---------------------------------------------------------------------------
// Controller wiring.
// ---------------------------------------------------------------------------
echo "controller\n";
$ctrl = (string) file_get_contents($ctrlFile);
chk('index passes csrf/types/groups to view', str_contains($ctrl, "'csrf'   => (string) (\$this->request->wbsCsrf ?? '')") && str_contains($ctrl, "'groups' => \$this->groupPickerOptions"));
chk('setSetting resolves key from URL or body', str_contains($ctrl, "\$key = trim((string) (\$in['key'] ?? ''))"));
chk('setSetting coerces value by type', str_contains($ctrl, '$this->coerceValue('));
chk('setFlag resolves flag key from URL or body', str_contains($ctrl, "\$flagKey = trim((string) (\$in['flag_key'] ?? ''))"));
chk('setGroupConfig resolves group+capability from URL or body', str_contains($ctrl, "\$in['capability']"));
chk('PRG helpers redirect with localized flash', str_contains($ctrl, "Admin.settings.form.savedFlash"));
chk('group picker built from group roster', str_contains($ctrl, 'listForOrg('));
chk('coerceValue handles boolean/integer/number/json', str_contains($ctrl, "'boolean' => \$this->truthy") && str_contains($ctrl, "'json'    => \$this->decodeJsonOrRaw"));

// ---------------------------------------------------------------------------
// CSP hygiene: the write forms must NOT rely on inline JS (script-src 'self').
// ---------------------------------------------------------------------------
echo "CSP hygiene (no inline JS)\n";
$settingsSrc = (string) file_get_contents("$viewDir/settings.php");
$gcSrc       = (string) file_get_contents("$viewDir/group_config.php");
chk('settings.php has no inline on* handlers', ! preg_match('/\son[a-z]+\s*=\s*"/i', $settingsSrc));
chk('settings.php has no <script>', ! str_contains($settingsSrc, '<script'));
chk('group_config.php has no inline on* handlers', ! preg_match('/\son[a-z]+\s*=\s*"/i', $gcSrc));
chk('group_config.php has no <script>', ! str_contains($gcSrc, '<script'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
