<?php

declare(strict_types=1);

/**
 * Framework error views (404 / 400 / production 500) — localized, branded bodies.
 *
 * These are the errors CodeIgniter renders itself, below ProblemResponder's
 * filter layer. They used to be the stock templates: hardcoded lang="en", light
 * theme, `lang('Errors.pageNotFound')` from the framework's own English bundle.
 * This pins that they now render the SAME shared problem page as every other
 * browser-facing failure, in six locales, without leaking exception internals:
 *
 *   1. the three views are thin delegations to WBS\Shared\Http\ErrorPages and no
 *      longer carry stock markup, and they tolerate the handler passing no
 *      variables at all;
 *   2. the rendered body is the shared page: localized heading + explanation,
 *      house tokens, RTL-correct, no JS, no external assets, noindexed;
 *   3. the action depends on whether the visitor is signed in (dashboard vs.
 *      sign-in), and both targets are same-site;
 *   4. a 500 says nothing about the exception — no class, file, line or message —
 *      while a 404/400 may show the framework's own message as a muted, truncated
 *      reference only;
 *   5. the development trace template stays stock on purpose.
 *
 *   php app/Modules/Shared/Http/tests/error_pages_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
function slurp(string $p): string
{
    return (string) @file_get_contents($p);
}

$GLOBALS['__root'] = $root;
$GLOBALS['__loc']  = 'en';
$GLOBALS['__cookie'] = '';

// ── harness ─────────────────────────────────────────────────────────────────
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('lang')) {
    function lang(string $key, array $args = [])
    {
        static $cache = [];
        $loc = $GLOBALS['__loc'] ?? 'en';
        if (! isset($cache[$loc])) {
            $cache[$loc] = require $GLOBALS['__root'] . '/app/Language/' . $loc . '/App.php';
        }
        $node = $cache[$loc];
        foreach (explode('.', $key) as $seg) {
            if ($seg === 'App') {
                continue;
            }
            if (! is_array($node) || ! array_key_exists($seg, $node)) {
                return $key;
            }
            $node = $node[$seg];
        }
        if (! is_string($node)) {
            return $key;
        }
        foreach ($args as $i => $v) {
            $node = str_replace('{' . $i . '}', (string) $v, $node);
        }

        return $node;
    }
}
if (! function_exists('service')) {
    function service($name = null)
    {
        if ($name === 'request') {
            return new class {
                public function getCookie(string $n): ?string
                {
                    return $n === 'wbs_session' && $GLOBALS['__cookie'] !== '' ? $GLOBALS['__cookie'] : null;
                }

                public function getLocale(): string
                {
                    return $GLOBALS['__loc'] ?? 'en';
                }
            };
        }

        return null;
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            /** @var list<string> */
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
/** Resolve the shared problem page and render it (no framework). */
if (! function_exists('view')) {
    function view(string $name, array $data = []): string
    {
        $rel  = str_replace(['WBS\\Shared\\Views\\', '\\'], ['', '/'], $name);
        $file = $GLOBALS['__root'] . '/app/Modules/Shared/Views/' . $rel . '.php';
        if (! is_file($file)) {
            return 'MISSING VIEW ' . $name;
        }
        extract($data);
        ob_start();
        include $file;

        return (string) ob_get_clean();
    }
}

require_once $root . '/app/Modules/Shared/Http/ProblemResponder.php';
require_once $root . '/app/Modules/Shared/Http/ErrorPages.php';

use WBS\Shared\Http\ErrorPages;

$renderView = static function (string $file, array $vars): string {
    extract($vars);
    ob_start();
    include $GLOBALS['__root'] . '/app/Views/errors/html/' . $file;

    return (string) ob_get_clean();
};

// ── 1. The views are thin delegations ───────────────────────────────────────
echo "framework error views\n";
$views = [
    'error_404.php'  => ['notFound', 'WBS\Shared\Http\ErrorPages::notFound'],
    'error_400.php'  => ['badRequest', 'WBS\Shared\Http\ErrorPages::badRequest'],
    'production.php' => ['serverError', 'WBS\Shared\Http\ErrorPages::serverError'],
];
foreach ($views as $file => [$method, $call]) {
    // Comments stripped first: the docblocks quote the stock markup they replaced.
    $src = (string) php_strip_whitespace($root . '/app/Views/errors/html/' . $file);
    chk("$file delegates to ErrorPages::$method", str_contains($src, $call));
    chk("$file has no stock markup left",
        ! str_contains($src, 'Helvetica') && ! str_contains($src, '#fafafa')
        && ! str_contains($src, "lang('Errors.") && ! str_contains($src, 'debug.css'));
    chk("$file keeps the framework in charge of status/headers",
        ! str_contains($src, 'setStatusCode') && ! str_contains($src, 'http_response_code'));
    chk("$file is hardening-friendly (strict types)", str_contains($src, 'declare(strict_types=1);'));
}
chk('error_404 tolerates a handler that passes no $message', str_contains(slurp($root . '/app/Views/errors/html/error_404.php'), "(\$message ?? '')"));
chk('production tolerates a handler that passes no $statusCode', str_contains(slurp($root . '/app/Views/errors/html/production.php'), "(\$statusCode ?? 500)"));
chk('the development trace template is deliberately left stock',
    str_contains(slurp($root . '/app/Views/errors/html/error_exception.php'), 'trace')
    && ! str_contains(slurp($root . '/app/Views/errors/html/error_exception.php'), 'ErrorPages'));
