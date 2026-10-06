<x-filament-panels::page>
    <style>
        .steel-forecast-table { border-collapse: collapse; width: 100%; min-width: 48rem; font-size: 0.875rem; }
        .steel-forecast-table th, .steel-forecast-table td { border: 1px solid #bcbdbc; padding: 0.5rem 0.75rem; }
        .steel-forecast-table thead th { background: #002f67; color: #fff; font-weight: 600; }
        .steel-forecast-table thead tr:last-child th { background: #0a3d7c; }
        .steel-forecast-table tbody td { text-align: right; font-variant-numeric: tabular-nums; }
        .steel-forecast-table tbody th { text-align: left; font-weight: 500; }
        .steel-forecast-table .is-input th, .steel-forecast-table .is-input td { background: #fff6c7; }
        .steel-forecast-table .is-order th, .steel-forecast-table .is-order td { background: #e8f5ee; color: #1e6e42; font-weight: 600; }
    </style>

    <x-filament::section>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <x-operational-filters
                class="flex-1 sm:grid-cols-3 lg:grid-cols-5"
                :plants="$plants"
                :shifts="$shifts"
                :machines="$machines"
                :columns="5"
                aria-label="Steel forecast filters"
            >
                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Wire code
                    <select wire:model.live="wireCode" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                        <option value="">All wire codes</option>
                        @foreach ($wireCodes as $wire)
                            <option value="{{ $wire }}">{{ $wire }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Urgency
                    <select wire:model.live="urgency" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                        <option value="">All urgency states</option>
                        @foreach ($urgencies as $urgencyOption)
                            <option value="{{ $urgencyOption }}">{{ str($urgencyOption)->title() }}</option>
                        @endforeach
                    </select>
                </label>
            </x-operational-filters>

            @if ($canCalculate)
                <x-filament::button wire:click="calculateForecasts" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">
                    Calculate forecasts
                </x-filament::button>
            @endif
        </div>

        @if ($calculatedAt)
            <p class="mt-3 text-xs text-gray-500">Calculated {{ \Carbon\Carbon::parse($calculatedAt)->diffForHumans() }} from current schedules and stock positions.</p>
        @endif
    </x-filament::section>

    @if ($tables->isEmpty())
        <x-filament::section>
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No steel forecasts found</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Adjust the filters or create a forecast stock position for an accessible plant.</p>
            </div>
        </x-filament::section>
    @else
        @foreach ($tables as $table)
            <x-filament::section>
                <x-slot name="heading">Steel Forecast</x-slot>
                <x-slot name="description">{{ $table['depot']->code }} · Tonnes by Tencia wire stock code</x-slot>

                <div class="overflow-x-auto">
                    <table class="steel-forecast-table">
                        <thead>
                            <tr>
                                <th colspan="2"></th>
                                <th colspan="{{ max(1, count($table['wire_codes'])) }}" style="text-align: center">
                                    Wire Diameter — Tencia Stock Code
                                </th>
                            </tr>
                            <tr>
                                <th>DATE FROM</th>
                                <th>DATE TO</th>
                                @foreach ($table['wire_codes'] as $wireCode)
                                    <th style="text-align: right">{{ $wireCode }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table['weeks'] as $week)
                                <tr>
                                    <td style="text-align: left">{{ $week['from']->format('j/n/Y') }}</td>
                                    <td style="text-align: left">{{ $week['to']->format('j/n/Y') }}</td>
                                    @foreach ($table['wire_codes'] as $wireCode)
                                        <td>{{ $week['tonnes'][$wireCode] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach

                            <tr>
                                <td colspan="{{ 2 + count($table['wire_codes']) }}" style="border: 0; height: 0.75rem"></td>
                            </tr>

                            @php
                                $summaryRows = [
                                    ['label' => 'Requirement', 'values' => $table['requirement'], 'tone' => 'plain'],
                                    ['label' => 'Coils on hand', 'values' => $table['coils_on_hand'], 'tone' => 'input'],
                                    ['label' => 'Average weight per coil', 'values' => $table['average_coil_weight'], 'tone' => 'plain'],
                                    ['label' => 'Tonnes on hand', 'values' => $table['tonnes_on_hand'], 'tone' => 'plain'],
                                    ['label' => 'On order not delivered', 'values' => $table['on_order'], 'tone' => 'input'],
                                    ['label' => 'Steel being delivered from other state', 'values' => $table['transfers_in'], 'tone' => 'input'],
                                    ['label' => 'Calculated stock at end of period', 'values' => $table['ending_stock'], 'tone' => 'plain'],
                                    ['label' => 'Safety stock level (2 weeks based average of consumption)', 'values' => $table['safety_stock'], 'tone' => 'plain'],
                                    ['label' => 'Planned new order quantity', 'values' => $table['planned_order'], 'tone' => 'order'],
                                ];
                            @endphp

                            @foreach ($summaryRows as $row)
                                <tr @class([
                                    'is-input' => $row['tone'] === 'input',
                                    'is-order' => $row['tone'] === 'order',
                                ])>
                                    <th colspan="2">{{ $row['label'] }}</th>
                                    @foreach ($table['wire_codes'] as $wireCode)
                                        <td>{{ $row['values'][$wireCode] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    @endif

    <x-filament::section>
        <x-slot name="heading">ERP stock on hand</x-slot>
        <x-slot name="description">
            Read-only Eloquent rows from `dbo.vwSTOCK_ON_HAND`. Credentials stay in local `.env`.
        </x-slot>

        @if ($erpStock['status'] !== 'readable')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $erpStock['reason'] }} Join the corporate network or VPN and refresh to pull this view.</p>
        @elseif ($erpStock['rows'] === [])
            <p class="text-sm text-gray-500">No ERP stock-on-hand rows were returned.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Entity</th>
                            <th class="py-2 pr-4 font-medium">On hand</th>
                            <th class="py-2 pr-4 font-medium">Allocated</th>
                            <th class="py-2 font-medium">Available</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($erpStock['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['entity'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['qty_on_hand'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ $row['qty_allocated'] }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['qty_available'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">ERP BOM lines</x-slot>
        <x-slot name="description">
            Read-only Eloquent rows from `dbo.vwBOM_LINES`. Credentials stay in local `.env`.
        </x-slot>

        @if ($erpBom['status'] !== 'readable')
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $erpBom['reason'] }} Join the corporate network or VPN and refresh to pull this view.</p>
        @elseif ($erpBom['rows'] === [])
            <p class="text-sm text-gray-500">No ERP BOM lines were returned.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Entity</th>
                            <th class="py-2 font-medium">Bill order qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($erpBom['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">{{ $row['entity'] }}</td>
                                <td class="py-2 text-gray-600 dark:text-gray-300">{{ $row['bill_order_qty'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
