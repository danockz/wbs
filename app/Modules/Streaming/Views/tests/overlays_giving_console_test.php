<?php

declare(strict_types=1);

/**
 * OVERLAY/CO-HOST console + GIVING config write-UI test.
 *
 * The moderator overlay console and the organizer giving-config page used to
 * render read-only, so their management writes (invite/issue-token/remove co-host,
 * upsert overlay, set visibility; configure giving) fell through to the generic
 * data-page with no webcsrf. They are now bespoke write surfaces: each control
 * POSTs to a webcsrf-guarded route and PRGs back with a localized flash (JSON
 * preserved for API clients); overlay author + authorizing organizer identities
 * come from the session; the one-time co-host join token is surfaced via a single
 * one-shot flash and never re-rendered. This test asserts view controls, the
 * controllers' PRG + identity + JSON parity + token-once handling, route webcsrf
 * upgrades, i18n parity for the new keys, and a headless render smoke (+ ar RTL).
 *
 *   php app/Modules/Streaming/Views/tests/overlays_giving_console_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Streaming/Language';
$viewDir    = $root . '/app/Modules/Streaming/Views';
$ovCtrl     = $root . '/app/Modules/Streaming/Controllers/OverlayController.php';
$gvCtrl     = $root . '/app/Modules/Streaming/Controllers/StreamGivingController.php';
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
    return $o;
};

// ── 1. i18n parity for overlaysConsole.* and new givingConfig.* keys ──────────
echo "language parity (overlaysConsole.* + givingConfig additions)\n";
$en   = require $langDir . '/en/Streaming.php';
$enOC = $flatten($en['overlaysConsole']);
$enGC = $flatten($en['givingConfig']);
$needOC = ['inviteHeading', 'userIdLabel', 'roleLabel', 'inviteBtn', 'issueTokenBtn', 'removeBtn', 'removeConfirm',
    'tokenOnce', 'overlayHeading', 'overlayTypeLabel', 'overlayBtn', 'showBtn', 'hideBtn',
    'invitedFlash', 'tokenIssuedFlash', 'removedFlash', 'overlaySavedFlash', 'visibilityFlash'];
foreach ($needOC as $k) {
    chk("en overlaysConsole.$k present", in_array($k, $enOC, true));
}
foreach (['editHeading', 'savedFlash', 'causePh', 'saveBtn', 'suggestedPh', 'causeHint'] as $k) {
    chk("en givingConfig.$k present", in_array($k, $enGC, true));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Streaming.php";
    $foc = $flatten($l['overlaysConsole'] ?? []);
    $fgc = $flatten($l['givingConfig'] ?? []);
    chk("$loc mirrors all en overlaysConsole keys", array_diff($enOC, $foc) === [],
        'missing: ' . implode(',', array_diff($enOC, $foc)));
    chk("$loc no stray overlaysConsole keys", array_diff($foc, $enOC) === [],
        'extra: ' . implode(',', array_diff($foc, $enOC)));
    chk("$loc mirrors all en givingConfig keys", array_diff($enGC, $fgc) === [],
        'missing: ' . implode(',', array_diff($enGC, $fgc)));
}

// ── 2. overlays.php controls ─────────────────────────────────────────────────
echo "overlays.php exposes console controls\n";
$ov = (string) file_get_contents("$viewDir/overlays.php");
chk('invite form posts to /cohosts', str_contains($ov, "'streams/' . \$sidAttr . '/cohosts'"));
chk('issue-token form posts to /cohosts/token', str_contains($ov, "/cohosts/token"));
chk('remove form posts to /cohosts/…/remove', str_contains($ov, "/remove'"));
chk('remove asks confirm()', str_contains($ov, 'removeConfirm'));
chk('upsert-overlay form posts to /overlays', str_contains($ov, "'streams/' . \$sidAttr . '/overlays'"));
chk('overlay config uses config[...] fields', str_contains($ov, 'name="config[title]"') && str_contains($ov, 'name="config[cause_id]"'));
chk('visibility toggle posts to /overlays/…/visibility', str_contains($ov, "/visibility'"));
chk('visibility flips current value', str_contains($ov, "! empty(\$o['visible']) ? '0' : '1'"));
chk('one-time token flash surfaced', str_contains($ov, "session('cohost_token')") && str_contains($ov, 'tokenOnce'));
chk('never renders token hash/plaintext from data', ! str_contains($ov, 'join_token_hash') && ! str_contains($ov, "['join_token']"));
chk('forms carry _csrf bound to $csrf', substr_count($ov, 'name="_csrf" value="<?= esc($csrf') >= 4);
chk('renders PRG flash', str_contains($ov, "session('success')") && str_contains($ov, "session('error')"));

// ── 3. giving_config.php controls ────────────────────────────────────────────
echo "giving_config.php exposes an edit form\n";
$gv = (string) file_get_contents("$viewDir/giving_config.php");
chk('edit form posts to /giving/config', str_contains($gv, "'streams/' . \$sidAttr . '/giving/config'"));
chk('has enabled + cause + currency fields', str_contains($gv, 'name="enabled"') && str_contains($gv, 'name="cause_id"') && str_contains($gv, 'name="currency"'));
chk('has widget/progress/anonymous/ack toggles', str_contains($gv, 'name="widget_enabled"') && str_contains($gv, 'name="progress_bar_enabled"') && str_contains($gv, 'name="allow_anonymous"') && str_contains($gv, 'name="ack_enabled"'));
chk('has min/max + suggested amounts', str_contains($gv, 'name="min_amount_minor"') && str_contains($gv, 'name="max_amount_minor"') && str_contains($gv, 'name="suggested_amounts"'));
chk('checkboxes reflect current config ($chk)', str_contains($gv, '$chk('));
chk('edit form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $gv));
chk('renders PRG flash', str_contains($gv, "session('success')") && str_contains($gv, "session('error')"));

// ── 4. controllers: PRG + identity + JSON parity ─────────────────────────────
echo "controllers: PRG + session identity + JSON parity\n";
$oc = (string) file_get_contents($ovCtrl);
chk('has respondOverlayDecision PRG helper', str_contains($oc, 'private function respondOverlayDecision'));
chk('console passes csrf + streamId', (bool) preg_match('/function console\(.*?wbsCsrf/s', $oc) && str_contains($oc, "'streamId' => \$streamId"));
chk('invite PRGs with invitedFlash', (bool) preg_match('/function inviteCohost\(.*?invitedFlash/s', $oc));
chk('issueToken surfaces one-time token', str_contains($oc, "\$result->data['join_token']") && str_contains($oc, 'tokenIssuedFlash'));
chk('token passed as one-shot flash', str_contains($oc, "with('cohost_token', \$tokenOnce)"));
chk('remove honors URL id or field fallback', str_contains($oc, "\$this->field('user_id'"));
chk('upsert uses session actor', str_contains($oc, '$this->actorId()') && (bool) preg_match('/function upsertOverlay\(.*?overlaySavedFlash/s', $oc));
chk('setVisibility redirects using stream_id field', (bool) preg_match('/function setVisibility\(.*?stream_id/s', $oc));
chk('overlay PRG redirects to console', str_contains($oc, "/console'") && str_contains($oc, 'rawurlencode($streamId)'));
chk('overlay PRG keeps JSON', (bool) preg_match('/respondOverlayDecision.*?if \(\$this->wantsJson\(\)\)/s', $oc));

$gc = (string) file_get_contents($gvCtrl);
chk('configure uses session organizer (currentUserId)', str_contains($gc, "currentUserId('actor_id')"));
chk('configure PRGs with savedFlash to giving/config', str_contains($gc, "givingConfig.savedFlash") && str_contains($gc, "/giving/config"));
chk('configure normalizes suggested_amounts string->array', str_contains($gc, "preg_split") && str_contains($gc, "suggested_amounts"));
chk('configure keeps JSON for API', (bool) preg_match('/function configure\(.*?if \(\$this->wantsJson\(\)\)/s', $gc));
chk('showConfig passes csrf + streamId', (bool) preg_match('/function showConfig\(.*?wbsCsrf/s', $gc) && str_contains($gc, "'streamId' => \$streamId"));

// ── 5. routes webcsrf ────────────────────────────────────────────────────────
echo "routes: webcsrf-guarded console/giving writes\n";
$routes = (string) file_get_contents($routesFile);
foreach ([
    'OverlayController::inviteCohost/$1',
    'OverlayController::issueCohostToken/$1',
    'OverlayController::upsertOverlay/$1',
    'OverlayController::setVisibility/$1',
    'StreamGivingController::configure/$1',
] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
    } else {
        chk("$needle route present", false);
    }
}
// POST alias for remove (browser-form friendly) present + webcsrf; DELETE kept
chk('POST remove alias present + webcsrf', (bool) preg_match('#post\(.*cohosts/\(:segment\)/remove.*removeCohost.*webcsrf#', $routes));
chk('DELETE removeCohost still present', (bool) preg_match('#delete\(.*cohosts.*removeCohost#', $routes));

// ── 6. headless render smoke ─────────────────────────────────────────────────
echo "render smoke — overlays + giving (fr + ar)\n";
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Streaming') { return $key; }
        $v = $GLOBALS['__sLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return '/' . ltrim((string) $p, '/'); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__sFlash'][$k] ?? null; }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__sLang'] = require $langDir . "/$loc/Streaming.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data) {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__sFlash'] = [];
$console = [
    'stream'        => ['id' => 'st1', 'title' => 'Sunday', 'status' => 'live', 'access_policy' => 'public'],
    'cohosts'       => [
        ['user_id' => 'u1', 'role' => 'cohost', 'status' => 'invited', 'token_active' => false],
        ['user_id' => 'u2', 'role' => 'guest', 'status' => 'removed'],
    ],
    'overlays'      => [
        ['overlay_id' => 'o1', 'overlay_type' => 'lower_third', 'version' => 2, 'visible' => true, 'config' => ['title' => 'Welcome']],
        ['overlay_id' => 'o2', 'overlay_type' => 'quote_card', 'version' => 1, 'visible' => false, 'config' => ['quote' => 'Hi']],
    ],
    'overlay_types' => ['lower_third', 'cause_progress', 'quote_card'],
    'cohost_roles'  => ['host', 'cohost', 'guest'],
];
$oh = $render("$viewDir/overlays.php", ['result' => $console, 'streamId' => 'st1', 'csrf' => 'OC'], 'fr');
chk('ov smoke: invite form present', str_contains($oh, 'action="/streams/st1/cohosts"'));
chk('ov smoke: invited co-host has token + remove controls', str_contains($oh, 'action="/streams/st1/cohosts/token"') && str_contains($oh, 'action="/streams/st1/cohosts/u1/remove"'));
chk('ov smoke: removed co-host has NO controls', ! str_contains($oh, '/cohosts/u2/remove'));
chk('ov smoke: overlay upsert form present', str_contains($oh, 'action="/streams/st1/overlays"'));
chk('ov smoke: visible overlay offers Hide', str_contains($oh, 'action="/streams/overlays/o1/visibility"') && str_contains($oh, lang('Streaming.overlaysConsole.hideBtn')));
chk('ov smoke: hidden overlay offers Show', str_contains($oh, 'action="/streams/overlays/o2/visibility"') && str_contains($oh, lang('Streaming.overlaysConsole.showBtn')));
chk('ov smoke: visibility value flips (o1 visible -> 0)', str_contains($oh, 'name="visible" value="0"'));
chk('ov smoke: invite heading localized (fr)', str_contains($oh, 'Inviter un co-animateur'));
chk('ov smoke: csrf on forms', substr_count($oh, 'name="_csrf" value="OC"') >= 4);

// user_id on the invite-co-host form is an entity reference → roster-backed
// person picker when a roster is supplied (excluding existing co-hosts u1/u2),
// else bounded text (unchanged behaviour, as in $oh above).
echo "invite-cohost user_id entity-reference picker\n";
$ohp = $render("$viewDir/overlays.php", [
    'result'   => $console,
    'streamId' => 'st1',
    'csrf'     => 'OC',
    'roster'   => [
        ['id' => 'u1', 'display_name' => 'Existing Cohost'], // excluded (already a co-host)
        ['id' => 'u2', 'display_name' => 'Removed Guest'],   // excluded (already in list)
        ['id' => 'u9', 'display_name' => 'Yaa Asante'],      // offered
    ],
], 'fr');
chk('cohost picker: renders <select name="user_id"> required', str_contains($ohp, '<select name="user_id" required>'));
chk('cohost picker: offers a non-cohost', str_contains($ohp, 'value="u9"') && str_contains($ohp, 'Yaa Asante'));
chk('cohost picker: excludes existing co-hosts', ! preg_match('/<option value="u1"/', $ohp) && ! preg_match('/<option value="u2"/', $ohp));
chk('cohost picker: none option present', str_contains($ohp, lang('Streaming.overlaysConsole.userIdNone')));
chk('cohost picker: bounded text fallback when no roster', str_contains($oh, 'type="text" name="user_id" required maxlength="64"'));

// one-time token flash surfaced once
$GLOBALS['__sFlash'] = ['cohost_token' => 'SECRET-TOKEN-XYZ', 'success' => 'Jeton d’accès émis.'];
$oht = $render("$viewDir/overlays.php", ['result' => $console, 'streamId' => 'st1', 'csrf' => 'OC'], 'fr');
chk('ov smoke: one-time token shown in flash', str_contains($oht, 'SECRET-TOKEN-XYZ') && str_contains($oht, lang('Streaming.overlaysConsole.tokenOnce')));
$GLOBALS['__sFlash'] = [];

// ar RTL
$oha = $render("$viewDir/overlays.php", ['result' => $console, 'streamId' => 'st1', 'csrf' => 'OC'], 'ar');
chk('ov smoke(ar): invite button localized', str_contains($oha, lang('Streaming.overlaysConsole.inviteBtn')));

// giving config — enabled + disabled + edit form always present
$gEnabled = $render("$viewDir/giving_config.php", [
    'config' => ['stream_id' => 'st1', 'enabled' => true, 'cause_id' => 'c9', 'currency' => 'GHS',
        'widget_enabled' => true, 'progress_bar_enabled' => false, 'suggested_amounts' => [500, 1000]],
    'streamId' => 'st1', 'csrf' => 'GC',
], 'fr');
chk('gv smoke: edit form present when enabled', str_contains($gEnabled, 'action="/streams/st1/giving/config"'));
chk('gv smoke: enabled checkbox checked', (bool) preg_match('/name="enabled"[^>]*checked/', $gEnabled));
chk('gv smoke: progress toggle unchecked', ! preg_match('/name="progress_bar_enabled"[^>]*checked/', $gEnabled));
chk('gv smoke: cause + currency prefilled', str_contains($gEnabled, 'value="c9"') && str_contains($gEnabled, 'value="GHS"'));
// cause_id is an entity reference → when the active causes list is supplied it
// must render a name→id <select> (with the current value preselected), and fall
// back to a bounded text input only when no causes are available.
$gPick = $render("$viewDir/giving_config.php", [
    'config'   => ['stream_id' => 'st1', 'enabled' => true, 'cause_id' => 'c2', 'currency' => 'GHS'],
    'streamId' => 'st1', 'csrf' => 'GC',
    'causes'   => [['id' => 'c1', 'name' => 'Building Fund'], ['id' => 'c2', 'name' => 'Missions']],
], 'fr');
chk('gv picker: cause_id is a <select>', str_contains($gPick, '<select id="cause_id" name="cause_id">'));
chk('gv picker: options carry id + name', str_contains($gPick, 'value="c1"') && str_contains($gPick, 'Building Fund') && str_contains($gPick, 'Missions'));
chk('gv picker: current cause preselected', (bool) preg_match('/value="c2" selected/', $gPick));
chk('gv picker: none/default option present', str_contains($gPick, lang('Streaming.givingConfig.causeNone')));
// stale cause not in active list is preserved as a selected option
$gStale = $render("$viewDir/giving_config.php", [
    'config'   => ['stream_id' => 'st1', 'enabled' => true, 'cause_id' => 'archived-x'],
    'streamId' => 'st1', 'csrf' => 'GC',
    'causes'   => [['id' => 'c1', 'name' => 'Building Fund']],
], 'fr');
chk('gv picker: stale cause_id preserved as selected option', (bool) preg_match('/value="archived-x" selected/', $gStale));
// no causes supplied → graceful bounded free-text fallback
$gFallback = $render("$viewDir/giving_config.php", [
    'config'   => ['stream_id' => 'st1', 'enabled' => true, 'cause_id' => 'c9'],
    'streamId' => 'st1', 'csrf' => 'GC',
], 'fr');
chk('gv picker: falls back to bounded text input when no causes', str_contains($gFallback, 'type="text" id="cause_id" name="cause_id" maxlength="64"'));
chk('gv smoke: suggested amounts joined', str_contains($gEnabled, 'value="500, 1000"'));
chk('gv smoke: edit heading localized (fr)', str_contains($gEnabled, 'Modifier la configuration'));

$gDisabled = $render("$viewDir/giving_config.php", ['config' => ['stream_id' => 'st1', 'enabled' => false], 'streamId' => 'st1', 'csrf' => 'GC'], 'fr');
chk('gv smoke: disabled state shown', str_contains($gDisabled, lang('Streaming.givingConfig.disabled')));
chk('gv smoke: edit form still present when disabled', str_contains($gDisabled, 'action="/streams/st1/giving/config"'));

$GLOBALS['__sFlash'] = ['success' => 'Configuration des dons enregistrée.'];
$gFlash = $render("$viewDir/giving_config.php", ['config' => ['stream_id' => 'st1', 'enabled' => false], 'streamId' => 'st1', 'csrf' => 'GC'], 'fr');
chk('gv smoke: success flash rendered', str_contains($gFlash, 'Configuration des dons enregistrée.'));
$GLOBALS['__sFlash'] = [];

$gAr = $render("$viewDir/giving_config.php", ['config' => ['stream_id' => 'st1', 'enabled' => true, 'cause_id' => 'c'], 'streamId' => 'st1', 'csrf' => 'GC'], 'ar');
chk('gv smoke(ar): save button localized', str_contains($gAr, lang('Streaming.givingConfig.saveBtn')));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
