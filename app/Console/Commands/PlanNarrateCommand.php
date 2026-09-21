<?php

namespace App\Console\Commands;

use App\Models\PlanRun;
use App\Services\Planning\PlanNarrator;
use Illuminate\Console\Command;

/**
 * Writes the plain-English summary onto a plan run, for the review screen.
 *
 * Separate from plan:generate so a plan never waits on an external call, and so a narrative can be
 * rewritten without regenerating the plan underneath it.
 */
class PlanNarrateCommand extends Command
{
    protected $signature = 'plan:narrate
        {--run= : Plan run id. Defaults to the most recent run.}
        {--show-brief : Print what would be sent to Claude, without calling it.}';

    protected $description = 'Write a plain-English explanation of a plan run';

    public function handle(PlanNarrator $narrator): int
    {
        $run = $this->planRun();

        if ($run === null) {
            $this->components->error('No plan run found. Generate one with plan:generate.');

            return self::FAILURE;
        }

        if ($this->option('show-brief')) {
            $this->line(json_encode($narrator->briefFor($run), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (blank(config('claude.api_key'))) {
            $this->components->warn(
                'ANTHROPIC_API_KEY is not set, so the plan has no narrative. Everything else about '
                .'the plan is unaffected — the narrative is advisory text.',
            );

            return self::SUCCESS;
        }

        $this->components->info("Explaining plan run #{$run->id}.");

        $narrative = $narrator->narrate($run);

        if ($narrative === null) {
            $this->components->error('Could not write a narrative. See the log for why.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($narrative);
        $this->newLine();

        return self::SUCCESS;
    }

    private function planRun(): ?PlanRun
    {
        $id = $this->option('run');

        return $id === null
            ? PlanRun::query()->latest('id')->first()
            : PlanRun::query()->find((int) $id);
    }
}
