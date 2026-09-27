<?php

declare(strict_types=1);

/**
 * Unified sweep runner test (Theme C).
 *
 * Proves the shared runner behaviour the reviews asked for, without a database:
 *   - registry: unique keys, duplicate rejected, lookup;
 *   - runOne/runAll drive each SweepContract and aggregate swept/failed/skipped;
 *   - a sweep that THROWS is isolated (failed result, batch continues);
 *   - a contended LOCK skips the sweep (not a failure) and the sweep body never
 *     runs; the lock is always released;
 *   - `--only` subset selection;
 *   - a heartbeat line is emitted per sweep.
 *
 *   php app/Modules/Shared/Sweep/tests/sweep_runner_test.php
 */

use WBS\Shared\Support\Clock;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepLock;
use WBS\Shared\Sweep\SweepRegistry;
use WBS\Shared\Sweep\SweepResult;
use WBS\Shared\Sweep\SweepRunner;

$root = dirname(__DIR__, 5);
require_once $root . '/app/Modules/Shared/Support/Clock.php';
require_once $root . '/app/Modules/Shared/Sweep/SweepResult.php';
require_once $root . '/app/Modules/Shared/Sweep/SweepContract.php';
require_once $root . '/app/Modules/Shared/Sweep/SweepLock.php';
require_once $root . '/app/Modules/Shared/Sweep/SweepRegistry.php';
require_once $root . '/app/Modules/Shared/Sweep/SweepRunner.php';

$pass = 0;
$fail = 0;
$chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
};

// ---- fakes ------------------------------------------------------------------
/** A configurable sweep: reports a count, or throws, and records its calls. */
$makeSweep = static function (string $key, $behaviour) {
    return new class ($key, $behaviour) implements SweepContract {
        public int $calls = 0;
        public ?string $lastOrg = null;
        public array $lastOptions = [];

        public function __construct(private string $k, private $behaviour)
        {
        }

        public function key(): string
        {
            return $this->k;
        }

        public function description(): string
        {
            return 'fake ' . $this->k;
        }

        public function run(?string $organizationId, array $options = []): SweepResult
        {
            $this->calls++;
            $this->lastOrg = $organizationId;
            $this->lastOptions = $options;
            if (is_callable($this->behaviour)) {
                return ($this->behaviour)($organizationId, $options);
            }
            if ($this->behaviour === 'throw') {
                throw new RuntimeException('boom');
            }

            return SweepResult::ok((int) $this->behaviour, ['n' => (int) $this->behaviour]);
        }
    };
};

$grantLock = new class implements SweepLock {
    public array $acquired = [];
    public array $released = [];

    public function acquire(string $name): bool
    {
        $this->acquired[] = $name;

        return true;
    }

    public function release(string $name): void
    {
        $this->released[] = $name;
    }
};

$denyLock = new class implements SweepLock {
    public array $released = [];

    public function acquire(string $name): bool
    {
        return false;
    }

    public function release(string $name): void
    {
        $this->released[] = $name;
    }
};

$clock = new Clock();

// ---- registry ---------------------------------------------------------------
$a = $makeSweep('mod.a', 3);
$b = $makeSweep('mod.b', 5);
$reg = new SweepRegistry([$a, $b]);
$chk('registry has both keys', $reg->has('mod.a') && $reg->has('mod.b'));
$chk('registry keys sorted', $reg->keys() === ['mod.a', 'mod.b']);
$chk('registry get returns instance', $reg->get('mod.a') === $a);
$chk('registry get missing returns null', $reg->get('nope') === null);

$dupThrew = false;
try {
    $reg->register($makeSweep('mod.a', 1));
} catch (InvalidArgumentException) {
    $dupThrew = true;
}
$chk('duplicate key rejected', $dupThrew);

// ---- runAll happy path ------------------------------------------------------
$log = [];
$logger = static function (string $line) use (&$log): void {
    $log[] = $line;
};
$runner = new SweepRunner($reg, $grantLock, $clock, $logger);
$sum = $runner->runAll('org-1');
$chk('both sweeps ran', $a->calls === 1 && $b->calls === 1);
$chk('org threaded through', $a->lastOrg === 'org-1' && $b->lastOrg === 'org-1');
$chk('aggregate swept = 8', $sum['swept'] === 8, (string) $sum['swept']);
$chk('ran = 2', $sum['ran'] === 2);
$chk('failed = 0', $sum['failed'] === 0);
$chk('skipped = 0', $sum['skipped'] === 0);
$chk('locks acquired for both', count($grantLock->acquired) === 2);
$chk('locks released for both', count($grantLock->released) === 2);
$chk('heartbeat emitted per sweep', count($log) === 2, (string) count($log));
$chk('heartbeat mentions the key + swept', str_contains($log[0], 'mod.a') && str_contains(implode('', $log), 'swept 3'));

// ---- options pass-through ---------------------------------------------------
$runner->runAll('org-1', ['mod.a'], ['grace' => 12, 'limit' => 50]);
$chk('options passed to sweep', ($a->lastOptions['grace'] ?? null) === 12 && ($a->lastOptions['limit'] ?? null) === 50);

// ---- error isolation --------------------------------------------------------
$ok = $makeSweep('mod.ok', 2);
$boom = $makeSweep('mod.boom', 'throw');
$after = $makeSweep('mod.after', 4);
$reg2 = new SweepRegistry([$ok, $boom, $after]);
$runner2 = new SweepRunner($reg2, $grantLock, $clock, $logger);
$sum2 = $runner2->runAll(null);
$chk('a throwing sweep does not abort the batch', $ok->calls === 1 && $after->calls === 1);
$chk('throwing sweep counted as failed', $sum2['failed'] === 1);
$chk('failed sweep result carries the error', $sum2['results']['mod.boom']->ok === false
    && str_contains((string) $sum2['results']['mod.boom']->error, 'boom'));
$chk('swept excludes the failed sweep', $sum2['swept'] === 6, (string) $sum2['swept']);
$chk('lock released even when the sweep throws', in_array('mod.boom', $grantLock->released, true));

// ---- lock contention skips --------------------------------------------------
$skipSweep = $makeSweep('mod.skip', 9);
$reg3 = new SweepRegistry([$skipSweep]);
$runner3 = new SweepRunner($reg3, $denyLock, $clock, $logger);
$sum3 = $runner3->runOne('mod.skip', 'org-1');
$chk('contended sweep body never runs', $skipSweep->calls === 0);
$chk('contended sweep is skipped, not failed', $sum3->ok === true && $sum3->skipped === true);
$chk('skip does not count as swept', $sum3->swept === 0);
$chk('deny-lock never released (never acquired)', $denyLock->released === []);

// ---- unknown key ------------------------------------------------------------
$unk = $runner->runOne('does.not.exist');
$chk('unknown key -> failed result', $unk->ok === false && str_contains((string) $unk->error, 'unknown sweep'));

// ---- SweepResult helpers ----------------------------------------------------
$chk('SweepResult::nothing is ok, zero', SweepResult::nothing()->ok && SweepResult::nothing()->swept === 0);
$chk('SweepResult::fail summary marked FAILED', str_contains(SweepResult::fail('x')->summary(), 'FAILED'));
$chk('SweepResult::ok summary shows details', str_contains(SweepResult::ok(2, ['expired' => 2])->summary(), 'expired=2'));

echo "\n";
if ($fail === 0) {
    echo "OK  {$pass} passed, 0 failed\n";
    exit(0);
}
echo "FAIL  {$pass} passed, {$fail} failed\n";
exit(1);
