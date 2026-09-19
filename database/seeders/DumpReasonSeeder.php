<?php

namespace Database\Seeders;

use App\Models\DumpReason;
use Illuminate\Database\Seeder;

/**
 * Dumped-product reason codes from the 'DP Reason-Cause' tab, shared by all three states.
 * Pipes use 101-124 and cages 201-220. Descriptions are corrected for the typos in the
 * workbook ('MIssing Lifting hold', '118 -Stuck') while the codes stay exactly as recorded,
 * because the codes are what production writes on the sheet.
 */
class DumpReasonSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->reasons() as $productType => $reasons) {
            foreach ($reasons as $code => $description) {
                DumpReason::query()->updateOrCreate(
                    ['code' => (string) $code],
                    [
                        'description' => $description,
                        'product_type' => $productType,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function reasons(): array
    {
        return [
            DumpReason::TYPE_PIPE => [
                101 => 'Damaged socket',
                102 => 'Damaged spigot',
                103 => 'Damaged barrel',
                104 => 'Damaged lifting hole',
                105 => 'Circumferential crack',
                106 => 'Longitudinal crack',
                107 => 'Barrel ridges/shunting',
                108 => 'Spigot out of round',
                109 => 'Exposed reinforcement (socket)',
                110 => 'Exposed reinforcement (spigot)',
                111 => 'Exposed reinforcement (internal barrel)',
                112 => 'Exposed reinforcement (external barrel)',
                113 => 'Exposed reinforcement (lifting hole)',
                114 => 'Delamination/separation',
                115 => 'Boney socket',
                116 => 'Boney spigot',
                117 => 'Boney barrel',
                118 => 'Stuck lifting hole plug',
                119 => 'Missing lifting hole',
                120 => 'Noncompliant finish',
                121 => 'Non-compliant cover to steel',
                122 => 'Failed load test',
                123 => 'Other',
                124 => 'Cage let go',
            ],
            DumpReason::TYPE_CAGE => [
                201 => 'Weak welds',
                202 => 'Dimension out of spec',
                203 => 'Wire out of spec',
                220 => 'Other (comment)',
            ],
        ];
    }
}
