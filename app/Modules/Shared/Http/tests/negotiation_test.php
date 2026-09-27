<?php

declare(strict_types=1);

/**
 * Negotiation / fallback test: browsers never see raw JSON.
 *
 * Verifies the BaseController representation contract and the generic data-page
 * safety net (SRS FR-ARC-001/002):
 *   - API clients (wantsJson) always get JSON.
 *   - A browser with a bespoke view gets that view.
 *   - A browser with NO view gets the generic data-page template — NOT raw JSON.
 *   - The data-page view renders every Result shape (list/map/scalar/problem)
 *     as a full HTML document inside the shared layout (with the universal menu).
 *
 * Source-level + isolated-render assertions (no framework/browser runtime in the
 * sandbox).
 *
 *   php app/Modules/Shared/Http/tests/negotiation_test.php
 */

$root = dirname(__DIR__, 5);            // tests->Http->Shared->Modules->app->ROOT
$base = (string) @file_get_contents($root . '/app/Modules/Shared/Http/BaseController.php');
$dpF  = $root . '/app/Modules/Shared/Views/data_page.php';
$dp   = (string) @file_get_contents($dpF);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

echo "http negotiation / fallback\n";

// -- Source contract in BaseController::respondWith --------------------------
chk('BaseController exists', $base !== '');
chk('API clients (wantsJson) short-circuit to JSON first', (bool) preg_match('/if \(\$this->wantsJson\(\)\) \{\s*return \$this->respondJson/', $base));
chk('a bespoke htmlView is honoured for browsers', str_contains($base, 'if ($htmlView !== null)') && str_contains($base, 'respondHtml($result, $htmlView'));
chk('defines a generic fallback view constant', str_contains($base, "FALLBACK_HTML_VIEW = 'WBS\\Shared\\Views\\data_page'"));
chk('browser + no view falls back to the data page (not JSON)', str_contains($base, 'self::FALLBACK_HTML_VIEW'));
chk('fallback still honours a failure redirect target', substr_count($base, "redirect()->to(\$redirectTo)->with('error'") >= 2);
chk('fallback passes result/ok/title to the view', str_contains($base, "'result' => \$result->toArray()") && str_contains($base, "'ok'     => \$result->ok"));

// -- The data-page view -----------------------------------------------------
chk('data-page view exists on disk at its resolved path', $dp !== '');
// Regression guard (log-2026-09-09): the fallback constant must point at a file
// the framework view locator can actually resolve. Bare 'shared/data_page' threw
// "Invalid file". The namespaced module form maps to app/Modules/Shared/Views/.
chk('fallback constant uses the namespaced module view form', str_contains($base, 'WBS\\Shared\\Views\\data_page'));
chk('admin console constant uses the namespaced module view form', str_contains($base, 'WBS\\Shared\\Views\\admin_console'));
chk('data-page NOT left at the unresolvable bare path', ! str_contains($base, "= 'shared/data_page'"));
chk('data-page extends the shared app layout', str_contains($dp, "extend('layouts/app')"));
chk('data-page renders a problem (title/detail/status)', str_contains($dp, '$isOk') && str_contains($dp, "payload['detail']"));
chk('data-page has no external assets (CSP-safe)', ! str_contains($dp, 'http://') && ! str_contains($dp, 'https://') || str_contains($dp, 'about:blank'));

// -- Admin console contract -------------------------------------------------
$adminF = $root . '/app/Modules/Shared/Views/admin_console.php';
$admin  = (string) @file_get_contents($adminF);
chk('BaseController exposes respondAdmin()', str_contains($base, 'protected function respondAdmin('));
chk('respondAdmin returns JSON to API clients', (bool) preg_match('/respondAdmin\([^{]*\{\s*if \(\$this->wantsJson\(\)\) \{\s*return \$this->respondJson/s', $base));
chk('respondAdmin renders the admin console view for browsers', str_contains($base, 'self::ADMIN_CONSOLE_VIEW'));
chk('admin console view exists', $admin !== '');
chk('admin console extends the shared layout', str_contains($admin, "extend('layouts/app')"));

