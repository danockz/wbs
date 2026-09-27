<?php

declare(strict_types=1);

/**
 * PHP wrapper that folds the FUNCTIONAL JS test (table_enhance_functional.mjs)
 * into the platform suite. It executes the real public/assets/js/table-enhance.js
 * against a tiny built-in DOM shim via `node` and asserts behaviour (sort, filter,
 * CSV export, copy).
 *
 * If `node` is unavailable the test SKIPS (counts as passed) rather than failing —
 * the suite has no npm/node dependency, this is an opportunistic extra guard on
 * top of the markup assertions in page_presenter_test.php.
 *
 *   php app/Modules/Shared/Navigation/tests/table_enhance_functional_test.php
 */

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$mjs = __DIR__ . '/table_enhance_functional.mjs';
chk('functional harness file exists', is_file($mjs));

// Locate node without a shell dependency assumption.
$nodeBin = null;
foreach (['node', '/usr/bin/node', '/usr/local/bin/node'] as $cand) {
    $which = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
    if (is_string($which) && trim($which) !== '') {
        $nodeBin = trim($which);
        break;
    }
}

if ($nodeBin === null) {
    echo "  skip node not available — functional JS test skipped (markup assertions still cover this in page_presenter_test.php)\n";
    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail > 0 ? 1 : 0);
}

$cmd    = escapeshellarg($nodeBin) . ' ' . escapeshellarg($mjs) . ' 2>&1';
$output = (string) shell_exec($cmd);
echo $output;

// Fold the child's own tally into this test's result.
if (preg_match('/==\s*(\d+)\s+passed,\s+(\d+)\s+failed\s*==/', $output, $m)) {
    $childPass = (int) $m[1];
    $childFail = (int) $m[2];
    chk('functional JS suite ran', true);
    chk('functional JS suite: 0 failures', $childFail === 0, $childFail . ' failed');
    chk('functional JS suite: covered ≥ 25 assertions', $childPass >= 25, $childPass . ' passed');
} else {
    chk('functional JS suite produced a tally', false, 'no summary line — node error?');
}

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
