<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Agent settings</x-slot>
        <x-slot name="description">
            Non-secret configuration only. Graph and SQL credentials stay in `.env` and are never shown here.
        </x-slot>

        @if (! $settings['enabled'])
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                <p class="font-medium">Sending is off</p>
                <p class="mt-1">
                    The scheduled job will not email customers until
                    <code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">DELIVERY_REMINDERS_ENABLED=true</code>
                    is set in `.env`. Run
                    <code class="rounded bg-amber-100 px-1 dark:bg-amber-500/20">php artisan delivery-reminders:mark-seen</code>
                    before enabling so the historic overdue book is not emailed.
                </p>
            </div>
        @endif

        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Enabled</dt>
                <dd class="mt-1 font-semibold text-gray-950 dark:text-white">
                    {{ $settings['enabled'] ? 'Yes' : 'No' }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Recent overdue window</dt>
                <dd class="mt-1 font-semibold text-gray-950 dark:text-white">
                    {{ $settings['overdue_max_days_past'] }} days past
                </dd>
            </div>
        </dl>

        <div class="mt-6">
            <h3 class="text-sm font-medium text-gray-950 dark:text-white">From addresses by entity</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Each order is sent from the shared sales mailbox for its state. Replies are read from the same mailbox.
            </p>
            <ul class="mt-2 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                @foreach ($settings['mailboxes'] as $mailboxRow)
                    <li>
                        <span class="font-medium">{{ $mailboxRow['entity'] }}</span>
                        —
                        {{ $mailboxRow['mailbox'] ?? 'Not mapped (sends skipped)' }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Reminder stages</h3>
                <ul class="mt-2 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($settings['stages'] as $stage)
                        <li>
                            <span class="font-medium">{{ $stage['label'] }}</span>
                            — {{ $stage['window'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-950 dark:text-white">Schedule</h3>
                <ul class="mt-2 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($settings['schedule'] as $item)
                        <li>
                            <code class="rounded bg-gray-100 px-1 text-xs dark:bg-white/10">{{ $item['command'] }}</code>
                            — {{ $item['cadence'] }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Both tasks run only while the agent is enabled.
                </p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">National overview</x-slot>
        <x-slot name="description">
            Totals across all entities from local send and reply records.
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Emails sent</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['sent']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Lines confirmed</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['confirmed']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Dates written</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['written']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Pending rep approval</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['pending_rep_approval']) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Unresolved replies</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['unresolved']) }}</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">By entity</x-slot>
        <x-slot name="description">
            Sent is emails. Confirmed / written / pending approval are order lines. Unresolved is emails whose reply could not be applied.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Entity</th>
                        <th class="py-2 pr-4 font-medium">Sent</th>
                        <th class="py-2 pr-4 font-medium">Confirmed</th>
                        <th class="py-2 pr-4 font-medium">Written to Tencia</th>
                        <th class="py-2 pr-4 font-medium">Pending rep approval</th>
                        <th class="py-2 font-medium">Unresolved replies</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entitySummaries as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-3 pr-4 font-medium text-gray-950 dark:text-white">{{ $row['entity'] }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ number_format($row['sent']) }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ number_format($row['confirmed']) }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ number_format($row['written']) }}</td>
                            <td class="py-3 pr-4 text-gray-700 dark:text-gray-300">{{ number_format($row['pending_rep_approval']) }}</td>
                            <td class="py-3 text-gray-700 dark:text-gray-300">{{ number_format($row['unresolved']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Recent emails sent</x-slot>
        <x-slot name="description">
            One row per sales-order reminder email. Recipient addresses are shown; secrets are not.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
