<?php

namespace App\Services\Logistics;

use App\Exceptions\IneligibleLoadQuantityException;
use App\Models\DeliveryScheduleLine;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use App\Services\Scheduling\LoadCapacityService;
use App\Services\Scheduling\LoadWeightResult;

final class ScheduleLineDispatchService
{
    public function __construct(private LoadCapacityService $loads) {}

    public function addToLoad(DeliveryScheduleLine $line, DispatchLoad $load): LoadWeightResult
    {
        $line->loadMissing('schedule');

        if (! $line->is_confirmed) {
            throw new IneligibleLoadQuantityException('Confirm the schedule line before adding it to a load.');
        }

        if ($line->order_allocation_id === null) {
            throw new IneligibleLoadQuantityException('This line has no allocation to load.');
        }

        if ((int) $line->schedule->depot_id !== (int) $load->depot_id) {
            throw new IneligibleLoadQuantityException('The load plant does not match the delivery schedule.');
        }

        if ($line->schedule->schedule_date->toDateString() !== $load->dispatch_date->toDateString()) {
            throw new IneligibleLoadQuantityException('The load date does not match the delivery schedule.');
        }

        $allocation = OrderAllocation::query()->findOrFail($line->order_allocation_id);
        $alreadyLoaded = (int) DispatchLoadItem::query()
            ->where('order_allocation_id', $allocation->id)
            ->sum('quantity');
        $quantity = (int) $line->dispatch_qty - $alreadyLoaded;

        if ($quantity < 1) {
            throw new IneligibleLoadQuantityException('This line is already fully loaded.');
        }

        return $this->loads->addItem($load, $allocation, $quantity);
    }
}
