<?php

namespace App\Models\Rcpa;

class VwQUOTESView extends ReadOnlyView
{
    protected $table = 'dbo.vwQUOTES';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'CREATED_DATE' => 'datetime',
            'LAST_CHANGE_DATE' => 'datetime',
            'ORDER_DATE' => 'datetime',
            'SALES_CONVERSION' => 'decimal:2',
            'UNIT_WEIGHT' => 'decimal:2',
            'SELL_PRICE' => 'decimal:2',
            'QTY' => 'decimal:2',
            'ENTERED_QTY' => 'decimal:2',
            'WEIGHT' => 'decimal:2',
            'PRICE_EX_GST' => 'decimal:2',
            'TAX' => 'decimal:2',
            'PRICE_INC_GST' => 'decimal:2',
        ];
    }
}
