<?php

declare(strict_types=1);

namespace WBS\Geo\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Geo\Config\Services as GeoServices;

/**
 * Import public geo reference data (dr5hn countries-states-cities / GeoDB) from
 * JSON files, preserving source ids and upserting idempotently (SRS §8.1).
 *
 * Import in hierarchy order so foreign keys resolve:
 *   regions → subregions → countries → states → cities → towns_villages
 *
 *   php spark geo:import regions      /data/regions.json     --version=2024.1 --source=dr5hn
 *   php spark geo:import countries    /data/countries.json   --version=2024.1
 *   php spark geo:import cities       /data/cities.json       --version=2024.1
 *
 * Or import a whole directory of {dataset}.json files in the correct order:
 *   php spark geo:import --dir=/data --version=2024.1 --source=dr5hn
 */
final class GeoImportCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'geo:import';
    protected $description = 'Import geo reference data (regions…towns_villages) from JSON, idempotently.';
    protected $usage       = 'geo:import [dataset] [path] [--dir=DIR] [--version=TAG] [--source=NAME]';
    protected $arguments   = [
        'dataset' => 'One of: regions|subregions|countries|states|cities|towns_villages',
        'path'    => 'Path to the dataset JSON file (a top-level array).',
    ];
    protected $options = [
        '--dir'     => 'Import every {dataset}.json found in DIR, in hierarchy order.',
        '--version' => 'Source version/tag recorded for provenance (default: unversioned).',
        '--source'  => 'Attribution/license string recorded for provenance.',
    ];

    /** Hierarchy order — parents before children so FKs resolve. */
    private const ORDER = ['regions', 'subregions', 'countries', 'states', 'cities', 'towns_villages'];

    public function run(array $params): int
    {
        $import  = GeoServices::geoImport();
        $version = (string) (CLI::getOption('version') ?: 'unversioned');
        $source  = (string) (CLI::getOption('source') ?: 'unattributed');
        $dir     = CLI::getOption('dir');

        $jobs = [];
        if (is_string($dir) && $dir !== '') {
            foreach (self::ORDER as $ds) {
                foreach (["{$dir}/{$ds}.json"] as $candidate) {
                    if (is_readable($candidate)) {
                        $jobs[] = [$ds, $candidate];
                    }
                }
            }
            if ($jobs === []) {
                CLI::error("No {dataset}.json files found in {$dir}.");

                return EXIT_ERROR;
            }
        } else {
            $dataset = (string) ($params[0] ?? '');
            $path    = (string) ($params[1] ?? '');
            if ($dataset === '' || $path === '') {
                CLI::error('Usage: geo:import <dataset> <path>  OR  geo:import --dir=DIR');

                return EXIT_ERROR;
            }
            $jobs[] = [$dataset, $path];
        }

        $failed = 0;
        foreach ($jobs as [$dataset, $path]) {
            CLI::write("Importing {$dataset} from {$path} …", 'yellow');
            $result = $import->importFile($dataset, $path, ['version' => $version, 'source' => $source]);

            if ($result->ok) {
                $d = $result->data;
                if (($d['status'] ?? null) === 'already_applied') {
                    CLI::write("  {$dataset}: already applied (idempotent no-op).", 'cyan');
                } else {
                    CLI::write(sprintf(
                        '  %s: processed %d, upserted %d, skipped %d.',
                        $dataset,
                        (int) ($d['processed'] ?? 0),
                        (int) ($d['upserted'] ?? 0),
                        (int) ($d['skipped'] ?? 0),
                    ), 'green');
                }
            } else {
                $failed++;
                CLI::error(sprintf('  %s: %s (%s)', $dataset, $result->message, $result->code));
            }
        }

        return $failed === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
