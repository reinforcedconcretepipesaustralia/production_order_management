<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\SalesHistorySync;
use App\Services\Sync\SyncResult;

class SyncSalesHistoryCommand extends SyncCommand
{
    protected $signature = 'sync:sales-history {--months=24 : How far back to load.}';

    protected $description = 'Count live invoiced sales history. It is read from Tencia when a plan is built and is not stored.';

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
        $this->sync->months((int) $this->option('months'));

        return $this->sync->result();
    }
}
