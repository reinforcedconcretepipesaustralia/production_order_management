<?php

namespace App\Services\Planning;

use App\Models\Depot;
use App\Models\DowntimeEvent;
use App\Models\NonProductionDay;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Expands reference data into the concrete shift slots a plan can be built on.
 *
 * For every day in the horizon and every active shift at a plant it works out the shift
 * length for that weekday, drops non-production days, subtracts overlapping downtime, and
 * resolves the OEE settings in force. This is the step the workbooks have no equivalent for:
 * there, a planner types a date down column A and remembers which days the plant is shut.
 */
final class CapacityCalendarBuilder
{
    public function __construct(
        private readonly OeeSettingResolver $oeeSettings,
    ) {}

    /**
     * @param  Collection<int, Depot>  $depots
     * @return Collection<int, CapacitySlot> Ordered by date, then plant, then shift start.
     */
    public function build(Collection $depots, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($from->greaterThan($to)) {
            return collect();
        }

        $depotIds = $depots->pluck('id')->all();
        $closures = $this->closuresByDepot($depotIds, $from, $to);
        $downtime = $this->downtimeByDepot($depotIds, $from, $to);

        $slots = collect();

        foreach ($depots as $depot) {
            $shifts = $depot->shifts->where('is_active', true);

            foreach ($this->dates($from, $to) as $date) {
                if (($closures[$depot->id][$date->toDateString()] ?? null) !== null) {
                    continue;
                }

                foreach ($shifts as $shift) {
                    $durationMinutes = $shift->durationMinutesForWeekday($date->dayOfWeek);

                    if ($durationMinutes === null || $durationMinutes <= 0) {
                        continue;
                    }

                    foreach ($depot->machines->where('is_active', true) as $machine) {
                        $slots->push(new CapacitySlot(
                            depot: $depot,
                            shift: $shift,
                            machine: $machine,
                            date: $date,
                            durationMinutes: $durationMinutes,
                            downtimeMinutes: $this->downtimeMinutesFor(
                                $downtime[$depot->id] ?? collect(),
                                $shift,
                                $machine->id,
                                $date,
                                $durationMinutes,
                            ),
                            oeeSetting: $this->oeeSettings->resolve($depot->id, $shift->id, $machine->id, $date),
                        ));
                    }
                }
            }
        }

        return $slots->sortBy(fn (CapacitySlot $slot): string => implode('|', [
            $slot->date->toDateString(),
            $slot->depot->code,
            (string) $slot->shift->starts_at,
            $slot->machine->code,
        ]))->values();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        for ($date = $from->startOfDay(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @param  list<int>  $depotIds
     * @return array<int, array<string, NonProductionDay>>
     */
    private function closuresByDepot(array $depotIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return NonProductionDay::query()
            ->whereIn('depot_id', $depotIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('depot_id')
            ->map(fn (Collection $days) => $days->keyBy(fn (NonProductionDay $day): string => $day->date->toDateString())->all())
            ->all();
    }

    /**
     * @param  list<int>  $depotIds
     * @return array<int, Collection<int, DowntimeEvent>>
     */
    private function downtimeByDepot(array $depotIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DowntimeEvent::query()
            ->whereIn('depot_id', $depotIds)
            ->overlapping($from->startOfDay(), $to->endOfDay())
            ->get()
            ->groupBy('depot_id')
            ->all();
    }

    /**
     * Downtime overlapping this shift, capped at the shift length. A plant-wide event
     * (null machine) applies to every machine; an event pinned to another shift does not.
     *
     * @param  Collection<int, DowntimeEvent>  $events
     */
    private function downtimeMinutesFor(
        Collection $events,
        Shift $shift,
        int $machineId,
        CarbonImmutable $date,
        int $durationMinutes,
    ): int {
        if ($events->isEmpty()) {
            return 0;
        }

        $shiftStart = $date->setTimeFromTimeString((string) $shift->starts_at);
        $shiftEnd = $shiftStart->addMinutes($durationMinutes);

        $minutes = $events
            ->filter(fn (DowntimeEvent $event): bool => $event->machine_asset_id === null || $event->machine_asset_id === $machineId)
            ->filter(fn (DowntimeEvent $event): bool => $event->shift_id === null || $event->shift_id === $shift->id)
            ->sum(fn (DowntimeEvent $event): int => $event->minutesWithin($shiftStart, $shiftEnd));

        return min($durationMinutes, (int) $minutes);
    }
}
