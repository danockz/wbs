<?php

declare(strict_types=1);

/**
 * SHARED HTTP PROBLEM PAGE (error_page.php) — view contract.
 *
 * The behaviour around it (negotiation, envelopes, return-target guards, the four
 * filters) is pinned by Http/tests/problem_responder_test.php; this file guards
 * the page itself:
 *
 *  1. self-contained + CSP-safe: own <html lang dir>, shared tokens, no external
 *     assets, no JS, noindexed;
 *  2. every echo is escaped, and hostile values in heading/reference/href cannot
 *     break out of the markup;
 *  3. every documented @var is actually supplied by ProblemResponder (no drift
 *     between the responder's data contract and the view), and any of them can be
 *     absent without a warning or a raw `App.err.x` token leaking;
 *  4. all six locales render their own copy, RTL-correct for Arabic, with the
 *     `App.err.*` block at parity and `{0}` placeholders intact.
 *
 *   php app/Modules/Shared/Views/tests/error_page_view_test.php
 */

$root     = dirname(__DIR__, 5);
$viewFile = $root . '/app/Modules/Shared/Views/error_page.php';
$locale   = $root . '/app/Modules/Shared/Views/_locale.php';
$responder = $root . '/app/Modules/Shared/Http/ProblemResponder.php';
$langRoot = $root . '/app/Language';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$view = (string) file_get_contents($viewFile);
$src  = (string) file_get_contents($responder);

// ── 1. Self-contained, CSP-safe, noindexed ──────────────────────────────────
echo "page hygiene\n";
chk('the view exists', $view !== '');
chk('uses the shared HTML shell',
    str_contains($view, '_shell_open.php') && str_contains($view, '_shell_close.php'));
chk('resolves the locale through the shared _locale.php', str_contains($view, "require __DIR__ . '/_locale.php';")
    && is_file($locale));
chk('pulls the shared design tokens via the shell', is_file($root . '/app/Modules/Shared/Views/_tokens.php')
    && str_contains((string) file_get_contents($root . '/app/Modules/Shared/Views/_shell_open.php'), '_tokens.php'));
chk('no external assets', ! preg_match('#(src|href)\s*=\s*["\']https?://#i', $view));
chk('no JS of any kind', ! str_contains(strtolower($view), '<script') && ! str_contains($view, 'javascript:')
    && ! preg_match('/\son[a-z]+\s*=/i', $view));
chk('an error page is never indexed', str_contains($view, 'name="robots"') && str_contains($view, 'noindex'));
chk('the title carries the brand', str_contains($view, "App.brand"));
chk('RTL-safe: logical properties, no hardcoded left/right', ! preg_match('/text-align:\s*(left|right)/', $view)
    && ! preg_match('/margin-(left|right)\s*:/', $view));

// ── 2. Escaping ─────────────────────────────────────────────────────────────
echo "escaping\n";
preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $view, $m);
$unsafe = [];
$allow  = ['$status', '$retryAfter'];
foreach ($m[1] as $body) {
    if (! str_starts_with($body, 'esc(') && ! in_array(trim($body), $allow, true)) {
        $unsafe[] = $body;
    }
}
chk('every echo is escaped or an int (' . count($m[1]) . ' echoes)', $unsafe === [], json_encode(array_slice($unsafe, 0, 3)));
chk('the two raw echoes are cast to int first',
    str_contains($view, '$status      = (int) ($status ?? 500);')
    && str_contains($view, '$retryAfter  = isset($retryAfter) ? (int) $retryAfter : null;'));
chk('hrefs are escaped in attribute context', substr_count($view, "'attr')") >= 3);

