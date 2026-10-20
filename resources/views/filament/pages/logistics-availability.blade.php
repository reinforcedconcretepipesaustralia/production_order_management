<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <x-filament::section>
            <x-slot name="heading">Filters</x-slot>

            <div class="grid gap-4">
                <x-operational-filters
                    :plants="$plants"
                    :shifts="$shifts"
                    :machines="$machines"
                    :columns="1"
                    class="sm:grid-cols-1"
                    aria-label="Availability filters"
                />

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Dispatch date
                    <input wire:model.live="dispatchDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                </label>

                @if ($canReconcile)
                    <x-filament::button wire:click="runReconcile" icon="heroicon-o-arrow-path">
                        Run reconcile now
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Stock and dispatch readiness</x-slot>
            <x-slot name="description">
                @if ($depot)
                    {{ $depot->name }} · prime SOH and quantities dispatch-ready on {{ \Illuminate\Support\Carbon::parse($dispatchDate)->format('d M Y') }}
                @else
                    Select a plant to view availability.
                @endif
            </x-slot>

            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No open demand or stock for this plant.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                                <th class="px-3 py-2">SKU</th>
                                <th class="px-3 py-2 text-right">Prime SOH</th>
                                <th class="px-3 py-2 text-right">Open order</th>
                                <th class="px-3 py-2 text-right">Allocated</th>
                                <th class="px-3 py-2 text-right">Dispatch ready</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="px-3 py-2 font-medium">{{ $row['sku_code'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['prime_soh'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['open_order_qty'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $row['allocated_qty'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-primary-700 dark:text-primary-300">{{ $row['dispatch_ready_qty'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
