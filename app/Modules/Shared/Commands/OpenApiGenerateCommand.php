<?php

declare(strict_types=1);

namespace WBS\Shared\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use WBS\Shared\Support\OpenApiGenerator;

/**
 * Regenerate the static OpenAPI spec at public/openapi.json from the live route
 * table (SRS FR-ARC-001/002).
 *
 *   php spark openapi:generate
 *
 * Run this in CI / at build time after routes change. ApiDocsController serves
 * the generated file (falling back to on-the-fly generation if it is missing).
 */
final class OpenApiGenerateCommand extends BaseCommand
{
    protected $group       = 'WBS';
    protected $name        = 'openapi:generate';
    protected $description = 'Generate public/openapi.json from the canonical route table.';
    protected $usage       = 'openapi:generate [--out=path]';
    protected $options     = ['--out' => 'Output path (default: public/openapi.json).'];

    public function run(array $params): int
    {
        $out  = (string) ($params['out'] ?? CLI::getOption('out') ?? (FCPATH . 'openapi.json'));
        $spec = (new OpenApiGenerator())->generate();

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            CLI::error('Failed to encode OpenAPI spec: ' . json_last_error_msg());

            return EXIT_ERROR;
        }

        if (file_put_contents($out, $json) === false) {
            CLI::error('Failed to write ' . $out);

            return EXIT_ERROR;
        }

        $ops = 0;
        foreach ($spec['paths'] as $verbs) {
            $ops += count($verbs);
        }
        CLI::write(sprintf(
            'Wrote %s — %d paths, %d operations, %d tags, %d permissions.',
            $out,
            count($spec['paths']),
            $ops,
            count($spec['tags']),
            count($spec['x-permissions']),
        ), 'green');

        return EXIT_SUCCESS;
    }
}
