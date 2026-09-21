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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Where a planner reviews what the engine proposed, changes what they disagree with, and signs it
 * off. This is the screen the model exists for: the plan arrives written, and a human's job is to
 * check it rather than author it.
 *
 * Laid out by plant, date and shift the way the workbook's Prod Plan tab is, because that is the
 * shape planners already read.
 */
class PlanReview extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Plan Review';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.plan-review';

    public ?int $planRunId = null;

    /** The line currently open for editing, if any. */
    public ?int $editingLineId = null;

    /** @var array<string, mixed> */
    public array $editing = [];

    public string $editReason = '';

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
    public static function canAccess(): bool
    {
        return static::canViewOperationalPage([
            Capability::ViewSchedules,
            Capability::ManageSchedules,
            Capability::ViewDepotOperations,
        ]);
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
        $run = $this->planRun();
        $filters = $this->operationalFilterOptions();

        return [
            'runs' => $this->selectableRuns(),
            'run' => $run,
            ...$filters,
            'lanes' => $run === null ? collect() : $this->lanes($run),
            'materials' => $run === null ? collect() : $this->materials($run),
            'changes' => $run === null ? collect() : $this->changes($run),
            'canManage' => $this->canManage(),
        ];
    }

    public function editLine(int $lineId): void
    {
        $line = $this->reachableLine($lineId);

        if ($line === null) {
            return;
        }

        $this->editingLineId = $line->id;
        $this->editReason = '';
        $this->editing = [
            'planned_quantity' => (int) $line->planned_quantity,
            'shift_fraction' => (float) $line->shift_fraction,
            // Left null rather than coerced to 0: priority is nullable, and turning "not set" into
            // zero would log a change on every line a planner merely opened.
            'priority' => $line->priority,
            'is_steamed' => (bool) $line->is_steamed,
            'planner_notes' => (string) $line->planner_notes,
        ];
    }

    public function cancelEdit(): void
    {
        $this->editingLineId = null;
        $this->editing = [];
        $this->editReason = '';
    }

    public function saveLine(PlanReviewService $review): void
    {
        if (! $this->mayManage()) {
            return;
        }

        $line = $this->reachableLine((int) $this->editingLineId);

        if ($line === null) {
            return;
        }

        try {
            $applied = $review->updateLine(
                $line,
                [
                    'planned_quantity' => max(0, (int) ($this->editing['planned_quantity'] ?? 0)),
                    'shift_fraction' => max(0, (float) ($this->editing['shift_fraction'] ?? 0)),
                    'priority' => $this->optionalInteger($this->editing['priority'] ?? null),
                    'is_steamed' => (bool) ($this->editing['is_steamed'] ?? false),
                    'planner_notes' => trim((string) ($this->editing['planner_notes'] ?? '')) ?: null,
                ],
                auth()->user(),
                trim($this->editReason) ?: null,
            );
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($applied === 0 ? 'Nothing changed.' : "Saved {$applied} change(s).")
            ->send();

        $this->cancelEdit();
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
     * Lines grouped the way a planner reads them: plant, then day, then shift.
     *
     * @return Collection<string, Collection<int, ProductionSchedule>>
     */
    private function lanes(PlanRun $run): Collection
    {
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
        )
            ->get()
            ->groupBy(fn (ProductionSchedule $line): string => sprintf(
                '%s|%s|%s',
                $line->depot?->code ?? '—',
                $line->schedule_date?->toDateString() ?? '—',
                $line->shift?->name ?? 'Shift',
            ));
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
     * An emptied number field means "not set", which is different from zero.
     */
    private function optionalInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : max(0, (int) $value);
    }
}
