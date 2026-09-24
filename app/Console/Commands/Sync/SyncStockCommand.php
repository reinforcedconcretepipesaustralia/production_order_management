<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\StockSync;
use App\Services\Sync\SyncResult;

class SyncStockCommand extends SyncCommand
{
    protected $signature = 'sync:stock';

    protected $description = 'Count live stock on hand. It is read from Tencia when a plan is built and is not stored.';

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
        $this->sync->positions();

        return $this->sync->result();
    }
}
