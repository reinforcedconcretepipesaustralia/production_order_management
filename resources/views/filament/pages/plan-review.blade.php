<x-filament-panels::page>
    <x-filament::section>
        <x-operational-filters
            :plants="$plants"
            :shifts="$shifts"
            :machines="$machines"
            :columns="4"
            aria-label="Plan review filters"
        >
            <label class="grid gap-1 text-sm font-medium text-gray-700 dark:text-gray-200">
                Plan run
                <select wire:model.live="planRunId" class="fi-select-input rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5">
                    @foreach ($runs as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </x-operational-filters>
    </x-filament::section>

    @if ($run === null)
        <x-filament::section>
            <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="font-semibold text-gray-900 dark:text-white">No plan has been generated yet</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Run <code>php artisan plan:generate</code> to propose one.</p>
            </div>
        </x-filament::section>
    @else
        @php($metrics = $run->metrics ?? [])

        <x-filament::section>
            <x-slot name="heading">What this plan does</x-slot>
            <x-slot name="description">
                {{ $run->horizon_from->format('j M Y') }} to {{ $run->horizon_to->format('j M Y') }},
                on the demand as it stood {{ $run->as_of_date->format('j M Y') }}.
            </x-slot>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'Pipes' => number_format($metrics['planned_eaches'] ?? 0),
                    'Tonnes' => number_format($metrics['planned_tonnes'] ?? 0, 1),
                    'Wire' => number_format($metrics['wire_tonnes'] ?? 0, 1) . ' t',
                    'Concrete' => number_format($metrics['concrete_m3'] ?? 0, 0) . ' m³',
                ] as $label => $value)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            @if ($run->narrative)
                <div class="mt-4 rounded-lg bg-gray-50 p-4 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">
                    {!! nl2br(e($run->narrative)) !!}
                </div>
            @endif

            @if ($canManage && $run->isDraft())
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button
                        wire:click="approve"
                        wire:confirm="Approve this plan? Every line will be held against regeneration."
                        icon="heroicon-m-check-circle"
                    >
                        Approve plan
                    </x-filament::button>
                    <x-filament::button
                        wire:click="discard"
                        wire:confirm="Discard this plan? Its lines will be cancelled."
                        color="danger"
                        outlined
                        icon="heroicon-m-x-circle"
                    >
                        Discard
                    </x-filament::button>
                </div>
            @elseif ($run->isApproved())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Approved{{ $run->approvedBy ? ' by ' . $run->approvedBy->name : '' }}
                    on {{ $run->approved_at?->format('j M Y') }}. Generate a new plan to change it.
                </p>
            @endif
        </x-filament::section>

        @if ($materials->isNotEmpty())
            <x-filament::section collapsible>
                <x-slot name="heading">Steel this plan needs</x-slot>

                <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($materials as $wireCode => $kilograms)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $wireCode }}</p>
                            <p class="mt-1 font-semibold text-gray-900 dark:text-white">
                                {{ number_format($kilograms / 1000, 2) }} t
                            </p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">The plan, line by line</x-slot>
            <x-slot name="description">
                Change pipes or date in the table. A line you change or hold survives the next regeneration.
            </x-slot>

            {{ $this->table }}
        </x-filament::section>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">What people changed</x-slot>
            <x-slot name="description">The last 25 edits to this plan.</x-slot>

            @if ($changes->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nobody has changed this plan yet.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($changes as $change)
                        <li class="flex flex-wrap gap-x-2 text-gray-700 dark:text-gray-200">
                            <span class="font-medium">{{ $change->line_description }}</span>
                            <span>{{ $change->summary() }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                — {{ $change->user?->name ?? 'the system' }},
                                {{ $change->created_at->diffForHumans() }}
                                @if ($change->reason)
                                    ({{ $change->reason }})
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
