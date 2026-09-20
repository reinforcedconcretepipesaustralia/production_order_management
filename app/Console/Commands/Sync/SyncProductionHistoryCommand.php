<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductionHistorySync;
use App\Services\Sync\SyncResult;

class SyncProductionHistoryCommand extends SyncCommand
{
    protected $signature = 'sync:production-history {--months=24 : How far back to load.}';

    protected $description = 'Import closed production runs from Tencia';

    public function __construct(private ProductionHistorySync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'production history';
    }

    protected function runSync(): SyncResult
    {
        return $this->sync->sync((int) $this->option('months'));
    }
}
