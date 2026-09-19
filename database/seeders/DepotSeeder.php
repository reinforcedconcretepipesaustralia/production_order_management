<?php

namespace Database\Seeders;

use App\Models\Depot;
use Illuminate\Database\Seeder;

/**
 * The five RCPA pipe plants, with the Tencia mapping each one needs for imports.
 *
 * erp_entity_code matches the ENTITY column on the TenciaRCP_View vw* views. Stock
 * locations and categories come from the 'In Stock' and 'Outstanding Orders' queries in
 * each state's Pipes to Manufacture workbook, and the prime-versus-rework split from the
 * lookup table at O1:P14 on each workbook's 'Stock OH' sheet.
 *
 * Location codes are only unique within an entity — 'MAIN' is a Somersby yard in NSW and a
 * general good-stock location in VIC — so imports must always resolve on entity plus
 * location, never location alone.
 */
class DepotSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->depots() as $depot) {
            Depot::query()->updateOrCreate(['code' => $depot['code']], $depot);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function depots(): array
    {
        return [
            // Melton and Kilmore share one Victorian yard, so stock cannot be attributed to
            // a plant by location. The producing plant comes from the BOM 'Machine' column;
            // the workbook's fallback rule routes 225-375 mm and 600 mm to Kilmore and
            // everything else to Melton.
            [
                'code' => 'MEL',
                'name' => 'Melton',
                'state' => 'VIC',
                'timezone' => 'Australia/Melbourne',
                'erp_entity_code' => 'VIC',
                'erp_stock_categories' => ['VA', 'VB', 'VD'],
                'prime_stock_locations' => ['MAIN', '31', '32', '35', '36'],
                'rework_stock_locations' => ['33', '34', '37', '38'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'KIL',
                'name' => 'Kilmore',
                'state' => 'VIC',
                'timezone' => 'Australia/Melbourne',
                'erp_entity_code' => 'VIC',
                'erp_stock_categories' => ['VA', 'VB', 'VD'],
                'prime_stock_locations' => ['MAIN', '31', '32', '35', '36'],
                'rework_stock_locations' => ['33', '34', '37', '38'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            // NSW runs two plants with their own yards: MAIN is Somersby, 22 is Thornton.
            // Ownership of TS, QS and QT is not stated in the workbook and needs confirming
            // with the NSW team before stock in them is attributed to a plant.
            [
                'code' => 'SOM',
                'name' => 'Somersby',
                'state' => 'NSW',
                'timezone' => 'Australia/Sydney',
                'erp_entity_code' => 'NSW',
                'erp_stock_categories' => ['NA', 'NB', 'ND'],
                'prime_stock_locations' => ['MAIN'],
                'rework_stock_locations' => ['QS'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'THO',
                'name' => 'Thornton',
                'state' => 'NSW',
                'timezone' => 'Australia/Sydney',
                'erp_entity_code' => 'NSW',
                'erp_stock_categories' => ['NA', 'NB', 'ND'],
                'prime_stock_locations' => ['22'],
                'rework_stock_locations' => ['QT'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'YAT',
                'name' => 'Yatala',
                'state' => 'QLD',
                'timezone' => 'Australia/Brisbane',
                'erp_entity_code' => 'QLD',
                'erp_stock_categories' => ['YA', 'YB', 'YK', 'NK'],
                'prime_stock_locations' => ['40'],
                'rework_stock_locations' => ['42'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
        ];
    }
}
