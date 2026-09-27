<?php

declare(strict_types=1);

/**
 * Community i18n test — asserts every locale's Community.php mirrors the English
 * keys (incl. the nested visibility enum group), that the feed view references
 * lang('Community.*') rather than hardcoded English, and that it renders
 * translated strings with graceful fallback for unknown visibility values,
 * keeps free-form post data verbatim (author id, title, sanitized body_html),
 * and preserves numeric reaction/comment counts.
 *
 * The view extends the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation.
 *
 *   php app/Modules/Community/Views/tests/community_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Community/Language';
$viewDir = $root . '/app/Modules/Community/Views';

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
$en     = require $langDir . '/en/Community.php';
$enKeys = $flatten($en);
chk('en has nested visibility.group', in_array('visibility.group', $enKeys, true));
chk('en has post + posts plural keys', in_array('post', $enKeys, true) && in_array('posts', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Community.php";
    if (! is_file($f)) {
        chk("$loc/Community.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view uses lang(), not hardcoded English\n";
$src = (string) file_get_contents("$viewDir/feed.php");
chk("feed.php calls lang('Community.", str_contains($src, "lang('Community."));
foreach (['<h1>Community feed</h1>', 'No posts to show yet.', 'visible to you'] as $needle) {
    chk("feed.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback & verbatim data\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Community') {
            return $key;
        }
        $v = $GLOBALS['__coLang'];
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
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__coLang'] = require $langDir . "/$loc/Community.php";
    $renderer            = new class {
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

// fr — heading, plural, visibility label + unknown fallback, verbatim data, counts
$h = $render("$viewDir/feed.php", [
    'result' => ['posts' => [
        ['author_id' => 'user-42', 'created_at' => '2026-10-09', 'title' => 'Praise Report', 'body_html' => '<b>Hello</b>', 'visibility' => 'group', 'reaction_count' => 5, 'comment_count' => 2, 'pinned' => true],
        ['author_id' => 'user-7', 'created_at' => '2026-10-08', 'body_html' => 'plain', 'visibility' => 'weird_vis', 'reaction_count' => 0, 'comment_count' => 0],
    ]],
], 'fr');
chk('fr heading translated', str_contains($h, '<h1>Fil de la communauté</h1>'));
chk('fr posts plural + visibleToYou translated', str_contains($h, '2 publications') && str_contains($h, 'visibles pour vous'));
chk('fr visibility group -> groupe', str_contains($h, '>groupe<'));
chk('fr unknown visibility falls back', str_contains($h, 'weird_vis'));
chk('fr pinned translated', str_contains($h, 'épinglé'));
chk('fr author id verbatim', str_contains($h, 'user-42'));
chk('fr title verbatim', str_contains($h, 'Praise Report'));
chk('fr body_html not re-escaped', str_contains($h, '<b>Hello</b>'));
chk('fr counts preserved', str_contains($h, '♥ 5') && str_contains($h, '💬 2'));

// fr singular
$h1 = $render("$viewDir/feed.php", [
    'result' => ['posts' => [['author_id' => 'u1', 'body_html' => 'x', 'visibility' => 'public']]],
], 'fr');
chk('fr post singular', (bool) preg_match('/1 publication\b/u', $h1) && ! str_contains($h1, '1 publications'));

// ar — RTL strings, empty state, member fallback
$h = $render("$viewDir/feed.php", ['result' => ['posts' => []]], 'ar');
chk('ar heading translated', str_contains($h, 'موجز المجتمع'));
chk('ar empty translated', str_contains($h, 'لا توجد منشورات لعرضها بعد'));

$h2 = $render("$viewDir/feed.php", [
    'result' => ['posts' => [['body_html' => 'x', 'visibility' => 'private']]],
], 'ar');
chk('ar member fallback translated', str_contains($h2, 'عضو'));
chk('ar visibility private translated', str_contains($h2, 'خاص'));

// ── write-UI: compose/react/comment/report/moderate ─────────────────────────
echo "write-UI: forms + csrf + gating\n";
$authedMod = $render("$viewDir/feed.php", [
    'csrf'        => 'COCSRF',
    'canModerate' => true,
    'isAuthed'    => true,
    'result'      => ['posts' => [
        ['id' => 'post-1', 'author_id' => 'u1', 'created_at' => '2026-10-09', 'body_html' => 'hi', 'visibility' => 'group', 'reaction_count' => 1, 'comment_count' => 0],
    ]],
], 'fr');
chk('compose form posts to /community/posts', str_contains($authedMod, 'action="/community/posts"'));
chk('compose has body + visibility', str_contains($authedMod, 'name="body"') && str_contains($authedMod, 'name="visibility"'));
chk('react form posts to /reactions', str_contains($authedMod, 'action="/community/posts/post-1/reactions"'));
chk('comment form posts to /comments', str_contains($authedMod, 'action="/community/posts/post-1/comments"'));
chk('report form posts to /community/reports', str_contains($authedMod, 'action="/community/reports"'));
chk('report carries subject_type=post + subject_id', str_contains($authedMod, 'name="subject_type" value="post"') && str_contains($authedMod, 'name="subject_id" value="post-1"'));
chk('moderator control shown for moderator', str_contains($authedMod, 'action="/community/moderate"'));
chk('moderator action select present', str_contains($authedMod, 'name="action"') && str_contains($authedMod, '>Masquer<'));
chk('every write form carries _csrf bound to $csrf', substr_count($authedMod, 'name="_csrf" value="COCSRF"') >= 4);
chk('report/moderate reason localized (fr Spam/Harcèlement)', str_contains($authedMod, 'Harcèlement'));
chk('post button localized (fr)', str_contains($authedMod, '>Publier<'));

// authed non-moderator: member affordances but NO moderator control
$authedOnly = $render("$viewDir/feed.php", [
    'csrf'        => 'COCSRF',
    'canModerate' => false,
    'isAuthed'    => true,
    'result'      => ['posts' => [['id' => 'post-2', 'author_id' => 'u2', 'body_html' => 'hey', 'visibility' => 'group']]],
], 'fr');
chk('member sees compose + react + report', str_contains($authedOnly, 'action="/community/posts"') && str_contains($authedOnly, '/reactions"') && str_contains($authedOnly, '/community/reports"'));
chk('member does NOT see moderator control', ! str_contains($authedOnly, 'action="/community/moderate"'));

// anonymous: read-only, no write forms at all
$anon = $render("$viewDir/feed.php", [
    'csrf'        => '',
    'canModerate' => false,
    'isAuthed'    => false,
    'result'      => ['posts' => [['id' => 'post-3', 'author_id' => 'u3', 'body_html' => 'yo', 'visibility' => 'public']]],
], 'fr');
chk('anon sees no compose form', ! str_contains($anon, 'action="/community/posts"'));
chk('anon sees no react/report/moderate', ! str_contains($anon, '/reactions"') && ! str_contains($anon, '/community/reports"') && ! str_contains($anon, '/community/moderate"'));

echo "controllers: PRG + one-PDP-gate + no-JSON-to-browser\n";
$fc = (string) file_get_contents("$root/app/Modules/Community/Controllers/FeedController.php");
$mc = (string) file_get_contents("$root/app/Modules/Community/Controllers/ModerationController.php");
chk('FeedController has respondFeedDecision PRG', str_contains($fc, 'private function respondFeedDecision'));
chk('FeedController PRG keeps JSON for API', str_contains($fc, 'if ($this->wantsJson())') && str_contains($fc, 'respondJson'));
chk('FeedController PRG redirects to /community/feed', str_contains($fc, "'/community/feed'"));
chk('FeedController computes canModerate via AuthorizationService PDP', str_contains($fc, 'authorization()->isAllowed') && str_contains($fc, "action: 'community.moderate'"));
chk('canModerate is one call per render (index only)', substr_count($fc, 'canModerate(') === 2); // definition + one call site
chk('FeedController passes csrf + canModerate + isAuthed', str_contains($fc, "'csrf'") && str_contains($fc, "'canModerate'") && str_contains($fc, "'isAuthed'"));
chk('ModerationController has respondModerationDecision PRG', str_contains($mc, 'private function respondModerationDecision'));
chk('ModerationController PRG keeps JSON for API', str_contains($mc, 'if ($this->wantsJson())'));
chk('report flashes reportedFlash', str_contains($mc, "'reportedFlash'"));
chk('act flashes moderatedFlash', str_contains($mc, "'moderatedFlash'"));

echo "routes: webcsrf-guarded writes (auth group preserved)\n";
$routes = (string) file_get_contents("$root/app/Config/Routes.php");
foreach (['FeedController::create', 'FeedController::comment/$1', 'FeedController::react/$1', 'ModerationController::report', 'ModerationController::act'] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
    } else {
        chk("$needle route present", false);
    }
}
chk('moderate still authorize:community.moderate', (bool) preg_match('#ModerationController::act.*authorize:community\.moderate#', $routes));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
