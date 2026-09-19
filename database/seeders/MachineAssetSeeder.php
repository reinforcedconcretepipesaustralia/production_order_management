<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\MachineAsset;
use Illuminate\Database\Seeder;

/**
 * Pipe machines per plant, taken from the 'Machine' column (V) of each state's BOM tab and
 * the machine breakdown on the 'OEE Performance' tab.
 *
 * That BOM column conflates two things: at Melton and Yatala it names a machine ('BiDi 36',
 * 'BiDi 84'), while at Kilmore, Somersby and Thornton it names the plant ('KILMORE', 'SOM',
 * 'THO') because those plants run a single pipe machine. It is also inconsistently cased —
 * 'BiDI 84' appears 250 times and 'BiDi 84' once in the VIC BOM — so the Phase 2 importer
 * must normalise on a case-insensitive match rather than the raw string.
 */
class MachineAssetSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->machinesByDepotCode() as $depotCode => $machines) {
            $depot = Depot::query()->where('code', $depotCode)->first();

            if ($depot === null) {
                continue;
            }

            foreach ($machines as $machine) {
                MachineAsset::query()->updateOrCreate(
                    ['code' => $machine['code']],
                    $machine + ['depot_id' => $depot->id, 'is_active' => true],
                );
            }
        }
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function machinesByDepotCode(): array
    {
        return [
            'MEL' => [
                ['code' => 'MEL-BIDI36', 'name' => 'BiDi 36', 'machine_type' => 'bidi'],
                ['code' => 'MEL-BIDI84', 'name' => 'BiDi 84', 'machine_type' => 'bidi'],
            ],
            'KIL' => [
                ['code' => 'KIL-PM1', 'name' => 'Kilmore Pipe Machine', 'machine_type' => 'pipe_machine'],
            ],
            'SOM' => [
                ['code' => 'SOM-PM1', 'name' => 'Somersby Pipe Machine', 'machine_type' => 'pipe_machine'],
            ],
            'THO' => [
                ['code' => 'THO-PM1', 'name' => 'Thornton Pipe Machine', 'machine_type' => 'pipe_machine'],
            ],
            'YAT' => [
                ['code' => 'YAT-BIDI36', 'name' => 'BiDi 36', 'machine_type' => 'bidi'],
                ['code' => 'YAT-BIDI84', 'name' => 'BiDi 84', 'machine_type' => 'bidi'],
            ],
        ];
    }
}
