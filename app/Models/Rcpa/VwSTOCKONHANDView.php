<?php

namespace App\Models\Rcpa;

class VwSTOCKONHANDView extends ReadOnlyView
{
    protected $table = 'dbo.vwSTOCK_ON_HAND';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'Stock Weight' => 'decimal:2',
            'Std Cost' => 'decimal:2',
            'Qty On Hand' => 'decimal:2',
            'Qty Allocated' => 'decimal:2',
            'Qty Backorder' => 'decimal:2',
            'Qty Available' => 'decimal:2',
        ];
    }
}
