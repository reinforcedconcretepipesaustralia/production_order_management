<?php

namespace App\Console\Commands;

use App\Services\Scheduling\LogisticsReconcileService;
use Illuminate\Console\Command;

class LogisticsReconcileCommand extends Command
{
    protected $signature = 'logistics:reconcile
        {--depot= : Limit to one plant id}
        {--sku= : Limit to one sku_master id}
        {--dry-run : List work without rebuilding allocations}';

    protected $description = 'Rebuild FIFO allocations from live stock, orders, and committed production';

    public function handle(LogisticsReconcileService $reconcile): int
    {
        $depotId = $this->option('depot') !== null ? (int) $this->option('depot') : null;
        $skuId = $this->option('sku') !== null ? (int) $this->option('sku') : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $reconcile->reconcile($depotId, $skuId, $dryRun);

        $this->components->info(sprintf(
            '%s %d depot/SKU pair(s).',
            $dryRun ? 'Would reconcile' : 'Reconciled',
            $result->pairCount,
        ));

        if (! $dryRun) {
            $this->line("Deleted {$result->deletedAllocationRows} rebuildable allocation row(s).");
            $this->line("Allocated {$result->totalAllocatedQuantity} pipe(s); {$result->totalUnfulfilledQuantity} still unfulfilled.");
        }

        return self::SUCCESS;
    }
}
