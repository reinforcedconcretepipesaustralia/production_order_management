<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\MaterialRequirement;
use App\Models\PlanChangeLogEntry;
use App\Models\PlanRun;
use App\Models\ProductionSchedule;
use App\Services\Planning\PlanReviewService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Where a planner reviews what the engine proposed, changes what they disagree with, and signs it
 * off. Quantity and date are edited in the table so a planner can adjust the plan without opening
 * a form for every line.
 */
class PlanReview extends Page implements Tables\Contracts\HasTable
{
    use HasOperationalFilters;
    use Tables\Concerns\InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Plan Review';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.plan-review';

    public ?int $planRunId = null;

    public function mount(): void
    {
        $this->planRunId = $this->defaultRunId();
    }

    /**
     * The newest plan still worth looking at. A discarded run is only selected when there is nothing
     * else, so a planner does not land on a plan somebody already threw away.
     */
    private function defaultRunId(): ?int
    {
        return PlanRun::query()
            ->whereNot('status', PlanRun::STATUS_DISCARDED)
            ->latest('id')
            ->value('id')
            ?? PlanRun::query()->latest('id')->value('id');
    }

    /**
     * The factory needs to read the plan it is working to, so viewing is open to depot roles. Only
     * changing it is a planner's.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function canManage(): bool
    {
        return static::operationalUser()?->hasCapability(Capability::ManageSchedules) ?? false;
    }

    public function table(Table $table): Table
    {
        $editable = $this->linesAreEditable();

        return $table
            ->query($this->linesQuery())
            ->defaultGroup('depot.name')
            ->defaultSort('schedule_date')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('sku.sku_code')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ProductionSchedule $line): ?string => match (true) {
                        $line->origin === ProductionSchedule::ORIGIN_MANUAL && $line->is_steamed => 'Edited · steamed',
                        $line->origin === ProductionSchedule::ORIGIN_MANUAL => 'Edited',
                        $line->is_steamed => 'Steamed',
                        default => null,
                    }),
                TextInputColumn::make('schedule_date')
                    ->label('Date')
                    ->type('date')
                    ->disabled(fn (): bool => ! $editable)
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['schedule_date' => $state],
                    )),
                TextInputColumn::make('planned_quantity')
                    ->label('Pipes')
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->alignEnd()
                    ->disabled(fn (): bool => ! $editable)
                    ->updateStateUsing(fn (ProductionSchedule $record, mixed $state): mixed => $this->persistLine(
                        $record,
                        ['planned_quantity' => max(0, (int) $state)],
                    )),
                TextColumn::make('shift.name')
                    ->label('Shift')
                    ->placeholder('—'),
                TextColumn::make('machine.name')
                    ->label('Machine')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('reason_code')
                    ->label('Why')
                    ->formatStateUsing(fn (?string $state): string => $state === null || $state === ''
                        ? '—'
                        : str($state)->replace('_', ' ')->ucfirst()->toString())
                    ->description(fn (ProductionSchedule $line): ?string => $line->planner_notes),
                ToggleColumn::make('is_locked')
                    ->label('Hold')
                    ->disabled(fn (): bool => ! $editable)
                    ->updateStateUsing(function (ProductionSchedule $record, mixed $state): bool {
                        $this->toggleLock($record->id, app(PlanReviewService::class));

                        return (bool) $record->fresh()?->is_locked;
                    }),
            ])
            ->emptyStateHeading($this->planRun() === null ? 'No plan has been generated yet' : 'No lines in this plan')
            ->emptyStateDescription($this->planRun() === null
                ? 'Run php artisan plan:generate to propose one.'
                : 'Widen the plant, shift, or machine filters, or generate a new plan.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $run = $this->planRun();
        $filters = $this->operationalFilterOptions();

        return [
            'runs' => $this->selectableRuns(),
            'run' => $run,
            ...$filters,
            'materials' => $run === null ? collect() : $this->materials($run),
            'changes' => $run === null ? collect() : $this->changes($run),
            'canManage' => $this->canManage(),
        ];
    }

    public function toggleLock(int $lineId, PlanReviewService $review): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $line = $this->reachableLine($lineId);

        if ($line === null) {
            return;
        }

        try {
            $review->setLocked($line, ! $line->is_locked, auth()->user());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($line->is_locked ? 'Line held against regeneration.' : 'Line released.')
            ->send();
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
        return $this->planRunId === null ? null : PlanRun::query()->find($this->planRunId);
    }

    /**
     * @return Builder<ProductionSchedule>
     */
    private function linesQuery(): Builder
    {
        $run = $this->planRun();

        if ($run === null) {
            return ProductionSchedule::query()->whereRaw('0 = 1');
        }

        return $this->applyOperationalFilters(
            ProductionSchedule::query()
                ->with(['depot', 'machine', 'shift', 'sku'])
                // The run's own lines, plus the lines a planner kept from an earlier run. Regeneration
                // carries those through and plans around them, so they are committed work — a review
                // that hid them would approve a plan the floor is not actually running.
                ->where(fn (Builder $query) => $query
                    ->where('plan_run_id', $run->id)
                    ->orWhere(fn (Builder $carried) => $carried
                        ->whereIn('depot_id', $run->depot_ids ?? [])
                        ->whereBetween('schedule_date', [
                            $run->horizon_from->startOfDay(),
                            $run->horizon_to->endOfDay(),
                        ])
                        ->where(fn (Builder $kept) => $kept
                            ->where('origin', ProductionSchedule::ORIGIN_MANUAL)
                            ->orWhere('is_locked', true))
                        ->whereNot('status', 'cancelled')))
                ->orderBy('depot_id')
                ->orderBy('schedule_date')
                ->orderBy('planned_start_at')
                ->orderBy('id'),
        );
    }

