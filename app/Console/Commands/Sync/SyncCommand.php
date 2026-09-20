<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\SyncResult;
use App\Support\Rcpa\RcpaSqlFailureClassifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Shared shape for the Tencia syncs: run, report what landed, and say plainly what was left out.
 *
 * A sync that drops rows quietly is worse than one that fails, so rejects are printed rather than
 * only counted.
 */
abstract class SyncCommand extends Command
{
    abstract protected function runSync(): SyncResult;

    abstract protected function subject(): string;

    public function handle(): int
    {
        $this->components->info('Syncing '.$this->subject().' from Tencia.');

        try {
            $result = $this->runSync();
        } catch (Throwable $exception) {
            $this->components->error('Sync failed: '.RcpaSqlFailureClassifier::reason($exception));
            report($exception);

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($result->counts() as $label => $count) {
            $this->components->twoColumnDetail(ucfirst($label), number_format($count));
        }

        if ($result->rejects() !== []) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%s row(s) were left out. First %d:',
                number_format($result->rejectedTotal()),
                count($result->rejects()),
            ));

            foreach ($result->rejects() as $reject) {
                $this->line('  '.$reject);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