// ── 3. The responder's data contract ────────────────────────────────────────
echo "data contract\n";
preg_match_all('/@var\s+\S+\s+\$(\w+)/', $view, $vm);
$declared = array_values(array_unique($vm[1]));
chk('the view documents its inputs', count($declared) >= 10, (string) count($declared));
$missing = [];
foreach ($declared as $name) {
    if (! str_contains($src, "'" . $name . "'")) {
        $missing[] = $name;
    }
}
chk('ProblemResponder supplies every documented @var', $missing === [], json_encode($missing));
foreach ($declared as $name) {
    chk("\$$name has a default so an absent key cannot warn",
        (bool) preg_match('/\$' . $name . '\s*=\s*(\(int\)|\(string\)|isset\()/', $view));
}
chk('the heading falls back instead of rendering empty', str_contains($view, "App.err.generic"));
chk('an absent action renders no button', str_contains($view, '$actionLabel !== null && $actionLabel !== \'\'')
    && str_contains($view, "\$actionHref !== null && \$actionHref !== ''"));
chk('an absent reference renders no chip', str_contains($view, "\$refToken !== ''"));

// ── 4. i18n parity for the App.err block ────────────────────────────────────
echo "i18n parity\n";
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }

    return $o;
};
$base = null;
$locs = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
foreach ($locs as $loc) {
    $file = require "$langRoot/$loc/App.php";
    chk("$loc has an err block", isset($file['err']) && is_array($file['err']));
    $keys = $flatten($file['err'] ?? []);
    if ($base === null) {
        $base = $keys;
        echo '  ·    en baseline: ' . count($keys) . " keys\n";
        continue;
    }
    chk("$loc mirrors English", array_diff($base, $keys) === [] && array_diff($keys, $base) === [],
        json_encode(array_values(array_merge(array_diff($base, $keys), array_diff($keys, $base)))));
}
$en = require "$langRoot/en/App.php";
foreach (['generic', 'signInRequired', 'signInRequiredBody', 'sessionExpired', 'sessionExpiredBody',
    'signInAction', 'denied', 'deniedBody', 'dashboardAction', 'csrf', 'csrfBody', 'retryAction',
    'rateLimited', 'rateLimitedBody', 'statusLabel', 'retryLabel', 'reference', 'helpNote'] as $k) {
    chk("en defines err.$k", isset($en['err'][$k]) && is_string($en['err'][$k]) && $en['err'][$k] !== '');
}
chk('the rate-limit copy is parameterized, not concatenated', str_contains($en['err']['rateLimitedBody'], '{0}'));
foreach ($locs as $loc) {
    $f = require "$langRoot/$loc/App.php";
    chk("$loc keeps the {0} placeholder", str_contains($f['err']['rateLimitedBody'], '{0}'));
}
// every key the responder/view asks for exists
// Only real lookups count — the docblocks mention `App.err.x` as an example of a
// token that must NOT leak, which is not a key request.
$used = [];
preg_match_all("/(?:lang|\$T)\(\s*'App\.err\.([A-Za-z0-9_]+)/", $src . $view, $um);
foreach ($um[1] as $k) {
    $used[$k] = true;
}
// The responder builds two keys dynamically: 'App.err.' . $key ('Body' for the copy).
foreach (['signInRequired', 'signInRequiredBody', 'sessionExpired', 'sessionExpiredBody'] as $k) {
    $used[$k] = true;
}
chk('the responder really does build the 401 keys dynamically',
    str_contains($src, "lang('App.err.' . \$key)") && str_contains($src, "lang('App.err.' . \$key . 'Body')"));
$absent = [];
foreach (array_keys($used) as $k) {
    if (! isset($en['err'][$k])) {
        $absent[] = $k;
    }
}
chk('every App.err.* key used in code exists', $absent === [], json_encode($absent));