chk('ErrorPages only builds a body', ! str_contains(slurp($root . '/app/Modules/Shared/Http/ErrorPages.php'), 'setStatusCode')
    && ! str_contains(slurp($root . '/app/Modules/Shared/Http/ErrorPages.php'), 'setHeader'));

// ── 2. 404 for an anonymous visitor ─────────────────────────────────────────
echo "404 (anonymous)\n";
$GLOBALS['__cookie'] = '';
$h = $renderView('error_404.php', ['message' => "Can't find a route for 'GET /old-link'"]);
chk('it is a full localized page', str_contains($h, '<!DOCTYPE html>') && str_contains($h, 'lang="en"') && str_contains($h, 'dir="ltr"'));
chk('heading + explanation come from App.err', str_contains($h, 'We couldn’t find that page')
    && str_contains($h, 'nothing has been lost'));
chk('status badge and machine title', str_contains($h, '>404<') && str_contains($h, '404 NOT_FOUND'));
chk('the framework message is a muted reference, not the headline',
    str_contains($h, 'Can&#039;t find a route for') && ! str_contains($h, '<h1>Can'));
chk('an anonymous visitor is offered sign-in', str_contains($h, 'href="/login"') && str_contains($h, 'Sign in'));
chk('and the home page as the alternative', str_contains($h, 'href="/"') && str_contains($h, 'Go to the home page'));
chk('no raw lang keys leak', ! str_contains($h, 'App.err.'));
chk('no JS, no external assets', ! str_contains(strtolower($h), '<script') && ! preg_match('#(src|href)\s*=\s*["\']https?://#i', $h));
chk('house tokens are inlined', str_contains($h, '--wbs-bg') && str_contains($h, '--wbs-surface'));
chk('an error page is not indexed', str_contains($h, 'noindex'));

// ── 3. 404 for a signed-in member ───────────────────────────────────────────
echo "404 (signed in)\n";
$GLOBALS['__cookie'] = 'live-session';
$h = $renderView('error_404.php', []);
chk('the action becomes the dashboard', str_contains($h, 'href="/me"') && str_contains($h, 'Go to my dashboard'));
chk('and no sign-in link is offered', ! str_contains($h, 'href="/login"'));
chk('an absent message renders no reference chip', ! str_contains($h, 'Can&#039;t find'));

// ── 4. 400 and the production 500 ───────────────────────────────────────────
echo "400 + production\n";
$h = $renderView('error_400.php', ['message' => 'Malformed UTF-8 characters']);
chk('400 has its own copy', str_contains($h, 'That request could not be understood') && str_contains($h, '>400<'));
chk('400 keeps the message as a reference', str_contains($h, 'Malformed UTF-8 characters'));

$GLOBALS['__cookie'] = '';
$h = $renderView('production.php', ['statusCode' => 500]);
chk('500 has its own copy', str_contains($h, 'Something went wrong on our side') && str_contains($h, '>500<'));
chk('500 blames us, not the member', str_contains($h, 'nothing you did caused it'));
$leaks = ['Exception', 'Stack trace', 'app/Modules', '.php on line', 'PDOException', 'SQLSTATE'];
$found = [];
foreach ($leaks as $needle) {
    if (str_contains($h, $needle)) {
        $found[] = $needle;
    }
}
chk('500 leaks no exception internals', $found === [], json_encode($found));
chk('503 reads as service unavailable', str_contains(ErrorPages::serverError(503), 'SERVICE_UNAVAILABLE'));
$long = ErrorPages::notFound(str_repeat('x', 400));
chk('a long framework message is truncated with an ellipsis', str_contains($long, str_repeat('x', 157) . '…')
    && ! str_contains($long, str_repeat('x', 200)));

// ── 5. Six locales ──────────────────────────────────────────────────────────
echo "localization\n";
$want = [
    'en' => ['We couldn’t find that page', 'Something went wrong on our side', 'ltr'],
    'fr' => ['Nous n’avons pas trouvé cette page', 'Un problème est survenu de notre côté', 'ltr'],
    'es' => ['No pudimos encontrar esa página', 'Algo salió mal de nuestro lado', 'ltr'],
    'pt' => ['Não conseguimos encontrar essa página', 'Algo correu mal do nosso lado', 'ltr'],
    'zh' => ['我们找不到该页面', '我们这边出了点问题', 'ltr'],
    'ar' => ['لم نتمكّن من العثور على تلك الصفحة', 'حدث خطأ من جهتنا', 'rtl'],
];
foreach ($want as $loc => [$notFound, $serverError, $dir]) {
    $GLOBALS['__loc'] = $loc;
    $h404 = $renderView('error_404.php', []);
    $h500 = $renderView('production.php', []);
    chk("$loc 404 translated", str_contains($h404, $notFound));
    chk("$loc 500 translated", str_contains($h500, $serverError));
    chk("$loc lang/dir", str_contains($h404, 'lang="' . $loc . '"') && str_contains($h404, 'dir="' . $dir . '"'));
    chk("$loc action translated", ! str_contains($h404, 'App.err.'));
}
$GLOBALS['__loc'] = 'en';

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
