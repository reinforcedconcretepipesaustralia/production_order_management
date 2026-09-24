<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductionHistorySync;
use App\Services\Sync\SyncResult;

class SyncProductionHistoryCommand extends SyncCommand
{
    protected $signature = 'sync:production-history {--months=24 : How far back to load.}';

    protected $description = 'Count live closed production runs. They are read from Tencia when needed and are not stored.';

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
        $this->sync->runs((int) $this->option('months'));

        return $this->sync->result();
    }
}
