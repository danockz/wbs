<?php

declare(strict_types=1);

/**
 * Standalone test runner.
 *
 * Much of this platform's coverage lives in self-contained `*_test.php` scripts
 * under module `tests/` directories — each defines its own `chk()` harness, prints
 * "N passed, M failed", and `exit()`s with a non-zero code on failure. They are
 * NOT PHPUnit TestCases, so `vendor/bin/phpunit` never runs them; without this
 * runner they are only exercised by hand and can silently rot.
 *
 * This runner discovers every such script, runs each in its OWN php subprocess
 * (isolation: each file redefines global harness functions like `chk()`/`lang()`
 * and calls `exit()`, so they cannot share a process), then aggregates the
 * per-file "N passed, M failed" summaries into one repo-wide total. Exit code is
 * non-zero if ANY file fails or produces no par'able summary — making it safe to
 * wire into `composer qa`, the pre-commit hook, and CI.
 *
 * Usage:
 *   php tests/run-standalone.php            # run all discovered scripts
 *   php tests/run-standalone.php --filter=i18n   # only paths containing "i18n"
 *   php tests/run-standalone.php --quiet    # summaries only, hide per-file "ok" lines
 *   php tests/run-standalone.php --list     # list discovered scripts and exit
 */

$root = dirname(__DIR__);

// ---- args -------------------------------------------------------------------
$filter = null;
$quiet  = false;
$list   = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif ($arg === '--list') {
        $list = true;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

// ---- discovery --------------------------------------------------------------
// Any file named *_test.php under app/. Excludes the benchmark helper (menu_bench)
// which is a manual perf tool, not an assertion script.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS),
);
$files = [];
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if (! $f->isFile()) {
        continue;
    }
    $name = $f->getFilename();
    if (! str_ends_with($name, '_test.php')) {
        continue;
    }
    if (str_ends_with($name, 'bench.php')) {
        continue;
    }
    $path = $f->getPathname();
    if ($filter !== null && ! str_contains($path, $filter)) {
        continue;
    }
    $files[] = $path;
}
sort($files);

if ($files === []) {
    fwrite(STDERR, "No standalone *_test.php scripts discovered" . ($filter ? " for filter '{$filter}'" : '') . ".\n");
    exit(2);
}

if ($list) {
    foreach ($files as $f) {
        echo str_replace($root . '/', '', $f) . "\n";
    }
    echo "\n" . count($files) . " scripts.\n";
    exit(0);
}

// ---- run each in isolation --------------------------------------------------
$php          = PHP_BINARY;
$totalPass    = 0;
$totalFail    = 0;
$filesRun     = 0;
$filesFailed  = 0;
$noSummary    = [];
$slowest      = [];
$startAll     = microtime(true);

foreach ($files as $file) {
    $rel = str_replace($root . '/', '', $file);
    $t0  = microtime(true);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc        = proc_open([$php, $file], $descriptors, $pipes, $root);
    if (! is_resource($proc)) {
        fwrite(STDERR, "FAILED TO SPAWN: {$rel}\n");
        $filesFailed++;
        continue;
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $ms   = (microtime(true) - $t0) * 1000;

    $filesRun++;
    $slowest[$rel] = $ms;

    // Parse the last "N passed, M failed" summary the script printed.
    $pass = null;
    $fail = null;
    if (preg_match_all('/(\d+)\s+passed,\s+(\d+)\s+failed/i', $out, $m) && $m[1] !== []) {
        $pass = (int) end($m[1]);
        $fail = (int) end($m[2]);
        $totalPass += $pass;
        $totalFail += $fail;
    } else {
        $noSummary[] = $rel;
    }

    $ok = $code === 0 && ($fail === null || $fail === 0) && $pass !== null;
    if (! $ok) {
        $filesFailed++;
    }

    $tag = $ok ? 'PASS' : 'FAIL';
    $sum = $pass === null ? 'no summary' : "{$pass} passed, {$fail} failed";
    printf("  %-4s  %-72s %s  (%.0fms, exit %d)\n", $tag, $rel, $sum, $ms, $code);

    // On failure, surface the script's own output so CI logs are actionable.
    if (! $ok) {
        if (! $quiet) {
            foreach (preg_split('/\R/', trim($out)) as $line) {
                if (stripos($line, 'FAIL') !== false || str_contains($line, 'passed,')) {
                    echo '        ' . $line . "\n";
                }
            }
        }
        if (trim($err) !== '') {
            echo '        stderr: ' . trim(preg_replace('/\s+/', ' ', $err)) . "\n";
        }
    }
}

$elapsed = microtime(true) - $startAll;

// ---- report -----------------------------------------------------------------
echo "\n";
echo str_repeat('-', 78) . "\n";
if ($noSummary !== []) {
    echo 'Scripts with no parsable summary (treated as failures): ' . implode(', ', $noSummary) . "\n";
}
arsort($slowest);
$top = array_slice($slowest, 0, 3, true);
$parts = [];
foreach ($top as $rel => $ms) {
    $parts[] = sprintf('%s (%.0fms)', basename($rel), $ms);
}
echo 'Slowest: ' . implode(', ', $parts) . "\n";
printf(
    "Files: %d run, %d failed · Assertions: %d passed, %d failed · %.1fs\n",
    $filesRun,
    $filesFailed,
    $totalPass,
    $totalFail,
    $elapsed,
);

$green = $filesFailed === 0 && $totalFail === 0 && $noSummary === [];
echo ($green ? "== STANDALONE SUITE GREEN ==" : "== STANDALONE SUITE FAILED ==") . "\n";
exit($green ? 0 : 1);
