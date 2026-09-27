<?php

declare(strict_types=1);

namespace WBS\Gamification\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use WBS\Gamification\Config\Services as GamificationServices;

/**
 * Annual gamification season rollover (SRS FR-GAM-006).
 *
 * Scheduled once at the organization-wide rollover instant (default 1 Jan,
 * org timezone). Idempotent + lock-protected inside SeasonService, so running it
 * twice (or on multiple nodes) closes the season exactly once.
 *
 *   php spark gamification:rollover --org=<uuid> --tz=Africa/Accra
 *   php spark gamification:rollover --all        (every organization)
 */
final class SeasonRolloverCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'gamification:rollover';
    protected $description = 'Close the current gamification season and open the next (idempotent).';
    protected $usage       = 'gamification:rollover [--org=UUID] [--tz=IANA] [--all] [--force]';
    protected $options     = [
        '--org'   => 'Organization id to roll over.',
        '--tz'    => 'IANA timezone for the season boundary (default UTC).',
        '--all'   => 'Roll over every organization.',
        '--force' => 'Reclaim a wedged transition (failed, or running past its lease) and retry.',
    ];

    public function run(array $params): int
    {
        $seasons = GamificationServices::seasons();
        $tz      = (string) ($params['tz'] ?? CLI::getOption('tz') ?? 'UTC');
        $all     = array_key_exists('all', $params) || (bool) CLI::getOption('all');
        $force   = array_key_exists('force', $params) || (bool) CLI::getOption('force');

        $orgIds = [];
        if ($all) {
            $db     = Database::connect();
            $orgIds = array_column($db->table('organizations')->select('id')->get()->getResultArray(), 'id');
        } else {
            $org = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
            if ($org === '') {
                CLI::error('Provide --org=UUID or --all.');

                return EXIT_ERROR;
            }
            $orgIds = [$org];
        }

        foreach ($orgIds as $orgId) {
            $result = $seasons->rollover($orgId, $tz, $force);
            if ($result->ok) {
                CLI::write(sprintf('Org %s: %s', $orgId, $result->data['status'] ?? 'ok'), 'green');
            } else {
                CLI::write(sprintf('Org %s: %s (%s)', $orgId, $result->message, $result->code), 'red');
            }
        }

        return EXIT_SUCCESS;
    }
}
