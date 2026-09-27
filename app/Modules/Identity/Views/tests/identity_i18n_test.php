<?php

declare(strict_types=1);

/**
 * Identity web-flow i18n test.
 *
 * The 7 Identity pages are SELF-CONTAINED (own <html>), so besides translating
 * copy they must also emit a dynamic <html lang dir> (incl. RTL). This test
 * asserts: every locale mirrors the English keys (no missing/stray, {0} kept);
 * views call lang('Identity.*') and dropped hardcoded English + lang="en"; and
 * renders login/mfa/me/sessions/set_password end-to-end in fr + ar (RTL).
 *
 *   php app/Modules/Identity/Views/tests/identity_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Identity/Language';
$viewDir = $root . '/app/Modules/Identity/Views';

// me.php references WBS\Shared\Support\Avatar for the fallback initials avatar;
// standalone tests don't autoload WBS\Shared, so load it explicitly.
require_once $root . '/app/Modules/Shared/Support/Avatar.php';

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

echo "language completeness\n";
$en     = require $langDir . '/en/Identity.php';
$enKeys = $flatten($en);
chk('en has login.heading', in_array('login.heading', $enKeys, true));
chk('en has assurance.high', in_array('assurance.high', $enKeys, true));
chk('en has merged service key auth_failed', in_array('auth_failed', $enKeys, true));
chk('en greeting uses {0}', str_contains((string) $en['me']['greeting'], '{0}'));
echo "windows-safe language filenames\n";
$langRoot = $root . '/app';
$collisions = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($langRoot, FilesystemIterator::SKIP_DOTS));
$byDir = [];
foreach ($it as $f) {
    if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.php')) {
        continue;
    }
    $path = str_replace('\\', '/', $f->getPath());
    if (! str_contains($path, '/Language/')) {
        continue;
    }
    $byDir[$path][] = $f->getFilename();
}
foreach ($byDir as $dir => $files) {
    $lower = array_map('strtolower', $files);
    if (count($lower) !== count(array_unique($lower))) {
        $collisions[] = $dir . ': ' . implode(',', $files);
    }
}
chk('no case-colliding Language filenames (Windows/NTFS)', $collisions === [], implode(' | ', $collisions));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Identity.php";
    if (! is_file($f)) {
        chk("$loc/Identity.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc greeting keeps {0}", str_contains((string) ($arr['me']['greeting'] ?? ''), '{0}'));
    chk("$loc introInviteNamed keeps {0}", str_contains((string) ($arr['setpw']['introInviteNamed'] ?? ''), '{0}'));
}

echo "views localized (no hardcoded English / lang=en)\n";
foreach (['login', 'mfa', 'set_password', 'set_password_done', 'set_password_invalid', 'me', 'sessions'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Identity.", str_contains($src, "lang('Identity."));
    chk("$v.php has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$v.php emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
    chk("$v.php includes _locale.php", str_contains($src, "_locale.php"));
}

echo "render smoke (fr + ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$curLocale = 'en';
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale()
            {
                return $GLOBALS['__idloc'] ?? 'en';
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
$GLOBALS['__idL'] = [];
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Identity') {
            return $key;
        }
        $v = $GLOBALS['__idL'];
        foreach ($p as $s) {
            if (! is_array($v) || ! array_key_exists($s, $v)) {
                return $key;
            }
            $v = $v[$s];
        }
        return $v;
    }
}
$renderView = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__idloc'] = $loc;
    $GLOBALS['__idL']   = require $langDir . "/$loc/Identity.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr login
$h = $renderView("$viewDir/login.php", ['csrf' => 'x', 'return' => '/me', 'error' => null], 'fr');
chk('fr login lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr login translated heading', str_contains($h, 'Connexion'));
chk('fr login translated email label', str_contains($h, 'E-mail'));

// ar login (RTL)
$h = $renderView("$viewDir/login.php", ['csrf' => 'x', 'return' => '/me', 'error' => null], 'ar');
chk('ar login lang=ar dir=rtl', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('ar login translated heading', str_contains($h, 'تسجيل الدخول'));

// fr me — greeting interpolation + assurance badge
$h = $renderView("$viewDir/me.php", ['user' => ['display_name' => 'Amélie'], 'mfa_level' => 'high', 'csrf' => 'x'], 'fr');
chk('fr me greeting interpolates name', str_contains($h, 'Amélie'));
chk('fr me assurance badge translated', str_contains($h, 'Fort (MFA)'));

// ar sessions — RTL + revoke label + risk score number
$h = $renderView("$viewDir/sessions.php", ['sessions' => [['id' => 's1', 'mfa_level' => 'low', 'risk_score' => 42, 'current' => false]], 'csrf' => 'x'], 'ar');
chk('ar sessions dir=rtl', str_contains($h, 'dir="rtl"'));
chk('ar sessions revoke translated', str_contains($h, 'إبطال'));
chk('ar sessions keeps numeric risk score', str_contains($h, '42'));

// fr mfa high vs low subtitle selection
$hi = $renderView("$viewDir/mfa.php", ['csrf' => 'x', 'return' => '/me', 'error' => null, 'required' => 'high'], 'fr');
$lo = $renderView("$viewDir/mfa.php", ['csrf' => 'x', 'return' => '/me', 'error' => null, 'required' => 'low'], 'fr');
chk('fr mfa high subtitle', str_contains($hi, 'à risque'));
chk('fr mfa low subtitle', str_contains($lo, 'application d’authentification'));

// es set_password named invite
$h = $renderView("$viewDir/set_password.php", ['csrf' => 'x', 'token' => 't', 'purpose' => 'invite', 'email' => 'a@b.com', 'name' => 'Diego', 'error' => null], 'es');
chk('es set_password named intro interpolates', str_contains($h, 'Diego'));
chk('es set_password activate button', str_contains($h, 'Activar cuenta'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