    /**
     * @return Collection<int, MaterialRequirement>
     */
    private function materials(PlanRun $run): Collection
    {
        $accessible = $this->accessibleDepotIds();
        $selectedPlantIds = $this->selectedPlantIds();

        // Materials are plant-scoped only — requirements are not stored per shift or machine.
        return MaterialRequirement::query()
            ->wire()
            ->where('plan_run_id', $run->id)
            ->when($accessible !== null, fn (Builder $query) => $query->whereIn('depot_id', $accessible))
            ->when($selectedPlantIds !== null, fn (Builder $query) => $query->whereIn('depot_id', $selectedPlantIds))
            ->get()
            ->groupBy('material_code')
            ->map(fn (Collection $group): float => round((float) $group->sum('quantity'), 1))
            ->sortDesc();
    }

    /**
     * @return Collection<int, PlanChangeLogEntry>
     */
    private function changes(PlanRun $run): Collection
    {
        return PlanChangeLogEntry::query()
            ->with('user')
            ->where('plan_run_id', $run->id)
            ->latest('id')
            ->limit(25)
            ->get();
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

    /**
     * @param  array<string, mixed>  $changes
     */
    private function persistLine(ProductionSchedule $line, array $changes): mixed
    {
        if (! $this->mayManage()) {
            return $line->getAttribute(array_key_first($changes));
        }

        $reachable = $this->reachableLine($line->id);

        if ($reachable === null) {
            return $line->getAttribute(array_key_first($changes));
        }

        try {
            app(PlanReviewService::class)->updateLine($reachable, $changes, auth()->user());
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return $line->getAttribute(array_key_first($changes));
        }

        $fresh = $reachable->fresh();
        $field = (string) array_key_first($changes);

        if ($field === 'schedule_date') {
            return $fresh?->schedule_date?->toDateString();
        }

        return $fresh?->getAttribute($field);
    }

    /**
     * A line the current user may actually reach, which is how plant scoping is enforced on writes as
     * well as on reads. Returns null and says so rather than aborting: a raw 403 page in place of the
     * plan is a worse answer than a message beside it.
     */
    private function reachableLine(int $lineId): ?ProductionSchedule
    {
        $accessible = $this->accessibleDepotIds();

        $line = ProductionSchedule::query()
            ->with(['depot', 'sku', 'planRun'])
            ->when($accessible !== null, fn (Builder $query) => $query->whereIn('depot_id', $accessible))
            ->find($lineId);

        if ($line === null) {
            Notification::make()
                ->danger()
                ->title('That line is not in this plan, or not at a plant you can see.')
                ->send();
        }

        return $line;
    }

    private function linesAreEditable(): bool
    {
        $run = $this->planRun();

        return $this->canManage() && $run !== null && $run->isDraft();
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
}
