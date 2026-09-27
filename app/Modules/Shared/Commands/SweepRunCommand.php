<?php

declare(strict_types=1);

namespace WBS\Shared\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Unified sweep runner entry point (Theme C).
 *
 * Replaces the per-module cron entries (acl:expire, events:close-due,
 * events:reminders-due, …) with ONE scheduler that drives every registered
 * SweepContract on a common cadence — bounded batch, idempotent, per-org,
 * advisory-locked, with a structured "swept N" heartbeat per sweep. The old
 * per-module commands remain runnable for targeted operator use; this is the
 * scheduled entry point.
 *
 *   php spark sweep:run                       # every sweep, all orgs
 *   php spark sweep:run --org=<uuid>          # every sweep, one org
 *   php spark sweep:run --only=acl.expire,events.close-due
 *   php spark sweep:run --list                # list registered sweeps
 *   php spark sweep:run --grace=12 --hours=48 # pass-through options
 */
final class SweepRunCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'sweep:run';
    protected $description = 'Run the registered lifecycle sweeps (expiry, reminders, recompute, …) on one cadence.';
    protected $usage       = 'sweep:run [--org=UUID] [--only=key,key] [--list] [--grace=N] [--hours=N] [--limit=N]';
    protected $options     = [
        '--org'   => 'Restrict to a single organization id (default: all).',
        '--only'  => 'Comma-separated sweep keys to run (default: all registered).',
        '--list'  => 'List registered sweeps and exit.',
        '--grace' => 'Grace hours for close-due sweeps (default 6).',
        '--hours' => 'Look-ahead hours for reminder sweeps (default 24).',
        '--limit' => 'Per-sweep batch bound (default per-sweep).',
    ];

    public function run(array $params): int
    {
        $registry = SharedServices::sweepRegistry();

        if (array_key_exists('list', $params) || (bool) CLI::getOption('list')) {
            CLI::write('Registered sweeps:', 'yellow');
            foreach ($registry->all() as $key => $sweep) {
                CLI::write(sprintf('  %-32s %s', $key, $sweep->description()));
            }

            return EXIT_SUCCESS;
        }

        $org  = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
        $orgArg = $org !== '' ? $org : null;

        $onlyRaw = (string) ($params['only'] ?? CLI::getOption('only') ?? '');
        $only = null;
        if ($onlyRaw !== '') {
            $only = array_values(array_filter(array_map('trim', explode(',', $onlyRaw))));
            foreach ($only as $k) {
                if (! $registry->has($k)) {
                    CLI::error("Unknown sweep key: {$k}");
                    CLI::write('Run `php spark sweep:run --list` to see valid keys.');

                    return EXIT_ERROR;
                }
            }
        }

        $options = [];
        foreach (['grace', 'hours', 'limit'] as $opt) {
            $v = $params[$opt] ?? CLI::getOption($opt);
            if ($v !== null && $v !== '') {
                $options[$opt] = (int) $v;
            }
        }

        $runner  = SharedServices::sweepRunner();
        $summary = $runner->runAll($orgArg, $only, $options);

        foreach ($summary['results'] as $key => $res) {
            $colour = $res->ok ? ($res->skipped ? 'yellow' : 'green') : 'red';
            CLI::write(sprintf('  %-32s %s', $key, $res->summary()), $colour);
        }

        CLI::write(sprintf(
            'Sweep pass complete: ran %d, swept %d, skipped %d, failed %d (org %s).',
            $summary['ran'],
            $summary['swept'],
            $summary['skipped'],
            $summary['failed'],
            $orgArg ?? 'ALL',
        ), $summary['failed'] > 0 ? 'red' : 'green');

        return $summary['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
