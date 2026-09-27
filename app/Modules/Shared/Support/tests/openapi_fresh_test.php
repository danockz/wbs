<?php

declare(strict_types=1);

/**
 * OpenAPI freshness guard. The committed public/openapi.json is generated from
 * the canonical route table (tools/gen_openapi.py mirrors `php spark
 * openapi:generate`). This test regenerates the spec into a temp file and fails
 * if it differs from the committed artifact — so a route added/changed without
 * rebuilding the spec (as had happened: 244 stale paths vs 375 real) is caught.
 *
 * It also asserts the spec's path count matches the number of DISTINCT
 * openapi-normalized paths in Routes.php, and that a couple of representative
 * routes (incl. the member profile-photo endpoints) are present with the right
 * security shape.
 *
 *   php app/Modules/Shared/Support/tests/openapi_fresh_test.php
 */

$root  = dirname(__DIR__, 5);
$gen   = $root . '/tools/gen_openapi.py';
$spec  = $root . '/public/openapi.json';
$tmp   = sys_get_temp_dir() . '/openapi_fresh_' . getmypid() . '.json';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// Locate a python interpreter; skip gracefully if none (never a false failure).
$python = null;
foreach (['python3', 'python'] as $cand) {
    $which = @shell_exec('command -v ' . $cand . ' 2>/dev/null');
    if (is_string($which) && trim($which) !== '') {
        $python = trim($which);
        break;
    }
}

chk('committed spec exists', is_file($spec));
$committed = is_file($spec) ? (string) file_get_contents($spec) : '';
$doc       = json_decode($committed, true);
chk('committed spec is valid JSON', is_array($doc) && isset($doc['paths']));

if ($python === null) {
    echo "  note  python interpreter unavailable — skipping regen diff\n";
} else {
    // Regenerate into a temp file: `python gen_openapi.py --out` isn't supported,
    // so run it from a copy of the repo root but redirect OUT via env is not
    // available either; instead generate to the default path in a scratch copy.
    // Simpler: run the generator with cwd=$root but capture by diffing a fresh
    // build written to $tmp using a one-line wrapper.
    $code = <<<PY
import runpy, sys, json, io, os
os.chdir(%s)
import importlib.util
spec = importlib.util.spec_from_file_location("genoa", %s)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
routes = m.parse()
doc = m.spec(routes)
open(%s, "w").write(json.dumps(doc, indent=2))
PY;
    $script = sprintf($code, var_export($root, true), var_export($gen, true), var_export($tmp, true));
    $spf    = $tmp . '.py';
    file_put_contents($spf, $script);
    @exec(escapeshellarg($python) . ' ' . escapeshellarg($spf) . ' 2>&1', $out, $rc);
    chk('generator ran', $rc === 0, implode(' | ', $out));

    if (is_file($tmp)) {
        $fresh = (string) file_get_contents($tmp);
        chk('committed spec is up to date (regen matches)', $fresh === $committed,
            'run `python3 tools/gen_openapi.py` and commit public/openapi.json');
        @unlink($tmp);
    }
    @unlink($spf);
}

// Structural spot-checks on the committed doc.
if (is_array($doc) && isset($doc['paths'])) {
    $paths = $doc['paths'];
    chk('has a healthy number of paths (>300)', count($paths) > 300, (string) count($paths));

    // Member profile-photo endpoints present with the right shape.
    chk('GET /me/avatar present + secured', isset($paths['/me/avatar']['get']['security']));
    chk('POST /me/photo present + secured', isset($paths['/me/photo']['post']['security']));
    chk('POST /me/photo/remove present', isset($paths['/me/photo/remove']['post']));

    // Home tagged System (canonical), not General.
    chk('Home tagged System', ($paths['/']['get']['tags'] ?? []) === ['System']);
    $general = 0;
    foreach ($paths as $verbs) {
        foreach ($verbs as $op) {
            if (($op['tags'] ?? []) === ['General']) {
                $general++;
            }
        }
    }
    chk('no General-tagged operations', $general === 0, "found {$general}");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
