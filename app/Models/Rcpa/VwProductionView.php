<?php

namespace App\Models\Rcpa;

class VwProductionView extends ReadOnlyView
{
    protected $table = 'dbo.vwProduction';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'ORDER_DATE' => 'datetime',
            'REQUIRED_DATE' => 'datetime',
            'QTY_ORDERED' => 'decimal:2',
            'QTY_USED' => 'decimal:2',
            'BILL_UNIT_WEIGHT' => 'decimal:2',
            'BILL_TONNES_PRODUCED' => 'decimal:2',
        ];
    }
}
