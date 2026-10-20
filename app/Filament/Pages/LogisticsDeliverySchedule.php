<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\TransportCarrier;
use App\Services\Logistics\DeliveryScheduleBuilder;
use App\Services\Logistics\DeliveryScheduleMailService;
use App\Services\Logistics\FreightEstimateService;
use App\Services\Logistics\OpenOrderLineReader;
use App\Services\Sync\OrderSync;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogisticsDeliverySchedule extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Logistics';

    protected static ?string $navigationLabel = 'Delivery Schedule';

    protected static ?string $title = 'Delivery schedule';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.logistics-delivery-schedule';

    public string $scheduleDate = '';

    public function mount(): void
    {
        $this->scheduleDate = now()->addDay()->toDateString();
        $this->syncOperationalFilterSelection();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage();
    }

    public function refreshSchedule(): void
    {
        $depot = $this->requireManageDepot();

        app(DeliveryScheduleBuilder::class)->refresh(
            $depot,
            CarbonImmutable::parse($this->scheduleDate),
            runReconcile: true,
        );

        Notification::make()->title('Schedule refreshed from ERP and allocations')->success()->send();
    }

    public function publishSchedule(): void
    {
        $schedule = $this->requireSchedule();
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $schedule->depot_id), 403);

        app(DeliveryScheduleMailService::class)->publish($schedule);

        Notification::make()->title('Schedule published')->success()->send();
    }

    public function sendToCarriers(): void
    {
        $schedule = $this->requireSchedule();
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $schedule->depot_id), 403);

        $recipients = app(DeliveryScheduleMailService::class)->sendToCarriers($schedule);

        Notification::make()
            ->title($recipients === [] ? 'No carrier emails sent' : 'Carrier emails queued')
            ->body($recipients === [] ? 'Assign carriers with email addresses first.' : implode(', ', $recipients))
            ->success()
            ->send();
    }

    public function updateLine(int $lineId, string $field, mixed $value): void
    {
        $line = DeliveryScheduleLine::query()->with('schedule')->findOrFail($lineId);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $line->schedule->depot_id), 403);

        if (! in_array($field, ['transport_carrier_id', 'is_confirmed', 'is_delayed', 'notes', 'dispatch_qty'], true)) {
            return;
        }

        if ($field === 'transport_carrier_id') {
            $line->transport_carrier_id = $value !== '' && $value !== null ? (int) $value : null;
            $line->transport_carrier_vehicle_id = null;
            $line->estimated_freight_amount = $this->freightForLine($line);
            $line->save();

            return;
        }

        if ($field === 'dispatch_qty') {
            $line->dispatch_qty = max(0, (int) $value);
            $line->save();

            return;
        }

        if ($field === 'is_confirmed' || $field === 'is_delayed') {
            $line->{$field} = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $line->save();

            return;
        }

        $line->notes = is_string($value) ? $value : null;
        $line->save();
    }

    public function exportCsv(): StreamedResponse
    {
        $schedule = $this->requireSchedule();
        abort_unless($this->canManageDepot(Capability::ViewDispatchLoads, $schedule->depot_id), 403);

        $filename = sprintf('delivery-schedule-%s-%s.csv', $schedule->depot->code, $schedule->schedule_date->format('Y-m-d'));

        return Response::streamDownload(function () use ($schedule): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Line', 'Qty', 'Tonnage', 'Transport', 'Confirmed', 'Notes']);

            foreach ($schedule->lines as $line) {
                fputcsv($handle, [
                    $line->erp_line_reference,
                    $line->dispatch_qty,
                    $line->tonnage,
                    $line->transportCarrier?->name,
                    $line->is_confirmed ? 'Yes' : 'No',
                    $line->notes,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $depot = $this->selectedDepot();
        $filters = $this->operationalFilterOptions();
        $schedule = null;
        $lineRows = collect();
        $carriers = TransportCarrier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_pickup']);

        if ($depot instanceof Depot) {
            $schedule = DeliverySchedule::query()
                ->with(['lines.transportCarrier'])
                ->where('depot_id', $depot->id)
                ->whereDate('schedule_date', $this->scheduleDate)
                ->first();

            if ($schedule !== null) {
                $entity = (string) $depot->erp_entity_code;
                $openByLine = app(OpenOrderLineReader::class)->lines($entity)
                    ->keyBy(fn (array $line): string => (string) $line['line_unique_id']);
                $ordersByLine = app(OrderSync::class)->lines()
                    ->filter(fn (array $line): bool => (int) $line['depot_id'] === $depot->id)
                    ->keyBy('erp_line_reference');

                $lineRows = $schedule->lines->map(function (DeliveryScheduleLine $line) use ($openByLine, $ordersByLine): array {
                    $open = $openByLine->get($line->erp_line_reference);
                    $order = $ordersByLine->get($line->erp_line_reference);

                    return [
                        'model' => $line,
                        'customer' => $open['customer_name'] ?? $order['customer_name'] ?? '—',
                        'suburb' => $open['suburb'] ?? '—',
                        'reference' => $open['reference_nbr'] ?? $order['erp_order_reference'] ?? '—',
                    ];
                });
            }
        }

        $totalsByCarrier = $lineRows
            ->groupBy(fn (array $row): string => (string) ($row['model']->transport_carrier_id ?? 'unassigned'))
            ->map(fn ($group): array => [
                'tonnage' => $group->sum(fn (array $row): float => (float) $row['model']->tonnage),
                'freight' => $group->sum(fn (array $row): float => (float) ($row['model']->estimated_freight_amount ?? 0)),
            ]);

        return [
            'plants' => $filters['plants'],
            'shifts' => $filters['shifts'],
            'machines' => $filters['machines'],
            'depot' => $depot,
            'schedule' => $schedule,
            'lineRows' => $lineRows,
            'carriers' => $carriers,
            'totalsByCarrier' => $totalsByCarrier,
            'canManage' => $depot instanceof Depot
                && $this->canManageDepot(Capability::ManageDispatchLoads, $depot),
        ];
    }

    private function freightForLine(DeliveryScheduleLine $line): ?float
    {
        if ($line->transport_carrier_id === null) {
            return null;
        }

        $carrier = TransportCarrier::query()->find($line->transport_carrier_id);

        if ($carrier === null) {
            return null;
        }

        $estimate = app(FreightEstimateService::class)->estimate(
            $line->erp_entity_code,
            '',
            $carrier,
            (float) $line->tonnage,
        );

        return $estimate?->estimatedAmount;
    }

    private function selectedDepot(): ?Depot
    {
        if ($this->depotId === null) {
            return null;
        }

        return $this->accessiblePlants()->firstWhere('id', $this->depotId);
    }

    private function requireManageDepot(): Depot
    {
        $depot = $this->selectedDepot();
        abort_unless($depot instanceof Depot, 422);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $depot), 403);

        return $depot;
    }

    private function requireSchedule(): DeliverySchedule
    {
        $depot = $this->selectedDepot();
        abort_unless($depot instanceof Depot, 422);

        return DeliverySchedule::query()
            ->with(['depot', 'lines.transportCarrier'])
            ->where('depot_id', $depot->id)
            ->whereDate('schedule_date', $this->scheduleDate)
            ->firstOrFail();
    }
}
