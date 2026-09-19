<?php

namespace App\Console\Commands;

use App\Services\Planning\WorkbookReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Compares the app's capacity figures against a state production planning workbook, line by
 * line, so the model can be trusted before anyone plans on it.
 *
 * Deliberately built before the planner rather than after: it is the difference between showing
 * the planners that the numbers match what they already run, and asking them to take it on faith.
 */
class PlanReconcileCommand extends Command
{
    protected $signature = 'plan:reconcile
        {--file=* : Path to a production planning workbook. Repeatable.}
        {--sheet= : Sheet holding the plan. Defaults to the "Prod  Plan" tab.}
        {--entity= : Tencia entity the workbook covers. Needed when the sheet names only a shift, as Yatala does.}
        {--from= : Only reconcile rows on or after this date (Y-m-d).}
        {--to= : Only reconcile rows on or before this date (Y-m-d).}
        {--tolerance=0 : Pipes of difference to treat as a match.}
        {--limit=25 : Variances to list per workbook.}';

    protected $description = 'Reconcile planned pipes and tonnes against a state production planning workbook';

    public function handle(WorkbookReconciler $reconciler): int
    {
        $files = array_filter((array) $this->option('file'));

        if ($files === []) {
            $this->components->error('Pass at least one --file, e.g. --file="C:\path\VIC Production Planning File.xlsm".');

            return self::INVALID;
        }

        $exitCode = self::SUCCESS;

        foreach ($files as $file) {
            try {
                $exitCode = $this->reconcileOne($reconciler, $file) === self::SUCCESS
                    ? $exitCode
                    : self::FAILURE;
            } catch (RuntimeException $exception) {
                $this->components->error($exception->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function reconcileOne(WorkbookReconciler $reconciler, string $file): int
    {
        $this->components->info('Reconciling '.basename($file));

        // The tab name carries a double space in every state's workbook.
        $sheet = $this->option('sheet') ?: 'Prod  Plan';

        $report = $reconciler->reconcile(
            path: $file,
            sheet: $sheet,
            from: $this->option('from') === null ? null : CarbonImmutable::parse($this->option('from')),
            to: $this->option('to') === null ? null : CarbonImmutable::parse($this->option('to')),
            tolerance: (int) $this->option('tolerance'),
            entityCode: $this->option('entity') === null ? null : strtoupper((string) $this->option('entity')),
        );

        if ($report->rowsRead === 0) {
            $this->components->warn('No planned rows found in that date range.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Layout detected</>', $report->layoutSummary);
        $this->components->twoColumnDetail('Rows with planned production', (string) $report->rowsRead);
        $this->components->twoColumnDetail('Compared', (string) $report->rowsCompared);
        $this->components->twoColumnDetail(
            'Matching planned pipes',
            $report->matched === $report->rowsCompared
                ? "<fg=green>{$report->matched} of {$report->rowsCompared}</>"
                : "<fg=yellow>{$report->matched} of {$report->rowsCompared}</>",
        );

        if ($report->skipped !== []) {
            $this->newLine();
            $this->components->warn('Could not compare some rows — reference data is missing:');

            foreach ($report->skipped as $reason => $count) {
                $this->components->twoColumnDetail("  {$reason}", (string) $count);
            }
        }

        if ($report->variances !== []) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Showing %d of %d variances:',
                min((int) $this->option('limit'), count($report->variances)),
                count($report->variances),
            ));
            $this->newLine();

            $this->table(
                ['Row', 'Date', 'Plant / shift', 'SKU', 'Workbook', 'App', 'Diff', 'Why'],
                collect($report->variances)
                    ->take((int) $this->option('limit'))
                    ->map(fn (array $variance): array => [
                        $variance['row'],
                        $variance['date'],
                        $variance['scope'],
                        $variance['sku'],
                        $variance['workbook'],
                        $variance['app'],
                        sprintf('%+d', $variance['difference']),
                        $variance['explanation'],
                    ])
                    ->all(),
            );
        }

        $this->newLine();

        if ($report->rowsCompared === 0) {
            $this->components->error(
                'Nothing could be compared. The reference data above is missing — import the BOM with plan:import-bom, or sync products once SQL is reachable.',
            );

            return self::FAILURE;
        }

        if ($report->isReconciled()) {
            $this->components->info('Reconciled: every comparable row matches the workbook.');

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            '%d of %d comparable rows differ from the workbook.',
            count($report->variances),
            $report->rowsCompared,
        ));

        return self::FAILURE;
    }
}
