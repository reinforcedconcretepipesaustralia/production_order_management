<?php

namespace App\Filament\Widgets;

use App\Enums\Capability;
use App\Filament\Concerns\HasOperationalFilters;
use App\Models\MachineAsset;
use App\Models\ProductionSchedule;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductionScheduleCalendar extends CalendarWidget
{
    use HasOperationalFilters;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    protected string|\Illuminate\Support\HtmlString|bool|null $heading = 'Guava schedule timeline';

    protected array $options = [
        'headerToolbar' => [
            'start' => 'prev,next today',
            'center' => 'title',
            'end' => 'resourceTimelineDay,resourceTimelineWeek',
        ],
        'buttonText' => [
            'resourceTimelineDay' => 'Day',
            'resourceTimelineWeek' => 'Week',
        ],
        'nowIndicator' => true,
        'selectable' => false,
        'eventStartEditable' => false,
        'eventDurationEditable' => false,
        'filterResourcesWithEvents' => true,
        'scrollTime' => '06:00',
        'height' => 680,
        'views' => [
            'resourceTimelineDay' => [
                'slotDuration' => '00:30',
                'slotLabelInterval' => '02:00',
                'slotWidth' => 32,
            ],
            'resourceTimelineWeek' => [
                'slotDuration' => '02:00',
                'slotLabelInterval' => '04:00',
                'slotWidth' => 28,
            ],
        ],
    ];

    public static function canView(): bool
    {
        return static::canViewOperationalPage([
            Capability::ViewSchedules,
            Capability::ViewDepotOperations,
        ]);
    }

    protected function getEvents(FetchInfo $info): Collection|array|Builder
    {
        return $this->applyOperationalFilters(
            ProductionSchedule::query()
                ->with(['depot', 'machine', 'sku'])
                ->where('planned_start_at', '<', $info->end)
                ->where('planned_end_at', '>', $info->start)
                ->orderBy('planned_start_at')
                ->orderBy('id'),
        )
            ->get()
            ->map(fn (ProductionSchedule $schedule): CalendarEvent => CalendarEvent::make()
                ->title($this->eventTitle($schedule))
                ->start($schedule->planned_start_at)
                ->end($schedule->planned_end_at)
                ->resourceId($schedule->machine_asset_id)
                ->backgroundColor($this->statusColor($schedule->status))
                ->textColor('#ffffff')
                ->editable(false)
                ->extendedProps([
                    'status' => $schedule->status,
                    'quantity' => $schedule->planned_quantity,
                    'oee' => (float) $schedule->planned_oee_percent,
                    'depot' => $schedule->depot->code,
                    'machine' => $schedule->machine->name ?: $schedule->machine->code,
                ]));
    }

    protected function getResources(): Collection|array|Builder
    {
        $accessibleDepotIds = $this->accessibleDepotIds();
        $this->syncOperationalFilterSelection();

        return MachineAsset::query()
            ->with('depot')
            ->where('is_active', true)
            ->when(
                $accessibleDepotIds !== null,
                fn (Builder $query) => $query->whereIn('depot_id', $accessibleDepotIds),
            )
            ->when($this->depotId !== null, fn (Builder $query) => $query->where('depot_id', $this->depotId))
            ->when(
                $this->machineName !== '',
                fn (Builder $query) => $query->whereKey($this->matchingMachineIds()),
            )
            ->when(
                $this->shiftName !== '',
                fn (Builder $query) => $query->whereHas(
                    'schedules',
                    fn (Builder $schedules) => $schedules->whereIn('shift_id', $this->matchingShiftIds()),
                ),
            )
            ->orderBy('depot_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (MachineAsset $machine): CalendarResource => CalendarResource::make($machine)
                ->title("{$machine->depot->code} — ".($machine->name ?: $machine->code)));
    }

    private function eventTitle(ProductionSchedule $schedule): string
    {
        return sprintf(
            '%s · %s pipes · OEE %s%% · %s',
            $schedule->sku->sku_code,
            number_format($schedule->planned_quantity),
            number_format((float) $schedule->planned_oee_percent, 0),
            str($schedule->status)->replace('_', ' ')->title(),
        );
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'scheduled' => '#002F67',
            'in_progress' => '#2A5A96',
            'completed' => '#1E6E42',
            'cancelled' => '#B3261E',
            default => '#747678',
        };
    }
}
