<?php

namespace App\Services\Logistics;

use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\SkuMaster;
use App\Services\Sync\OrderSync;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Planned dispatches for a date range, from live order dates overlaid with the local schedule and loads.
 *
 * @phpstan-type HorizonLine array{
 *     reference: string,
 *     customer: string,
 *     suburb: ?string,
 *     sku: string,
 *     qty: int,
 *     tonnes: float,
 *     status: string,
 *     carrier: ?string,
 *     depot: string,
 * }
 * @phpstan-type HorizonDay array{
 *     qty: int,
 *     tonnes: float,
 *     confirmed: int,
 *     loaded: int,
 *     loads: int,
 *     load_tonnes: float,
 *     lines: list<HorizonLine>,
 * }
 */
final class DispatchHorizon
{
    public function __construct(private OrderSync $orders) {}

    /**
     * @param  list<int>|null  $depotIds  Null includes every plant.
     * @return Collection<string, HorizonDay>
     */
    public function between(CarbonInterface $start, CarbonInterface $end, ?array $depotIds = null): Collection
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $depots = Depot::query()
            ->when($depotIds !== null, fn ($query) => $query->whereKey($depotIds))
            ->get()
            ->keyBy('id');

        $allowedDepotIds = $depots->keys()->map(fn (mixed $id): int => (int) $id)->all();

        $schedules = DeliverySchedule::query()
            ->with(['lines.transportCarrier', 'lines.orderAllocation.dispatchItems.dispatchLoad'])
            ->whereDate('schedule_date', '>=', $startDate)
            ->whereDate('schedule_date', '<=', $endDate)
            ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $allowedDepotIds))
            ->get();

        $linesByKey = $schedules->flatMap(function (DeliverySchedule $schedule) {
            return $schedule->lines->each(fn (DeliveryScheduleLine $line) => $line->setRelation('schedule', $schedule));
        })->keyBy(fn (DeliveryScheduleLine $line): string => $this->lineKey(
            (int) $line->schedule->depot_id,
            $line->erp_entity_code,
            $line->erp_line_reference,
            $line->schedule->schedule_date->toDateString(),
        ));

        $weights = SkuMaster::query()->pluck('unit_weight_tonnes', 'id');

        $days = [];

        foreach ($this->orders->lines() as $order) {
            $date = (string) $order['requested_delivery_date'];

            if ($date < $startDate || $date > $endDate) {
                continue;
            }

            $depotId = (int) $order['depot_id'];

            if ($depotIds !== null && ! in_array($depotId, $allowedDepotIds, true)) {
                continue;
            }

            $depot = $depots->get($depotId);

            if ($depot === null) {
                continue;
            }

            $scheduleLine = $linesByKey->get($this->lineKey(
                $depotId,
                (string) $order['erp_entity_code'],
                (string) $order['erp_line_reference'],
                $date,
            ));

            $qty = $scheduleLine !== null
                ? (int) $scheduleLine->dispatch_qty
                : (int) $order['ordered_quantity'];
            $tonnes = $scheduleLine !== null
                ? (float) $scheduleLine->tonnage
                : round((float) ($weights[$order['sku_master_id']] ?? 0) * $qty, 3);
            $status = $this->status($scheduleLine);

            $days[$date] ??= $this->emptyDay();
            $days[$date]['qty'] += $qty;
            $days[$date]['tonnes'] = round($days[$date]['tonnes'] + $tonnes, 3);
            $days[$date]['confirmed'] += $status === 'confirmed' || $status === 'loaded' ? 1 : 0;
            $days[$date]['loaded'] += $status === 'loaded' ? 1 : 0;
            $days[$date]['lines'][] = [
                'reference' => (string) ($scheduleLine->erp_order_reference ?? $order['erp_order_reference']),
                'customer' => (string) ($scheduleLine->customer_name ?? $order['customer_name']),
                'suburb' => $scheduleLine->suburb ?? null,
                'sku' => (string) ($scheduleLine->stock_code ?? ''),
                'qty' => $qty,
                'tonnes' => $tonnes,
                'status' => $status,
                'carrier' => $scheduleLine?->transportCarrier?->name,
                'depot' => $depot->name,
            ];
        }

        $loads = DispatchLoad::query()
            ->whereDate('dispatch_date', '>=', $startDate)
            ->whereDate('dispatch_date', '<=', $endDate)
            ->when($depotIds !== null, fn ($query) => $query->whereIn('depot_id', $allowedDepotIds))
            ->get(['dispatch_date', 'current_weight_tonnes']);

        foreach ($loads as $load) {
            $date = $load->dispatch_date->toDateString();
            $days[$date] ??= $this->emptyDay();
            $days[$date]['loads']++;
            $days[$date]['load_tonnes'] = round($days[$date]['load_tonnes'] + (float) $load->current_weight_tonnes, 3);
        }

        ksort($days);

        return collect($days);
    }

    private function status(?DeliveryScheduleLine $line): string
    {
        if ($line === null) {
            return 'due';
        }

        $onLoad = $line->orderAllocation?->dispatchItems
            ->contains(fn ($item): bool => $item->dispatchLoad?->dispatch_date?->toDateString() === $line->schedule->schedule_date->toDateString());

        if ($onLoad) {
            return 'loaded';
        }

        return $line->is_confirmed ? 'confirmed' : 'scheduled';
    }

    private function lineKey(int $depotId, string $entity, string $lineReference, string $date): string
    {
        return $depotId.'|'.$entity.'|'.$lineReference.'|'.$date;
    }

    /**
     * @return HorizonDay
     */
    private function emptyDay(): array
    {
        return [
            'qty' => 0,
            'tonnes' => 0.0,
            'confirmed' => 0,
            'loaded' => 0,
            'loads' => 0,
            'load_tonnes' => 0.0,
            'lines' => [],
        ];
    }
}
