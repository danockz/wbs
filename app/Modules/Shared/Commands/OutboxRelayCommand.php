<?php

declare(strict_types=1);

namespace WBS\Shared\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Relays pending transactional-outbox rows to the queue (SRS FR-ARC-005).
 *
 * Run as a short-interval cron or a loop:
 *   php spark outbox:relay          (one pass)
 *   php spark outbox:relay --loop   (continuous, 1s cadence)
 */
final class OutboxRelayCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'outbox:relay';
    protected $description = 'Relay pending transactional-outbox messages to the queue (exactly-once).';
    protected $usage       = 'outbox:relay [--loop] [--batch=100]';
    protected $options     = [
        '--loop'  => 'Run continuously instead of a single pass.',
        '--batch' => 'Max messages per pass (default 100).',
    ];

    public function run(array $params): int
    {
        $relay = SharedServices::outboxRelay();
        $batch = (int) ($params['batch'] ?? CLI::getOption('batch') ?? 100);
        $loop  = array_key_exists('loop', $params) || (bool) CLI::getOption('loop');

        do {
            $count = $relay->relayBatch($batch);
            if ($count > 0) {
                CLI::write(sprintf('[%s] relayed %d message(s)', date('H:i:s'), $count), 'green');
            }
            if ($loop) {
                sleep(1);
            }
        } while ($loop);

        return EXIT_SUCCESS;
    }
}
