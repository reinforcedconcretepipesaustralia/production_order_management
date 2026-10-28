<x-filament-panels::page>
    <div class="grid gap-6 xl:grid-cols-[18rem_minmax(0,1fr)]">
        <x-filament::section>
            <x-slot name="heading">Plant</x-slot>
            <x-operational-filters
                :plants="$plants"
                :shifts="$shifts"
                :machines="$machines"
                :columns="1"
                class="sm:grid-cols-1"
                aria-label="Dispatch calendar filters"
            />
        </x-filament::section>

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">
                    <span class="flex items-center justify-between gap-3">
                        <span>{{ $monthLabel }}</span>
                        <span class="flex gap-2">
                            <x-filament::button size="sm" color="gray" wire:click="previousMonth">Previous</x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="nextMonth">Next</x-filament::button>
                        </span>
                    </span>
                </x-slot>
                <x-slot name="description">Due pipe from open orders, with confirmed schedule lines and loads already planned.</x-slot>

                <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 text-xs dark:border-white/10 dark:bg-white/10 sm:text-sm">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="bg-gray-50 px-2 py-1 font-medium text-gray-500 dark:bg-gray-900 dark:text-gray-400">{{ $weekday }}</div>
                    @endforeach
                    @foreach ($cells as $cell)
                        <button
                            type="button"
                            wire:click="selectDate('{{ $cell['date'] }}')"
                            class="min-h-24 bg-white p-2 text-left dark:bg-gray-950 {{ $cell['inMonth'] ? '' : 'opacity-40' }} {{ $selectedDate === $cell['date'] ? 'ring-2 ring-inset ring-primary-600' : '' }}"
                        >
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $cell['day'] }}</span>
                            @if ($cell['summary'] && ($cell['summary']['qty'] > 0 || $cell['summary']['loads'] > 0))
                                <span class="mt-1 block text-gray-700 dark:text-gray-200">{{ $cell['summary']['qty'] }} pipe</span>
                                <span class="block text-gray-500">{{ number_format($cell['summary']['tonnes'], 1) }} t</span>
                                @if ($cell['summary']['confirmed'] > 0)
                                    <span class="mt-1 block text-success-700 dark:text-success-400">{{ $cell['summary']['confirmed'] }} confirmed</span>
                                @endif
                                @if ($cell['summary']['loads'] > 0)
                                    <span class="block text-primary-700 dark:text-primary-300">{{ $cell['summary']['loads'] }} load{{ $cell['summary']['loads'] === 1 ? '' : 's' }}</span>
                                @endif
                            @endif
                        </button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ \Illuminate\Support\Carbon::parse($selectedDate)->format('l j F Y') }}</x-slot>
                <x-slot name="description">
                    <a href="{{ $scheduleUrl }}" class="text-primary-700 underline dark:text-primary-300">Open this day in the delivery schedule</a>
                </x-slot>

                @if ($selected === null || $selected['lines'] === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nothing is due on this day.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-left dark:border-white/10">
                                    <th class="px-2 py-2">Plant</th>
                                    <th class="px-2 py-2">Order</th>
                                    <th class="px-2 py-2">Customer</th>
                                    <th class="px-2 py-2">Suburb</th>
                                    <th class="px-2 py-2">Pipe</th>
                                    <th class="px-2 py-2 text-right">Qty</th>
                                    <th class="px-2 py-2 text-right">T</th>
                                    <th class="px-2 py-2">Status</th>
                                    <th class="px-2 py-2">Transport</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($selected['lines'] as $line)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-2 py-2">{{ $line['depot'] }}</td>
                                        <td class="px-2 py-2 font-medium">{{ $line['reference'] }}</td>
                                        <td class="px-2 py-2">{{ $line['customer'] }}</td>
                                        <td class="px-2 py-2">{{ $line['suburb'] ?: '—' }}</td>
                                        <td class="px-2 py-2">{{ $line['sku'] ?: '—' }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums">{{ $line['qty'] }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums">{{ number_format($line['tonnes'], 3) }}</td>
                                        <td class="px-2 py-2 capitalize">{{ $line['status'] }}</td>
                                        <td class="px-2 py-2">{{ $line['carrier'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
