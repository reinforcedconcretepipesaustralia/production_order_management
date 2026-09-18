<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Depot::query()->whereIn('code', ['MEL', 'KIL'])->each(function (Depot $depot): void {
            $depot->shifts()->where('code', 'AFT')->update(['is_active' => false]);

            foreach ([
                ['code' => 'DAY', 'name' => 'Day Shift', 'starts_at' => '06:00:00', 'duration_minutes' => 600],
                ['code' => 'AFT-MON', 'name' => 'Afternoon Shift Monday', 'starts_at' => '16:00:00', 'duration_minutes' => 600],
                ['code' => 'AFT-TUE', 'name' => 'Afternoon Shift Tuesday', 'starts_at' => '16:00:00', 'duration_minutes' => 720],
                ['code' => 'AFT-WED', 'name' => 'Afternoon Shift Wednesday', 'starts_at' => '16:00:00', 'duration_minutes' => 720],
                ['code' => 'AFT-THU', 'name' => 'Afternoon Shift Thursday', 'starts_at' => '16:00:00', 'duration_minutes' => 720],
                ['code' => 'AFT-FRI', 'name' => 'Afternoon Shift Friday', 'starts_at' => '16:00:00', 'duration_minutes' => 600],
            ] as $shift) {
                Shift::query()->updateOrCreate(
                    ['depot_id' => $depot->id, 'code' => $shift['code']],
                    $shift + [
                        'prestart_minutes' => 15,
                        'cleaning_minutes' => 30,
                        'performance_target_percent' => 90,
                        'availability_target_percent' => 95,
                        'is_active' => true,
                    ],
                );
            }
        });
    }
}
