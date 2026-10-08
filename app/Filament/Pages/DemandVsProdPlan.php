<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Services\Planning\DemandVsProdPlanWorkbook;
use Carbon\CarbonImmutable;
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

    public bool $hideBlankPlan = true;

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

        if ($this->hideBlankPlan) {
            $table['rows'] = array_values(array_filter(
                $table['rows'],
                fn (array $row): bool => array_sum($row['plan']) !== 0,
            ));
        }

        $table['totals'] = $this->totalsFromRows($table['rows'], $table['months']);

        return [
            ...$this->operationalFilterOptions(),
            'table' => $table,
        ];
    }

    /**
     * @param  list<array{soh: int, demand: array<string, int>, plan: array<string, int>, variance: array<string, int>}>  $rows
     * @param  list<CarbonImmutable>  $months
     * @return array{soh: int, demand: array<string, int>, plan: array<string, int>, variance: array<string, int>}
     */
    private function totalsFromRows(array $rows, array $months): array
    {
        $keys = array_map(fn ($month): string => $month->toDateString(), $months);
        $empty = array_fill_keys($keys, 0);
        $totals = [
            'soh' => 0,
            'demand' => $empty,
            'plan' => $empty,
            'variance' => $empty,
        ];

        foreach ($rows as $row) {
            $totals['soh'] += $row['soh'];

            foreach ($keys as $key) {
                $totals['demand'][$key] += $row['demand'][$key];
                $totals['plan'][$key] += $row['plan'][$key];
                $totals['variance'][$key] += $row['variance'][$key];
            }
        }

        return $totals;
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
