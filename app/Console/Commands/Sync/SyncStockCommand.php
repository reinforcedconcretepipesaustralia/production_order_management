<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\StockSync;
use App\Services\Sync\SyncResult;

class SyncStockCommand extends SyncCommand
{
    protected $signature = 'sync:stock';

    protected $description = 'Import stock on hand per plant from Tencia, split prime/rework/quarantine';

    public function __construct(private StockSync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'stock on hand';
    }

    protected function runSync(): SyncResult
    {
        return $this->sync->sync();
    }
}
