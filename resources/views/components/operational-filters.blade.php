@props([
    'plants',
    'shifts',
    'machines',
    'ariaLabel' => 'Operational filters',
    'columns' => 3,
])

@php
    $columnClass = match ((int) $columns) {
        1 => 'grid-cols-1',
        4 => 'sm:grid-cols-2 lg:grid-cols-4',
        5 => 'sm:grid-cols-2 lg:grid-cols-5',
        6 => 'sm:grid-cols-2 lg:grid-cols-6',
        7 => 'sm:grid-cols-2 lg:grid-cols-7',
        default => 'sm:grid-cols-2 lg:grid-cols-3',
    };
@endphp

<form {{ $attributes->class(['grid items-end gap-3', $columnClass]) }} aria-label="{{ $ariaLabel }}">
    <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
        Plant
        <select wire:model.live="depotId" class="fi-select-input w-full min-w-0 rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
            <option value="">All accessible plants</option>
            @foreach ($plants as $plant)
                <option value="{{ $plant->id }}">{{ $plant->code }} — {{ $plant->name }}</option>
            @endforeach
        </select>
    </label>

    <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
        Shift
        <select wire:model.live="shiftName" class="fi-select-input w-full min-w-0 rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
            <option value="">All shifts</option>
            @foreach ($shifts as $shift)
                <option value="{{ $shift }}">{{ $shift }}</option>
            @endforeach
        </select>
    </label>

    <label class="grid min-w-0 gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
        Machines
        <select wire:model.live="machineName" class="fi-select-input w-full min-w-0 rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
            <option value="">All machines</option>
            @foreach ($machines as $machine)
                <option value="{{ $machine }}">{{ $machine }}</option>
            @endforeach
        </select>
    </label>

    {{ $slot }}
</form>
