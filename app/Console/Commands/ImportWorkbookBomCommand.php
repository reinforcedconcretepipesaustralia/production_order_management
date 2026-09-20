<?php

namespace App\Console\Commands;

use App\Services\Planning\WorkbookBomImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads products, machine run rates and steel-per-pipe from a state planning workbook.
 *
 * Products and steel now come from the Tencia sync, so the workbooks are only still needed for the
 * machine rated speeds that no Tencia view carries. Prefer --run-rates-only: a full import run after
 * sync:bom replaces Tencia's steel figures with whatever the workbook last refreshed.
 */
class ImportWorkbookBomCommand extends Command
{
    protected $signature = 'plan:import-bom
        {--file= : Path to a production planning workbook.}
        {--entity= : Tencia entity the workbook covers, e.g. RCPVIC.}
        {--run-rates-only : Take only the rated speeds and leave products and steel to the sync.}
        {--dry-run : Count what would be imported without writing.}';

    protected $description = 'Import machine run rates (and optionally products and steel) from a planning workbook';

    public function handle(WorkbookBomImporter $importer): int
    {
        $file = (string) $this->option('file');
        $entity = strtoupper((string) $this->option('entity'));

        if ($file === '' || $entity === '') {
            $this->components->error('Pass both --file and --entity, e.g. --entity=VIC.');

            return self::INVALID;
        }

        $this->components->info(sprintf(
            '%s %s for entity %s',
            $this->option('dry-run') ? 'Inspecting' : 'Importing',
            basename($file),
            $entity,
        ));

        $runRatesOnly = (bool) $this->option('run-rates-only');

        if (! $runRatesOnly && ! $this->option('dry-run')) {
            $this->components->warn(
                'A full import replaces the steel figures the Tencia sync loaded. '
                .'Pass --run-rates-only to take just the rated speeds, or re-run sync:bom afterwards.',
            );
        }

        try {
            $counts = $importer->import($file, $entity, (bool) $this->option('dry-run'), $runRatesOnly);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($counts as $label => $count) {
            $this->components->twoColumnDetail(ucfirst($label), (string) $count);
        }

        $this->newLine();
        $this->components->info(
            $this->option('dry-run')
                ? 'Dry run — nothing written.'
                : 'Imported. Workbook data is only as current as its last refresh; the Tencia sync supersedes it.',
        );

        return self::SUCCESS;
    }
}
