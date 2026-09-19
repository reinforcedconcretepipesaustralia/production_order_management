<?php

namespace App\Console\Commands;

use App\Services\Planning\OrderBookImporter;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the national outstanding order extract, the one covering every entity in a single file.
 *
 * Preferred over the per-state workbook tabs while the SQL host is unreachable: it carries the
 * delivery date the planner ranks on, and names the entity on every line.
 */
class ImportOrderBookCommand extends Command
{
    protected $signature = 'plan:import-orders
        {--file= : Path to an All Entities Outstanding Orders export.}
        {--keep-existing : Add to the current order book instead of replacing it.}';

    protected $description = 'Import the national outstanding order book from a Tencia extract';

    public function handle(OrderBookImporter $importer): int
    {
        $file = (string) $this->option('file');

        if ($file === '') {
            $this->components->error('Pass --file, e.g. --file="C:\path\All Entities Outstanding Orders.xlsx".');

            return self::INVALID;
        }

        $this->components->info('Importing the order book from '.basename($file));

        try {
            $counts = $importer->import($file, ! $this->option('keep-existing'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($counts as $label => $count) {
            $this->components->twoColumnDetail(ucfirst($label), number_format($count));
        }

        $this->newLine();
        $this->components->info('Imported. Only as current as the extract; the Tencia sync supersedes it.');

        if (($counts['skipped (unknown product)'] ?? 0) > 0) {
            $this->components->warn(
                'Some products are not in the product master. Run plan:import-bom for each state first.',
            );
        }

        return self::SUCCESS;
    }
}
