<?php

declare(strict_types=1);

namespace WBS\Identity\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Identity\Config\Services as IdentityServices;

/**
 * Prune server-side sessions (SRS FR-ID session lifetime housekeeping).
 *
 * Revokes any still-active session past its absolute `expires_at`, then
 * hard-deletes rows revoked/expired longer than the retention window so the
 * table stays bounded. `SessionService::active()` already fails closed on
 * expiry/idle at request time, so this is a consistency/cleanup pass rather than
 * a correctness gate. Idempotent — safe to run on a schedule (e.g. hourly cron).
 *
 *   php spark identity:prune-sessions [--retention-days=30]
 */
final class SessionPruneCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'identity:prune-sessions';
    protected $description = 'Revoke expired sessions and delete old revoked rows.';
    protected $usage       = 'identity:prune-sessions [--retention-days=30]';
    protected $options     = [
        '--retention-days' => 'Delete sessions revoked/expired more than N days ago (default 30).',
    ];

    public function run(array $params): int
    {
        $days = (int) ($params['retention-days'] ?? CLI::getOption('retention-days') ?? 30);
        $days = $days > 0 ? $days : 30;

        $result = IdentityServices::sessions()->prune($days);

        CLI::write('Sessions expired (revoked): ' . (string) $result['expired'], 'green');
        CLI::write('Old session rows deleted:   ' . (string) $result['deleted'], 'green');

        return EXIT_SUCCESS;
    }
}
