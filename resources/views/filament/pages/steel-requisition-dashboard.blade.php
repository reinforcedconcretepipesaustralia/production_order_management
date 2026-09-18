<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <form class="grid flex-1 gap-4 sm:grid-cols-3" aria-label="Steel requisition filters">
                <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                    Depot
                    <select wire:model.live="depotId" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                        <option value="">All accessible depots</option>
                        @foreach ($depots as $depot)
                            <option value="{{ $depot->id }}">{{ $depot->code }} — {{ $depot->name }}</option>
                        @endforeach
                    </select>
                </label>

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
            </form>

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

    @if ($rows->isEmpty())
        <x-filament::section>
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No steel forecasts found</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Adjust the filters or create a forecast stock position for an accessible depot.</p>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-4 xl:grid-cols-2">
            @foreach ($rows as $row)
                @php($forecast = $row['forecast'])
                <x-filament::section>
                    <x-slot name="heading">{{ $forecast->wire_code }}</x-slot>
                    <x-slot name="description">{{ $forecast->depot->code }} · Due {{ $forecast->forecast_date->format('d M Y') }}</x-slot>

                    <div class="mb-4">
                        <x-filament::badge :color="match ($row['urgency']) {
                            'overdue' => 'danger',
                            'critical' => 'warning',
                            'watch' => 'info',
                            default => 'success',
                        }">
                            {{ str($row['urgency'])->title() }}
                        </x-filament::badge>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-gray-500">Required</dt>
                            <dd class="font-semibold text-gray-900 dark:text-white">{{ number_format((float) $row['required_kg'], 1) }} kg</dd>
                            <dd class="text-xs text-gray-500">{{ $row['required_coils'] }} coils</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">On hand</dt>
                            <dd class="font-semibold text-gray-900 dark:text-white">{{ number_format((float) $row['stock_kg'], 1) }} kg</dd>
                            <dd class="text-xs text-gray-500">{{ $forecast->on_hand_coils }} coils</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">On order</dt>
                            <dd class="font-semibold text-gray-900 dark:text-white">{{ number_format((float) $row['on_order_kg'], 1) }} kg</dd>
                            <dd class="text-xs text-gray-500">{{ $forecast->on_order_coils }} coils</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Projected ending</dt>
                            <dd class="font-semibold {{ (float) $row['projected_ending_kg'] < 0 ? 'text-danger-700' : 'text-gray-900 dark:text-white' }}">
                                {{ number_format((float) $row['projected_ending_kg'], 1) }} kg
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-gray-500">Replenishment shortfall</dt>
                            <dd class="font-semibold {{ $row['recommended_coils'] > 0 ? 'text-danger-700' : 'text-success-700' }}">
                                {{ number_format((float) $row['shortfall_kg'], 1) }} kg
                                @if ($row['recommended_coils'] > 0)
                                    · {{ $row['recommended_coils'] }} coils recommended
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
