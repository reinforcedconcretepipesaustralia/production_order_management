<x-filament-panels::page>
    <x-filament::section>
        <form class="grid gap-4 md:grid-cols-4" aria-label="Production board filters">
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
                From
                <input wire:model.live.debounce.400ms="fromDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>

            <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                To
                <input wire:model.live.debounce.400ms="toDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>

            <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                Status
                <select wire:model.live="status" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption }}">{{ str($statusOption)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Schedule timeline</x-slot>
        <x-slot name="description">Times are shown in each depot’s operating day. This board is intentionally view-only and does not imply drag-and-drop scheduling.</x-slot>

        @if ($lanes->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No production schedules found</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Adjust the depot, date, or status filters to widen the planning window.</p>
            </div>
        @else
            <div class="overflow-x-auto" role="region" aria-label="Production Gantt board" tabindex="0">
                <div class="min-w-[72rem]">
                    <div class="grid border-b border-gray-200 dark:border-white/10" style="grid-template-columns: 13rem repeat({{ $days->count() }}, minmax(12rem, 1fr));">
                        <div class="p-3 text-xs font-semibold uppercase tracking-wider text-gray-500">Depot / machine</div>
                        @foreach ($days as $day)
                            <div class="border-l border-gray-200 p-3 text-center dark:border-white/10">
                                <span class="block text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $day->format('D') }}</span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $day->format('d M') }}</span>
                            </div>
                        @endforeach
                    </div>

                    @foreach ($lanes as $lane)
                        <div class="grid border-b border-gray-100 last:border-0 dark:border-white/5" style="grid-template-columns: 13rem repeat({{ $days->count() }}, minmax(12rem, 1fr));">
                            <div class="p-3">
                                <span class="block text-xs font-semibold text-primary-700 dark:text-primary-300">{{ $lane['depot']->code }}</span>
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $lane['machine_name'] }}</span>
                            </div>

                            @foreach ($days as $day)
                                <div class="relative min-h-28 border-l border-gray-100 bg-gray-50/40 p-2 dark:border-white/5 dark:bg-white/[0.02]">
                                    @foreach ($lane['schedules']->filter(fn ($item) => $item['model']->schedule_date->isSameDay($day)) as $item)
                                        @php($schedule = $item['model'])
                                        <article
                                            class="absolute top-2 z-10 min-w-28 rounded-md border-l-4 bg-white p-2 shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10 {{ $item['conflicts']->isNotEmpty() || $item['is_over_capacity'] ? 'border-danger-500' : ($item['is_low_oee'] ? 'border-warning-500' : 'border-primary-600') }}"
                                            style="left: {{ number_format($item['left'], 3, '.', '') }}%; width: {{ number_format($item['width'], 3, '.', '') }}%;"
                                            aria-label="{{ $schedule->sku->sku_code }} from {{ $schedule->planned_start_at->format('H:i') }} to {{ $schedule->planned_end_at->format('H:i') }}"
                                        >
                                            <div class="truncate text-xs font-bold text-primary-700 dark:text-primary-300">{{ $schedule->sku->sku_code }}</div>
                                            <div class="whitespace-nowrap text-[0.7rem] text-gray-600 dark:text-gray-300">
                                                {{ $schedule->planned_start_at->format('H:i') }}–{{ $schedule->planned_end_at->format('H:i') }}
                                            </div>
                                            <div class="mt-1 flex flex-wrap gap-1 text-[0.65rem]">
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">{{ number_format($schedule->planned_quantity) }} pipes</span>
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">OEE {{ number_format((float) $schedule->planned_oee_percent, 0) }}%</span>
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-white/10">Cap {{ number_format($item['capacity']) }}</span>
                                            </div>
                                            @if ($item['is_over_capacity'])
                                                <p class="mt-1 text-[0.65rem] font-semibold text-danger-700">Over shift capacity</p>
                                            @endif
                                            @if ($item['conflicts']->isNotEmpty())
                                                <p class="mt-1 text-[0.65rem] font-semibold text-danger-700" title="{{ $item['conflicts']->pluck('reason')->join(' ') }}">
                                                    {{ $item['conflicts']->count() }} mould constraint(s)
                                                </p>
                                            @endif
                                        </article>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
