<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Models\SteelForecast;
use App\Services\Rcpa\RcpaViewReadService;
use App\Services\Scheduling\SteelForecastWorkbook;
use App\Support\Rcpa\RcpaViewCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SteelRequisitionDashboard extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Steel Forecast';

    protected static ?string $title = 'Steel Forecast';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.steel-requisition-dashboard';

    public string $wireCode = '';

    public string $urgency = '';

    public ?string $calculatedAt = null;

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function calculateForecasts(SteelForecastWorkbook $workbook): void
    {
        $depots = $this->filteredDepots();

        abort_unless(
            $depots->isNotEmpty()
            && $depots->every(fn (Depot $depot): bool => $this->canManageDepot(Capability::ManageSteelPurchasing, $depot)),
            403,
        );

        $this->buildTables($workbook);
        $this->calculatedAt = now()->toIso8601String();

        Notification::make()
            ->title('Steel forecast recalculated')
            ->body('Requirements and replenishment signals now reflect the current production schedule.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $tables = $this->buildTables(app(SteelForecastWorkbook::class));
        $urgencies = ['critical', 'overdue', 'watch', 'covered'];

        $filters = $this->operationalFilterOptions();
        $wireCodes = SteelForecast::query()
            ->when($this->accessibleDepotIds() !== null, fn (Builder $query) => $query->whereIn('depot_id', $this->accessibleDepotIds()))
            ->distinct()
            ->orderBy('wire_code')
            ->pluck('wire_code');

        $reader = new RcpaViewReadService(new RcpaViewCatalog);

        return [
            ...$filters,
            'wireCodes' => $wireCodes,
            'urgencies' => $urgencies,
            'tables' => $tables,
            'erpStock' => $reader->stockOnHand(),
            'erpBom' => $reader->bomLines(),
            'canCalculate' => $this->filteredDepots()->isNotEmpty()
                && $this->filteredDepots()->every(
                    fn (Depot $depot): bool => $this->canManageDepot(Capability::ManageSteelPurchasing, $depot),
                ),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildTables(SteelForecastWorkbook $workbook): Collection
    {
        $depots = $this->filteredDepots();

        $forecasts = SteelForecast::query()
            ->with('depot')
            ->whereIn('depot_id', $depots->modelKeys())
            ->when($this->wireCode !== '', fn (Builder $query) => $query->where('wire_code', $this->wireCode))
            ->orderBy('forecast_date')
            ->orderBy('wire_code')
            ->get();

        $tables = $workbook->tables(
            $depots,
            $forecasts,
            $this->applyOperationalFilters(ProductionSchedule::query()),
        );

        if ($this->wireCode !== '') {
            $tables = $this->keepWireColumns($tables, fn (string $code): bool => $code === $this->wireCode);
        }

        if (! in_array($this->urgency, ['critical', 'overdue', 'watch', 'covered'], true)) {
            return $tables;
        }

        return $this->keepWireColumns(
            $tables,
            fn (string $code, array $table): bool => ($table['urgencies'][$code] ?? null) === $this->urgency,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tables
     * @param  callable(string, array<string, mixed>): bool  $keep
     * @return Collection<int, array<string, mixed>>
     */
    private function keepWireColumns(Collection $tables, callable $keep): Collection
    {
        return $tables
            ->map(function (array $table) use ($keep): array {
                $table['wire_codes'] = array_values(array_filter(
                    $table['wire_codes'],
                    fn (string $code): bool => $keep($code, $table),
                ));

                return $table;
            })
            ->reject(fn (array $table): bool => $table['wire_codes'] === [])
            ->values();
    }

    /**
     * @return Collection<int, Depot>
     */
    private function filteredDepots(bool $applySelection = true): Collection
    {
        $depots = $this->accessiblePlants();

        if (! $applySelection) {
            return $depots;
        }

        $selectedPlantIds = $this->selectedPlantIds();

        if ($selectedPlantIds === null) {
            return $depots;
        }

        return $depots->whereIn('id', $selectedPlantIds)->values();
    }
}
