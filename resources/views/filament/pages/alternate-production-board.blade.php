<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Alternate production board</x-slot>
        <x-slot name="description">
            This view uses Guava Calendar to compare a resource timeline with the current production board.
        </x-slot>

        <div class="grid gap-3 text-sm text-gray-600 md:grid-cols-3 dark:text-gray-300">
            <p><span class="font-semibold text-gray-950 dark:text-white">Rows:</span> accessible depot and machine combinations.</p>
            <p><span class="font-semibold text-gray-950 dark:text-white">Events:</span> scheduled production positioned by planned start and end time.</p>
            <p><span class="font-semibold text-gray-950 dark:text-white">Mode:</span> view-only while we compare both approaches.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
