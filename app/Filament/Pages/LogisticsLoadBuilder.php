<?php

namespace App\Filament\Pages;

use App\Enums\Capability;
use App\Exceptions\IneligibleLoadQuantityException;
use App\Exceptions\LoadCapacityExceededException;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\Depot;
use App\Models\DispatchLoad;
use App\Models\DispatchLoadItem;
use App\Models\OrderAllocation;
use App\Services\Dispatch\DispatchNotificationManager;
use App\Services\Rcpa\RcpaViewReadService;
use App\Services\Scheduling\LoadCapacityService;
use App\Support\Rcpa\RcpaViewCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LogisticsLoadBuilder extends Page
{
    use HasOperationalFilters;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Load Builder';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.logistics-load-builder';

    public ?int $dispatchLoadId = null;

    public string $loadReference = '';

    public string $dispatchDate = '';

    public string $capacityTonnes = '24';

    public ?int $allocationId = null;

    public int $quantity = 1;

    public string $dispatchRecipients = '';

    public function mount(): void
    {
        $this->dispatchDate = now()->addDay()->toDateString();
    }

    public static function canAccess(): bool
    {
        return static::canViewOperationalPage([Capability::ViewDispatchLoads]);
    }

    public function createLoad(): void
    {
        $data = $this->validate([
            'depotId' => ['required', 'integer', Rule::exists(Depot::class, 'id')],
            'loadReference' => ['required', 'string', 'max:80', Rule::unique(DispatchLoad::class, 'load_reference')],
            'dispatchDate' => ['required', 'date'],
            'capacityTonnes' => ['required', 'numeric', 'gt:0', 'max:9999999'],
        ]);

        $depot = $this->accessiblePlants()->firstWhere('id', (int) $data['depotId']);
        abort_unless($depot instanceof Depot, 404);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $depot), 403);

        $load = DispatchLoad::query()->create([
            'depot_id' => $depot->id,
            'load_reference' => $data['loadReference'],
            'dispatch_date' => $data['dispatchDate'],
            'capacity_tonnes' => $data['capacityTonnes'],
            'status' => 'planned',
        ]);

        $this->dispatchLoadId = $load->id;
        $this->loadReference = '';

        Notification::make()->title('Dispatch load created')->success()->send();
    }

    public function addAllocation(LoadCapacityService $loadCapacityService): void
    {
        $data = $this->validate([
            'dispatchLoadId' => ['required', 'integer'],
            'allocationId' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $load = $this->selectedLoad();
        abort_unless($load instanceof DispatchLoad, 404);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $load->depot_id), 403);

        $allocation = OrderAllocation::query()
            ->whereKey($data['allocationId'])
            ->whereHas('salesOrder', fn (Builder $query) => $query->where('depot_id', $load->depot_id))
            ->first();
        abort_unless($allocation instanceof OrderAllocation, 404);

        try {
            $result = $loadCapacityService->addItem($load, $allocation, (int) $data['quantity']);

            $this->allocationId = null;
            $this->quantity = 1;

            Notification::make()
                ->title('Allocation added')
                ->body("Load weight is {$result->loadWeightTonnes} tonnes.")
                ->success()
                ->send();
        } catch (IneligibleLoadQuantityException|LoadCapacityExceededException|\InvalidArgumentException $exception) {
            $this->addError('quantity', $exception->getMessage());

            Notification::make()->title('Allocation could not be added')->body($exception->getMessage())->danger()->send();
        }
    }

    public function removeItem(int $itemId, LoadCapacityService $loadCapacityService): void
    {
        $load = $this->selectedLoad();
        abort_unless($load instanceof DispatchLoad, 404);
        abort_unless($this->canManageDepot(Capability::ManageDispatchLoads, $load->depot_id), 403);

        $item = DispatchLoadItem::query()
            ->where('dispatch_load_id', $load->id)
            ->find($itemId);
        abort_unless($item instanceof DispatchLoadItem, 404);

        $loadCapacityService->removeItem($load, $item);

        Notification::make()->title('Load item removed')->success()->send();
    }

    public function sendDispatchNotification(
        DispatchNotificationManager $notificationManager,
    ): void {
        $load = $this->selectedLoad();
        abort_unless($load instanceof DispatchLoad, 404);
        abort_unless($this->canManageDepot(Capability::SendDispatchNotifications, $load->depot_id), 403);
        $actor = static::operationalUser();
        abort_unless($actor !== null, 403);

        $data = $this->validate([
            'dispatchRecipients' => ['required', 'string', 'max:4000'],
        ]);
        $recipients = collect(preg_split('/[\s,;]+/', $data['dispatchRecipients']) ?: [])
            ->filter()
            ->values()
            ->all();
        $validator = Validator::make(
            ['recipients' => $recipients],
            [
                'recipients' => ['required', 'array', 'max:20'],
                'recipients.*' => ['required', 'email:rfc', 'max:254'],
            ],
        );

        if ($validator->fails()) {
            $message = $validator->errors()->first();
            $this->addError('dispatchRecipients', $message);
            Notification::make()
                ->title('Dispatch notification could not be queued')
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        try {
            $attempt = $notificationManager->queue(
                $load,
                $recipients,
                $actor,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('dispatchRecipients', $exception->getMessage());
            Notification::make()
                ->title('Dispatch notification could not be queued')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($attempt->status === 'sent') {
            Notification::make()
                ->title('Dispatch notification already sent')
                ->body('This load event has already been delivered and was not sent again.')
                ->info()
                ->send();

            return;
        }

        $this->dispatchRecipients = '';
        Notification::make()
            ->title($attempt->attempt_count > 0
                ? 'Dispatch notification retry queued'
                : 'Dispatch notification queued')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filters = $this->operationalFilterOptions();
        $depots = $filters['plants'];
        $selectedPlantIds = $this->selectedPlantIds();

        $loads = DispatchLoad::query()
            ->with('depot')
            ->when($this->accessibleDepotIds() !== null, fn (Builder $query) => $query->whereIn('depot_id', $this->accessibleDepotIds()))
            ->when($selectedPlantIds !== null, fn (Builder $query) => $query->whereIn('depot_id', $selectedPlantIds))
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->get();

        $load = $this->selectedLoad();
        $allocations = collect();

        if ($load !== null) {
            $allocations = OrderAllocation::query()
                ->with(['salesOrder.sku', 'inventory', 'productionSchedule'])
                ->withSum('dispatchItems as loaded_quantity', 'quantity')
                ->whereIn('status', ['allocated', 'reserved'])
                ->whereHas('salesOrder', fn (Builder $query) => $query->where('depot_id', $load->depot_id))
                ->orderBy('allocated_at')
                ->orderBy('id')
                ->get()
                ->filter(fn (OrderAllocation $allocation): bool => (int) ($allocation->loaded_quantity ?? 0) < $allocation->quantity)
                ->values();

            $load->load(['depot', 'items.orderAllocation.salesOrder.sku']);
        }

        $notificationAttempt = $load?->notificationAttempts()
            ->latest('id')
            ->first();

        return [
            ...$filters,
            'loads' => $loads,
            'load' => $load,
            'allocations' => $allocations,
            'canCreate' => $depots->isNotEmpty()
                && $depots->contains(fn (Depot $depot): bool => $this->canManageDepot(Capability::ManageDispatchLoads, $depot)),
            'canManageLoad' => $load !== null
                && $this->canManageDepot(Capability::ManageDispatchLoads, $load->depot_id),
            'canSendNotification' => $load !== null
                && $this->canManageDepot(Capability::SendDispatchNotifications, $load->depot_id),
            'notificationAttempt' => $notificationAttempt,
            'erpOrders' => (new RcpaViewReadService(new RcpaViewCatalog))->openSalesOrders(
                now()->toDateString(),
                now()->addDays(45)->toDateString(),
            ),
        ];
    }

    private function selectedLoad(): ?DispatchLoad
    {
        if ($this->dispatchLoadId === null) {
            return null;
        }

        return DispatchLoad::query()
            ->when($this->accessibleDepotIds() !== null, fn (Builder $query) => $query->whereIn('depot_id', $this->accessibleDepotIds()))
            ->find($this->dispatchLoadId);
    }
}