// -- Isolated render of every Result shape ----------------------------------
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
// data_page.php localizes its fallback strings via lang('App.*'); the framework
// helper isn't loaded in this bare harness, so stub it against the real English
// catalog (returns the raw key on a miss, mirroring CI4's behaviour).
if (! function_exists('lang')) {
    $GLOBALS['__negAppLang'] = require $root . '/app/Language/en/App.php';
    function lang(string $key, array $args = [], ?string $locale = null)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'App') {
            return $key;
        }
        $v = $GLOBALS['__negAppLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
require_once $root . '/app/Modules/Shared/Navigation/PermissionBits.php';
foreach (['MenuItem', 'MenuCategory', 'CoreMenuProvider', 'MenuCatalog', 'RoleWordTable', 'MenuService', 'MenuFragment'] as $f) {
    $p = $root . "/app/Modules/Shared/Navigation/$f.php";
    if (is_file($p)) {
        require_once $p;
    }
}

final class _NegFakeView
{
    private ?string $layout = null;
    private array $sections = [];
    private ?string $cur    = null;

    public function extend($l)
    {
        $this->layout = $l;
    }

    public function section($n)
    {
        $this->cur = $n;
        ob_start();
    }

    public function endSection()
    {
        $this->sections[$this->cur] = ob_get_clean();
        $this->cur                  = null;
    }

    public function renderSection($n)
    {
        echo $this->sections[$n] ?? '';
    }

    public function getLayout()
    {
        return $this->layout;
    }
}

$render = static function (string $file, array $data) use ($root): string {
    $v = new _NegFakeView();
    $b = Closure::bind(function () use ($file, $data) {
        extract($data);
        include $file;
    }, $v, _NegFakeView::class);
    $b();
    $lf = $root . '/app/Views/' . str_replace('\\', '/', (string) $v->getLayout()) . '.php';
    $lb = Closure::bind(function () use ($lf, $data) {
        extract($data);
        ob_start();
        include $lf;

        return ob_get_clean();
    }, $v, _NegFakeView::class);

    return $lb();
};

$shapes = [
    'rows'        => ['title' => 'Rows', 'ok' => true, 'result' => ['data' => [['id' => 'a', 'n' => 1], ['id' => 'b', 'n' => 2]]]],
    'map'         => ['title' => 'Map', 'ok' => true, 'result' => ['data' => ['k' => 'v', 'nested' => ['x' => true]]]],
    'null'        => ['title' => 'Null', 'ok' => true, 'result' => ['data' => null]],
    'meta'        => ['title' => 'Meta', 'ok' => true, 'result' => ['data' => [['p' => 1]], 'meta' => ['page' => 1]]],
    'problem'     => ['title' => 'Denied', 'ok' => false, 'result' => ['title' => 'ACCESS_DENIED', 'status' => 403, 'detail' => 'nope']],
    'problem_err' => ['title' => 'Invalid', 'ok' => false, 'result' => ['title' => 'BAD', 'status' => 422, 'detail' => 'v', 'errors' => ['name' => 'required']]],
    'scalars'     => ['title' => 'Tags', 'ok' => true, 'result' => ['data' => ['x', 'y']]],
    'empty'       => ['title' => 'Empty', 'ok' => true, 'result' => ['data' => []]],
];
foreach ($shapes as $name => $data) {
    $html = '';
    $err  = '';
    try {
        $html = $render($dpF, $data);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    $okDoc = $err === '' && str_contains($html, '<!DOCTYPE html>') && str_contains($html, '</html>');
    chk("data-page renders shape [$name] as full HTML" . ($err !== '' ? " ($err)" : ''), $okDoc);
    chk("data-page shape [$name] carries the universal menu", str_contains($html, 'wbs-menu'));
    chk("data-page shape [$name] emits no raw JSON envelope keys as literal text", ! str_contains($html, '"about:blank"'));
}

// The admin console renders the same shapes (plus a branded title/subtitle).
$adminShapes = [
    'admin_list'    => ['title' => 'Roles', 'subtitle' => '', 'ok' => true, 'result' => ['data' => [['id' => 'r1', 'name' => 'Admin']]]],
    'admin_record'  => ['title' => 'Role', 'subtitle' => 'r1', 'ok' => true, 'result' => ['data' => ['id' => 'r1', 'name' => 'Admin', 'perms' => ['a', 'b']]]],
    'admin_notfnd'  => ['title' => 'Access request', 'subtitle' => 'x', 'ok' => false, 'result' => ['title' => 'NOT_FOUND', 'status' => 404, 'detail' => 'gone']],
    'admin_empty'   => ['title' => 'Badges', 'subtitle' => '', 'ok' => true, 'result' => ['data' => []]],
];
foreach ($adminShapes as $name => $data) {
    $html = '';
    $err  = '';
    try {
        $html = $render($adminF, $data);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    $okDoc = $err === '' && str_contains($html, '<!DOCTYPE html>') && str_contains($html, '</html>');
    chk("admin console renders shape [$name] as full HTML" . ($err !== '' ? " ($err)" : ''), $okDoc);
    chk("admin console shape [$name] carries the universal menu", str_contains($html, 'wbs-menu'));
    chk("admin console shape [$name] shows the admin badge", str_contains($html, '>admin<'));
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
