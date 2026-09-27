<?php

declare(strict_types=1);

namespace WBS\Events\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Events\Config\Services as EventServices;

/**
 * Auto-completes published events that have finished (gap L3 — close automation).
 *
 * Reuses EventService::complete() (the same path as the manual button), so the
 * completed / completed_no_attendance decision, the completed_at stamp and the
 * `event.completed` domain event are produced through one code path. The sweep is
 * config-gated (events.autoclose.enabled, DEFAULT OFF) per group, and only closes
 * an event once `--grace` hours have passed since its scheduled finish. Idempotent
 * (complete()'s terminal status write guards a re-run), so it is safe on a cron.
 *
 *   php spark events:close-due --org=<uuid> [--grace=6]
 *   php spark events:close-due --all [--grace=12]
 */
final class EventCloseDueCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'events:close-due';
    protected $description = 'Auto-complete published events that finished over N hours ago (config-gated, default off).';
    protected $usage       = 'events:close-due [--org=UUID] [--all] [--grace=6]';
    protected $options     = [
        '--org'   => 'Organization id to process.',
        '--all'   => 'Process every organization.',
        '--grace' => 'Hours to wait past the scheduled finish before closing (default 6).',
    ];

    public function run(array $params): int
    {
        $closer = EventServices::eventCloser();
        $grace  = (int) ($params['grace'] ?? CLI::getOption('grace') ?? 6);
        $grace  = $grace >= 0 ? $grace : 6;
        $all    = array_key_exists('all', $params) || (bool) CLI::getOption('all');

        if ($all) {
            $r = $closer->processDueClosures(null, $grace);
            CLI::write($this->summary('all organizations', $grace, $r), 'green');

            return EXIT_SUCCESS;
        }

        $org = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
        if ($org === '') {
            CLI::error('Provide --org=UUID or --all.');

            return EXIT_ERROR;
        }

        $r = $closer->processDueClosures($org, $grace);
        CLI::write($this->summary('org ' . $org, $grace, $r), 'green');

        return EXIT_SUCCESS;
    }

    /** @param array{scanned:int, completed:int, skipped_gated:int, no_attendance:int} $r */
    private function summary(string $scope, int $grace, array $r): string
    {
        return sprintf(
            '%s: scanned %d finished event(s), auto-completed %d (%d with no attendance), skipped %d gated-off (grace %dh).',
            ucfirst($scope),
            $r['scanned'],
            $r['completed'],
            $r['no_attendance'],
            $r['skipped_gated'],
            $grace,
        );
    }
}
