<?php

declare(strict_types=1);

namespace WBS\Shared\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Messaging\JobRouter;

/**
 * Processes queued jobs (SRS FR-ARC-006/007).
 *
 * Reserves ready jobs, routes them through the JobRouter, and acks/retries with
 * backoff. Handlers are idempotent so at-least-once delivery is safe.
 *
 *   php spark queue:work                (continuous)
 *   php spark queue:work --once         (drain until empty then exit)
 *   php spark queue:work --queue=email
 */
final class QueueWorkCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'queue:work';
    protected $description = 'Reserve and process queued jobs with idempotent handlers.';
    protected $usage       = 'queue:work [--once] [--queue=default] [--sleep=1]';
    protected $options     = [
        '--once'  => 'Drain the queue then exit (do not idle-loop).',
        '--queue' => 'Queue name to consume (default "default").',
        '--sleep' => 'Idle seconds when no job is available (default 1).',
    ];

    public function run(array $params): int
    {
        $queue  = (string) ($params['queue'] ?? CLI::getOption('queue') ?? 'default');
        $once   = array_key_exists('once', $params) || (bool) CLI::getOption('once');
        $sleep  = (int) ($params['sleep'] ?? CLI::getOption('sleep') ?? 1);
        $worker = gethostname() . ':' . getmypid();

        $q      = SharedServices::queue();
        $router = new JobRouter();

        CLI::write(sprintf('Worker %s consuming queue "%s"%s', $worker, $queue, $once ? ' (once)' : ''), 'yellow');

        while (true) {
            $job = $q->reserve($worker, $queue);
            if ($job === null) {
                if ($once) {
                    break;
                }
                sleep(max(1, $sleep));

                continue;
            }

            $payload = json_decode((string) $job['payload'], true) ?: [];
            try {
                $router->dispatch((string) $job['topic'], $payload);
                $q->complete($job['id']);
                CLI::write(sprintf('[%s] done %s (%s)', date('H:i:s'), $job['id'], $job['topic']), 'green');
            } catch (Throwable $e) {
                $q->fail($job['id'], $e->getMessage());
                CLI::write(sprintf('[%s] fail %s (%s): %s', date('H:i:s'), $job['id'], $job['topic'], $e->getMessage()), 'red');
            }
        }

        return EXIT_SUCCESS;
    }
}
