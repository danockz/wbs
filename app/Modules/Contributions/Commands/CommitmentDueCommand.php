<?php

declare(strict_types=1);

namespace WBS\Contributions\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use WBS\Contributions\Config\Services as ContributionServices;

/**
 * Sends due-date reminders for recurring giving commitments (SRS FR-VBCS).
 *
 * Charges nothing — commitments are reminder-only. Idempotency is provided by
 * next_due_at advancement + the outbox relay's dedupe, so re-running is safe.
 *
 *   php spark contributions:commitments-due --org=<uuid>
 *   php spark contributions:commitments-due --all
 */
final class CommitmentDueCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'contributions:commitments-due';
    protected $description = 'Stage reminders for due recurring giving commitments (no charging).';
    protected $usage       = 'contributions:commitments-due [--org=UUID] [--all]';
    protected $options     = [
        '--org' => 'Organization id to process.',
        '--all' => 'Process every organization.',
    ];

    public function run(array $params): int
    {
        $service = ContributionServices::commitments();
        $all     = array_key_exists('all', $params) || (bool) CLI::getOption('all');

        if ($all) {
            $count = $service->processDue(null);
            CLI::write(sprintf('Processed %d due commitment(s) across all organizations.', $count), 'green');

            return EXIT_SUCCESS;
        }

        $org = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
        if ($org === '') {
            CLI::error('Provide --org=UUID or --all.');

            return EXIT_ERROR;
        }

        $count = $service->processDue($org);
        CLI::write(sprintf('Org %s: processed %d due commitment(s).', $org, $count), 'green');

        return EXIT_SUCCESS;
    }
}
