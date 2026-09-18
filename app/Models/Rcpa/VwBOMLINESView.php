<?php

namespace App\Models\Rcpa;

class VwBOMLINESView extends ReadOnlyView
{
    protected $table = 'dbo.vwBOM_LINES';

    protected $primaryKey = 'ENTITY';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'BILL_ORDER_QTY' => 'decimal:2',
        ];
    }
}
