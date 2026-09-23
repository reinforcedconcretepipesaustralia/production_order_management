<?php

namespace App\Console\Commands\Sync;

use Illuminate\Console\Command;

/**
 * Every Tencia sync, in dependency order. Products come first because everything else resolves
 * against the product master, and a run that cannot reach the server stops rather than leaving the
 * later imports to fail one at a time.
 */
class SyncAllCommand extends Command
{
    protected $signature = 'sync:all {--months=24 : How far back to load history.}';

    protected $description = 'Run every Tencia sync in dependency order';

    /** In order: later syncs resolve products loaded by earlier ones. */
    private const ORDER = [
        'sync:products' => [],
        'sync:bom' => [],
        'sync:stock' => [],
        'sync:sales-history' => ['--months'],
        'sync:production-history' => ['--months'],
    ];

    public function handle(): int
    {
        foreach (self::ORDER as $command => $passthrough) {
            $arguments = [];

            foreach ($passthrough as $option) {
                $arguments[$option] = $this->option(ltrim($option, '-'));
            }

            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->components->error("[$command] failed, so the syncs after it were not run.");

                return self::FAILURE;
            }
        }

        $this->components->info('All syncs complete. Generate a plan with plan:generate.');

        return self::SUCCESS;
    }
}
