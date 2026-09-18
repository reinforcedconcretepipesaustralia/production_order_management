<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalDepotScope;
use App\Models\Depot;
use App\Models\ProductionSchedule;
use App\Models\SteelForecast;
use App\Services\Rcpa\RcpaViewReadService;
use App\Services\Scheduling\SteelDemandLine;
use App\Services\Scheduling\SteelPlanningService;
use App\Services\Scheduling\SteelStockPosition;
use App\Support\Rcpa\RcpaViewCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SteelRequisitionDashboard extends Page
{
    use HasOperationalDepotScope;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Steel Requisitions';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.steel-requisition-dashboard';

    public ?int $depotId = null;

    public string $wireCode = '';

    public string $urgency = '';

    public ?string $calculatedAt = null;

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage([Capability::ViewMaterials]);
    }

    public function calculateForecasts(SteelPlanningService $steelPlanningService): void
    {
        $depots = $this->filteredDepots();

        abort_unless(
            $depots->isNotEmpty()
            && $depots->every(fn (Depot $depot): bool => $this->canManageDepot(Capability::ManageSteelPurchasing, $depot)),
            403,
        );

        $this->buildRows($steelPlanningService);
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
        $rows = $this->buildRows(app(SteelPlanningService::class));
        $urgencies = ['critical', 'overdue', 'watch', 'covered'];

        if (in_array($this->urgency, $urgencies, true)) {
            $rows = $rows->where('urgency', $this->urgency)->values();
        }

        $depots = $this->filteredDepots(false);
        $wireCodes = SteelForecast::query()
            ->when($this->accessibleDepotIds() !== null, fn (Builder $query) => $query->whereIn('depot_id', $this->accessibleDepotIds()))
            ->distinct()
            ->orderBy('wire_code')
            ->pluck('wire_code');

        $reader = new RcpaViewReadService(new RcpaViewCatalog);

        return [
            'depots' => $depots,
            'wireCodes' => $wireCodes,
            'urgencies' => $urgencies,
            'rows' => $rows,
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
    private function buildRows(SteelPlanningService $steelPlanningService): Collection
    {
        $depotIds = $this->filteredDepots()->pluck('id');

        return SteelForecast::query()
            ->with('depot')
            ->whereIn('depot_id', $depotIds)
            ->when($this->wireCode !== '', fn (Builder $query) => $query->where('wire_code', $this->wireCode))
            ->orderBy('forecast_date')
            ->orderBy('wire_code')
            ->get()
            ->map(function (SteelForecast $forecast) use ($steelPlanningService): array {
                $schedules = ProductionSchedule::query()
                    ->with('sku')
                    ->where('depot_id', $forecast->depot_id)
                    ->whereBetween('schedule_date', [
                        $forecast->forecast_date->toDateString(),
                        $forecast->forecast_date->copy()->addDays(13)->toDateString(),
                    ])
                    ->whereNotIn('status', ['cancelled'])
                    ->whereHas('sku', fn (Builder $query) => $query->where('wire_code', $forecast->wire_code))
                    ->orderBy('schedule_date')
                    ->get();

                $result = $steelPlanningService->calculate(
                    $schedules->map(fn (ProductionSchedule $schedule): SteelDemandLine => new SteelDemandLine(
                        depotId: $schedule->depot_id,
                        sku: $schedule->sku,
                        plannedQuantity: $schedule->planned_quantity,
                    )),
                    SteelStockPosition::fromForecast($forecast),
                );

                $kilogramsPerCoil = (float) $forecast->average_coil_weight_tonnes * 1_000;
                $requiredCoils = (int) ceil((float) $result->requiredKg / $kilogramsPerCoil);
                $recommendedCoils = (int) ceil((float) $result->recommendedOrderKg / $kilogramsPerCoil);
                $urgency = match (true) {
                    $recommendedCoils > 0 && $forecast->forecast_date->isPast() => 'overdue',
                    $recommendedCoils > 0 => 'critical',
                    (float) $result->projectedEndingKg < (float) $result->safetyStockKg => 'watch',
                    default => 'covered',
                };

                return [
                    'forecast' => $forecast,
                    'required_kg' => $result->requiredKg,
                    'required_coils' => $requiredCoils,
                    'stock_kg' => number_format($forecast->on_hand_coils * $kilogramsPerCoil, 3, '.', ''),
                    'on_order_kg' => number_format($forecast->on_order_coils * $kilogramsPerCoil, 3, '.', ''),
                    'shortfall_kg' => $result->recommendedOrderKg,
                    'recommended_coils' => $recommendedCoils,
                    'projected_ending_kg' => $result->projectedEndingKg,
                    'urgency' => $urgency,
                ];
            });
    }

    /**
     * @return Collection<int, Depot>
     */
    private function filteredDepots(bool $applySelection = true): Collection
    {
        $accessibleDepotIds = $this->accessibleDepotIds();
        $depots = Depot::query()
            ->where('is_active', true)
            ->when($accessibleDepotIds !== null, fn (Builder $query) => $query->whereKey($accessibleDepotIds))
            ->orderBy('name')
            ->get();

        if (! $applySelection || $this->depotId === null) {
            return $depots;
        }

        return $depots->where('id', $this->depotId)->values();
    }
}
