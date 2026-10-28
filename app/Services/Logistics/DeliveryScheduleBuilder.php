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
use App\Services\Sync\StockSync;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class DeliveryScheduleBuilder
{
    public function __construct(
        private LogisticsReconcileService $reconcile,
        private OrderSync $orders,
        private OpenOrderLineReader $openOrders,
        private StockSync $stock,
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

        $openByLine = $this->openOrders->lines((string) $depot->erp_entity_code)
            ->keyBy(fn (array $line): string => (string) $line['line_unique_id']);

        $primeBySku = $this->stock->forDepot($depot);

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
            $presentation = $this->presentation($order, $openByLine, $primeBySku);

            if ($existing->has($key)) {
                /** @var DeliveryScheduleLine $line */
                $line = $existing[$key];
                $line->fill([
                    ...$presentation,
                    'sort_order' => $sort++,
                    'order_allocation_id' => $this->primaryAllocationId($depot, $order),
                ]);
                $line->save();
                $this->applyLineMetrics($line);

                continue;
            }

            $line = DeliveryScheduleLine::query()->create([
                'delivery_schedule_id' => $schedule->id,
                'erp_entity_code' => $order['erp_entity_code'],
                'erp_line_reference' => $order['erp_line_reference'],
                'dispatch_qty' => $dispatchQty,
                'sort_order' => $sort++,
                'order_allocation_id' => $this->primaryAllocationId($depot, $order),
                ...$presentation,
            ]);
            $this->applyLineMetrics($line);
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

    public function applyLineMetrics(DeliveryScheduleLine $line): void
    {
        $line->loadMissing(['orderAllocation.sku', 'transportCarrier', 'transportCarrierVehicle']);

        $sku = $line->orderAllocation?->sku;

        if ($sku === null && filled($line->stock_code)) {
            $sku = SkuMaster::query()
                ->where('erp_entity_code', $line->erp_entity_code)
                ->where('sku_code', $line->stock_code)
                ->first();
        }

        $line->tonnage = round((float) ($sku?->unit_weight_tonnes ?? 0) * (int) $line->dispatch_qty, 4);
        $line->estimated_freight_amount = $this->freightAmount($line);
        $line->save();
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  Collection<string, array<string, mixed>>  $openByLine
     * @param  Collection<int, array{prime: int, rework: int}>  $primeBySku
     * @return array<string, mixed>
     */
    private function presentation(array $order, $openByLine, $primeBySku): array
    {
        $open = $openByLine->get((string) $order['erp_line_reference']);
        $prime = $primeBySku->get((int) $order['sku_master_id']);

        return [
            'erp_order_reference' => $open['reference_nbr'] ?? $order['erp_order_reference'] ?? null,
            'customer_name' => $open['customer_name'] ?? $order['customer_name'] ?? null,
            'contact_name' => $open['contact_name'] ?? null,
            'phone' => $open['phone'] ?? null,
            'delivery_name' => $open['project_name'] ?? null,
            'suburb' => $open['suburb'] ?? null,
            'zone_code' => $open['zone_code'] ?? null,
            'customer_order' => $open['customer_order'] ?? null,
            'stock_code' => $open['stock_code'] ?? null,
            'backorder_qty' => (int) round((float) ($open['backorder_qty'] ?? $order['ordered_quantity'] ?? 0)),
            'prime_soh' => (int) ($prime['prime'] ?? 0),
        ];
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

    private function freightAmount(DeliveryScheduleLine $line): ?float
    {
        if ($line->transport_carrier_id === null) {
            return null;
        }

        $carrier = $line->transportCarrier ?? TransportCarrier::query()->find($line->transport_carrier_id);

        if ($carrier === null) {
            return null;
        }

        $estimate = $this->freight->estimate(
            $line->erp_entity_code,
            (string) ($line->zone_code ?? ''),
            $carrier,
            (float) $line->tonnage,
            $line->transportCarrierVehicle,
        );

        return $estimate?->estimatedAmount;
    }
}
