<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Exceptions\IneligibleLoadQuantityException;
use App\Exceptions\LoadCapacityExceededException;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\DeliverySchedule;
use App\Models\DeliveryScheduleLine;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\TransportCarrier;
use App\Models\TransportCarrierVehicle;
use App\Services\Logistics\DeliveryScheduleBuilder;
use App\Services\Logistics\DeliveryScheduleMailService;
use App\Services\Logistics\ScheduleLineDispatchService;
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

    public ?int $dispatchLoadId = null;

    public string $loadReference = '';

    public string $capacityTonnes = '24';

    public function mount(): void
    {
        $date = request()->query('date');
        $this->scheduleDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? $date
            : now()->addDay()->toDateString();

        $depot = request()->query('depot');
        if (is_numeric($depot)) {
            $this->depotId = (int) $depot;
        }

        $this->syncOperationalFilterSelection();
    }

    public function updatedScheduleDate(): void
    {
        $this->dispatchLoadId = null;
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
            ->title($recipients === [] ? 'No carrier emails sent' : 'Carrier emails sent')
            ->body($recipients === []
                ? 'Confirm the lines, then assign a carrier that has an email address. Pickup carriers are not emailed.'
                : implode(', ', $recipients))
            ->success()
            ->send();
    }

    public function sendInternalCopy(): void
    {
        $schedule = $this->requireSchedule();
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $schedule->depot_id), 403);

        $recipients = app(DeliveryScheduleMailService::class)->sendInternalCopy($schedule);

        Notification::make()
            ->title($recipients === [] ? 'No internal copy sent' : 'Internal schedule sent')
            ->body($recipients === []
                ? 'Set LOGISTICS_INTERNAL_SCHEDULE_RECIPIENTS in the environment.'
                : implode(', ', $recipients))
            ->success()
            ->send();
    }

    public function createScheduleLoad(): void
    {
        $depot = $this->requireManageDepot();
        $data = $this->validate([
            'loadReference' => ['required', 'string', 'max:80', 'unique:dispatch_loads,load_reference'],
            'capacityTonnes' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'scheduleDate' => ['required', 'date'],
        ]);

        $load = DispatchLoad::query()->create([
            'depot_id' => $depot->id,
            'load_reference' => $data['loadReference'],
            'dispatch_date' => $data['scheduleDate'],
            'capacity_tonnes' => $data['capacityTonnes'],
            'status' => 'planned',
        ]);

        $this->dispatchLoadId = $load->id;
        $this->loadReference = '';

        Notification::make()->title('Dispatch load created')->success()->send();
    }

    public function addLineToLoad(int $lineId, ScheduleLineDispatchService $dispatcher): void
    {
        $line = DeliveryScheduleLine::query()->with('schedule')->findOrFail($lineId);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $line->schedule->depot_id), 403);

        $load = DispatchLoad::query()->find($this->dispatchLoadId);
        abort_unless($load instanceof DispatchLoad, 422);

        try {
            $result = $dispatcher->addToLoad($line, $load);
            Notification::make()
                ->title('Line added to load')
                ->body("Load weight is {$result->loadWeightTonnes} tonnes.")
                ->success()
                ->send();
        } catch (IneligibleLoadQuantityException|LoadCapacityExceededException|\InvalidArgumentException $exception) {
            Notification::make()->title('Line could not be loaded')->body($exception->getMessage())->danger()->send();
        }
    }

    public function updateLine(int $lineId, string $field, mixed $value): void
    {
        $line = DeliveryScheduleLine::query()->with('schedule')->findOrFail($lineId);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $line->schedule->depot_id), 403);

        if (! in_array($field, [
            'transport_carrier_id',
            'transport_carrier_vehicle_id',
            'is_confirmed',
            'is_delayed',
            'delay_reason',
            'notes',
            'dispatch_qty',
        ], true)) {
            return;
        }

        if ($field === 'transport_carrier_id') {
            $carrierId = $value !== '' && $value !== null ? (int) $value : null;
            $line->transport_carrier_id = $carrierId;
            $carrier = $carrierId === null ? null : TransportCarrier::query()->find($carrierId);
            $line->transport_carrier_vehicle_id = $carrier?->defaultVehicle()?->id;
            $line->save();
            app(DeliveryScheduleBuilder::class)->applyLineMetrics($line);

            return;
        }

        if ($field === 'transport_carrier_vehicle_id') {
            $line->transport_carrier_vehicle_id = $value !== '' && $value !== null ? (int) $value : null;
            $line->save();
            app(DeliveryScheduleBuilder::class)->applyLineMetrics($line);

            return;
        }

        if ($field === 'dispatch_qty') {
            $line->dispatch_qty = max(0, (int) $value);
            $line->save();
            app(DeliveryScheduleBuilder::class)->applyLineMetrics($line);

            return;
        }

        if ($field === 'is_confirmed' || $field === 'is_delayed') {
            $line->{$field} = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            $line->save();

            return;
        }

        $line->{$field} = is_string($value) ? $value : null;
        $line->save();
    }

    public function exportCsv(): StreamedResponse
    {
        $schedule = $this->requireSchedule();
        abort_unless($this->canManageDepot(Capability::ViewDispatchLoads, $schedule->depot_id), 403);

        $filename = sprintf('delivery-schedule-%s-%s.csv', $schedule->depot->code, $schedule->schedule_date->format('Y-m-d'));

        return Response::streamDownload(function () use ($schedule): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'SO#', 'Customer', 'Order#', 'Contact', 'Phone', 'Delivery name', 'Suburb', 'Zone',
                'Pipe', 'Balance', 'SOH', 'Qty', 'Tonnage', 'Delayed', 'Notes', 'Transport', 'Confirmed', 'Est. freight',
            ]);

            foreach ($schedule->lines as $line) {
                fputcsv($handle, [
                    $line->erp_order_reference,
                    $line->customer_name,
                    $line->customer_order,
                    $line->contact_name,
                    $line->phone,
                    $line->delivery_name,
                    $line->suburb,
                    $line->zone_code,
                    $line->stock_code,
                    $line->backorder_qty,
                    $line->prime_soh,
                    $line->dispatch_qty,
                    $line->tonnage,
                    $line->is_delayed ? ($line->delay_reason ?: 'Yes') : 'No',
                    $line->notes,
                    $line->transportCarrier?->name,
                    $line->is_confirmed ? 'Yes' : 'No',
                    $line->estimated_freight_amount,
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
        $vehicles = TransportCarrierVehicle::query()->orderBy('name')->get();

        if ($depot instanceof Depot) {
            $schedule = DeliverySchedule::query()
                ->with(['lines.transportCarrier'])
                ->where('depot_id', $depot->id)
                ->whereDate('schedule_date', $this->scheduleDate)
                ->first();

            $lineRows = $schedule?->lines ?? collect();
        }

        $loadedByAllocation = DispatchLoadItem::query()
            ->whereIn('order_allocation_id', $lineRows->pluck('order_allocation_id')->filter())
            ->selectRaw('order_allocation_id, sum(quantity) as loaded_qty')
            ->groupBy('order_allocation_id')
            ->pluck('loaded_qty', 'order_allocation_id');

        $loads = $depot instanceof Depot
            ? DispatchLoad::query()
                ->where('depot_id', $depot->id)
                ->whereDate('dispatch_date', $this->scheduleDate)
                ->orderBy('load_reference')
                ->get()
            : collect();

        $totalsByCarrier = $lineRows
            ->groupBy(fn (DeliveryScheduleLine $line): string => (string) ($line->transport_carrier_id ?? 'unassigned'))
            ->map(fn ($group): array => [
                'tonnage' => (float) $group->sum('tonnage'),
                'freight' => (float) $group->sum('estimated_freight_amount'),
            ]);

        return [
            'plants' => $filters['plants'],
            'shifts' => $filters['shifts'],
            'machines' => $filters['machines'],
            'depot' => $depot,
            'schedule' => $schedule,
            'lineRows' => $lineRows,
            'loads' => $loads,
            'loadedByAllocation' => $loadedByAllocation,
            'carriers' => $carriers,
            'vehicles' => $vehicles,
            'totalQty' => (int) $lineRows->sum('dispatch_qty'),
            'totalTonnage' => (float) $lineRows->sum('tonnage'),
            'totalsByCarrier' => $totalsByCarrier,
            'canManage' => $depot instanceof Depot
                && $this->canManageDepot(Capability::ManageDispatchLoads, $depot),
        ];
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
