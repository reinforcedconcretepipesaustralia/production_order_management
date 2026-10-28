<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <x-filament::section>
            <x-slot name="heading">Schedule</x-slot>

            <div class="grid gap-4">
                <x-operational-filters
                    :plants="$plants"
                    :shifts="$shifts"
                    :machines="$machines"
                    :columns="1"
                    class="sm:grid-cols-1"
                    aria-label="Delivery schedule filters"
                />

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Delivery date
                    <input wire:model.live="scheduleDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                </label>

                @if ($canManage && $depot && $schedule)
                    <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Working load
                        <select wire:model="dispatchLoadId" class="fi-select-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                            <option value="">Select a load</option>
                            @foreach ($loads as $loadOption)
                                <option value="{{ $loadOption->id }}">{{ $loadOption->load_reference }} · {{ number_format((float) $loadOption->current_weight_tonnes, 1) }}/{{ number_format((float) $loadOption->capacity_tonnes, 1) }} t</option>
                            @endforeach
                        </select>
                    </label>
                    <form wire:submit="createScheduleLoad" class="grid gap-2">
                        <input wire:model="loadReference" type="text" maxlength="80" placeholder="New load reference" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                        @error('loadReference') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        <input wire:model="capacityTonnes" type="number" min="0.001" step="0.001" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                        <x-filament::button type="submit" size="sm" color="gray">Create load</x-filament::button>
                    </form>
                @endif

                @if ($canManage && $depot)
                    <x-filament::button wire:click="refreshSchedule" icon="heroicon-o-arrow-path">
                        Refresh from ERP
                    </x-filament::button>
                    @if ($schedule)
                        <x-filament::button wire:click="publishSchedule" color="success" icon="heroicon-o-check-badge">
                            Publish
                        </x-filament::button>
                        <x-filament::button wire:click="sendToCarriers" icon="heroicon-o-paper-airplane">
                            Send to carriers
                        </x-filament::button>
                        <x-filament::button wire:click="sendInternalCopy" icon="heroicon-o-envelope" color="gray">
                            Send internal copy
                        </x-filament::button>
                        <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" color="gray">
                            Export CSV
                        </x-filament::button>
                    @endif
                @endif
            </div>
        </x-filament::section>

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Lines</x-slot>
                <x-slot name="description">
                    @if ($depot)
                        {{ $depot->name }} · {{ \Illuminate\Support\Carbon::parse($scheduleDate)->format('d M Y') }}
                        @if ($schedule)
                            · {{ ucfirst($schedule->status) }}
                        @endif
                    @else
                        Select a plant to build the daily schedule.
                    @endif
                </x-slot>

                @if ($lineRows->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($depot && ! $schedule)
                            No schedule yet. Refresh from ERP to pull dispatch-ready lines due on this date.
                        @else
                            No dispatch-ready lines for this date.
                        @endif
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="fi-ta-table w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                                    <th class="px-2 py-2">Order</th>
                                    <th class="px-2 py-2">Customer</th>
                                    <th class="px-2 py-2">Location</th>
                                    <th class="px-2 py-2">Pipe</th>
                                    <th class="px-2 py-2 text-right">Qty</th>
                                    <th class="px-2 py-2 text-right">T</th>
                                    <th class="px-2 py-2">Transport</th>
                                    <th class="px-2 py-2">Confirmed</th>
                                    <th class="px-2 py-2">Delayed</th>
                                    <th class="px-2 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lineRows as $line)
                                    <tr class="border-b border-gray-100 dark:border-white/5 align-top" wire:key="schedule-line-{{ $line->id }}">
                                        <td class="px-2 py-2">
                                            <div class="font-medium">{{ $line->erp_order_reference ?: '—' }}</div>
                                            <div class="text-xs text-gray-500">{{ $line->customer_order ?: 'Line '.$line->erp_line_reference }}</div>
                                        </td>
                                        <td class="px-2 py-2">
                                            <div>{{ $line->customer_name ?: '—' }}</div>
                                            <div class="text-xs text-gray-500">{{ $line->contact_name }} {{ $line->phone }}</div>
                                            <div class="text-xs text-gray-500">{{ $line->delivery_name }}</div>
                                        </td>
                                        <td class="px-2 py-2">
                                            <div>{{ $line->suburb ?: '—' }}</div>
                                            <div class="text-xs text-gray-500">{{ $line->zone_code }}</div>
                                        </td>
                                        <td class="px-2 py-2">
                                            <div>{{ $line->stock_code ?: '—' }}</div>
                                            <div class="text-xs text-gray-500">Bal {{ $line->backorder_qty }} · SOH {{ $line->prime_soh }}</div>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            @if ($canManage)
                                                <input
                                                    type="number"
                                                    min="0"
                                                    class="fi-input w-20 rounded-lg border-gray-300 text-right dark:border-white/10 dark:bg-white/5"
                                                    value="{{ $line->dispatch_qty }}"
                                                    wire:change="updateLine({{ $line->id }}, 'dispatch_qty', $event.target.value)"
                                                >
                                            @else
                                                {{ $line->dispatch_qty }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 text-right tabular-nums">
                                            {{ number_format((float) $line->tonnage, 3) }}
                                            @php($loadedQty = (int) ($loadedByAllocation[$line->order_allocation_id] ?? 0))
                                            @if ($loadedQty > 0)
                                                <div class="text-xs text-primary-700 dark:text-primary-300">{{ $loadedQty }} on a load</div>
                                            @endif
                                            @if ($canManage && $line->is_confirmed && $loadedQty < $line->dispatch_qty)
                                                <button type="button" wire:click="addLineToLoad({{ $line->id }})" class="mt-1 text-xs font-medium text-primary-700 underline dark:text-primary-300">Add to load</button>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2">
                                            @if ($canManage)
                                                <select
                                                    class="fi-select-input max-w-[12rem] rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                                                    wire:change="updateLine({{ $line->id }}, 'transport_carrier_id', $event.target.value)"
                                                >
                                                    <option value="">—</option>
                                                    @foreach ($carriers as $carrier)
                                                        <option value="{{ $carrier->id }}" @selected($line->transport_carrier_id === $carrier->id)>
                                                            {{ $carrier->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @php($carrierVehicles = $vehicles->where('transport_carrier_id', $line->transport_carrier_id))
                                                @if ($carrierVehicles->count() > 1)
                                                    <select
                                                        class="fi-select-input mt-1 max-w-[12rem] rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-white/5"
                                                        wire:change="updateLine({{ $line->id }}, 'transport_carrier_vehicle_id', $event.target.value)"
                                                    >
                                                        @foreach ($carrierVehicles as $vehicle)
                                                            <option value="{{ $vehicle->id }}" @selected($line->transport_carrier_vehicle_id === $vehicle->id)>
                                                                {{ $vehicle->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                                @if ($line->estimated_freight_amount !== null)
                                                    <div class="text-xs text-gray-500">${{ number_format((float) $line->estimated_freight_amount, 2) }} est.</div>
                                                @endif
                                            @else
                                                {{ $line->transportCarrier?->name ?? '—' }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-2">
                                            @if ($canManage)
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                    @checked($line->is_confirmed)
                                                    wire:change="updateLine({{ $line->id }}, 'is_confirmed', $event.target.checked)"
                                                >
                                            @else
                                                {{ $line->is_confirmed ? 'Yes' : 'No' }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-2">
                                            @if ($canManage)
                                                <input
                                                    type="checkbox"
                                                    class="rounded border-gray-300"
                                                    @checked($line->is_delayed)
                                                    wire:change="updateLine({{ $line->id }}, 'is_delayed', $event.target.checked)"
                                                >
                                                <input
                                                    type="text"
                                                    class="fi-input mt-1 w-28 rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-white/5"
                                                    placeholder="Reason"
                                                    value="{{ $line->delay_reason }}"
                                                    wire:change="updateLine({{ $line->id }}, 'delay_reason', $event.target.value)"
                                                >
                                            @else
                                                {{ $line->is_delayed ? ($line->delay_reason ?: 'Yes') : 'No' }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 min-w-[10rem]">
                                            @if ($canManage)
                                                <input
                                                    type="text"
                                                    class="fi-input w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                                                    value="{{ $line->notes }}"
                                                    wire:change="updateLine({{ $line->id }}, 'notes', $event.target.value)"
                                                >
                                            @else
                                                {{ $line->notes }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                                    <td class="px-2 py-2" colspan="4">{{ $lineRows->count() }} lines</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ $totalQty }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ number_format($totalTonnage, 3) }}</td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            @if ($totalsByCarrier->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Transport summary</x-slot>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($totalsByCarrier as $carrierId => $totals)
                            @php
                                $label = $carrierId === 'unassigned'
                                    ? 'Unassigned'
                                    : ($carriers->firstWhere('id', (int) $carrierId)?->name ?? 'Carrier');
                            @endphp
                            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                                <p class="font-semibold">{{ $label }}</p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ number_format($totals['tonnage'], 3) }} t</p>
                                @if ($totals['freight'] > 0)
                                    <p class="text-sm text-gray-500">${{ number_format($totals['freight'], 2) }} est. freight</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
