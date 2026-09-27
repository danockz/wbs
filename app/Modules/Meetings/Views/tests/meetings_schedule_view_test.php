<?php

declare(strict_types=1);

/**
 * Meetings schedule-view i18n + render smoke. Asserts Meetings.* key parity
 * across locales, that the SELF-CONTAINED schedule view references
 * lang('Meetings.*'), includes _locale.php, emits a dynamic <html lang dir>, and
 * renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback status/provider/mode/access, PHP singular/plural count,
 * verbatim data, join link, and the no-link / no-time fallbacks.
 *
 *   php app/Modules/Meetings/Views/tests/meetings_schedule_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Meetings/Language';
$viewDir = $root . '/app/Modules/Meetings/Views';

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

echo "language file completeness\n";
$en     = require $langDir . '/en/Meetings.php';
$enKeys = $flatten($en);
chk('en has nested status.live', in_array('status.live', $enKeys, true));
chk('en has nested provider.zoom', in_array('provider.zoom', $enKeys, true));
chk('en has nested access.restricted', in_array('access.restricted', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/Meetings.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/schedule.php");
chk("schedule.php calls lang('Meetings.", str_contains($src, "lang('Meetings."));
chk('schedule.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('schedule.php includes _locale.php', str_contains($src, '_locale.php'));
chk('schedule.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Meeting schedule', 'No meetings scheduled', 'Scheduled</'] as $needle) {
    chk("schedule.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar)\n";
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
            function getLocale() { return $GLOBALS['__mLoc'] ?? 'en'; }
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
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Meetings') {
            return $key;
        }
        $v = $GLOBALS['__mLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__mLoc']  = $loc;
    $GLOBALS['__mLang'] = require $langDir . "/$loc/Meetings.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$h = $render("$viewDir/schedule.php", [
    'meetings' => [
        ['title' => 'Leadership Sync', 'mode' => 'meeting', 'provider' => 'zoom', 'status' => 'live', 'access_policy' => 'restricted', 'join_url' => 'https://zoom.us/j/123', 'starts_at' => '2026-09-11 14:00:00', 'ends_at' => '2026-09-11 15:00:00'],
        ['title' => 'Public Webinar', 'mode' => 'webinar', 'provider' => 'weirdprov', 'status' => 'weird', 'access_policy' => 'public', 'join_url' => '', 'starts_at' => null, 'ends_at' => null],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Calendrier des réunions'));
chk('fr plural count interpolated', str_contains($h, '2 réunions'));
chk('fr status live translated', str_contains($h, 'En direct'));
chk('fr provider zoom verbatim', str_contains($h, 'Zoom'));
chk('fr mode webinar translated', str_contains($h, 'Webinaire'));
chk('fr access restricted translated', str_contains($h, 'Restreint'));
chk('fr access public translated', str_contains($h, 'Public'));
chk('fr unknown status falls back (Weird)', str_contains($h, 'Weird'));
chk('fr unknown provider falls back (Weirdprov)', str_contains($h, 'Weirdprov'));
chk('fr title verbatim', str_contains($h, 'Leadership Sync'));
chk('fr join link rendered', str_contains($h, 'href="https://zoom.us/j/123"') && str_contains($h, 'Lien de connexion'));
chk('fr time window formatted', str_contains($h, '2026-09-11 14:00') && str_contains($h, 'UTC'));
chk('fr no-link fallback', str_contains($h, 'Aucun lien'));
chk('fr no-time fallback', str_contains($h, 'Heure non définie'));

$h1 = $render("$viewDir/schedule.php", ['meetings' => [['title' => 'Solo', 'mode' => 'meeting', 'provider' => 'meet', 'status' => 'scheduled', 'access_policy' => 'public', 'join_url' => 'https://x', 'starts_at' => '2026-09-11 09:00:00', 'ends_at' => null]]], 'fr');
chk('fr singular count', str_contains($h1, '1 réunion') && ! str_contains($h1, '1 réunions'));

$ha = $render("$viewDir/schedule.php", ['meetings' => [], 'csrf' => 'MT1'], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'جدول الاجتماعات'));
chk('ar empty translated', str_contains($ha, 'لا توجد اجتماعات مجدولة بعد.'));

// ── write-UI: forms, csrf, PRG, routes ──────────────────────────────────────
echo "write-UI: forms + csrf + PRG + routes\n";
$hw = $render("$viewDir/schedule.php", [
    'csrf'     => 'MTCSRF',
    'meetings' => [
        ['id' => 'mtg-abc', 'title' => 'Leadership Sync', 'mode' => 'meeting', 'provider' => 'zoom',
            'status' => 'scheduled', 'access_policy' => 'restricted', 'join_url' => 'https://zoom.us/j/1',
            'starts_at' => '2026-09-11 14:00:00', 'ends_at' => null],
    ],
], 'fr');
chk('create form posts to /meetings', str_contains($hw, 'action="/meetings"') && str_contains($hw, 'method="post"'));
chk('create form has title + provider + access fields', str_contains($hw, 'name="title"') && str_contains($hw, 'name="provider"') && str_contains($hw, 'name="access_policy"'));
chk('create form carries _csrf bound to $csrf', str_contains($hw, 'name="_csrf" value="MTCSRF"'));
chk('status form posts to /meetings/{id}/transition', str_contains($hw, 'action="/meetings/mtg-abc/transition"'));
chk('status form current status pre-selected', str_contains($hw, 'value="scheduled" selected'));
chk('grant form posts to /meetings/{id}/grant', str_contains($hw, 'action="/meetings/mtg-abc/grant"'));
chk('grant form has user_id + role', str_contains($hw, 'name="user_id"') && str_contains($hw, 'name="role"'));
chk('every write form carries a csrf token', substr_count($hw, 'name="_csrf" value="MTCSRF"') >= 3);
chk('schedule button localized (fr)', str_contains($hw, 'Planifier la réunion'));
chk('grant button localized (fr)', str_contains($hw, 'Accorder l’accès'));

// user_id on the per-meeting grant form is an entity reference → roster-backed
// person picker when a roster is supplied, else bounded text (unchanged).
echo "grant user_id entity-reference picker\n";
$hp = $render("$viewDir/schedule.php", [
    'csrf'     => 'MTCSRF',
    'meetings' => [
        ['id' => 'mtg-abc', 'title' => 'Leadership Sync', 'mode' => 'meeting', 'provider' => 'zoom',
            'status' => 'scheduled', 'access_policy' => 'restricted', 'join_url' => 'https://zoom.us/j/1',
            'starts_at' => '2026-09-11 14:00:00', 'ends_at' => null],
    ],
    'roster'   => [
        ['id' => 'u-1', 'display_name' => 'Ama Owusu'],
        ['id' => 'u-2', 'display_name' => 'Kofi Mensah'],
    ],
], 'fr');
chk('grant picker: renders <select name="user_id"> required', str_contains($hp, '<select name="user_id" required'));
chk('grant picker: option value = user id + name', str_contains($hp, 'value="u-1"') && str_contains($hp, 'Ama Owusu'));
chk('grant picker: none option present', str_contains($hp, lang('Meetings.admin.userIdNone')));
// no roster → bounded text fallback (unchanged behaviour)
chk('grant picker: bounded text fallback when no roster', str_contains($hw, 'name="user_id" required maxlength="64"'));

// flash rendering
$hf = $render("$viewDir/schedule.php", ['meetings' => [], 'csrf' => 'MT1', '__unused' => 1], 'fr');
$GLOBALS['__flashProbe'] = true;
// simulate a flash by defining session() to return a message for this render
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__mFlash'][$k] ?? null; }
}
$GLOBALS['__mFlash'] = ['success' => 'Réunion planifiée.'];
$hs = $render("$viewDir/schedule.php", ['meetings' => [], 'csrf' => 'MT1'], 'fr');
chk('success flash rendered when present', str_contains($hs, 'Réunion planifiée.') && str_contains($hs, 'flash ok'));
$GLOBALS['__mFlash'] = ['error' => 'Fournisseur invalide.'];
$he = $render("$viewDir/schedule.php", ['meetings' => [], 'csrf' => 'MT1'], 'fr');
chk('error flash rendered when present', str_contains($he, 'Fournisseur invalide.') && str_contains($he, 'flash err'));
$GLOBALS['__mFlash'] = [];

echo "view source: write-UI wiring\n";
chk('view guards missing csrf (default \'\')', str_contains($src, "\$csrf     = \$csrf ?? ''"));
chk('view reads flashes via session()', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('view rawurlencodes meeting id in actions', str_contains($src, 'rawurlencode($mId)'));

echo "controller: PRG + no-JSON-to-browser\n";
$ctrl = (string) file_get_contents("$root/app/Modules/Meetings/Controllers/MeetingController.php");
chk('has respondMeetingDecision PRG helper', str_contains($ctrl, 'private function respondMeetingDecision'));
chk('PRG keeps JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, 'respondJson'));
chk('PRG redirects browser back to /meetings', str_contains($ctrl, "redirect()->to('/meetings')"));
chk('create flashes createdFlash', str_contains($ctrl, "'createdFlash'"));
chk('transition flashes transitionedFlash', str_contains($ctrl, "'transitionedFlash'"));
chk('grant flashes grantedFlash', str_contains($ctrl, "'grantedFlash'"));
chk('one-time grant token NOT flashed to browser', ! str_contains($ctrl, 'join_token'));
chk('index passes csrf to the view', str_contains($ctrl, "'csrf'     => (string) (\$this->request->wbsCsrf"));

echo "routes: webcsrf-guarded writes\n";
$routes = (string) file_get_contents("$root/app/Config/Routes.php");
foreach (['MeetingController::create', 'MeetingController::grant/$1', 'MeetingController::transition/$1'] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
        chk("$needle still authorize:meeting.manage", str_contains($mm[0], 'authorize:meeting.manage'));
    } else {
        chk("$needle route present", false);
    }
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
