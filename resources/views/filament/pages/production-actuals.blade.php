<x-filament-panels::page>
    <x-filament::section>
        <x-operational-filters
            :plants="$plants"
            :shifts="$shifts"
            :machines="$machines"
            :columns="5"
            aria-label="Production actuals filters"
        >
            <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                From
                <input wire:model.live.debounce.400ms="fromDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>

            <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                To
                <input wire:model.live.debounce.400ms="toDate" type="date" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </label>
        </x-operational-filters>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">What the floor made</x-slot>
        <x-slot name="description">
            Type good pipes after the run. If anything was dumped, type the dump reason first, then the dumped quantity. These numbers are what you then key into Tencia.
            @unless ($canEnter)
                You can read this list but not change it.
            @endunless
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
