<?php

namespace App\Services\Operations;

use App\Enums\AppRole;
use App\Models\DeliveryScheduleLine;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\ProductionActual;
use App\Models\ProductionSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Today's production and dispatch figures for the home dashboard.
 *
 * Dates are the Melbourne business day. Stock and open orders stay on the
 * dispatch calendar; this reads only local plans, actuals, schedules, and loads.
 *
 * @phpstan-type Pulse array{
 *     planned_quantity: int,
 *     runs: int,
 *     good_quantity: int,
 *     dumped_quantity: int,
 *     schedule_lines: int,
 *     confirmed_lines: int,
 *     unconfirmed_lines: int,
 *     confirmed_tonnes: float,
 *     loads: int,
 *     load_tonnes: float,
 *     load_capacity_tonnes: float,
 *     loaded_quantity: int,
 * }
 */
final class OperationsPulse
{
    /**
     * Null means every plant. An empty list means the user has no plants.
     *
     * @return list<int>|null
     */
    public function depotIdsFor(User $user): ?array
    {
        if ($user->hasRole((string) config('filament-shield.super_admin.name'))
            || $user->hasAnyRole(AppRole::PlannerAdmin->value, AppRole::ViewerExecutive->value)) {
            return null;
        }

        return $user->depots()->pluck('depots.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>|null  $depotIds
     * @return Pulse
     */
    public function snapshot(?array $depotIds, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $madeFrom = $day->startOfDay()->utc();
        $madeTo = $day->endOfDay()->utc();

        $schedules = ProductionSchedule::query()
            ->whereDate('schedule_date', $date)
            ->whereIn('status', ['scheduled', 'in_progress', 'completed'])
            ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $depotIds));

        $actuals = ProductionActual::query()
            ->whereBetween('manufactured_at', [$madeFrom, $madeTo])
            ->whereHas('schedule', function ($query) use ($depotIds): void {
                $query->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $depotIds));
            });

        $lines = DeliveryScheduleLine::query()
            ->whereHas('schedule', function ($query) use ($date, $depotIds): void {
                $query->whereDate('schedule_date', $date)
                    ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $depotIds));
            });

        $loads = DispatchLoad::query()
            ->whereDate('dispatch_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $depotIds));

        $loadedQuantity = (int) DispatchLoadItem::query()
            ->whereHas('dispatchLoad', function ($query) use ($date, $depotIds): void {
                $query->whereDate('dispatch_date', $date)
                    ->whereNotIn('status', ['cancelled'])
                    ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $depotIds));
            })
            ->sum('quantity');

        $confirmedLines = (clone $lines)->where('is_confirmed', true);

        return [
            'planned_quantity' => (int) (clone $schedules)->sum('planned_quantity'),
            'runs' => (int) (clone $schedules)->count(),
            'good_quantity' => (int) (clone $actuals)->sum('good_quantity'),
            'dumped_quantity' => (int) (clone $actuals)->sum('dumped_quantity'),
            'schedule_lines' => (int) (clone $lines)->count(),
            'confirmed_lines' => (int) (clone $confirmedLines)->count(),
            'unconfirmed_lines' => (int) (clone $lines)->where('is_confirmed', false)->count(),
            'confirmed_tonnes' => round((float) (clone $confirmedLines)->sum('tonnage'), 3),
            'loads' => (int) (clone $loads)->count(),
            'load_tonnes' => round((float) (clone $loads)->sum('current_weight_tonnes'), 3),
            'load_capacity_tonnes' => round((float) (clone $loads)->sum('capacity_tonnes'), 3),
            'loaded_quantity' => $loadedQuantity,
        ];
    }
}
