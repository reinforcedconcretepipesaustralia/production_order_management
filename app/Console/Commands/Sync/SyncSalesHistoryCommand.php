<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\SalesHistorySync;
use App\Services\Sync\SyncResult;

class SyncSalesHistoryCommand extends SyncCommand
{
    protected $signature = 'sync:sales-history {--months=24 : How far back to load.}';

    protected $description = 'Import monthly sales history from Tencia for the demand model';

    public function __construct(private SalesHistorySync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'sales history';
    }

    protected function runSync(): SyncResult
    {
        return $this->sync->sync((int) $this->option('months'));
    }
}
