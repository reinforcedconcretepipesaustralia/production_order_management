<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductSync;
use App\Services\Sync\SyncResult;

class SyncProductsCommand extends SyncCommand
{
    protected $signature = 'sync:products {--entity= : Limit to one Tencia entity, e.g. RCPVIC.}';

    protected $description = 'Refresh the planning overlay from dbo.vwSTK_MASTER';

    public function __construct(private ProductSync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'products';
    }

    protected function runSync(): SyncResult
    {
        return $this->sync->sync($this->entity());
    }

    private function entity(): ?string
    {
        $entity = trim((string) $this->option('entity'));

        return $entity === '' ? null : strtoupper($entity);
    }
}
