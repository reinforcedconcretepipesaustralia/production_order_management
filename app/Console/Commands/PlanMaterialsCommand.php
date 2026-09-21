<?php

namespace App\Console\Commands;

use App\Models\Depot;
use App\Models\MaterialRequirement;
use App\Models\PlanRun;
use App\Models\SteelForecast;
use App\Services\Planning\MaterialForecastService;
use App\Services\Scheduling\SteelPlanningService;
use App\Services\Scheduling\SteelStockPosition;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What a plan run has to be bought and batched for, and what to order.
 *
 * Replaces the workbook's Steel Forecast tab. The requirement comes from the plan; the stock it is
 * netted against comes from the buyer's stocktake rows in steel_forecasts, because coils on hand and
 * on order are counted by a person, not held in Tencia by gauge.
 */
class PlanMaterialsCommand extends Command
{
    protected $signature = 'plan:materials
        {--run= : Plan run id. Defaults to the most recent run.}
        {--depot= : Limit the wire order advice to one plant code.}
        {--recompute : Recompute from the run\'s schedule lines before reporting.}';

    protected $description = 'Report the steel, concrete and mix a plan run requires';

    public function handle(MaterialForecastService $forecasts, SteelPlanningService $steel): int
    {
        $run = $this->planRun();

        if ($run === null) {
            $this->components->error('No plan run found. Generate one with plan:generate.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Materials for plan run #%d (%s), %s to %s',
            $run->id,
            $run->status,
            $run->horizon_from->format('j M Y'),
            $run->horizon_to->format('j M Y'),
        ));

        if ($this->option('recompute')) {
            $forecasts->forPlanRun($run);
        }

        $requirements = MaterialRequirement::query()->where('plan_run_id', $run->id)->get();

        if ($requirements->isEmpty()) {
            $this->components->warn('This run has no material requirements. Re-run with --recompute.');

            return self::SUCCESS;
        }

        $this->reportWire($requirements);
        $this->reportConcrete($requirements);
        $this->reportWeeks($requirements);
        $this->reportOrderAdvice($requirements, $steel);

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, MaterialRequirement>  $requirements
     */
    private function reportWire($requirements): void
    {
        $wire = $requirements->where('kind', MaterialRequirement::KIND_WIRE);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Wire required</>', number_format($wire->sum('quantity') / 1000, 1).' t');

        $rows = $wire->groupBy('material_code')
            ->map(fn ($group, string $code): array => [
                $code,
                number_format($group->sum('quantity'), 1),
                number_format($group->sum('quantity') / 1000, 3),
            ])
            ->sortByDesc(fn (array $row): float => (float) str_replace(',', '', $row[2]))
            ->values()
            ->all();

        $this->table(['Wire code', 'Kilograms', 'Tonnes'], $rows);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, MaterialRequirement>  $requirements
     */
    private function reportConcrete($requirements): void
    {
        $concrete = $requirements->where('kind', MaterialRequirement::KIND_CONCRETE);

        if ($concrete->isEmpty()) {
            return;
        }

        $rows = $concrete->groupBy('material_code')
            ->map(fn ($group, string $code): array => [
                $code,
                number_format($group->sum('quantity'), 2),
                number_format($group->sum('planned_pipes')),
            ])
            ->sortByDesc(fn (array $row): float => (float) str_replace(',', '', $row[1]))
            ->values()
            ->all();

        $this->table(['Mix', 'Cubic metres', 'Pipes'], $rows);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, MaterialRequirement>  $requirements
     */
    private function reportWeeks($requirements): void
    {
        $rows = $requirements->where('kind', MaterialRequirement::KIND_WIRE)
            ->groupBy(fn (MaterialRequirement $row): string => $row->week_starting->toDateString())
            ->sortKeys()
            ->map(fn ($group, string $week): array => [
                $group->first()->week_starting->format('j M Y'),
                number_format($group->sum('quantity'), 1),
                number_format($group->sum('quantity') / 1000, 2),
            ])
            ->values()
            ->all();

        $this->table(['Week starting', 'Wire kg', 'Wire t'], $rows);
    }

    /**
     * Netted against the buyer's stocktake. A gauge with no stocktake row is reported as such rather
     * than assumed to be sitting in the yard.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, MaterialRequirement>  $requirements
     */
    private function reportOrderAdvice($requirements, SteelPlanningService $steel): void
    {
        $depotCode = $this->option('depot');
        $depots = Depot::query()
            ->when($depotCode !== null, fn ($query) => $query->where('code', strtoupper((string) $depotCode)))
            ->get()
            ->keyBy('id');

        $rows = [];
        $missing = [];

        foreach ($requirements->where('kind', MaterialRequirement::KIND_WIRE)->groupBy('depot_id') as $depotId => $lines) {
            $depot = $depots->get($depotId);

            if ($depot === null) {
                continue;
            }

            foreach ($lines->groupBy('material_code') as $wireCode => $gaugeLines) {
                $requiredKg = (float) $gaugeLines->sum('quantity');
                $stocktake = SteelForecast::query()
                    ->where('depot_id', $depotId)
                    ->where('wire_code', $wireCode)
                    ->latest('forecast_date')
                    ->first();

                if ($stocktake === null) {
                    $missing[] = "{$depot->code} {$wireCode} — ".number_format($requiredKg, 0).' kg required';

                    continue;
                }

                $result = $steel->calculate(
                    [],
                    SteelStockPosition::fromForecast($stocktake),
                    $this->weeklyConsumption($gaugeLines),
                );

                $rows[] = [
                    $depot->code,
                    $wireCode,
                    number_format($requiredKg, 0),
                    number_format((float) $result->projectedEndingKg - $requiredKg, 0),
                    number_format((float) $result->safetyStockKg, 0),
                    number_format(max(0, (float) $result->safetyStockKg - ((float) $result->projectedEndingKg - $requiredKg)), 0),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(
                ['Plant', 'Wire', 'Required kg', 'Projected kg', 'Safety kg', 'Order kg'],
                $rows,
            );
        }

        if ($missing !== []) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d gauge/plant combination(s) have no coil stocktake, so no order can be advised:',
                count($missing),
            ));

            foreach (array_slice($missing, 0, 12) as $line) {
                $this->line('  '.$line);
            }
        }
    }

    /**
     * @param  Collection<int, MaterialRequirement>  $lines
     * @return list<float>
     */
    private function weeklyConsumption($lines): array
    {
        return $lines
            ->groupBy(fn (MaterialRequirement $row): string => $row->week_starting->toDateString())
            ->map(fn ($group): float => (float) $group->sum('quantity'))
            ->values()
            ->all();
    }

    private function planRun(): ?PlanRun
    {
        $id = $this->option('run');

        return $id === null
            ? PlanRun::query()->latest('id')->first()
            : PlanRun::query()->find((int) $id);
    }
}
