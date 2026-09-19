<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\DepotShiftPattern;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Shifts for all five plants, as each state's workbook runs them today.
 *
 * Victoria and Yatala run a day and an afternoon shift. NSW runs a single block per plant —
 * Somersby 16.5 hr and Thornton 9 hr — which is how its OEE Settings tab is configured.
 *
 * Varying shift lengths live in depot_shift_patterns rather than in separate shift records.
 * This replaces the earlier AFT-MON..AFT-FRI workaround, which had to invent five shifts to
 * express one shift that is 10 hr on Monday and Friday and 12 hr from Tuesday to Thursday.
 */
class ShiftSeeder extends Seeder
{
    /** Monday to Friday, matching Carbon::dayOfWeek where Sunday is 0. */
    private const WEEKDAYS = [1, 2, 3, 4, 5];

    public function run(): void
    {
        foreach ($this->shiftsByDepotCode() as $depotCode => $shifts) {
            $depot = Depot::query()->where('code', $depotCode)->first();

            if ($depot === null) {
                continue;
            }

            // Retire the per-weekday afternoon shifts this seeder used to create.
            $depot->shifts()
                ->whereIn('code', ['AFT-MON', 'AFT-TUE', 'AFT-WED', 'AFT-THU', 'AFT-FRI'])
                ->update(['is_active' => false]);

            foreach ($shifts as $shift) {
                $patterns = $shift['patterns'];
                unset($shift['patterns']);

                $record = Shift::query()->updateOrCreate(
                    ['depot_id' => $depot->id, 'code' => $shift['code']],
                    $shift + ['is_active' => true],
                );

                foreach ($patterns as $weekday => $durationMinutes) {
                    DepotShiftPattern::query()->updateOrCreate(
                        ['shift_id' => $record->id, 'weekday' => $weekday],
                        [
                            'depot_id' => $depot->id,
                            'duration_minutes' => $durationMinutes,
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function shiftsByDepotCode(): array
    {
        return [
            'MEL' => $this->dayAndAfternoon(performance: 100, availability: 60),
            'KIL' => $this->dayAndAfternoon(performance: 80, availability: 85),
            'YAT' => $this->dayAndAfternoon(performance: 87, availability: 87),
            // Somersby runs a day and an afternoon shift. Its OEE Settings tab collapses them
            // into one 16.5 hr block of 14.75 effective hours, so the split below preserves
            // that total exactly rather than inventing capacity: a 10 hr day shift and a
            // 6.5 hr afternoon shift, 540 + 345 = 885 min = 14.75 hr effective.
            // The real start times and lengths should be confirmed with the NSW team and
            // corrected in Plant Configuration → Shifts & hours.
            'SOM' => [
                [
                    'code' => 'DAY',
                    'name' => 'Day Shift',
                    'starts_at' => '06:00:00',
                    'duration_minutes' => 600,
                    'prestart_minutes' => 15,
                    'cleaning_minutes' => 45,
                    'performance_target_percent' => 87,
                    'availability_target_percent' => 87,
                    'patterns' => array_fill_keys(self::WEEKDAYS, 600),
                ],
                [
                    'code' => 'AFT',
                    'name' => 'Afternoon Shift',
                    'starts_at' => '16:00:00',
                    'duration_minutes' => 390,
                    'prestart_minutes' => 15,
                    'cleaning_minutes' => 30,
                    'performance_target_percent' => 87,
                    'availability_target_percent' => 87,
                    'patterns' => array_fill_keys(self::WEEKDAYS, 390),
                ],
            ],
            'THO' => [
                [
                    'code' => 'DAY',
                    'name' => 'Day Shift',
                    'starts_at' => '06:00:00',
                    'duration_minutes' => 540,
                    'prestart_minutes' => 15,
                    'cleaning_minutes' => 90,
                    'performance_target_percent' => 87,
                    'availability_target_percent' => 87,
                    'patterns' => array_fill_keys(self::WEEKDAYS, 540),
                ],
            ],
        ];
    }

    /**
     * A 10 hr day shift, and an afternoon shift that runs 10 hr on Monday and Friday and
     * 12 hr from Tuesday to Thursday.
     *
     * @return list<array<string, mixed>>
     */
    private function dayAndAfternoon(int $performance, int $availability): array
    {
        $common = [
            'prestart_minutes' => 15,
            'cleaning_minutes' => 45,
            'performance_target_percent' => $performance,
            'availability_target_percent' => $availability,
        ];

        return [
            [
                'code' => 'DAY',
                'name' => 'Day Shift',
                'starts_at' => '06:00:00',
                'duration_minutes' => 600,
                'patterns' => array_fill_keys(self::WEEKDAYS, 600),
            ] + $common,
            [
                'code' => 'AFT',
                'name' => 'Afternoon Shift',
                'starts_at' => '16:00:00',
                'duration_minutes' => 600,
                'patterns' => [1 => 600, 2 => 720, 3 => 720, 4 => 720, 5 => 600],
            ] + $common,
        ];
    }
}
