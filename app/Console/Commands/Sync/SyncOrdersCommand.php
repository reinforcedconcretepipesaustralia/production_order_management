<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\OrderSync;
use App\Services\Sync\SyncResult;

class SyncOrdersCommand extends SyncCommand
{
    protected $signature = 'sync:orders
        {--keep-existing : Add to the current order book instead of replacing it.}';

    protected $description = 'Import the outstanding order book from Tencia';

    public function __construct(private OrderSync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'the order book';
    }

    protected function runSync(): SyncResult
    {
        return $this->sync->sync(! $this->option('keep-existing'));
    }
}
