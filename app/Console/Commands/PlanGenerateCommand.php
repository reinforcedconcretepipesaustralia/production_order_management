<?php

namespace App\Console\Commands;

use App\Models\Depot;
use App\Models\PlanRun;
use App\Services\Planning\PlanGenerator;
use App\Services\Planning\PlanNarrator;
use App\Services\Planning\PlanRequest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PlanGenerateCommand extends Command
{
    protected $signature = 'plan:generate
        {--from= : First day of the plan (Y-m-d). Defaults to tomorrow.}
        {--to= : Last day of the plan (Y-m-d). Defaults to six months out.}
        {--depot=* : Plant codes to plan. Repeatable. Omit for every planning-enabled plant.}
        {--as-of= : Date to take the demand snapshot on. Defaults to the start of the horizon.}
        {--months-cover=1.0 : Months of stock held for a fast mover with no target of its own.}
        {--minimum-run=0.25 : Smallest share of a shift worth starting a new product for.}
        {--max-runs=2 : Products a machine may be set up for in one shift.}
        {--orders-only : Plan order-backed work only, with no safety stock.}
        {--narrate : Also write a plain-English explanation of the plan.}';

    protected $description = 'Generate a production plan across the plants';

    public function handle(PlanGenerator $generator): int
    {
        $from = CarbonImmutable::parse($this->option('from') ?: now()->addDay()->toDateString());
        $to = CarbonImmutable::parse($this->option('to') ?: $from->addMonths(6)->toDateString());

        $depotIds = $this->depotIds();

        if ($depotIds === false) {
            return self::INVALID;
        }

        $this->components->info(sprintf(
            'Planning %s to %s',
            $from->format('j M Y'),
            $to->format('j M Y'),
        ));

        $run = $generator->generate(new PlanRequest(
            from: $from,
            to: $to,
            depotIds: $depotIds,
            asOf: $this->option('as-of') === null ? null : CarbonImmutable::parse($this->option('as-of')),
            targetMonthsOnHand: (float) $this->option('months-cover'),
            minimumRunFraction: (float) $this->option('minimum-run'),
            maxRunsPerShift: (int) $this->option('max-runs'),
            includeReplenishment: ! $this->option('orders-only'),
            generatedBy: null,
        ));

        $metrics = $run->metrics ?? [];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Plan run</>', "#{$run->id} ({$run->status})");
        $this->components->twoColumnDetail('Lines', (string) ($metrics['lines'] ?? 0));
        $this->components->twoColumnDetail('Planned pipes', number_format($metrics['planned_eaches'] ?? 0));
        $this->components->twoColumnDetail('Planned tonnes', number_format($metrics['planned_tonnes'] ?? 0, 1));

        $this->renderDepotTable($metrics['by_depot'] ?? []);
        $this->renderMonthTable($metrics['by_month'] ?? []);

        $short = collect($metrics['by_depot'] ?? [])->sum(fn (array $row): int => $row['unmet_order_eaches'] ?? 0);

        $this->newLine();

        if ($short > 0) {
            $this->components->warn(number_format($short).' pipes of ordered work could not be fitted in this horizon.');
        } else {
            $this->components->info('Every order in the horizon is covered.');
        }

        if ($this->option('narrate')) {
            $this->explain($run);
        }

        return self::SUCCESS;
    }

    /**
     * Written after the plan is reported, and never allowed to fail the run: the narrative is
     * advisory text and the plan stands without it.
     */
    private function explain(PlanRun $run): void
    {
        if (blank(config('claude.api_key'))) {
            $this->components->warn('ANTHROPIC_API_KEY is not set, so no narrative was written.');

            return;
        }

        $narrative = app(PlanNarrator::class)->narrate($run);

        if ($narrative === null) {
            $this->components->warn('Could not write a narrative. The plan is unaffected.');

            return;
        }

        $this->newLine();
        $this->line($narrative);
        $this->newLine();
    }

    /**
     * @return list<int>|null|false False signals an unknown plant code.
     */
    private function depotIds(): array|null|false
    {
        $codes = $this->option('depot');

        if ($codes === []) {
            return null;
        }

        /** @var Collection<int, Depot> $depots */
        $depots = Depot::query()->whereIn('code', $codes)->get();
        $missing = array_diff($codes, $depots->pluck('code')->all());

        if ($missing !== []) {
            $this->components->error('Unknown plant codes: '.implode(', ', $missing));

            return false;
        }

        return $depots->pluck('id')->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $byDepot
     */
    private function renderDepotTable(array $byDepot): void
    {
        if ($byDepot === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['Plant', 'Lines', 'Pipes', 'Tonnes', 'Shifts used', 'Unmet orders', 'SKUs short'],
            collect($byDepot)->map(fn (array $row, string $code): array => [
                $code,
                $row['lines'],
                number_format($row['planned_eaches']),
                number_format($row['planned_tonnes'], 1),
                "{$row['shift_slots_used']} / {$row['shift_slots']}",
                $row['unmet_order_eaches'] > 0
                    ? '<fg=yellow>'.number_format($row['unmet_order_eaches']).'</>'
                    : '0',
                count($row['skus_short']) > 0 ? (string) count($row['skus_short']) : '—',
            ])->values()->all(),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $byMonth
     */
    private function renderMonthTable(array $byMonth): void
    {
        if ($byMonth === []) {
            return;
        }

        ksort($byMonth);

        $this->table(
            ['Month', 'Pipes', 'Tonnes'],
            collect($byMonth)->map(fn (array $row, string $month): array => [
                CarbonImmutable::parse($month.'-01')->format('M Y'),
                number_format($row['eaches']),
                number_format($row['tonnes'], 1),
            ])->values()->all(),
        );
    }
}
