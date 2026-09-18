<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalDepotScope;
use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Services\Rcpa\RcpaViewReadService;
use App\Services\Scheduling\MouldConstraintService;
use App\Services\Scheduling\ShiftCapacityCalculator;
use App\Support\Rcpa\RcpaViewCatalog;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductionBoard extends Page
{
    use HasOperationalDepotScope;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Production Board';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.production-board';

    public ?int $depotId = null;

    public string $fromDate = '';

    public string $toDate = '';

    public string $status = '';

    public function mount(): void
    {
        $this->fromDate = now()->startOfWeek()->toDateString();
        $this->toDate = now()->endOfWeek()->toDateString();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage([
            Capability::ViewSchedules,
            Capability::ViewDepotOperations,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $accessibleDepotIds = $this->accessibleDepotIds();
        $depots = Depot::query()
            ->where('is_active', true)
            ->when($accessibleDepotIds !== null, fn (Builder $query) => $query->whereKey($accessibleDepotIds))
            ->orderBy('name')
            ->get();

        $selectedDepotId = $this->depotId;
        if ($selectedDepotId !== null && ! $depots->contains('id', $selectedDepotId)) {
            $selectedDepotId = null;
            $this->depotId = null;
        }

        [$from, $to] = $this->dateRange();
        $statuses = ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'];
        $selectedStatus = in_array($this->status, $statuses, true) ? $this->status : '';

        $schedules = ProductionSchedule::query()
            ->with(['depot', 'machine', 'mould', 'shift', 'sku'])
            ->when($accessibleDepotIds !== null, fn (Builder $query) => $query->whereIn('depot_id', $accessibleDepotIds))
            ->when($selectedDepotId !== null, fn (Builder $query) => $query->where('depot_id', $selectedDepotId))
            ->when($selectedStatus !== '', fn (Builder $query) => $query->where('status', $selectedStatus))
            ->whereBetween('schedule_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('depot_id')
            ->orderBy('machine_asset_id')
            ->orderBy('planned_start_at')
            ->orderBy('id')
            ->get();

        $reader = new RcpaViewReadService(new RcpaViewCatalog);

        return [
            'depots' => $depots,
            'statuses' => $statuses,
            'days' => collect(range(0, $from->diffInDays($to)))
                ->map(fn (int $offset): CarbonImmutable => $from->addDays($offset)),
            'lanes' => $this->buildLanes($schedules),
            'erpProduction' => $reader->productionDemand(
                $from->toDateString(),
                $to->toDateString(),
            ),
            'erpSkuMaster' => $reader->skuMaster(),
        ];
    }

    /**
     * @param  Collection<int, ProductionSchedule>  $schedules
     * @return Collection<string, array{depot: Depot, machine_name: string, schedules: Collection<int, array<string, mixed>>}>
     */
    private function buildLanes(Collection $schedules): Collection
    {
        $capacityCalculator = app(ShiftCapacityCalculator::class);
        $constraintService = app(MouldConstraintService::class);

        return $schedules
            ->groupBy(fn (ProductionSchedule $schedule): string => "{$schedule->depot_id}:{$schedule->machine_asset_id}")
            ->map(function (Collection $laneSchedules) use ($capacityCalculator, $constraintService): array {
                /** @var ProductionSchedule $first */
                $first = $laneSchedules->first();

                return [
                    'depot' => $first->depot,
                    'machine_name' => $first->machine?->name ?? $first->machine?->code ?? 'Unassigned machine',
                    'schedules' => $laneSchedules->map(function (ProductionSchedule $schedule) use ($capacityCalculator, $constraintService): array {
                        $capacity = $capacityCalculator->calculate($schedule->shift, $schedule->sku);
                        $conflicts = $constraintService->conflictsFor($schedule);
                        $dayStart = $schedule->planned_start_at->copy()->startOfDay();
                        $startMinutes = $dayStart->diffInMinutes($schedule->planned_start_at);
                        $durationMinutes = max(15, $schedule->planned_start_at->diffInMinutes($schedule->planned_end_at));

                        return [
                            'model' => $schedule,
                            'capacity' => $capacity->plannedPipes,
                            'is_over_capacity' => $schedule->planned_quantity > $capacity->plannedPipes,
                            'conflicts' => $conflicts,
                            'is_low_oee' => (float) $schedule->planned_oee_percent < 75,
                            'left' => min(100, max(0, ($startMinutes / 1440) * 100)),
                            'width' => min(100, max(2, ($durationMinutes / 1440) * 100)),
                        ];
                    }),
                ];
            })
            ->values();
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dateRange(): array
    {
        try {
            $from = CarbonImmutable::parse($this->fromDate)->startOfDay();
            $to = CarbonImmutable::parse($this->toDate)->startOfDay();
        } catch (\Throwable) {
            $from = CarbonImmutable::now()->startOfWeek();
            $to = $from->endOfWeek();
        }

        if ($to->isBefore($from)) {
            $to = $from;
        }

        return [$from, $to->min($from->addDays(30))];
    }
}
