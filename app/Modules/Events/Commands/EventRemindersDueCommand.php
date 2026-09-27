<?php

declare(strict_types=1);

namespace WBS\Events\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Events\Config\Services as EventServices;

/**
 * Stages pre-event reminders for published events starting soon (gap G3).
 *
 * Sends nothing itself — it stages notifications through the Notifications module
 * (idempotent send + outbox). Idempotency is provided by the events.last_reminded_at
 * watermark + the notification dedupe key, so re-running is safe (a second run in
 * the same window is a no-op).
 *
 *   php spark events:reminders-due --org=<uuid> [--hours=24]
 *   php spark events:reminders-due --all [--hours=48]
 */
final class EventRemindersDueCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'events:reminders-due';
    protected $description = 'Stage pre-event reminders for published events starting within N hours (no sending).';
    protected $usage       = 'events:reminders-due [--org=UUID] [--all] [--hours=24]';
    protected $options     = [
        '--org'   => 'Organization id to process.',
        '--all'   => 'Process every organization.',
        '--hours' => 'Look-ahead window in hours (default 24).',
    ];

    public function run(array $params): int
    {
        $notifier = EventServices::eventNotifier();
        $hours    = (int) ($params['hours'] ?? CLI::getOption('hours') ?? 24);
        $hours    = $hours > 0 ? $hours : 24;
        $all      = array_key_exists('all', $params) || (bool) CLI::getOption('all');

        if ($all) {
            $r = $notifier->processDueReminders(null, $hours);
            CLI::write(sprintf(
                'Reminded %d event(s), staged %d notification(s) across all organizations (window %dh).',
                $r['events'],
                $r['notifications'],
                $hours,
            ), 'green');

            return EXIT_SUCCESS;
        }

        $org = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
        if ($org === '') {
            CLI::error('Provide --org=UUID or --all.');

            return EXIT_ERROR;
        }

        $r = $notifier->processDueReminders($org, $hours);
        CLI::write(sprintf(
            'Org %s: reminded %d event(s), staged %d notification(s) (window %dh).',
            $org,
            $r['events'],
            $r['notifications'],
            $hours,
        ), 'green');

        return EXIT_SUCCESS;
    }
}
