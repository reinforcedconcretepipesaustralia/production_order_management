<x-filament-panels::page>
    <x-filament::section>
        <x-operational-filters
            :plants="$plants"
            :shifts="$shifts"
            :machines="$machines"
            aria-label="Guava production board filters"
        />
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Alternate production board</x-slot>
        <x-slot name="description">
            This view uses Guava Calendar to compare a resource timeline with the current production board.
        </x-slot>

        <div class="grid gap-3 text-sm text-gray-600 md:grid-cols-3 dark:text-gray-300">
            <p><span class="font-semibold text-gray-950 dark:text-white">Rows:</span> accessible plant and machine combinations.</p>
            <p><span class="font-semibold text-gray-950 dark:text-white">Events:</span> scheduled production positioned by planned start and end time.</p>
            <p><span class="font-semibold text-gray-950 dark:text-white">Mode:</span> view-only while we compare both approaches.</p>
        </div>
    </x-filament::section>

    @livewire(\App\Filament\Widgets\ProductionScheduleCalendar::class, [
        'depotId' => $depotId,
        'shiftName' => $shiftName,
        'machineName' => $machineName,
    ], key('guava-calendar-'.$depotId.'-'.$shiftName.'-'.$machineName))
</x-filament-panels::page>
