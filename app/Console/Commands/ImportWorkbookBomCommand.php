<?php

namespace App\Console\Commands;

use App\Services\Planning\WorkbookBomImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads products, machine run rates and steel-per-pipe from a state planning workbook.
 *
 * A stopgap while the SQL host is only reachable on the corporate network. The Tencia sync will
 * supersede it, and anything imported here can be corrected by that sync.
 */
class ImportWorkbookBomCommand extends Command
{
    protected $signature = 'plan:import-bom
        {--file= : Path to a production planning workbook.}
        {--entity= : Tencia entity the workbook covers (VIC, NSW or QLD).}
        {--dry-run : Count what would be imported without writing.}';

    protected $description = 'Import products, run rates and steel per pipe from a planning workbook';

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

        try {
            $counts = $importer->import($file, $entity, (bool) $this->option('dry-run'));
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
