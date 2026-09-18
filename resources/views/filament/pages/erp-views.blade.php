<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">RCPA SQL views</x-slot>
        <x-slot name="description">
            Read-only Eloquent access to RCP-AUS-SQL01. Store `RCPA_SQL_*` values only in local `.env`. If status is unreadable, join the corporate network or VPN and retry.
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button wire:click="retryConnection" size="sm" color="gray" icon="heroicon-m-arrow-path">
                Retry connection
            </x-filament::button>
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">View</th>
                        <th class="py-2 pr-4 font-medium">Model</th>
                        <th class="py-2 pr-4 font-medium">Status</th>
                        <th class="py-2 font-medium">Sample key</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($views as $view)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">
                                <button
                                    type="button"
                                    wire:click="$set('selectedTable', '{{ $view['table'] }}')"
                                    class="text-left underline decoration-gray-300 underline-offset-2 hover:text-[#002F67] dark:hover:text-white"
                                >
                                    {{ $view['table'] }}
                                </button>
                            </td>
                            <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $view['model'] }}</td>
                            <td class="py-3 pr-4">
                                @if ($view['status'] === 'readable')
                                    <x-filament::badge color="success">Readable</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger">Unreadable</x-filament::badge>
                                    @if ($view['reason'])
                                        <p class="mt-1 text-xs text-gray-500">{{ $view['reason'] }}</p>
                                    @endif
                                @endif
                            </td>
                            <td class="py-3 text-gray-600 dark:text-gray-300">
                                @if ($view['has_row'])
                                    {{ $view['identifier'] }}
                                @elseif ($view['status'] === 'readable')
                                    No rows
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($preview['rows'] !== [])
        <x-filament::section>
            <x-slot name="heading">{{ $this->selectedTable }}</x-slot>
            <x-slot name="description">First rows via Eloquent. Writes are blocked on this connection.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            @foreach ($preview['columns'] as $column)
                                <th class="py-2 pr-4 font-medium">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                @foreach ($preview['columns'] as $column)
                                    <td class="py-2 pr-4 text-gray-700 dark:text-gray-300">{{ $row[$column] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
