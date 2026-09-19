<?php

namespace Database\Factories;

use App\Models\Depot;
use App\Models\MachineAsset;
use App\Models\OeeSetting;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OeeSetting>
 */
class OeeSettingFactory extends Factory
{
    /**
     * Defaults mirror the VIC workbook's Melton dayshift block: a 10 hr shift less 15 min
     * prestart and 45 min in-shift cleaning gives the 9 effective hours it plans on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'depot_id' => Depot::factory(),
            'shift_id' => null,
            'machine_asset_id' => null,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'performance_percent' => 100,
            'availability_percent' => 60,
            'quality_percent' => null,
            'prestart_minutes' => 15,
            'paid_break_minutes' => 0,
            'unpaid_break_minutes' => 0,
            'in_shift_cleaning_minutes' => 45,
            'end_of_shift_cleaning_minutes' => 0,
            'notes' => null,
        ];
    }

    public function forShift(Shift $shift): static
    {
        return $this->state(fn (): array => [
            'depot_id' => $shift->depot_id,
            'shift_id' => $shift->id,
        ]);
    }

    public function forMachine(MachineAsset $machine): static
    {
        return $this->state(fn (): array => [
            'depot_id' => $machine->depot_id,
            'machine_asset_id' => $machine->id,
        ]);
    }
}
