<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Services\Logistics\AvailabilityProjectionService;
use App\Services\Scheduling\LogisticsReconcileService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class LogisticsAvailability extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Availability';

    protected static ?string $title = 'Logistics availability';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.logistics-availability';

    public string $dispatchDate = '';

    public function mount(): void
    {
        $this->dispatchDate = now()->toDateString();
        $this->syncOperationalFilterSelection();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function runReconcile(): void
    {
        $depot = $this->selectedDepot();

        if ($depot === null) {
            Notification::make()->title('Select a plant first')->warning()->send();

            return;
        }

        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $depot), 403);

        $result = app(LogisticsReconcileService::class)->reconcile($depot->id);

        Notification::make()
            ->title('Reconcile complete')
            ->body("Allocated {$result->totalAllocatedQuantity} pipe(s); {$result->totalUnfulfilledQuantity} still unfulfilled.")
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $depot = $this->selectedDepot();
        $filters = $this->operationalFilterOptions();
        $rows = collect();

        if ($depot instanceof Depot) {
            $rows = app(AvailabilityProjectionService::class)->rowsForDepot(
                $depot,
                CarbonImmutable::parse($this->dispatchDate),
            );
        }

        return [
            'plants' => $filters['plants'],
            'shifts' => $filters['shifts'],
            'machines' => $filters['machines'],
            'rows' => $rows,
            'depot' => $depot,
            'canReconcile' => $depot instanceof Depot
                && $this->canManageDepot(Capability::ManageDispatchLoads, $depot),
        ];
    }

    private function selectedDepot(): ?Depot
    {
        if ($this->depotId === null) {
            return null;
        }

        return $this->accessiblePlants()->firstWhere('id', $this->depotId);
    }
}
