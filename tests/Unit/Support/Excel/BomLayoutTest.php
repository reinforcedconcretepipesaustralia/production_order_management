<?php

namespace Tests\Unit\Support\Excel;

use App\Support\Excel\BomLayout;
use Tests\TestCase;

class BomLayoutTest extends TestCase
{
    public function test_detects_mould_cage_and_pallet_headers(): void
    {
        $layout = BomLayout::detect([
            5 => [
                'A' => 'Tencia Code',
                'B' => 'Mould type',
                'C' => 'No. moulds',
                'D' => 'Reo cage Single / Double',
                'E' => 'No. pallets',
            ],
        ]);

        $this->assertSame('B', $layout->column('mould_type'));
        $this->assertSame('C', $layout->column('mould_count'));
        $this->assertSame('D', $layout->column('cage'));
        $this->assertSame('E', $layout->column('pallet_count'));
    }
}
