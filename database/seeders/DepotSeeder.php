<?php

namespace Database\Seeders;

use App\Models\Depot;
use Illuminate\Database\Seeder;

class DepotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Depot::query()->updateOrCreate(
            ['code' => 'MEL'],
            ['name' => 'Melton', 'state' => 'VIC', 'timezone' => 'Australia/Melbourne', 'is_active' => true],
        );

        Depot::query()->updateOrCreate(
            ['code' => 'KIL'],
            ['name' => 'Kilmore', 'state' => 'VIC', 'timezone' => 'Australia/Melbourne', 'is_active' => true],
        );
    }
}
