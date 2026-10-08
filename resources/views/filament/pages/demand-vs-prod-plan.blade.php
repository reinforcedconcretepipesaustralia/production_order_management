<x-filament-panels::page>
    <style>
        .demand-plan-table { border-collapse: collapse; width: 100%; min-width: 64rem; font-size: 0.8125rem; }
        .demand-plan-table th, .demand-plan-table td { border: 1px solid #bcbdbc; padding: 0.375rem 0.5rem; }
        .demand-plan-table thead th { background: #002f67; color: #fff; font-weight: 600; }
        .demand-plan-table thead tr:last-child th { background: #0a3d7c; }
        .demand-plan-table tbody td { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .demand-plan-table tbody th { text-align: left; font-weight: 500; position: sticky; left: 0; background: #fff; z-index: 1; }
        .demand-plan-table .is-total th, .demand-plan-table .is-total td { background: #e4ebf4; font-weight: 600; }
        .demand-plan-table .is-total th { background: #e4ebf4; }
        .demand-plan-table .is-negative { color: #b3261e; font-weight: 600; }
        .demand-plan-table .is-soh { background: #f1f5fa; }
        .demand-plan-table .is-month-end { border-right-width: 2px; }
        html.dark .demand-plan-table thead th { background: #002f67; }
        html.dark .demand-plan-table tbody th { background: #111827; color: #fff; }
        html.dark .demand-plan-table .is-total th,
        html.dark .demand-plan-table .is-total td { background: #0a3d7c; color: #fff; }
        html.dark .demand-plan-table .is-soh { background: #1f2937; }
        html.dark .demand-plan-table .is-negative { color: #f87171; }
    </style>

    <x-filament::section>
        <x-operational-filters
            :plants="$plants"
            :shifts="$shifts"
            :machines="$machines"
            :columns="5"
            aria-label="Demand vs production plan filters"
        >
            <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                Product
                <input
                    wire:model.live.debounce.400ms="productSearch"
                    type="search"
                    placeholder="Search product"
                    class="fi-input w-full min-w-0 rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                >
            </label>
            <label class="flex min-w-0 items-center gap-2 pb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                <input
                    wire:model.live="hideBlankPlan"
                    type="checkbox"
                    @checked($hideBlankPlan)
                    class="rounded border-gray-300 text-[#002F67] focus:ring-[#002F67] dark:border-white/20 dark:bg-white/5"
                >
                Hide blank plan
            </label>
        </x-operational-filters>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Demand vs Prod Plan</x-slot>
        <x-slot name="description">
            Outstanding orders against the production plan for the next {{ count($table['months']) }} months. Overdue demand sits in this month. Variance is plan minus demand.
        </x-slot>

        @if ($table['rows'] === [])
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No demand or plan in this window</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Widen the plant filter, or wait until open orders or schedule lines fall in the next six months.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="demand-plan-table">
                    <thead>
                        <tr>
                            <th rowspan="2">Product</th>
                            <th rowspan="2">SOH</th>
                            @foreach ($table['months'] as $month)
                                <th colspan="3" class="is-month-end" style="text-align: center">{{ $month->format('M-y') }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach ($table['months'] as $month)
                                <th>Demand</th>
                                <th>Plan</th>
                                <th class="is-month-end">Variance</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                <th>{{ $row['sku_code'] }}</th>
                                <td class="is-soh">{{ $this->formatQty($row['soh'], blankZero: false) }}</td>
                                @foreach ($table['months'] as $month)
                                    @php $key = $month->toDateString(); @endphp
                                    <td @class(['is-negative' => $row['demand'][$key] < 0])>{{ $this->formatQty($row['demand'][$key]) }}</td>
                                    <td @class(['is-negative' => $row['plan'][$key] < 0])>{{ $this->formatQty($row['plan'][$key]) }}</td>
                                    <td @class(['is-month-end', 'is-negative' => $row['variance'][$key] < 0])>{{ $this->formatQty($row['variance'][$key]) }}</td>
                                @endforeach
                            </tr>
                        @endforeach

                        <tr class="is-total">
                            <th>Grand Total</th>
                            <td class="is-soh">{{ $this->formatQty($table['totals']['soh'], blankZero: false) }}</td>
                            @foreach ($table['months'] as $month)
                                @php $key = $month->toDateString(); @endphp
                                <td @class(['is-negative' => $table['totals']['demand'][$key] < 0])>{{ $this->formatQty($table['totals']['demand'][$key]) }}</td>
                                <td @class(['is-negative' => $table['totals']['plan'][$key] < 0])>{{ $this->formatQty($table['totals']['plan'][$key]) }}</td>
                                <td @class(['is-month-end', 'is-negative' => $table['totals']['variance'][$key] < 0])>{{ $this->formatQty($table['totals']['variance'][$key]) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
