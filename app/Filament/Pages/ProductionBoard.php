<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Models\Shift;
use App\Services\Planning\PlanGenerator;
use App\Services\Planning\PlanRequest;
use App\Services\Planning\PlanReviewService;
use App\Services\Planning\SchedulingRuleResolver;
use App\Services\Rcpa\RcpaViewReadService;
use App\Services\Scheduling\MouldConstraintService;
use App\Services\Scheduling\ShiftCapacityCalculator;
use App\Support\Rcpa\RcpaViewCatalog;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class ProductionBoard extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Production Plan';

    protected static ?string $title = 'Production Plan';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.production-board';

    public const PILOT_PLANT_CODE = 'YAT';

    public string $fromDate = '';

    public string $toDate = '';

    public string $status = '';

    public ?int $planRunId = null;

    public function mount(): void
    {
        $this->fromDate = now()->startOfWeek()->toDateString();
        $this->toDate = now()->endOfWeek()->toDateString();
        $this->planRunId = $this->defaultRunId();
        $this->depotId = $this->pilotPlant()?->id;
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function canManage(): bool
    {
        return static::operationalUser()?->hasCapability(Capability::ManageSchedules) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filters = $this->operationalFilterOptions();
        [$from, $to] = $this->dateRange();
        $statuses = ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'];
        $selectedStatus = in_array($this->status, $statuses, true) ? $this->status : '';
        $run = $this->planRun();

        $schedules = $this->applyOperationalFilters(
            ProductionSchedule::query()
                ->with(['depot', 'machine', 'mould', 'shift', 'sku', 'planRun'])
                ->when($selectedStatus !== '', fn ($query) => $query->where('status', $selectedStatus))
                ->whereBetween('schedule_date', [$from->startOfDay(), $to->endOfDay()])
                ->orderBy('depot_id')
                ->orderBy('schedule_date')
                ->orderBy('planned_start_at')
                ->orderBy('id'),
        )->get();

        $reader = new RcpaViewReadService(new RcpaViewCatalog);

        return [
            ...$filters,
            'statuses' => $statuses,
            'runs' => $this->selectableRuns(),
            'run' => $run,
            'canManage' => $this->canManage(),
            'editable' => $this->canManage() && $run !== null && $run->isDraft(),
            'grid' => $this->buildGrid($schedules, $from, $to),
            'erpProduction' => $reader->productionDemand(
                $from->toDateString(),
                $to->toDateString(),
            ),
            'erpSkuMaster' => $reader->skuMaster(),
        ];
    }

    public function moveLine(int $lineId, string $date, int $shiftId, PlanReviewService $review): void
    {
        $line = $this->reachableLine($lineId);

        if ($line === null || ! $this->mayManage()) {
            return;
        }

        try {
            $review->updateLine($line, [
                'schedule_date' => $date,
                'shift_id' => $shiftId,
            ], auth()->user(), 'Moved on the production plan');
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Line moved.')->send();
    }

    public function updateQuantity(int $lineId, mixed $quantity, PlanReviewService $review): void
    {
        $line = $this->reachableLine($lineId);

        if ($line === null || ! $this->mayManage()) {
            return;
        }

        try {
            $review->updateLine($line, [
                'planned_quantity' => max(0, (int) $quantity),
            ], auth()->user());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }

    public function approveWeek(PlanReviewService $review): void
    {
        [$from] = $this->dateRange();

        $this->approvePeriod($review, $from->startOfWeek(), $from->endOfWeek());
    }

    public function approveMonth(PlanReviewService $review): void
    {
        [$from] = $this->dateRange();

        $this->approvePeriod($review, $from->startOfMonth(), $from->endOfMonth());
    }

    public function approve(PlanReviewService $review): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $run = $this->planRun();

        if ($run === null) {
            return;
        }

        try {
            $review->approve($run, auth()->user());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Plan approved. Every line is now held.')->send();
    }

    public function generatePlan(PlanGenerator $generator, SchedulingRuleResolver $rules): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $plant = $this->pilotPlant();

        if ($plant === null) {
            Notification::make()
                ->danger()
                ->title('Yatala is not set up for planning, or you cannot see it.')
                ->send();

            return;
        }

        $from = CarbonImmutable::now()->addDay()->startOfDay();
        $defaults = $rules->planDefaults();

        try {
            $run = $generator->generate(new PlanRequest(
                from: $from,
                to: $from->addMonths(6),
                depotIds: [$plant->id],
                targetMonthsOnHand: $defaults['target_months_on_hand'],
                minimumRunFraction: $defaults['minimum_run_fraction'],
                maxRunsPerShift: $defaults['max_runs_per_shift'],
                includeReplenishment: $defaults['include_replenishment'],
                generatedBy: auth()->id(),
            ));
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->depotId = $plant->id;
        $this->planRunId = $run->id;

        $lines = (int) ($run->metrics['lines'] ?? 0);

        Notification::make()
            ->success()
            ->title("Yatala plan #{$run->id} is ready.")
            ->body($lines === 0
                ? 'No lines fitted. Check orders, qualified machines, and the week on the board.'
                : number_format($lines).' lines proposed. Review the week, then approve.')
            ->send();
    }

    public function discard(PlanReviewService $review): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $run = $this->planRun();

        if ($run === null) {
            return;
        }

        try {
            $review->discard($run);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Plan discarded.')->send();

        $this->planRunId = $this->defaultRunId();
    }

    public function planRun(): ?PlanRun
    {
        return $this->planRunId === null ? null : PlanRun::query()->with('approvedBy')->find($this->planRunId);
    }

    /**
     * @param  Collection<int, ProductionSchedule>  $schedules
     * @return Collection<int, array<string, mixed>>
     */
    private function buildGrid(Collection $schedules, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($schedules->isEmpty()) {
            return collect();
        }

        $capacityCalculator = app(ShiftCapacityCalculator::class);
        $constraintService = app(MouldConstraintService::class);
        $days = collect(range(0, $from->diffInDays($to)))
            ->map(fn (int $offset): CarbonImmutable => $from->addDays($offset));

        $items = $schedules->map(function (ProductionSchedule $schedule) use ($capacityCalculator, $constraintService): array {
            $capacity = $capacityCalculator->calculate($schedule->shift, $schedule->sku);

            return [
                'model' => $schedule,
                'capacity' => $capacity->plannedPipes,
                'is_over_capacity' => $schedule->planned_quantity > $capacity->plannedPipes,
                'conflicts' => $constraintService->conflictsFor($schedule),
                'is_low_oee' => (float) $schedule->planned_oee_percent < 75,
            ];
        });

        $depotIds = $schedules->pluck('depot_id')->unique()->all();
        $shiftsByDepot = Shift::query()
            ->whereIn('depot_id', $depotIds)
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get()
            ->groupBy('depot_id');

        $depots = $schedules
            ->map(fn (ProductionSchedule $schedule): Depot => $schedule->depot)
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->values();

        return $depots->map(function (Depot $depot) use ($days, $items, $shiftsByDepot): array {
            $shifts = $shiftsByDepot->get($depot->id, collect())->values();
            $dayBlocks = $days->map(function (CarbonImmutable $day) use ($depot, $items, $shifts): array {
                $shiftBlocks = $shifts->map(function (Shift $shift) use ($depot, $day, $items): array {
                    $lines = $items
                        ->filter(fn (array $item): bool => $item['model']->depot_id === $depot->id
                            && $item['model']->shift_id === $shift->id
                            && $item['model']->schedule_date?->isSameDay($day))
                        ->values();

                    return [
                        'shift' => $shift,
                        'lines' => $lines,
                    ];
                })->values();

                $orphanLines = $items
                    ->filter(fn (array $item): bool => $item['model']->depot_id === $depot->id
                        && $item['model']->schedule_date?->isSameDay($day)
                        && ! $shifts->contains('id', $item['model']->shift_id))
                    ->groupBy(fn (array $item): int => (int) ($item['model']->shift_id ?? 0));

                foreach ($orphanLines as $grouped) {
                    $shiftBlocks->push([
                        'shift' => $grouped->first()['model']->shift,
                        'lines' => $grouped->values(),
                    ]);
                }

                return [
                    'date' => $day,
                    'shifts' => $shiftBlocks,
                    'rowspan' => $shiftBlocks->sum(fn (array $block): int => max(1, $block['lines']->count())),
                ];
            });

            return [
                'depot' => $depot,
                'days' => $dayBlocks,
            ];
        });
    }

    private function approvePeriod(
        PlanReviewService $review,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        if (! $this->mayManage()) {
            return;
        }

        $run = $this->planRun();

        if ($run === null) {
            return;
        }

        try {
            $held = $review->approvePeriod($run, $from, $to, auth()->user());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $label = $from->equalTo($from->startOfMonth()) && $to->equalTo($from->endOfMonth())
            ? $from->format('F Y')
            : $from->format('j M').' to '.$to->format('j M');

        Notification::make()
            ->success()
            ->title($run->fresh()?->isApproved()
                ? 'The whole plan is now approved.'
                : "Approved {$label}. {$held} line(s) held.")
            ->send();
    }

    /**
     * @return Collection<int, string>
     */
    private function selectableRuns(): Collection
    {
        return PlanRun::query()
            ->latest('id')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (PlanRun $run): array => [
                $run->id => sprintf(
                    '#%d — %s to %s (%s)',
                    $run->id,
                    $run->horizon_from->format('j M Y'),
                    $run->horizon_to->format('j M Y'),
                    $run->status,
                ),
            ]);
    }

    private function pilotPlant(): ?Depot
    {
        $plant = Depot::query()
            ->where('code', self::PILOT_PLANT_CODE)
            ->where('is_planning_enabled', true)
            ->first();

        if ($plant === null) {
            return null;
        }

        $accessible = $this->accessibleDepotIds();

        if ($accessible !== null && ! in_array($plant->id, $accessible, true)) {
            return null;
        }

        return $plant;
    }

    private function defaultRunId(): ?int
    {
        return PlanRun::query()
            ->whereNot('status', PlanRun::STATUS_DISCARDED)
            ->latest('id')
            ->value('id')
            ?? PlanRun::query()->latest('id')->value('id');
    }

    private function reachableLine(int $lineId): ?ProductionSchedule
    {
        $accessible = $this->accessibleDepotIds();

        $line = ProductionSchedule::query()
            ->with(['depot', 'sku', 'planRun', 'shift'])
            ->when($accessible !== null, fn (Builder $query) => $query->whereIn('depot_id', $accessible))
            ->find($lineId);

        if ($line === null) {
            Notification::make()
                ->danger()
                ->title('That line is not in this window, or not at a plant you can see.')
                ->send();
        }

        return $line;
    }

    private function mayManage(): bool
    {
        if ($this->canManage()) {
            return true;
        }

        Notification::make()
            ->danger()
            ->title('You can read this plan but not change it.')
            ->send();

        return false;
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
