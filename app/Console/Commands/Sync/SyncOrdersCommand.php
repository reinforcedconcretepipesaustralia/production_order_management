<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\OrderSync;
use App\Services\Sync\SyncResult;

class SyncOrdersCommand extends SyncCommand
{
    protected $signature = 'sync:orders';

    protected $description = 'Count the live order book. It is read from Tencia when a plan is built and is not stored.';

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
        $this->sync->lines();

        return $this->sync->result();
    }
}
