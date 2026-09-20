<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\BomSync;
use App\Services\Sync\SyncResult;

class SyncBomCommand extends SyncCommand
{
    protected $signature = 'sync:bom {--entity= : Limit to one Tencia entity, e.g. RCPVIC.}';

    protected $description = 'Import steel and concrete per pipe from Tencia bills of material';

    public function __construct(private BomSync $sync)
    {
        parent::__construct();
    }

    protected function subject(): string
    {
        return 'bills of material';
    }

    protected function runSync(): SyncResult
    {
        $entity = trim((string) $this->option('entity'));

        return $this->sync->sync($entity === '' ? null : strtoupper($entity));
    }
}
