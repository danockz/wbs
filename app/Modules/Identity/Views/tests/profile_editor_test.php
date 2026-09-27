<?php

declare(strict_types=1);

/**
 * MEMBER SELF-SERVICE PROFILE EDITOR wiring test — closes the section-C gap where
 * AccountService::updateProfile existed but was ORPHANED (no route, no page) and
 * the photo set/remove endpoints returned raw JSON with no browser page. Proves
 * /me now has a no-JS, CSP-safe, PRG profile editor:
 *
 *   - WebSessionController::profile (GET) renders the editor (JSON for API);
 *     updateProfile (POST) saves display name / language / time zone via the
 *     (now-wired) AccountService::updateProfile and PRGs back with a flash;
 *   - locale is validated against the supported-locale allowlist;
 *   - setPhoto/removePhoto now PRG back to /me/profile for browsers (JSON kept
 *     for API) via a shared profilePrg helper;
 *   - routes: GET/POST me/profile carry auth (+ webcsrf on the POST); the photo
 *     POSTs keep auth + webcsrf;
 *   - the view renders the details form + language <select> + read-only email +
 *     photo set/remove forms, all csrf-bound — CSP-clean;
 *   - i18n parity for the new Identity.profile.* block + me.profileEdit link
 *     across all 6 locales (covered by identity_i18n_test too; asserted here).
 *
 *   php app/Modules/Identity/Views/tests/profile_editor_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Identity/Language';
$viewDir    = $root . '/app/Modules/Identity/Views';
$controller = $root . '/app/Modules/Identity/Controllers/WebSessionController.php';
$service    = $root . '/app/Modules/Identity/Services/AccountService.php';
$routesFile = $root . '/app/Config/Routes.php';

require_once $root . '/app/Modules/Shared/Support/Avatar.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for Identity.profile.* + me.profileEdit ───────────────────
echo "language parity (Identity.profile.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en     = require $langDir . '/en/Identity.php';
$enKeys = $flat($en['profile'] ?? []);
chk('en profile block present (>= 20 keys)', count($enKeys) >= 20, (string) count($enKeys));
chk('en profile.langName covers 6 locales', count($en['profile']['langName'] ?? []) === 6);
chk('en me.profileEdit present', isset($en['me']['profileEdit']));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr  = require $langDir . "/$loc/Identity.php";
    $keys = $flat($arr['profile'] ?? []);
    chk("$loc mirrors profile keys", array_diff($enKeys, $keys) === [] && array_diff($keys, $enKeys) === [],
        'missing: ' . implode(',', array_diff($enKeys, $keys)) . ' extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc has me.profileEdit", isset($arr['me']['profileEdit']));
}

// ── 2. Service: updateProfile is now wired (still resource-light) ────────────
echo "service: updateProfile\n";
$svc = (string) file_get_contents($service);
chk('updateProfile() present', str_contains($svc, 'function updateProfile'));
chk('updateProfile writes display_name/locale/timezone', str_contains($svc, "'display_name'") && str_contains($svc, "'locale'") && str_contains($svc, "'timezone'"));

// ── 3. Controller: profile(GET) + updateProfile(POST) + PRG photo ───────────
echo "controller: profile/updateProfile + PRG\n";
$ctrl = (string) file_get_contents($controller);
chk('profile() renders the profile view', (bool) preg_match('/function profile\(.*?Views\\\\\\\\profile/s', $ctrl));
chk('profile() keeps JSON for API', (bool) preg_match('/function profile\(.*?wantsJson\(\)/s', $ctrl));
chk('profile() passes supported locales', (bool) preg_match('/function profile\(.*?Locale::class.*?supported/s', $ctrl));
chk('updateProfile() calls AccountService::updateProfile', (bool) preg_match('/function updateProfile\(.*?accounts\(\)->updateProfile\(/s', $ctrl));
chk('updateProfile() validates locale against allowlist', (bool) preg_match('/function updateProfile\(.*?in_array\(\$locale, \$locales, true\)/s', $ctrl));
chk('updateProfile() PRGs via profilePrg', (bool) preg_match('/function updateProfile\(.*?profilePrg\(/s', $ctrl));
chk('setPhoto() now PRGs via profilePrg', (bool) preg_match('/function setPhoto\(.*?profilePrg\(/s', $ctrl));
chk('removePhoto() now PRGs via profilePrg', (bool) preg_match('/function removePhoto\(.*?profilePrg\(/s', $ctrl));
chk('profilePrg keeps JSON for API + redirects to /me/profile', (bool) preg_match("/function profilePrg.*?wantsJson\(\).*?'\/me\/profile'/s", $ctrl));

// ── 4. Routes: me/profile GET+POST guarded; photo POSTs still guarded ────────
echo "routes: me/profile + photo guards\n";
$routes = (string) file_get_contents($routesFile);
chk('GET me/profile (auth)', (bool) preg_match("#get\\('me/profile'.*?WebSessionController::profile.*?'auth'#", $routes));
if (preg_match("#post\\('me/profile'.*?updateProfile.*#", $routes, $m)) {
    chk('POST me/profile present', true);
    chk('POST me/profile has auth', str_contains($m[0], "'auth'"));
    chk('POST me/profile has webcsrf', str_contains($m[0], 'webcsrf'));
} else {
    chk('POST me/profile present', false);
}
chk('POST me/photo still auth+webcsrf', (bool) preg_match("#post\\('me/photo'.*?setPhoto.*?'auth'.*?webcsrf#s", $routes));
chk('POST me/photo/remove still auth+webcsrf', (bool) preg_match("#post\\('me/photo/remove'.*?removePhoto.*?'auth'.*?webcsrf#s", $routes));

// ── 5. View controls: CSP-clean, csrf-bound, no-JS ───────────────────────────
echo "profile.php view controls\n";
$src = (string) file_get_contents("$viewDir/profile.php");
chk('details form posts to /me/profile', str_contains($src, 'action="/me/profile"'));
chk('photo form posts to /me/photo', str_contains($src, 'action="/me/photo"'));
chk('remove form posts to /me/photo/remove', str_contains($src, 'action="/me/photo/remove"'));
chk('language select present', str_contains($src, 'name="locale"'));
chk('email rendered read-only', str_contains($src, 'id="p-email"') && (bool) preg_match('/id="p-email".*?readonly/s', $src));
chk('phone rendered read-only (VERIFICATION field)', str_contains($src, 'id="p-phone"') && (bool) preg_match('/id="p-phone".*?readonly/s', $src));
chk('date of birth rendered read-only (REGISTRATION field)', str_contains($src, 'id="p-dob"') && (bool) preg_match('/id="p-dob".*?readonly/s', $src));
chk('country rendered read-only (REGISTRATION field)', str_contains($src, 'id="p-country"') && (bool) preg_match('/id="p-country".*?readonly/s', $src));
chk('read-only identity explanation shown', str_contains($src, 'identityReadonlyNote'));
chk('all forms carry _csrf bound to token', substr_count($src, 'name="_csrf" value="<?= esc($csrf') >= 3);
chk('renders PRG flash', str_contains($src, "getFlashdata('success')") && str_contains($src, "getFlashdata('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input)\s*=/i', $noC));

// ── 6. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — with photo, without photo, locale preselect (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Identity') { return $key; }
        $v = $GLOBALS['__idLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$GLOBALS['__idLang'] = require $langDir . '/fr/Identity.php';
$render = static function (array $data) use ($viewDir): string {
    extract($data);
    ob_start();
    include "$viewDir/profile.php";
    return (string) ob_get_clean();
};
$base = [
    'avatarSrc' => 'https://img.example/a.jpg',
    'locales'   => ['en', 'fr', 'es', 'pt', 'zh', 'ar'],
    'csrf'      => 'TKN',
];

// with a photo -> remove form present, current locale preselected
$h = $render($base + ['user' => ['id' => 'u1', 'display_name' => 'Ama', 'email' => 'a@x.org', 'locale' => 'fr', 'timezone' => 'Africa/Accra', 'profile_photo_url' => 'https://img.example/a.jpg']]);
chk('with photo: remove form present', str_contains($h, 'action="/me/photo/remove"'));
chk('with photo: fr option preselected', (bool) preg_match('/value="fr" selected/', $h));
chk('with photo: display name populated', str_contains($h, 'value="Ama"'));
chk('with photo: timezone populated', str_contains($h, 'value="Africa/Accra"'));
chk('with photo: csrf bound', str_contains($h, 'value="TKN"'));

// without a photo -> no remove form
$h = $render($base + ['user' => ['id' => 'u1', 'display_name' => 'Kojo', 'email' => 'k@x.org', 'locale' => 'en', 'timezone' => 'UTC', 'profile_photo_url' => null]]);
chk('no photo: no remove form', ! str_contains($h, 'action="/me/photo/remove"'));
chk('no photo: still has details + photo forms', str_contains($h, 'action="/me/profile"') && str_contains($h, 'action="/me/photo"'));
chk('render: no untranslated Identity.profile keys leaked', ! str_contains($h, 'Identity.profile.'));

// ── 7. JSON payload + write-path lock (VERIFICATION fields read-only) ────────
echo "JSON payload + updateProfile lock\n";
$ctrlSrc = (string) file_get_contents($controller);
preg_match('/public function profile\(\)(.*?)public function /s', $ctrlSrc, $pm);
$profBody = (string) ($pm[1] ?? '');
chk('profile() method body found', $profBody !== '');
chk("profile() JSON exposes phone", str_contains($profBody, "'phone'"));
chk("profile() JSON exposes date_of_birth", str_contains($profBody, "'date_of_birth'"));
chk("profile() JSON exposes country_code", str_contains($profBody, "'country_code'"));
$svcSrc = (string) file_get_contents($service);
preg_match('/public function updateProfile\((.*?)public function /s', $svcSrc, $um);
$updBody = (string) ($um[1] ?? '');
chk('updateProfile method body found', $updBody !== '');
chk('updateProfile does NOT write phone/dob/country', ! str_contains($updBody, "'phone'") && ! str_contains($updBody, "'date_of_birth'") && ! str_contains($updBody, "'country_code'"));

echo "\n== {$pass} passed, {$fail} failed ==\n";
