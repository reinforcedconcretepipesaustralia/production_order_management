<?php

namespace App\Console\Commands;

use App\Services\Planning\WorkbookDemandImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the order book, stock on hand and monthly sales from a 'Pipes to Manufacture' workbook.
 *
 * A stopgap until the Tencia sync runs, so a plan can be generated and reviewed before the SQL
 * host is reachable.
 */
class ImportWorkbookDemandCommand extends Command
{
    protected $signature = 'plan:import-demand
        {--file= : Path to a Pipes to Manufacture workbook.}
        {--entity= : Tencia entity the workbook covers (VIC, NSW or QLD).}
        {--keep-existing : Add to what is already loaded instead of replacing this entity\'s demand.}';

    protected $description = 'Import orders, stock and sales history from a Pipes to Manufacture workbook';

    public function handle(WorkbookDemandImporter $importer): int
    {
        $file = (string) $this->option('file');
        $entity = strtoupper((string) $this->option('entity'));

        if ($file === '' || $entity === '') {
            $this->components->error('Pass both --file and --entity, e.g. --entity=VIC.');

            return self::INVALID;
        }

        $this->components->info('Importing demand from '.basename($file)." for entity {$entity}");

        try {
            $counts = $importer->import($file, $entity, ! $this->option('keep-existing'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($counts as $label => $count) {
            $this->components->twoColumnDetail(ucfirst($label), number_format($count));
        }

        $this->newLine();
        $this->components->info('Imported. Only as current as the workbook\'s last refresh; the Tencia sync supersedes it.');

        return self::SUCCESS;
    }
}
