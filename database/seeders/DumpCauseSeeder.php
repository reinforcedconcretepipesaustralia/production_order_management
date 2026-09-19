<?php

namespace Database\Seeders;

use App\Models\DumpCause;
use Illuminate\Database\Seeder;

/**
 * Root causes from the 'DP Reason-Cause' tab column C. Grouped into categories here so
 * plan-versus-actual reporting can roll dumped pipes up to machine, mix, handling, mould or
 * process without asking production to change how they record a dump.
 *
 * Typos in the workbook are corrected ('Not required by Costumer', 'Poor  repair'); 'Upgrade',
 * 'Downgrade' and 'Repaired' are kept because they are recorded as outcomes today.
 */
class DumpCauseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->causes() as $category => $descriptions) {
            foreach ($descriptions as $description) {
                DumpCause::query()->updateOrCreate(
                    ['description' => $description],
                    ['category' => $category, 'is_active' => true],
                );
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function causes(): array
    {
        return [
            'machine' => [
                'BiDi set up/settings',
                'Broken machine',
                'Head set up',
                'Machine error',
                'Machine overfeed',
                'Mechanical issue',
                'Misaligned floor plate',
                'Pallet catching on rollerhead',
                'Rollerhead stalled',
                'Top plates came off',
                'Tyre let go',
                'Wheel came off',
                'Wheel issues',
            ],
            'mix' => [
                'Bridging in hopper',
                'Concrete strength',
                'Dry mix',
                'Lack of feed',
                'Mix/batching',
                'Poor compaction',
                'Wet mix',
            ],
            'mould' => [
                'Mould door issue',
                'Mould wear bands',
                'Spigot former condition',
                'Spigot keeper',
                'Tile broke',
                'Tiles condition',
                'Loss of plug',
                'Plug came out',
                'Plug holder twisted',
                'Plug stuck',
                'Excessive oiling',
                'Insufficient oiling',
            ],
            'reinforcement' => [
                'Cage let go',
                'Cage out of spec',
                'Cage position',
                'Cage specification',
                'Longo sticking out',
                'Weak welds',
            ],
            'handling' => [
                'Depalletizing damage',
                'Fork lift handling',
                'Forklift grab pressure',
                'Incorrect dunnage/protection',
                'Incorrect load restraint',
                'Incorrect stacking',
                'Insufficient tine protection',
                'Pallet condition',
                'Pipe dropped',
                'Stacking specification',
                'Yard/road conditions',
            ],
            'process' => [
                'Ambient conditions',
                'Brushing in',
                'Demoulding',
                'Knocking off',
                'Longbottom issues',
                'Operator error',
                'Poor repair',
                'Require finish',
            ],
            'disposition' => [
                'Downgrade',
                'Load tested/cored pipe',
                'Not required by customer',
                'Repaired',
                'Upgrade',
                'Unknown',
            ],
        ];
    }
}
