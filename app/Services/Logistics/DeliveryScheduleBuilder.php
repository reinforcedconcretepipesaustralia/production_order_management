<?php

namespace App\Services\Logistics;

use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\OrderAllocation;
use App\Models\SkuMaster;
use App\Models\TransportCarrier;
use App\Services\Scheduling\LoadCapacityService;
use App\Services\Scheduling\LogisticsReconcileService;
use App\Services\Sync\OrderSync;
use Carbon\CarbonInterface;

final class DeliveryScheduleBuilder
{
    public function __construct(
        private LogisticsReconcileService $reconcile,
        private OrderSync $orders,
        private LoadCapacityService $loads,
        private FreightEstimateService $freight,
    ) {}

    public function refresh(Depot $depot, CarbonInterface $scheduleDate, bool $runReconcile = true): DeliverySchedule
    {
        if ($runReconcile) {
            $this->orders->lines()
                ->filter(fn (array $line): bool => (int) $line['depot_id'] === $depot->id)
                ->pluck('sku_master_id')
                ->unique()
                ->each(fn (mixed $skuId): mixed => $this->reconcile->reconcile($depot->id, (int) $skuId));
        }

        $schedule = DeliverySchedule::query()
            ->where('depot_id', $depot->id)
            ->whereDate('schedule_date', $scheduleDate->toDateString())
            ->first();

        if ($schedule === null) {
            $schedule = DeliverySchedule::query()->create([
                'depot_id' => $depot->id,
                'schedule_date' => $scheduleDate->toDateString(),
                'status' => 'draft',
            ]);
        }

        $existing = $schedule->lines()->get()->keyBy(
            fn (DeliveryScheduleLine $line): string => $line->erp_entity_code.'|'.$line->erp_line_reference,
        );

        $eligibleOrders = $this->orders->lines()
            ->filter(fn (array $line): bool => (int) $line['depot_id'] === $depot->id
                && $line['requested_delivery_date'] === $scheduleDate->toDateString())
            ->sortBy([
                ['ordered_at', 'asc'],
                ['requested_delivery_date', 'asc'],
                ['erp_line_reference', 'asc'],
            ]);

        $seenKeys = [];
        $sort = 0;

        foreach ($eligibleOrders as $order) {
            $dispatchQty = $this->dispatchQtyForLine($depot, $order, $scheduleDate);

            if ($dispatchQty <= 0) {
                continue;
            }

            $key = $order['erp_entity_code'].'|'.$order['erp_line_reference'];
            $seenKeys[$key] = true;
            $tonnage = $this->tonnageForLine((int) $order['sku_master_id'], $dispatchQty);

            $attributes = [
                'dispatch_qty' => $dispatchQty,
                'tonnage' => $tonnage,
                'sort_order' => $sort++,
                'order_allocation_id' => $this->primaryAllocationId($depot, $order),
            ];

            if ($existing->has($key)) {
                /** @var DeliveryScheduleLine $line */
                $line = $existing[$key];
                $line->fill($attributes);
                $line->estimated_freight_amount = $this->estimatedFreight($line, $order, $tonnage);
                $line->save();

                continue;
            }

            DeliveryScheduleLine::query()->create([
                'delivery_schedule_id' => $schedule->id,
                'erp_entity_code' => $order['erp_entity_code'],
                'erp_line_reference' => $order['erp_line_reference'],
                ...$attributes,
            ]);
        }

        $schedule->lines()
            ->get()
            ->each(function (DeliveryScheduleLine $line) use ($seenKeys): void {
                $key = $line->erp_entity_code.'|'.$line->erp_line_reference;

                if (isset($seenKeys[$key]) || $line->is_confirmed) {
                    return;
                }

                $line->delete();
            });

        return $schedule->fresh(['lines']);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function dispatchQtyForLine(Depot $depot, array $order, CarbonInterface $scheduleDate): int
    {
        return (int) OrderAllocation::query()
            ->where('depot_id', $depot->id)
            ->where('erp_entity_code', $order['erp_entity_code'])
            ->where('erp_line_reference', $order['erp_line_reference'])
            ->with(['salesOrder.sku', 'sku', 'productionSchedule.actuals', 'inventory'])
            ->get()
            ->sum(fn (OrderAllocation $allocation): int => $this->loads->dispatchReadyQuantity($allocation, $scheduleDate));
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function primaryAllocationId(Depot $depot, array $order): ?int
    {
        return OrderAllocation::query()
            ->where('depot_id', $depot->id)
            ->where('erp_entity_code', $order['erp_entity_code'])
            ->where('erp_line_reference', $order['erp_line_reference'])
            ->orderBy('id')
            ->value('id');
    }

    private function tonnageForLine(int $skuMasterId, int $quantity): float
    {
        $weight = SkuMaster::query()->whereKey($skuMasterId)->value('unit_weight_tonnes');

        return round((float) $weight * $quantity, 4);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function estimatedFreight(DeliveryScheduleLine $line, array $order, float $tonnage): ?float
    {
        if ($line->transport_carrier_id === null) {
            return null;
        }

        $carrier = TransportCarrier::query()->find($line->transport_carrier_id);

        if ($carrier === null) {
            return null;
        }

        $zone = '';

        $estimate = $this->freight->estimate(
            (string) $order['erp_entity_code'],
            $zone,
            $carrier,
            $tonnage,
            $line->transportCarrierVehicle,
        );

        return $estimate?->estimatedAmount;
    }
}
