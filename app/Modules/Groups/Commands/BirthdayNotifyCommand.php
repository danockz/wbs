<?php

declare(strict_types=1);

namespace WBS\Groups\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Notifications\Config\Services as NotificationServices;

/**
 * php spark birthdays:notify --org=<uuid> | --all
 *
 * Stages in-app birthday notices for everyone who would see that person on
 * the hub TODAY (including the person themselves). Idempotent via
 * notification_deliveries.dedupe_key.
 */
final class BirthdayNotifyCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'birthdays:notify';
    protected $description = 'Stage in-app birthday notices for today (org timezone).';
    protected $usage       = 'birthdays:notify --org=<uuid> | --all';
    protected $options     = [
        '--org' => 'Organization UUID to process.',
        '--all' => 'Process every organization.',
    ];

    public function run(array $params): int
    {
        $all   = array_key_exists('all', $params) || (bool) CLI::getOption('all');
        $orgId = trim((string) ($params['org'] ?? CLI::getOption('org') ?? ''));
        if (! $all && $orgId === '') {
            CLI::error('Pass --org=<uuid> or --all.');

            return EXIT_ERROR;
        }

        $ids = $all ? $this->allOrgIds() : [$orgId];
        $svc = GroupServices::birthdays();
        $n   = NotificationServices::notifications();
        $subjects = 0;
        $notices  = 0;
        foreach ($ids as $id) {
            $stats = $svc->notifyToday($id, $n);
            $subjects += (int) ($stats['subjects'] ?? 0);
            $notices  += (int) ($stats['notices'] ?? 0);
        }
        CLI::write("birthday notices: {$notices} staged across {$subjects} viewer/subject pairs.");

        return EXIT_SUCCESS;
    }

    /** @return list<string> */
    private function allOrgIds(): array
    {
        $rows = Database::connect()->table('organizations')->select('id')->get()->getResultArray();
        $ids  = [];
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
