<?php

declare(strict_types=1);

namespace WBS\AccessControl\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\AccessControl\Config\Services as AccessControlServices;

/**
 * Expire lapsed access grants and requests (SRS FR-ACL-004: expiry) plus
 * break-glass emergency sessions (SRS FR-ACL-006: automatic expiry).
 *
 * Deactivates role_assignments past their effective_to, marks the owning
 * approved requests expired, and flips lapsed break-glass sessions to "expired"
 * (leaving them pending their mandatory post-use review). Idempotent — safe to
 * run on a schedule (e.g. hourly cron). The PDP also filters expired grants and
 * sessions at decision time, so this command is a housekeeping/consistency pass
 * rather than a correctness gate.
 *
 *   php spark acl:expire [--org=UUID]
 */
final class AccessExpireCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'acl:expire';
    protected $description = 'Expire lapsed access grants/requests past their effective_to.';
    protected $usage       = 'acl:expire [--org=UUID]';
    protected $options     = [
        '--org' => 'Restrict to a single organization id.',
    ];

    public function run(array $params): int
    {
        $org = (string) ($params['org'] ?? CLI::getOption('org') ?? '');
        $orgArg = $org !== '' ? $org : null;

        $result = AccessControlServices::accessRequests()->expireLapsed($orgArg);
        if ($result->failed()) {
            CLI::error('Expiry pass failed: ' . (string) $result->message);

            return EXIT_ERROR;
        }

        // Break-glass sessions past their narrow TTL (FR-ACL-006).
        $bg = AccessControlServices::breakGlass()->expireLapsed($orgArg);
        if ($bg->failed()) {
            CLI::error('Break-glass expiry failed: ' . (string) $bg->message);

            return EXIT_ERROR;
        }

        CLI::write('Access expiry pass complete as of ' . (string) ($result->data['as_of'] ?? 'now') . '.', 'green');
        CLI::write('Break-glass sessions expired: ' . (string) ($bg->data['expired'] ?? 0) . '.', 'green');

        return EXIT_SUCCESS;
    }
}
