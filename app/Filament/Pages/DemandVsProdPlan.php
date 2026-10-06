<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Services\Planning\DemandVsProdPlanWorkbook;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DemandVsProdPlan extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Demand vs Prod Plan';

    protected static ?string $title = 'Demand vs Prod Plan';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.demand-vs-prod-plan';

    public string $productSearch = '';

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function formatQty(int $value, bool $blankZero = true): string
    {
        if ($blankZero && $value === 0) {
            return '';
        }

        if ($value < 0) {
            return '('.number_format(abs($value)).')';
        }

        return number_format($value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $table = app(DemandVsProdPlanWorkbook::class)->table(
            $this->filteredDepots(),
            $this->applyOperationalFilters(ProductionSchedule::query()),
        );

        $search = Str::upper(trim($this->productSearch));

        if ($search !== '') {
            $table['rows'] = array_values(array_filter(
                $table['rows'],
                fn (array $row): bool => str_contains(Str::upper($row['sku_code']), $search),
            ));
        }

        return [
            ...$this->operationalFilterOptions(),
            'table' => $table,
        ];
    }

    /**
     * @return Collection<int, Depot>
     */
    private function filteredDepots(): Collection
    {
        $depots = $this->accessiblePlants();
        $selectedPlantIds = $this->selectedPlantIds();

        if ($selectedPlantIds === null) {
            return $depots;
        }

        return $depots->whereIn('id', $selectedPlantIds)->values();
    }
}