// ── 5. Render smoke ─────────────────────────────────────────────────────────
echo "render smoke\n";
$GLOBALS['__loc']  = 'en';
$GLOBALS['__root'] = $root;
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
    function service($x = null)
    {
        return new class {
            public function getLocale()
            {
                return $GLOBALS['__loc'] ?? 'en';
            }
        };
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
$render = static function (array $data) use ($viewFile): string {
    extract($data);
    ob_start();
    include $viewFile;

    return (string) ob_get_clean();
};

$h = $render([
    'status' => 401, 'title' => 'UNAUTHENTICATED', 'detail' => 'identity.unauthenticated',
    'heading' => lang('App.err.sessionExpired'), 'explanation' => lang('App.err.sessionExpiredBody'),
    'actionLabel' => lang('App.err.signInAction'), 'actionHref' => '/login?return=%2Fme',
    'accent' => '#f7b84b',
]);
chk('a 401 renders the status badge and title', str_contains($h, '>401<') && str_contains($h, '401 UNAUTHENTICATED'));
chk('a 401 renders heading + explanation + action',
    str_contains($h, 'Your session has expired') && str_contains($h, 'Nothing was lost')
    && str_contains($h, 'href="/login?return=%2Fme"'));
chk('a 401 falls back to the message key as the reference', str_contains($h, 'identity.unauthenticated'));
chk('no retry chip without a retryAfter', ! str_contains($h, 'Retry after'));
chk('no secondary link without one', substr_count($h, 'class="alt"') === 0);
chk('the design tokens are inlined', str_contains($h, '--wbs-bg'));

// absent everything but the status: still a page, still no leaked keys
$h = $render(['status' => 403, 'title' => 'ACCESS_DENIED', 'detail' => '']);
chk('a bare render still produces a full page', str_contains($h, '<!DOCTYPE html>') && str_contains($h, '</html>'));
chk('with the generic heading fallback', str_contains($h, 'This request could not be completed'));
chk('and no raw lang key anywhere', ! str_contains($h, 'App.err.'));
chk('and no button when there is no action', ! str_contains($h, 'class="btn"'));

// hostile input cannot break out
$h = $render([
    'status' => 403, 'title' => 'X', 'detail' => 'd',
    'heading'     => '"><script>alert(1)</script>',
    'explanation' => '<img src=x onerror=alert(2)>',
    'reference'   => '"><b>bold</b>',
    'actionLabel' => 'Go', 'actionHref' => '/x" onmouseover="alert(3)',
]);
chk('a hostile heading is escaped', ! str_contains($h, '<script>') && str_contains($h, '&lt;script&gt;'));
chk('a hostile explanation is escaped', ! str_contains($h, '<img src=x')
    && str_contains($h, '&lt;img src=x onerror=alert(2)&gt;'));
chk('a hostile reference is escaped', ! str_contains($h, '<b>bold</b>'));
chk('a hostile href is escaped', ! str_contains($h, 'onmouseover="alert') && str_contains($h, '&quot;'));

// six locales
$want = ['en' => 'Your session has expired', 'fr' => 'Votre session a expiré', 'es' => 'Su sesión ha caducado',
    'pt' => 'A sua sessão expirou', 'zh' => '你的会话已过期', 'ar' => 'انتهت صلاحية جلستك'];
foreach ($want as $loc => $heading) {
    $GLOBALS['__loc'] = $loc;
    $h = $render([
        'status' => 401, 'title' => 'UNAUTHENTICATED', 'detail' => 'identity.unauthenticated',
        'heading' => lang('App.err.sessionExpired'), 'explanation' => lang('App.err.sessionExpiredBody'),
        'actionLabel' => lang('App.err.signInAction'), 'actionHref' => '/login?return=%2Fme',
        'accent' => '#f7b84b',
    ]);
    chk("$loc renders its own heading", str_contains($h, $heading));
    $dir = $loc === 'ar' ? 'rtl' : 'ltr';
    chk("$loc lang/dir", str_contains($h, 'lang="' . $loc . '"') && str_contains($h, 'dir="' . $dir . '"'));
    chk("$loc localizes the chrome too", str_contains($h, lang('App.err.statusLabel'))
        && str_contains($h, lang('App.err.reference')));
    $h429 = $render(['status' => 429, 'title' => 'TOO_MANY_REQUESTS', 'detail' => 'rate.limited',
        'heading' => lang('App.err.rateLimited'), 'explanation' => lang('App.err.rateLimitedBody', ['9']),
        'retryAfter' => 9, 'reference' => 'Retry-After: 9', 'accent' => '#299cdb']);
    chk("$loc substitutes the retry seconds", str_contains($h429, '9') && ! str_contains($h429, '{0}'));
    chk("$loc shows the retry chip", str_contains($h429, lang('App.err.retryLabel')) && str_contains($h429, '9s'));
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
