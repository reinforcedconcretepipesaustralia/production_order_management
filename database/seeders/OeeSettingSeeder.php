<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\OeeSetting;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Current OEE settings, transcribed from the 'OEE Settings' tab of each state's production
 * planning workbook.
 *
 * Each block on that tab lists performance, availability and effective hours, then the
 * overheads that produce the effective hours. Those overheads are stored here in minutes so
 * the capacity calculator derives effective hours rather than trusting a typed-in figure:
 *
 *   Melton, Kilmore, Yatala   600 - 15 prestart - 45 cleaning = 540 min = 9.00 hr
 *   Somersby                  990 - 15 prestart - 90 cleaning = 885 min = 14.75 hr
 *   Thornton                  540 - 15 prestart - 90 cleaning = 435 min = 7.25 hr
 *
 * Breaks are zero across every plant because all five stagger crews to run through them.
 * End-of-shift cleaning is zero because the Dust and Asset Management team covers it.
 *
 * The FY26 target on every tab is 0.90 performance and 0.90 availability. It is not seeded:
 * plans must be built on what the plants are achieving now, and a future target is added as
 * a new row with its own effective_from when the business commits to the date.
 */
class OeeSettingSeeder extends Seeder
{
    public function run(): void
    {
        $effectiveFrom = now()->startOfMonth()->toDateString();

        foreach ($this->settingsByDepotCode() as $depotCode => $settings) {
            $depot = Depot::query()->where('code', $depotCode)->first();

            if ($depot === null) {
                continue;
            }

            foreach ($settings as $shiftCode => $setting) {
                $shift = Shift::query()
                    ->where('depot_id', $depot->id)
                    ->where('code', $shiftCode)
                    ->first();

                if ($shift === null) {
                    continue;
                }

                OeeSetting::query()->updateOrCreate(
                    [
                        'depot_id' => $depot->id,
                        'shift_id' => $shift->id,
                        'machine_asset_id' => null,
                        'effective_from' => $effectiveFrom,
                    ],
                    $setting,
                );
            }
        }
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function settingsByDepotCode(): array
    {
        return [
            'MEL' => [
                'DAY' => $this->tenHourShift(performance: 100, availability: 60),
                'AFT' => $this->tenHourShift(performance: 100, availability: 60),
            ],
            'KIL' => [
                'DAY' => $this->tenHourShift(performance: 80, availability: 85),
                'AFT' => $this->tenHourShift(performance: 80, availability: 85),
            ],
            'YAT' => [
                'DAY' => $this->tenHourShift(performance: 87, availability: 87),
                'AFT' => $this->tenHourShift(performance: 87, availability: 87),
            ],
            // Somersby's day and afternoon shifts together preserve the 14.75 effective hours
            // its workbook plans on: (600 - 60) + (390 - 45) = 885 min.
            'SOM' => [
                'DAY' => $this->nswShift(inShiftCleaningMinutes: 45),
                'AFT' => $this->nswShift(inShiftCleaningMinutes: 30),
            ],
            'THO' => [
                'DAY' => $this->nswShift(inShiftCleaningMinutes: 90),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenHourShift(int $performance, int $availability): array
    {
        return [
            'performance_percent' => $performance,
            'availability_percent' => $availability,
            'quality_percent' => null,
            'prestart_minutes' => 15,
            'paid_break_minutes' => 0,
            'unpaid_break_minutes' => 0,
            'in_shift_cleaning_minutes' => 45,
            'end_of_shift_cleaning_minutes' => 0,
            'notes' => 'Breaks staggered to run through; end-of-shift cleaning covered by Dust/Asset Mgmt.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nswShift(int $inShiftCleaningMinutes): array
    {
        return [
            'performance_percent' => 87,
            'availability_percent' => 87,
            'quality_percent' => null,
            'prestart_minutes' => 15,
            'paid_break_minutes' => 0,
            'unpaid_break_minutes' => 0,
            'in_shift_cleaning_minutes' => $inShiftCleaningMinutes,
            'end_of_shift_cleaning_minutes' => 0,
            'notes' => 'Breaks staggered to run through; end-of-shift cleaning covered by Dust/Asset Mgmt.',
        ];
    }
}
