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
                Every line says why it is there. Change what you disagree with; a line you change or
                hold survives the next regeneration.
            </x-slot>

            @if ($lanes->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                    <p class="font-semibold text-gray-900 dark:text-white">No lines in this plan</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Widen the plant, shift, or machine filters, or generate a new plan.</p>
                </div>
            @else
                <div class="space-y-6">
                    @foreach ($lanes as $key => $lines)
                        @php([$plantCode, $day, $shiftName] = explode('|', $key))

                        <div>
                            <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-white">
                                {{ $plantCode }} — {{ \Carbon\CarbonImmutable::parse($day)->format('D j M Y') }} — {{ $shiftName }}
                            </h3>

                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                        <tr>
                                            <th class="px-3 py-2">Product</th>
                                            <th class="px-3 py-2">Machine</th>
                                            <th class="px-3 py-2 text-right">Pipes</th>
                                            <th class="px-3 py-2 text-right">Share</th>
                                            <th class="px-3 py-2">Why</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                        @foreach ($lines as $line)
                                            <tr @class(['bg-primary-50/50 dark:bg-primary-500/5' => $line->is_locked])>
                                                <td class="px-3 py-2 font-medium text-gray-900 dark:text-white">
                                                    {{ $line->sku?->sku_code }}
                                                    @if ($line->is_steamed)
                                                        <span class="ml-1 text-xs text-gray-500">steamed</span>
                                                    @endif
                                                    @if ($line->origin === \App\Models\ProductionSchedule::ORIGIN_MANUAL)
                                                        <span class="ml-1 text-xs font-normal text-warning-600 dark:text-warning-400">edited</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $line->machine?->name ?? '—' }}</td>
                                                <td class="px-3 py-2 text-right text-gray-900 dark:text-white">{{ number_format($line->planned_quantity) }}</td>
                                                <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300">{{ number_format((float) $line->shift_fraction * 100, 0) }}%</td>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">
                                                    {{ $line->reason_code ? str($line->reason_code)->replace('_', ' ')->ucfirst() : '—' }}
                                                    @if ($line->planner_notes)
                                                        <span class="block text-xs italic text-gray-500">{{ $line->planner_notes }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                                    @if ($canManage && $run->isDraft())
                                                        <button type="button" wire:click="editLine({{ $line->id }})" class="text-primary-600 hover:underline dark:text-primary-400">Edit</button>
                                                        <button type="button" wire:click="toggleLock({{ $line->id }})" class="ml-2 text-gray-500 hover:underline dark:text-gray-400">
                                                            {{ $line->is_locked ? 'Release' : 'Hold' }}
                                                        </button>
                                                    @elseif ($line->is_locked)
                                                        <span class="text-xs text-gray-500">held</span>
                                                    @endif
                                                </td>
                                            </tr>

                                            @if ($editingLineId === $line->id)
                                                <tr class="bg-gray-50 dark:bg-white/5">
                                                    <td colspan="6" class="px-3 py-4">
                                                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                                            <label class="grid gap-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                                Pipes
                                                                <input type="number" min="0" wire:model="editing.planned_quantity" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                                            </label>
                                                            <label class="grid gap-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                                Share of shift
                                                                <input type="number" step="0.05" min="0" max="2" wire:model="editing.shift_fraction" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                                            </label>
                                                            <label class="grid gap-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                                Priority
                                                                <input type="number" wire:model="editing.priority" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                                            </label>
                                                            <label class="grid gap-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                                Notes
                                                                <input type="text" wire:model="editing.planner_notes" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                                            </label>
                                                            <label class="flex items-end gap-2 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                                <input type="checkbox" wire:model="editing.is_steamed" class="fi-checkbox-input rounded border-gray-300">
                                                                Steamed
                                                            </label>
                                                        </div>

                                                        <label class="mt-3 grid gap-1 text-xs font-medium text-gray-700 dark:text-gray-200">
                                                            Why are you changing it? (kept in the change log)
                                                            <input type="text" wire:model="editReason" class="fi-input rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                                        </label>

                                                        <div class="mt-3 flex gap-2">
                                                            <x-filament::button wire:click="saveLine" size="sm">Save</x-filament::button>
                                                            <x-filament::button wire:click="cancelEdit" size="sm" color="gray" outlined>Cancel</x-filament::button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
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
