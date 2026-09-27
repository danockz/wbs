<?php

declare(strict_types=1);

namespace WBS\Gamification\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use WBS\Gamification\Config\Services as GamificationServices;

/**
 * Rebuild the group_point_rollup ranking cache from the immutable ledger
 * (design doc Part B.4 step 4).
 *
 * group_point_rollup is a pure, derived function of point_ledger + group_closure,
 * so it can always be dropped and recomputed. This command is used for:
 *   - BACKFILL after migration 000045 added group attribution columns (existing
 *     ledger rows have group_id = NULL until re-attributed, so a rebuild after
 *     backfill populates ancestor standings);
 *   - DISASTER RECOVERY if the cache is ever suspected inconsistent.
 *
 *   php spark gamification:rebuild-rollup --org=<uuid> [--season=<uuid>]
 *   php spark gamification:rebuild-rollup --all
 */
final class RebuildRollupCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'gamification:rebuild-rollup';
    protected $description = 'Recompute group_point_rollup from the ledger (idempotent, rebuildable cache).';
    protected $usage       = 'gamification:rebuild-rollup [--org=UUID] [--season=UUID] [--all] [--backfill]';
    protected $options     = [
        '--org'      => 'Organization id to rebuild.',
        '--season'   => 'Restrict to one season id (default: all seasons in the org).',
        '--all'      => 'Rebuild every organization.',
        '--backfill' => 'First backfill attribution columns on historical ledger rows (Part B.7 step 2), then rebuild.',
    ];

    public function run(array $params): int
    {
        $rollup   = GamificationServices::rollup();
        $season   = (string) ($params['season'] ?? CLI::getOption('season') ?? '');
        $season   = $season !== '' ? $season : null;
        $all      = array_key_exists('all', $params) || (bool) CLI::getOption('all');
        $backfill = array_key_exists('backfill', $params) || (bool) CLI::getOption('backfill');

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
            if ($backfill) {
                $bf = $rollup->backfillLedgerAttribution($orgId);
                CLI::write(sprintf(
                    'Org %s: backfilled attribution — %d contribution group/project, %d amount rows.',
                    $orgId,
                    $bf['contribution_group'],
                    $bf['contribution_amount'],
                ), 'yellow');
            }
            $applied = $rollup->rebuild($orgId, $season);
            CLI::write(sprintf('Org %s: rebuilt rollup from %d final ledger entries.', $orgId, $applied), 'green');
        }

        return EXIT_SUCCESS;
    }
}
