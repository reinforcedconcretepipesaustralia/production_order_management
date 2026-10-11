<x-filament-panels::page>
    <style>
        .prod-plan-table { border-collapse: collapse; width: 100%; min-width: 78rem; font-size: 0.8125rem; }
        .prod-plan-table th, .prod-plan-table td { border: 1px solid #bcbdbc; padding: 0.4rem 0.55rem; vertical-align: top; }
        .prod-plan-table thead th { background: #002f67; color: #fff; font-weight: 600; text-align: left; }
        .prod-plan-table .is-date, .prod-plan-table .is-shift { background: #fff6c7; font-weight: 600; white-space: nowrap; }
        .prod-plan-table .is-line { background: #fffce8; }
        .prod-plan-table .is-empty { background: #f4f4f4; color: #747678; }
        .prod-plan-table .is-drop { outline: 2px dashed #002f67; }
        .prod-plan-table .is-held { opacity: 0.72; }
        .prod-plan-table .is-danger { border-left: 4px solid #b3261e; }
        .prod-plan-table .is-warning { border-left: 4px solid #e0bb00; }
        .prod-plan-table tbody tr[draggable="true"] { cursor: grab; }
        html.dark .prod-plan-table .is-date,
        html.dark .prod-plan-table .is-shift { background: #3d3410; color: #fff; }
        html.dark .prod-plan-table .is-line { background: #1f2937; }
        html.dark .prod-plan-table .is-empty { background: #111827; }
    </style>

    <x-filament::section>
        <x-operational-filters
            :plants="$plants"
            :shifts="$shifts"
            :machines="$machines"
            :columns="7"
            aria-label="Production plan filters"
        >
            <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                From
                <input wire:model.live.debounce.400ms="fromDate" type="date" class="fi-input w-full min-w-0 rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>

            <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                To
                <input wire:model.live.debounce.400ms="toDate" type="date" class="fi-input w-full min-w-0 rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>

            <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                Status
                <select wire:model.live="status" class="fi-select-input w-full min-w-0 rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption }}">{{ str($statusOption)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                Plan run
                <select wire:model.live="planRunId" class="fi-select-input w-full min-w-0 rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                    <option value="">No generated plan</option>
                    @foreach ($runs as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </x-operational-filters>

        @if ($canManage)
            <div class="mt-3">
                <x-filament::button
                    wire:click="generatePlan"
                    wire:confirm="Generate a new Yatala plan? Draft lines that are not held will be replaced."
                    icon="heroicon-m-sparkles"
                >
                    Generate Yatala plan
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>

    @if ($run)
        <x-filament::section>
            <x-slot name="heading">What this plan does</x-slot>
            <x-slot name="description">
                {{ $run->horizon_from->format('j M Y') }} to {{ $run->horizon_to->format('j M Y') }}.
                The engine proposes the lines. Drag a product onto another date or shift to change it, then approve a week or month.
            </x-slot>

            @if ($canManage && $run->isDraft())
                <div class="flex flex-wrap gap-2">
                    <x-filament::button
                        wire:click="approveWeek"
                        wire:confirm="Approve this week? Those lines will be held against regeneration."
                        icon="heroicon-m-check"
                    >
                        Approve this week
                    </x-filament::button>
                    <x-filament::button
                        wire:click="approveMonth"
                        wire:confirm="Approve this month? Those lines will be held against regeneration."
                        color="info"
                        icon="heroicon-m-check-circle"
                    >
                        Approve this month
                    </x-filament::button>
                    <x-filament::button
                        wire:click="approve"
                        wire:confirm="Approve the whole plan? Every line will be held against regeneration."
                        outlined
                    >
                        Approve whole plan
                    </x-filament::button>
                    <x-filament::button
                        wire:click="discard"
                        wire:confirm="Discard this plan? Its lines will be cancelled."
                        color="danger"
                        outlined
                        icon="heroicon-m-x-circle"
                    >
                        Discard
                    </x-filament::button>
                </div>
            @elseif ($run->isApproved())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Approved{{ $run->approvedBy ? ' by '.$run->approvedBy->name : '' }}
                    on {{ $run->approved_at?->format('j M Y') }}. Generate a new plan to change it.
                </p>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Schedule</x-slot>
        <x-slot name="description">
            Dates stack down the left, with each plant’s shifts under them, the same way the workbook is read.
            @if ($editable)
                Drag a product row onto another date or shift.
            @endif
            Yatala is the pilot plant.
            @if ($canManage && ! $run)
                Use Generate Yatala plan to propose the next week.
            @endif
        </x-slot>

        @if ($grid->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No production schedules found</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Adjust the plant, shift, machine, date, or status filters to widen the planning window.</p>
            </div>
        @else
            @foreach ($grid as $plant)
                <div class="mb-6 overflow-x-auto last:mb-0">
                    <h3 class="mb-2 text-sm font-semibold tracking-wide text-primary-700 uppercase dark:text-primary-300">{{ $plant['depot']->code }} — {{ $plant['depot']->name }}</h3>
                    <table class="prod-plan-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift</th>
                                <th>Product</th>
                                <th>Machine</th>
                                <th>Pipes</th>
                                <th>OEE</th>
                                <th>Capacity</th>
                                <th>Mould type</th>
                                <th>Reo cage</th>
                                <th>Moulds #</th>
                                <th>Pallets #</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plant['days'] as $day)
                                @foreach ($day['shifts'] as $shiftIndex => $block)
                                    @php
                                        $lineCount = max(1, $block['lines']->count());
                                        $shift = $block['shift'];
                                    @endphp
                                    @for ($i = 0; $i < $lineCount; $i++)
                                        @php
                                            $item = $block['lines']->get($i);
                                            $schedule = $item['model'] ?? null;
                                            $canDrag = $editable && $schedule !== null && ! $schedule->is_locked;
                                        @endphp
                                        <tr
                                            @class([
                                                'is-line' => $schedule !== null,
                                                'is-empty' => $schedule === null,
                                                'is-held' => $schedule?->is_locked,
                                                'is-danger' => ($item['conflicts'] ?? collect())->isNotEmpty() || ($item['is_over_capacity'] ?? false),
                                                'is-warning' => ($item['is_low_oee'] ?? false),
                                            ])
                                            @if ($canDrag)
                                                draggable="true"
                                                x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $schedule->id }}')"
                                            @endif
                                            @if ($editable && $shift)
                                                x-on:dragover.prevent="$event.currentTarget.classList.add('is-drop')"
                                                x-on:dragleave="$event.currentTarget.classList.remove('is-drop')"
                                                x-on:drop.prevent="
                                                    $event.currentTarget.classList.remove('is-drop');
                                                    const id = Number($event.dataTransfer.getData('text/plain'));
                                                    if (id) $wire.moveLine(id, '{{ $day['date']->toDateString() }}', {{ $shift->id }})
                                                "
                                            @endif
                                        >
                                            @if ($shiftIndex === 0 && $i === 0)
                                                <td class="is-date" rowspan="{{ $day['rowspan'] }}">
                                                    <div>{{ $day['date']->format('D') }}</div>
                                                    <div>{{ $day['date']->format('j M Y') }}</div>
                                                </td>
                                            @endif
                                            @if ($i === 0)
                                                <td class="is-shift" rowspan="{{ $lineCount }}">
                                                    {{ $shift?->name ?? 'Unassigned' }}
                                                </td>
                                            @endif
                                            @if ($schedule)
                                                <td>
                                                    <span class="font-semibold text-primary-700 dark:text-primary-300">{{ $schedule->sku?->sku_code }}</span>
                                                    @if ($schedule->is_locked)
                                                        <span class="ml-1 text-[0.65rem] uppercase">Held</span>
                                                    @endif
                                                </td>
                                                <td>{{ $schedule->machine?->name ?? $schedule->machine?->code }}</td>
                                                <td>
                                                    @if ($editable && ! $schedule->is_locked)
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            step="1"
                                                            value="{{ $schedule->planned_quantity }}"
                                                            wire:change="updateQuantity({{ $schedule->id }}, $event.target.value)"
                                                            class="fi-input w-20 rounded border-gray-300 text-right dark:border-white/10 dark:bg-white/5"
                                                        >
                                                    @else
                                                        {{ number_format($schedule->planned_quantity) }}
                                                    @endif
                                                </td>
                                                <td>{{ number_format((float) $schedule->planned_oee_percent, 0) }}%</td>
                                                <td>{{ number_format($item['capacity']) }}</td>
                                                <td>{{ $schedule->sku?->mould_type }}</td>
                                                <td>{{ $schedule->sku?->reo_cage === null ? '' : ucfirst($schedule->sku->reo_cage) }}</td>
                                                <td>{{ $schedule->sku?->moulds_count }}</td>
                                                <td>{{ $schedule->sku?->pallets_count }}</td>
                                            @else
                                                <td colspan="9">Drop a product here</td>
                                            @endif
                                        </tr>
                                    @endfor
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ERP production demand</x-slot>
        <x-slot name="description">
            Read-only Eloquent rows from `dbo.vwProduction` on RCP-AUS-SQL01. Credentials stay in local `.env`.
        </x-slot>

        @if ($erpProduction['status'] !== 'readable')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $erpProduction['reason'] }} Join the corporate network or VPN and refresh to pull this view.</p>
        @elseif ($erpProduction['rows'] === [])
            <p class="text-sm text-gray-500">No ERP production rows in this date range.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Entity</th>
                            <th class="py-2 pr-4 font-medium">Ordered</th>
                            <th class="py-2 pr-4 font-medium">Required</th>
                            <th class="py-2 pr-4 font-medium">Qty ordered</th>
                            <th class="py-2 font-medium">Qty used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($erpProduction['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['entity'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['order_date'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['required_date'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['qty_ordered'] }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['qty_used'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ERP SKU master</x-slot>
        <x-slot name="description">
            Read-only Eloquent rows from `dbo.vwSTK_MASTER`. Credentials stay in local `.env`.
        </x-slot>

        @if ($erpSkuMaster['status'] !== 'readable')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $erpSkuMaster['reason'] }} Join the corporate network or VPN and refresh to pull this view.</p>
        @elseif ($erpSkuMaster['rows'] === [])
            <p class="text-sm text-gray-500">No ERP SKU master rows were returned.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Entity</th>
                            <th class="py-2 pr-4 font-medium">Unit weight</th>
                            <th class="py-2 pr-4 font-medium">Sales conversion</th>
                            <th class="py-2 font-medium">Standard cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($erpSkuMaster['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['entity'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['unit_weight'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['sales_conversion'] }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['standard_cost'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
