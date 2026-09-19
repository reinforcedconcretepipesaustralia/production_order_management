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
            // Victorian stock locations are plant-specific, despite what the state workbook
            // implies: the national extract names 31 'Melton Pipes' and 32 'Kilmore Pipes'.
            [
                'code' => 'MEL',
                'name' => 'Melton',
                'state' => 'VIC',
                'timezone' => 'Australia/Melbourne',
                'erp_entity_code' => 'RCPVIC',
                'erp_stock_categories' => ['VA', 'VB', 'VD'],
                'prime_stock_locations' => ['31'],
                'rework_stock_locations' => ['33', '37'],
                'quarantine_stock_locations' => [],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'KIL',
                'name' => 'Kilmore',
                'state' => 'VIC',
                'timezone' => 'Australia/Melbourne',
                'erp_entity_code' => 'RCPVIC',
                'erp_stock_categories' => ['VA', 'VB', 'VD'],
                'prime_stock_locations' => ['32'],
                'rework_stock_locations' => ['34'],
                'quarantine_stock_locations' => [],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            // The national extract names every NSW location, which settles who owns what:
            // MAIN is Somersby, 22 and TS are Thornton, and QS and QT are each plant's
            // quarantine rather than rework.
            [
                'code' => 'SOM',
                'name' => 'Somersby',
                'state' => 'NSW',
                'timezone' => 'Australia/Sydney',
                'erp_entity_code' => 'RCPNSW',
                'erp_stock_categories' => ['NA', 'NB', 'ND'],
                'prime_stock_locations' => ['MAIN'],
                'rework_stock_locations' => [],
                'quarantine_stock_locations' => ['QS'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'THO',
                'name' => 'Thornton',
                'state' => 'NSW',
                'timezone' => 'Australia/Sydney',
                'erp_entity_code' => 'RCPNSW',
                'erp_stock_categories' => ['NA', 'NB', 'ND'],
                'prime_stock_locations' => ['22', 'TS'],
                'rework_stock_locations' => [],
                'quarantine_stock_locations' => ['QT'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
            [
                'code' => 'YAT',
                'name' => 'Yatala',
                'state' => 'QLD',
                'timezone' => 'Australia/Brisbane',
                'erp_entity_code' => 'RCPQLD',
                'erp_stock_categories' => ['YA', 'YB', 'YK', 'NK'],
                'prime_stock_locations' => ['40'],
                'rework_stock_locations' => ['42'],
                'quarantine_stock_locations' => ['43'],
                'is_active' => true,
                'is_planning_enabled' => true,
            ],
        ];
    }
}
