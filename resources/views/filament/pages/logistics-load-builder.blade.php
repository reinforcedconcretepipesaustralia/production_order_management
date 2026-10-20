<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Load workspace</x-slot>

                <div class="grid gap-4">
                    <x-operational-filters
                        :plants="$plants"
                        :shifts="$shifts"
                        :machines="$machines"
                        :columns="1"
                        class="sm:grid-cols-1"
                        aria-label="Load builder filters"
                    />

                    <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                        Existing load
                        <select wire:model.live="dispatchLoadId" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                            <option value="">Select a load</option>
                            @foreach ($loads as $loadOption)
                                <option value="{{ $loadOption->id }}">{{ $loadOption->load_reference }} · {{ $loadOption->depot->code }} · {{ $loadOption->dispatch_date->format('d M') }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </x-filament::section>

            @if ($canCreate)
                <x-filament::section>
                    <x-slot name="heading">Create dispatch load</x-slot>

                    <form wire:submit="createLoad" class="grid gap-4">
                        <label class="grid gap-1 text-sm font-medium">
                            Load reference
                            <input wire:model="loadReference" type="text" maxlength="80" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                            @error('loadReference') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="grid gap-1 text-sm font-medium">
                            Dispatch date
                            <input wire:model="dispatchDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                            @error('dispatchDate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="grid gap-1 text-sm font-medium">
                            Capacity (tonnes)
                            <input wire:model="capacityTonnes" type="number" min="0.001" step="0.001" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                            @error('capacityTonnes') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        </label>
                        @error('depotId') <span class="text-xs text-danger-600">Select an accessible plant before creating a load.</span> @enderror

                        <x-filament::button type="submit" icon="heroicon-o-plus">Create load</x-filament::button>
                    </form>
                </x-filament::section>
            @endif
        </div>

        <div class="space-y-6">
            @if ($load === null)
                <x-filament::section>
                    <div class="rounded-lg border border-dashed border-gray-300 px-6 py-16 text-center dark:border-white/15">
                        <p class="font-semibold text-gray-900 dark:text-white">Select or create a dispatch load</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Eligible allocations and capacity controls will appear here.</p>
                    </div>
                </x-filament::section>
            @else
                @php($utilization = (float) $load->capacity_tonnes > 0 ? min(100, ((float) $load->current_weight_tonnes / (float) $load->capacity_tonnes) * 100) : 0)
                <x-filament::section>
                    <x-slot name="heading">{{ $load->load_reference }}</x-slot>
                    <x-slot name="description">{{ $load->depot->name }} · Dispatch {{ $load->dispatch_date->format('d M Y') }}</x-slot>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Current weight</p>
                            <p class="text-2xl font-bold text-primary-700 dark:text-primary-300">{{ number_format((float) $load->current_weight_tonnes, 3) }} t</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Capacity</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((float) $load->capacity_tonnes, 3) }} t</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-gray-500">Utilization</p>
                            <p class="text-2xl font-bold {{ $utilization >= 95 ? 'text-warning-700' : 'text-gray-900 dark:text-white' }}">{{ number_format($utilization, 1) }}%</p>
                        </div>
                    </div>

                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuenow="{{ round($utilization) }}" aria-valuemin="0" aria-valuemax="100" aria-label="Load capacity utilization">
                        <div class="h-full rounded-full {{ $utilization >= 95 ? 'bg-warning-500' : 'bg-primary-600' }}" style="width: {{ number_format($utilization, 3, '.', '') }}%"></div>
                    </div>
                </x-filament::section>

                @if ($canSendNotification)
                    <x-filament::section>
                        <x-slot name="heading">Dispatch email</x-slot>
                        <x-slot name="description">
                            Queue the load summary through Microsoft Graph after the load has at least one item.
                        </x-slot>

                        @if ($notificationAttempt?->status === 'sent')
                            <p class="text-sm text-success-700 dark:text-success-400">
                                Sent {{ $notificationAttempt->sent_at?->diffForHumans() }}.
                            </p>
                        @elseif (in_array($notificationAttempt?->status, ['queued', 'sending'], true))
                            <p class="text-sm text-primary-700 dark:text-primary-300">
                                This load notification is {{ $notificationAttempt->status }}.
                            </p>
                        @else
                            <form wire:submit="sendDispatchNotification" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                <label class="grid gap-1 text-sm font-medium">
                                    Recipient email addresses
                                    <input
                                        wire:model="dispatchRecipients"
                                        type="text"
                                        maxlength="4000"
                                        placeholder="dispatch@example.com, customer@example.com"
                                        class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                                    >
                                    <span class="text-xs text-gray-500">Separate up to 20 addresses with commas, semicolons, or spaces.</span>
                                    @error('dispatchRecipients') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                                </label>
                                <x-filament::button type="submit" icon="heroicon-o-envelope">
                                    {{ $notificationAttempt?->status === 'failed' ? 'Retry dispatch notification' : 'Send dispatch notification' }}
                                </x-filament::button>
                            </form>

                            @if ($notificationAttempt?->status === 'failed')
                                <p class="mt-3 text-sm text-danger-600">
                                    The previous attempt failed: {{ $notificationAttempt->failure_message }}
                                </p>
                            @endif
                        @endif
                    </x-filament::section>
                @endif

                @if ($canManageLoad)
                    <x-filament::section>
                        <x-slot name="heading">Add eligible allocation</x-slot>
                        <x-slot name="description">Only allocations from {{ $load->depot->code }} are available. Final quantity and QC eligibility are checked atomically.</x-slot>

                        <form wire:submit="addAllocation" class="grid gap-4 md:grid-cols-[minmax(0,1fr)_8rem_auto] md:items-end">
                            <label class="grid gap-1 text-sm font-medium">
                                Allocation
                                <select wire:model="allocationId" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                                    <option value="">Select eligible allocation</option>
                                    @foreach ($allocations as $allocation)
                                        @php($sku = $allocation->skuForDisplay())
                                        <option value="{{ $allocation->id }}">
                                            {{ $allocation->erpOrderReferenceForDisplay() }} · {{ $sku?->sku_code ?? '—' }} · {{ $allocation->quantity - (int) ($allocation->loaded_quantity ?? 0) }} remaining
                                        </option>
                                    @endforeach
                                </select>
                                @error('allocationId') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                            </label>
                            <label class="grid gap-1 text-sm font-medium">
                                Quantity
                                <input wire:model="quantity" type="number" min="1" step="1" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                @error('quantity') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                            </label>
                            <x-filament::button type="submit" icon="heroicon-o-plus">Add to load</x-filament::button>
                        </form>
                    </x-filament::section>
                @endif

                <x-filament::section>
                    <x-slot name="heading">Load items</x-slot>

                    @if ($load->items->isEmpty())
                        <p class="py-8 text-center text-sm text-gray-500">This load has no allocations yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                                    <tr>
                                        <th class="px-3 py-2">Order / SKU</th>
                                        <th class="px-3 py-2">Provenance</th>
                                        <th class="px-3 py-2 text-right">Quantity</th>
                                        <th class="px-3 py-2 text-right">Weight</th>
                                        @if ($canManageLoad)<th class="px-3 py-2"><span class="sr-only">Actions</span></th>@endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($load->items as $item)
                                        @php($allocation = $item->orderAllocation)
                                        @php($itemSku = $allocation->skuForDisplay())
                                        <tr wire:key="load-item-{{ $item->id }}">
                                            <td class="px-3 py-3">
                                                <span class="block font-semibold text-gray-900 dark:text-white">{{ $allocation->erpOrderReferenceForDisplay() }}</span>
                                                <span class="text-xs text-gray-500">{{ $itemSku?->sku_code ?? '—' }}</span>
                                            </td>
                                            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">
                                                Allocation #{{ $item->order_allocation_id }} · {{ filled($allocation->stock_location) ? 'Prime stock' : ($allocation->production_schedule_id ? 'Production schedule' : 'Inventory') }}
                                            </td>
                                            <td class="px-3 py-3 text-right">{{ number_format($item->quantity) }}</td>
                                            <td class="px-3 py-3 text-right">{{ number_format((float) $item->weight_tonnes, 3) }} t</td>
                                            @if ($canManageLoad)
                                                <td class="px-3 py-3 text-right">
                                                    <x-filament::button wire:click="removeItem({{ $item->id }})" color="danger" size="xs">Remove</x-filament::button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">ERP sales orders</x-slot>
        <x-slot name="description">
            Read-only Eloquent rows from `dbo.vwSALES_ORDERS` for the next 45 days. Credentials stay in local `.env`.
        </x-slot>

        @if ($erpOrders['status'] !== 'readable')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $erpOrders['reason'] }} Join the corporate network or VPN and refresh to pull this view.</p>
        @elseif ($erpOrders['rows'] === [])
            <p class="text-sm text-gray-500">No ERP sales-order rows were returned for this window.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Entity</th>
                            <th class="py-2 pr-4 font-medium">Ordered</th>
                            <th class="py-2 pr-4 font-medium">Delivery</th>
                            <th class="py-2 pr-4 font-medium">Order qty</th>
                            <th class="py-2 pr-4 font-medium">Allocated</th>
                            <th class="py-2 font-medium">Backorder</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($erpOrders['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['entity'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['order_date'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['delivery_date'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['order_qty'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['allocated_qty'] }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['backorder_qty'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
