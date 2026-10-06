<?php

namespace App\Services\DeliveryConfirmation;

use App\Enums\DeliveryConfirmationStage;
use App\Models\DeliveryConfirmationLineResponse;
use App\Models\DeliveryConfirmationSend;
use Illuminate\Support\Collection;

/**
 * Non-secret settings and per-entity counts for the Delivery reminders Filament page.
 */
class DeliveryReminderDashboardService
{
    public function __construct(private DeliveryReminderMailboxResolver $mailboxes) {}

    /**
     * @return array{
     *     enabled: bool,
     *     overdue_max_days_past: int,
     *     mailboxes: list<array{entity: string, mailbox: ?string}>,
     *     stages: list<array{key: string, label: string, window: string}>,
     *     schedule: list<array{command: string, cadence: string}>
     * }
     */
    public function settings(): array
    {
        return [
            'enabled' => (bool) config('delivery-reminders.enabled'),
            'overdue_max_days_past' => max(1, (int) config('delivery-reminders.overdue_max_days_past', 14)),
            'mailboxes' => $this->mailboxes->canonicalMailboxMap(),
            'stages' => [
                [
                    'key' => DeliveryConfirmationStage::Weeks34->value,
                    'label' => DeliveryConfirmationStage::Weeks34->label(),
                    'window' => 'Delivery date 15–28 days away',
                ],
                [
                    'key' => DeliveryConfirmationStage::Weeks12->value,
                    'label' => DeliveryConfirmationStage::Weeks12->label(),
                    'window' => 'Delivery date today through 14 days away',
                ],
                [
                    'key' => DeliveryConfirmationStage::Overdue->value,
                    'label' => DeliveryConfirmationStage::Overdue->label(),
                    'window' => 'Delivery date already past, limited to recent overdue only',
                ],
            ],
            'schedule' => [
                [
                    'command' => 'delivery-reminders:send',
                    'cadence' => 'Daily at 07:30 Australia/Melbourne',
                ],
                [
                    'command' => 'delivery-reminders:process-replies',
                    'cadence' => 'Every 30 minutes Australia/Melbourne',
                ],
            ],
        ];
    }

    /**
     * @return list<array{
     *     entity: string,
     *     sent: int,
     *     confirmed: int,
     *     written: int,
     *     pending_rep_approval: int,
     *     unresolved: int
     * }>
     */
    public function entitySummaries(): array
    {
        $sentByEntity = DeliveryConfirmationSend::query()
            ->selectRaw('entity, COUNT(*) as aggregate')
            ->where('status', 'sent')
            ->groupBy('entity')
            ->pluck('aggregate', 'entity');

        $unresolvedByEntity = DeliveryConfirmationSend::query()
            ->selectRaw('entity, COUNT(*) as aggregate')
            ->where('reply_outcome', 'unresolved')
            ->groupBy('entity')
            ->pluck('aggregate', 'entity');

        $lineOutcomes = DeliveryConfirmationLineResponse::query()
            ->join(
                'delivery_confirmation_sends',
                'delivery_confirmation_sends.id',
                '=',
                'delivery_confirmation_line_responses.delivery_confirmation_send_id',
            )
            ->selectRaw('delivery_confirmation_sends.entity as entity, delivery_confirmation_line_responses.outcome as outcome, COUNT(*) as aggregate')
            ->groupBy('delivery_confirmation_sends.entity', 'delivery_confirmation_line_responses.outcome')
            ->get();

        $confirmed = [];
        $written = [];
        $pending = [];

        foreach ($lineOutcomes as $row) {
            $entity = (string) $row->entity;
            $count = (int) $row->aggregate;

            match ((string) $row->outcome) {
                'confirmed' => $confirmed[$entity] = ($confirmed[$entity] ?? 0) + $count,
                'written' => $written[$entity] = ($written[$entity] ?? 0) + $count,
                'pending_rep_approval' => $pending[$entity] = ($pending[$entity] ?? 0) + $count,
                default => null,
            };
        }

        return array_map(
            fn (string $entity): array => [
                'entity' => $entity,
                'sent' => (int) ($sentByEntity[$entity] ?? 0),
                'confirmed' => $confirmed[$entity] ?? 0,
                'written' => $written[$entity] ?? 0,
                'pending_rep_approval' => $pending[$entity] ?? 0,
                'unresolved' => (int) ($unresolvedByEntity[$entity] ?? 0),
            ],
            DeliveryReminderMailboxResolver::CANONICAL_ENTITIES,
        );
    }

    /**
     * @return array{sent: int, confirmed: int, written: int, pending_rep_approval: int, unresolved: int}
     */
    public function totals(): array
    {
        $summaries = $this->entitySummaries();

        return [
            'sent' => (int) collect($summaries)->sum('sent'),
            'confirmed' => (int) collect($summaries)->sum('confirmed'),
            'written' => (int) collect($summaries)->sum('written'),
            'pending_rep_approval' => (int) collect($summaries)->sum('pending_rep_approval'),
            'unresolved' => (int) collect($summaries)->sum('unresolved'),
        ];
    }

    /**
     * @return Collection<int, DeliveryConfirmationSend>
     */
    public function recentSends(int $limit = 50): Collection
    {
        return DeliveryConfirmationSend::query()
            ->where('status', 'sent')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
